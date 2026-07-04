<?php
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();
$token = trim($_GET['token'] ?? $_SESSION['explorer_portal_token'] ?? '');

$team = explorer_fetch_team($pdo, $token);

if (!$team) {
    include __DIR__ . '/explorer_error.php';
}

$_SESSION['explorer_portal_token'] = $token;

// CSRF token setup
if (empty($_SESSION['explorer_checkin_csrf'])) {
    $_SESSION['explorer_checkin_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['explorer_checkin_csrf'];

// Handle POST: acknowledge announcement
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acknowledge_announcement') {
    try {
        // CSRF validation
        if (!isset($_POST['csrf_token'], $_SESSION['explorer_checkin_csrf'])
            || !hash_equals($_SESSION['explorer_checkin_csrf'], $_POST['csrf_token'])) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        // Validate announcement_id
        $announcementId = (int)($_POST['announcement_id'] ?? 0);
        if ($announcementId <= 0) {
            throw new RuntimeException('Invalid announcement.');
        }

        // Validate name
        $acknowledgedByName = trim($_POST['acknowledged_by_name'] ?? '');
        if ($acknowledgedByName === '') {
            throw new RuntimeException('Please enter your name to acknowledge this announcement.');
        }

        // Verify announcement exists and is targeted to this team
        $stmt = $pdo->prepare(
            'SELECT a.id, a.title, a.sender_leader_id, l.email AS sender_email, l.name AS sender_name
             FROM announcements a
             LEFT JOIN leaders l ON l.id = a.sender_leader_id
             WHERE a.id = ?
               AND (a.team_id IS NULL OR a.team_id = ?)'
        );
        $stmt->execute([$announcementId, (int)$team['id']]);
        $announcement = $stmt->fetch();

        if (!$announcement) {
            throw new RuntimeException('Announcement not found or not targeted to your team.');
        }

        // Insert acknowledgement (idempotent via INSERT IGNORE on unique key)
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO announcement_acknowledgements (announcement_id, team_id, acknowledged_by_name)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$announcementId, (int)$team['id'], $acknowledgedByName]);

        // Only send emails if this is a new acknowledgement (not a duplicate)
        if ($stmt->rowCount() > 0) {
            $emailSubject = e($team['name']) . ' acknowledged: ' . $announcement['title'];
            $emailContent = '<p><strong>' . e($team['name']) . '</strong> has acknowledged the announcement: <strong>' . e($announcement['title']) . '</strong></p>';
            $emailContent .= '<p>Acknowledged by: ' . e($acknowledgedByName) . '</p>';
            $emailContent .= '<p>Time: ' . e(date('d M Y, H:i')) . '</p>';

            // Queue email to sender leader (if has email)
            if (!empty($announcement['sender_email']) && filter_var($announcement['sender_email'], FILTER_VALIDATE_EMAIL)) {
                explorer_queue_email($pdo, $announcement['sender_email'], $emailSubject, $emailContent, (int)$team['id']);
            }

            // Queue emails to on-duty leaders
            try {
                $dutyStmt = $pdo->prepare(
                    'SELECT l.email
                     FROM leader_duty_roster r
                     JOIN leaders l ON l.id = r.leader_id
                     WHERE r.duty_date = CURDATE()
                       AND r.status = \'on_duty\'
                       AND l.email IS NOT NULL
                       AND l.email != \'\''
                );
                $dutyStmt->execute();
                $dutyLeaders = $dutyStmt->fetchAll();

                foreach ($dutyLeaders as $leader) {
                    $leaderEmail = trim($leader['email']);
                    // Avoid duplicate email to sender
                    if ($leaderEmail !== '' && filter_var($leaderEmail, FILTER_VALIDATE_EMAIL)
                        && strtolower($leaderEmail) !== strtolower($announcement['sender_email'] ?? '')) {
                        explorer_queue_email($pdo, $leaderEmail, $emailSubject, $emailContent, (int)$team['id']);
                    }
                }
            } catch (Throwable $e) {
                // Duty roster table may not exist — skip silently
            }
        }

        // PRG redirect
        header('Location: ' . url('explorer_announcements.php?token=' . urlencode($token)));
        exit;
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        $error = 'Something went wrong. Please try again.';
    }
}

include __DIR__ . '/explorer_header.php';

// Fetch announcements with acknowledgement status
$announcements = [];

