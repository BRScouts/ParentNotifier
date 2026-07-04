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

// --- Fetch unacknowledged announcements for this team ---
$unreadAnnouncements = [];
try {
    ensure_announcements_tables($pdo);
    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.content, a.created_at, l.name AS sender_name
         FROM announcements a
         LEFT JOIN leaders l ON l.id = a.sender_leader_id
         LEFT JOIN announcement_acknowledgements ack ON ack.announcement_id = a.id AND ack.team_id = ?
         WHERE (a.team_id IS NULL OR a.team_id = ?)
           AND ack.id IS NULL
         ORDER BY a.created_at DESC'
    );
    $stmt->execute([(int)$team['id'], (int)$team['id']]);
    $unreadAnnouncements = $stmt->fetchAll();
} catch (Throwable $e) {
    $unreadAnnouncements = [];
}

// --- Fetch recent check-in history (last 5) ---
$recentCheckins = [];
try {
    $stmt = $pdo->prepare(
        'SELECT id, location_name, latitude, longitude, accommodation_type, status, submitted_by, submitted_at
         FROM explorer_checkins
         WHERE team_id = ?
         ORDER BY submitted_at DESC
         LIMIT 5'
    );
    $stmt->execute([(int)$team['id']]);
    $recentCheckins = $stmt->fetchAll();
} catch (Throwable $e) {
    $recentCheckins = [];
}

// --- Check if team has already submitted a check-in today ---
$hasCheckedInToday = false;
$todayCheckinTime = null;
try {
    $tz = new DateTimeZone(defined('DEFAULT_TIMEZONE') ? DEFAULT_TIMEZONE : 'Europe/London');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT submitted_at FROM explorer_checkins
         WHERE team_id = ? AND DATE(submitted_at) = ?
         ORDER BY submitted_at DESC LIMIT 1'
    );
    $stmt->execute([(int)$team['id'], $today]);
    $todayRow = $stmt->fetch();
    if ($todayRow) {
        $hasCheckedInToday = true;
        $todayCheckinTime = $todayRow['submitted_at'];
    }
} catch (Throwable $e) {
    // Graceful fallback
}

include __DIR__ . '/explorer_header.php';

$tokenParam = urlencode($token);
?>

