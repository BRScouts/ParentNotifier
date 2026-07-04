<?php
require_once __DIR__ . '/auth.php';
require_login();

$pdo = db();
$user = current_user();
ensure_announcements_tables($pdo);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Fetch active teams for the target selector
$activeTeams = [];
try {
    $activeTeams = $pdo->query('SELECT id, name, contact_email FROM teams WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {
    // is_active column might not exist, fall back
    try {
        $activeTeams = $pdo->query('SELECT id, name, contact_email FROM teams ORDER BY name ASC')->fetchAll();
    } catch (Throwable $e2) {
        // contact_email might not exist either
        try {
            $activeTeams = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC')->fetchAll();
        } catch (Throwable $e3) {
            $activeTeams = [];
        }
    }
}

$error = '';
$success = '';

// --- Task 10.2: Handle POST for announcement creation ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_announcement') {
    // Validate CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $target = $_POST['target'] ?? 'all';

        // Validate inputs
        if ($title === '') {
            $error = 'Announcement title is required.';
        } elseif ($content === '') {
            $error = 'Announcement content is required.';
        } else {
            $teamId = null;

            // If targeting specific team, validate it exists
            if ($target !== 'all') {
                $teamId = (int)$target;
                $stmt = $pdo->prepare('SELECT id, name, explorer_token FROM teams WHERE id = ? LIMIT 1');
                $stmt->execute([$teamId]);
                $targetTeam = $stmt->fetch();

                if (!$targetTeam) {
                    $error = 'Selected team does not exist.';
                }
            }

            if ($error === '') {
                // INSERT announcement
                $stmt = $pdo->prepare(
                    'INSERT INTO announcements (team_id, sender_leader_id, title, content, created_at)
                     VALUES (?, ?, ?, ?, NOW())'
                );
                $stmt->execute([
                    $teamId,
                    (int)$user['id'],
                    $title,
                    $content,
                ]);

                // Queue notification emails (optional — gracefully handles missing columns)
                try {
                if ($teamId !== null) {
                    // Specific team: queue one email to team's contact_email
                    $stmt = $pdo->prepare('SELECT contact_email, explorer_token FROM teams WHERE id = ? LIMIT 1');
                    $stmt->execute([$teamId]);
                    $team = $stmt->fetch();

                    if ($team && !empty($team['contact_email'])) {
                        $portalLink = url('explorer_announcements.php?token=' . ($team['explorer_token'] ?? ''));
                        $emailContent = '<p>A new announcement has been posted: <strong>' . e($title) . '</strong></p>';
                        $emailContent .= '<p><a href="' . e($portalLink) . '">View announcements on the Explorer Portal</a></p>';

                        explorer_queue_email(
                            $pdo,
                            $team['contact_email'],
                            'New Announcement: ' . $title,
                            $emailContent,
                            $teamId
                        );
                    }
                } else {
                    // All teams: queue one email per active team with non-empty contact_email
                    $teamsForEmail = [];
                    try {
                        $teamsForEmail = $pdo->query(
                            'SELECT id, contact_email, explorer_token FROM teams WHERE is_active = 1 ORDER BY name ASC'
                        )->fetchAll();
                    } catch (Throwable $e) {
                        try {
                            $teamsForEmail = $pdo->query(
                                'SELECT id, contact_email, explorer_token FROM teams ORDER BY name ASC'
                            )->fetchAll();
                        } catch (Throwable $e2) {
                            $teamsForEmail = [];
                        }
                    }

                    foreach ($teamsForEmail as $t) {
                        if (!empty($t['contact_email'])) {
                            $portalLink = url('explorer_announcements.php?token=' . ($t['explorer_token'] ?? ''));
                            $emailContent = '<p>A new announcement has been posted: <strong>' . e($title) . '</strong></p>';
                            $emailContent .= '<p><a href="' . e($portalLink) . '">View announcements on the Explorer Portal</a></p>';

                            explorer_queue_email(
                                $pdo,
                                $t['contact_email'],
                                'New Announcement: ' . $title,
                                $emailContent,
                                (int)$t['id']
                            );
                        }
                    }
                }
                } catch (Throwable $emailEx) {
                    // contact_email column may not exist — email notifications skipped
                }

                // PRG redirect with success
                $_SESSION['announce_success'] = 'Announcement created successfully.';
                redirect('announcements_manage.php');
            }
        }
    }
}

// Flash message from redirect
if (!empty($_SESSION['announce_success'])) {
    $success = $_SESSION['announce_success'];
    unset($_SESSION['announce_success']);
}

// --- Task 10.3: Fetch existing announcements ---
$announcements = [];
try {
    $announcements = $pdo->query(
        'SELECT 
            a.*,
            l.name AS sender_name,
            CASE WHEN a.team_id IS NULL THEN \'All Teams\' ELSE t.name END AS target_name,
            (SELECT COUNT(*) FROM announcement_acknowledgements WHERE announcement_id = a.id) AS ack_count,
            (SELECT CASE WHEN a.team_id IS NULL 
                THEN (SELECT COUNT(*) FROM teams WHERE is_active = 1)
                ELSE 1 END) AS total_teams
         FROM announcements a
         LEFT JOIN leaders l ON l.id = a.sender_leader_id
         LEFT JOIN teams t ON t.id = a.team_id
         ORDER BY a.created_at DESC'
    )->fetchAll();
} catch (Throwable $e) {
    // Fallback without is_active
    try {
        $announcements = $pdo->query(
            'SELECT 
                a.*,
                l.name AS sender_name,
                CASE WHEN a.team_id IS NULL THEN \'All Teams\' ELSE t.name END AS target_name,
                (SELECT COUNT(*) FROM announcement_acknowledgements WHERE announcement_id = a.id) AS ack_count,
                (SELECT CASE WHEN a.team_id IS NULL 
                    THEN (SELECT COUNT(*) FROM teams)
                    ELSE 1 END) AS total_teams
             FROM announcements a
             LEFT JOIN leaders l ON l.id = a.sender_leader_id
             LEFT JOIN teams t ON t.id = a.team_id
             ORDER BY a.created_at DESC'
        )->fetchAll();
    } catch (Throwable $e2) {
        $announcements = [];
    }
}