try {
    $stmt = $pdo->prepare(
        'SELECT 
            a.id,
            a.title,
            a.content,
            a.created_at,
            l.name AS sender_name,
            ack.acknowledged_by_name,
            ack.acknowledged_at
        FROM announcements a
        LEFT JOIN leaders l ON l.id = a.sender_leader_id
        LEFT JOIN announcement_acknowledgements ack ON ack.announcement_id = a.id AND ack.team_id = :team_id
        WHERE (a.team_id IS NULL OR a.team_id = :team_id2)
        ORDER BY a.created_at DESC'
    );

    $stmt->execute([
        ':team_id' => (int)$team['id'],
        ':team_id2' => (int)$team['id'],
    ]);

    $announcements = $stmt->fetchAll();
} catch (Throwable $e) {
    // Tables may not exist yet — show empty state
    $announcements = [];
}
?>

<style>
    body {
        background: #f3f2f1;
        color: #1d1d1d;
    }

    .announcements-heading {
        font-weight: 900;
        margin-bottom: 1.5rem;
    }

    .announcement-card {
        background: #ffffff;
        border: 2px solid #d8d8d8;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.25rem;
    }

    .announcement-card--unacknowledged {
        border-left: 6px solid #7413dc;
        background: #ffffff;
    }

    .announcement-card--unacknowledged .announcement-title {
        font-weight: 900;
    }

    .announcement-card--acknowledged {
        border-left: 6px solid #00703c;
        background: #f8f8f8;
    }

    .announcement-card--acknowledged .announcement-title {
        font-weight: 700;
        color: #505a5f;
    }

    .announcement-title {
        font-size: 1.15rem;
        margin-bottom: 0.25rem;
    }

    .announcement-meta {
        font-size: 0.9rem;
        color: #505a5f;
        margin-bottom: 0.75rem;
    }

    .announcement-content {
        font-size: 1rem;
        line-height: 1.6;
        margin-bottom: 0.75rem;
    }

    .announcement-ack-status {
        font-size: 0.95rem;
        color: #00703c;
        font-weight: 700;
    }

    .announcement-ack-form {
        display: none;
        margin-top: 0.75rem;
        padding: 1rem;
        background: #f3f2f1;
        border: 1px solid #d8d8d8;
    }

    .empty-state {
        background: #ffffff;
        border: 2px solid #d8d8d8;
        padding: 2rem 1.5rem;
        text-align: center;
        color: #505a5f;
        font-size: 1.05rem;
    }
</style>

<div class="container" style="padding-top: 2rem; padding-bottom: 2rem;">

    <h1 class="announcements-heading">Announcements</h1>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (empty($announcements)): ?>
        <div class="empty-state">
            <p style="margin-bottom: 0;">No announcements at this time. Check back later.</p>
        </div>
    <?php else: ?>
        <?php foreach ($announcements as $announcement): ?>
            <?php
            $isAcknowledged = !empty($announcement['acknowledged_at']);
            $cardClass = $isAcknowledged ? 'announcement-card--acknowledged' : 'announcement-card--unacknowledged';
            ?>
            <div class="announcement-card <?= $cardClass ?>">
                <div class="announcement-title"><?= e($announcement['title']) ?></div>
                <div class="announcement-meta">
                    <?php if ($announcement['sender_name']): ?>
                        From <?= e($announcement['sender_name']) ?> &middot;
                    <?php endif; ?>
                    <?= e(format_datetime($announcement['created_at'])) ?>
                </div>
                <div class="announcement-content">
                    <?= nl2br(e($announcement['content'])) ?>
                </div>

                <?php if ($isAcknowledged): ?>
                    <div class="announcement-ack-status">
                        ✓ Acknowledged by <?= e($announcement['acknowledged_by_name']) ?> at <?= e(format_datetime($announcement['acknowledged_at'])) ?>
                    </div>
                <?php else: ?>
                    <button
                        type="button"
                        class="btn btn-primary btn-sm js-ack-toggle"
                        data-target="ack-form-<?= (int)$announcement['id'] ?>"
                    >
                        Acknowledge
                    </button>

                    <div class="announcement-ack-form" id="ack-form-<?= (int)$announcement['id'] ?>">
                        <form method="post" action="explorer_announcements.php?token=<?= e(urlencode($token)) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="acknowledge_announcement">
                            <input type="hidden" name="announcement_id" value="<?= (int)$announcement['id'] ?>">

                            <div class="form-group">
                                <label for="ack-name-<?= (int)$announcement['id'] ?>">Your name</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="ack-name-<?= (int)$announcement['id'] ?>"
                                    name="acknowledged_by_name"
                                    placeholder="Enter your name"
                                    required
                                >
                            </div>

                            <button type="submit" class="btn btn-success btn-sm">
                                Confirm Acknowledgement
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<script>
(function () {
    var buttons = document.querySelectorAll('.js-ack-toggle');

    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', function () {
            var targetId = this.getAttribute('data-target');
            var form = document.getElementById(targetId);

            if (form) {
                var isVisible = form.style.display === 'block';
                form.style.display = isVisible ? 'none' : 'block';
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/explorer_footer.php'; ?>