<style>
    .portal-unread-banner {
        border: 3px solid #7413dc;
        border-left: 8px solid #7413dc;
        background: #faf5ff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
    }
    .portal-unread-banner h3 {
        margin: 0 0 0.5rem 0;
        font-weight: 900;
        color: #7413dc;
        font-size: 1.15rem;
    }
    .portal-unread-banner p {
        margin: 0 0 0.75rem 0;
        font-size: 1rem;
    }
    .portal-unread-banner ul {
        margin: 0 0 0.75rem 0;
        padding-left: 1.25rem;
    }
    .portal-unread-banner li {
        margin-bottom: 0.3rem;
    }
    .portal-checkin-warning {
        border-left: 8px solid #ffdd00;
        background: #fff7bf;
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
    }
    .portal-checkin-warning strong {
        display: block;
        margin-bottom: 0.25rem;
    }
    .portal-checkin-history {
        background: #ffffff;
        border: 2px solid #d8d8d8;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }
    .portal-checkin-history h2 {
        font-weight: 900;
        margin-bottom: 1rem;
    }
    .checkin-history-item {
        border-bottom: 1px solid #d8d8d8;
        padding: 0.75rem 0;
    }
    .checkin-history-item:last-child {
        border-bottom: none;
    }
    .checkin-history-date {
        font-weight: 800;
        font-size: 0.95rem;
    }
    .checkin-history-detail {
        color: #505a5f;
        font-size: 0.9rem;
    }
    .checkin-status-badge {
        display: inline-block;
        padding: 0.15rem 0.45rem;
        font-size: 0.8rem;
        font-weight: 800;
        border: 2px solid;
        margin-left: 0.5rem;
    }
    .checkin-status-pending {
        background: #fff7bf;
        border-color: #b58900;
        color: #6b5200;
    }
    .checkin-status-approved {
        background: #e6f4ea;
        border-color: #00703c;
        color: #00703c;
    }
    .checkin-status-rejected {
        background: #fff1f0;
        border-color: #d4351c;
        color: #d4351c;
    }
</style>

<div class="container" style="padding-top: 2rem; padding-bottom: 2rem;">

    <!-- Hero / Team Name Panel -->
    <section style="background: #7413dc; color: #ffffff; padding: 2rem 1.5rem; margin-bottom: 2rem; border-radius: 0;">
        <h1 style="font-weight: 900; margin: 0 0 0.25rem 0; font-size: 2rem;">
            <?= e($team['name']) ?>
        </h1>
        <p style="margin: 0; opacity: 0.9; font-size: 1.1rem;">Explorer Portal</p>
    </section>

    <!-- Unread Announcements Warning -->
    <?php if (!empty($unreadAnnouncements)): ?>
        <section class="portal-unread-banner" role="alert">
            <h3>⚠️ You have <?= count($unreadAnnouncements) ?> unread announcement<?= count($unreadAnnouncements) > 1 ? 's' : '' ?></h3>
            <ul>
                <?php foreach (array_slice($unreadAnnouncements, 0, 3) as $ann): ?>
                    <li>
                        <strong><?= e($ann['title']) ?></strong>
                        <span style="color: #505a5f; font-size: 0.9rem;">
                            — <?= e(format_datetime($ann['created_at'])) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
                <?php if (count($unreadAnnouncements) > 3): ?>
                    <li style="color: #505a5f;">...and <?= count($unreadAnnouncements) - 3 ?> more</li>
                <?php endif; ?>
            </ul>
            <a href="<?= e(url('explorer_announcements.php?token=' . $tokenParam)) ?>" class="btn btn-primary btn-sm" style="border-radius: 0; font-weight: 800;">
                View & Acknowledge Announcements
            </a>
        </section>
    <?php endif; ?>

    <!-- Today's Check-in Warning -->
    <?php if ($hasCheckedInToday): ?>
        <section class="portal-checkin-warning">
            <strong>✓ You have already checked in today</strong>
            <span>Your last check-in was submitted at <?= e(format_datetime($todayCheckinTime)) ?>. You only need to check in once per day unless instructed otherwise by a leader.</span>
        </section>
    <?php endif; ?>

    <!-- Welcome Panel -->
    <section style="background: #ffffff; border: 2px solid #d8d8d8; padding: 1.5rem; margin-bottom: 2rem;">
        <h2 style="font-weight: 900; margin-bottom: 1rem; color: #1d1d1d;">Welcome to your Expedition Portal</h2>
        <p style="font-size: 1.05rem; line-height: 1.6; margin-bottom: 0.5rem;">
            This is your team's central hub during the expedition. Use the tabs above or the quick links below to:
        </p>
        <ul style="font-size: 1.05rem; line-height: 1.8; margin-bottom: 0;">
            <li>Submit your daily check-ins with location and welfare information</li>
            <li>View announcements from the leadership team</li>
            <li>Find emergency numbers and on-duty leader contacts</li>
        </ul>
    </section>

    <!-- Quick Links -->
    <section style="margin-bottom: 2rem;">
        <h2 style="font-weight: 900; margin-bottom: 1rem; color: #1d1d1d;">Quick Links</h2>
        <div class="row">
            <div class="col-sm-6 col-lg-3 mb-3">
                <a href="<?= e(url('explorer_checkin.php?token=' . $tokenParam)) ?>"
                   style="display: block; background: #ffffff; border: 2px solid #d8d8d8; padding: 1.25rem; text-decoration: none; color: #1d1d1d; height: 100%;">
                    <strong style="display: block; font-size: 1.1rem; margin-bottom: 0.5rem; color: #7413dc;">Check In</strong>
                    <span style="font-size: 0.95rem;">Submit your daily location and welfare check-in.</span>
                </a>
            </div>
            <div class="col-sm-6 col-lg-3 mb-3">
                <a href="<?= e(url('explorer_announcements.php?token=' . $tokenParam)) ?>"
                   style="display: block; background: #ffffff; border: 2px solid #d8d8d8; padding: 1.25rem; text-decoration: none; color: #1d1d1d; height: 100%; position: relative;">
                    <strong style="display: block; font-size: 1.1rem; margin-bottom: 0.5rem; color: #7413dc;">Announcements</strong>
                    <span style="font-size: 0.95rem;">View messages and updates from the leadership team.</span>
                    <?php if (!empty($unreadAnnouncements)): ?>
                        <span style="position: absolute; top: 0.5rem; right: 0.5rem; background: #d4351c; color: #fff; font-size: 0.8rem; font-weight: 900; padding: 0.2rem 0.5rem;"><?= count($unreadAnnouncements) ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="col-sm-6 col-lg-3 mb-3">
                <a href="<?= e(url('explorer_contact.php?token=' . $tokenParam)) ?>"
                   style="display: block; background: #ffffff; border: 2px solid #d8d8d8; padding: 1.25rem; text-decoration: none; color: #1d1d1d; height: 100%;">
                    <strong style="display: block; font-size: 1.1rem; margin-bottom: 0.5rem; color: #7413dc;">Contact & Emergency</strong>
                    <span style="font-size: 0.95rem;">Emergency numbers and on-duty leader contacts.</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Recent Check-in History -->
    <section class="portal-checkin-history">
        <h2>Recent Check-in History</h2>

        <?php if (empty($recentCheckins)): ?>
            <p style="color: #505a5f; margin-bottom: 0;">No check-ins submitted yet. Use the Check In page to submit your first one.</p>
        <?php else: ?>
            <?php foreach ($recentCheckins as $checkin): ?>
                <?php
                $statusClass = match ($checkin['status'] ?? 'pending') {
                    'approved' => 'checkin-status-approved',
                    'rejected' => 'checkin-status-rejected',
                    default => 'checkin-status-pending',
                };
                $statusLabel = match ($checkin['status'] ?? 'pending') {
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    default => 'Pending review',
                };
                ?>
                <div class="checkin-history-item">
                    <div class="checkin-history-date">
                        <?= e(format_datetime($checkin['submitted_at'])) ?>
                        <span class="checkin-status-badge <?= $statusClass ?>"><?= e($statusLabel) ?></span>
                    </div>
                    <div class="checkin-history-detail">
                        <?= e($checkin['location_name']) ?> &middot; <?= e($checkin['accommodation_type']) ?>
                        <?php if ($checkin['submitted_by']): ?>
                            &middot; Submitted by <?= e($checkin['submitted_by']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

</div>

<?php include __DIR__ . '/explorer_footer.php'; ?>