include __DIR__ . '/header.php';
?>

<style>
    .announce-shell {
        max-width: 960px;
        margin: 0 auto;
        padding: 2rem 1rem;
    }

    .announce-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .announce-panel h2,
    .announce-panel h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .announce-panel label {
        font-weight: 800;
    }

    .announce-table {
        width: 100%;
        border-collapse: collapse;
        background: #ffffff;
        border: 2px solid #d8d8d8;
    }

    .announce-table th,
    .announce-table td {
        border-bottom: 1px solid #d8d8d8;
        padding: 0.75rem 1rem;
        vertical-align: top;
        text-align: left;
    }

    .announce-table th {
        background: #f3f2f1;
        font-weight: 900;
        font-size: 0.9rem;
    }

    .announce-table td {
        font-size: 0.95rem;
    }

    .ack-badge {
        display: inline-block;
        padding: 0.2rem 0.5rem;
        font-size: 0.85rem;
        font-weight: 800;
        border-radius: 0;
        border: 2px solid;
    }

    .ack-badge-complete {
        background: #e6f4ea;
        border-color: #00703c;
        color: #00703c;
    }

    .ack-badge-partial {
        background: #fff7bf;
        border-color: #b58900;
        color: #6b5200;
    }

    .ack-badge-none {
        background: #fff1f0;
        border-color: #d4351c;
        color: #d4351c;
    }

    .announce-target {
        display: inline-block;
        padding: 0.15rem 0.45rem;
        background: #f3f2f1;
        border: 1px solid #b1b4b6;
        font-size: 0.85rem;
        font-weight: 800;
    }

    .announce-date {
        color: #505a5f;
        font-size: 0.85rem;
    }

    .announce-empty {
        text-align: center;
        padding: 2rem;
        color: #505a5f;
        font-style: italic;
    }
</style>

<div class="announce-shell">

    <h1 style="font-weight: 900; margin-bottom: 1.5rem;">Announcement Management</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="border-radius: 0; border-width: 2px;">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success" style="border-radius: 0; border-width: 2px;">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <!-- Task 10.1: Announcement creation form -->
    <div class="announce-panel">
        <h2>Create Announcement</h2>

        <form method="post" action="<?= e(url('announcements_manage.php')) ?>">
            <input type="hidden" name="action" value="create_announcement">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div class="form-group">
                <label for="announce_title">Title <span style="color: #d4351c;">*</span></label>
                <input
                    type="text"
                    class="form-control"
                    id="announce_title"
                    name="title"
                    required
                    maxlength="255"
                    placeholder="Enter announcement title"
                    value="<?= e($_POST['title'] ?? '') ?>"
                    style="border-radius: 0;"
                >
            </div>

            <div class="form-group">
                <label for="announce_content">Content <span style="color: #d4351c;">*</span></label>
                <textarea
                    class="form-control"
                    id="announce_content"
                    name="content"
                    rows="5"
                    required
                    placeholder="Enter announcement content"
                    style="border-radius: 0;"
                ><?= e($_POST['content'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label for="announce_target">Target</label>
                <select
                    class="form-control"
                    id="announce_target"
                    name="target"
                    style="border-radius: 0;"
                >
                    <option value="all">All Teams</option>
                    <?php foreach ($activeTeams as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= (($_POST['target'] ?? '') === (string)$t['id']) ? 'selected' : '' ?>>
                            <?= e($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="border-radius: 0; font-weight: 800;">
                Send Announcement
            </button>
        </form>
    </div>

    <!-- Task 10.3: Existing announcements list -->
    <div class="announce-panel">
        <h2>Existing Announcements</h2>

        <?php if (empty($announcements)): ?>
            <p class="announce-empty">No announcements have been created yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="announce-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Target</th>
                            <th>Sender</th>
                            <th>Date</th>
                            <th>Acknowledgements</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($announcements as $a): ?>
                            <tr>
                                <td>
                                    <strong><?= e($a['title']) ?></strong>
                                </td>
                                <td>
                                    <span class="announce-target"><?= e($a['target_name'] ?? 'Unknown') ?></span>
                                </td>
                                <td>
                                    <?= e($a['sender_name'] ?? 'Unknown') ?>
                                </td>
                                <td>
                                    <span class="announce-date"><?= e(format_datetime($a['created_at'] ?? null)) ?></span>
                                </td>
                                <td>
                                    <?php
                                    $ackCount = (int)($a['ack_count'] ?? 0);
                                    $totalTeams = (int)($a['total_teams'] ?? 1);
                                    if ($totalTeams < 1) $totalTeams = 1;

                                    if ($ackCount >= $totalTeams) {
                                        $badgeClass = 'ack-badge-complete';
                                    } elseif ($ackCount > 0) {
                                        $badgeClass = 'ack-badge-partial';
                                    } else {
                                        $badgeClass = 'ack-badge-none';
                                    }
                                    ?>
                                    <span class="ack-badge <?= $badgeClass ?>">
                                        <?= $ackCount ?>/<?= $totalTeams ?> teams acknowledged
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php include __DIR__ . '/footer.php'; ?>
