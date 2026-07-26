<?php
$loadLeaflet = true;
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

// --- Track parent portal visit for engagement analytics ---
try {
    $ppvTableCheck = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = "parent_portal_visits"'
    );
    $ppvTableCheck->execute();

    if ((int)$ppvTableCheck->fetchColumn() === 0) {
        $pdo->exec('
            CREATE TABLE parent_portal_visits (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                team_id INT UNSIGNED NOT NULL,
                token VARCHAR(128) NOT NULL,
                page VARCHAR(100) NOT NULL DEFAULT "portal",
                visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_hash VARCHAR(64) NULL,
                user_agent_hash VARCHAR(64) NULL,
                INDEX idx_ppv_team (team_id),
                INDEX idx_ppv_visited (visited_at),
                INDEX idx_ppv_page (page),
                INDEX idx_ppv_team_date (team_id, visited_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));
    $uaHash = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    $ppvStmt = $pdo->prepare(
        'INSERT INTO parent_portal_visits
            (team_id, token, page, visited_at, ip_hash, user_agent_hash)
         VALUES
            (?, ?, "portal", NOW(), ?, ?)'
    );
    $ppvStmt->execute([
        (int)$team['id'],
        substr($token, 0, 128),
        $ipHash,
        $uaHash,
    ]);
} catch (Throwable $ppvError) {
    // Don't block the portal for analytics failures
}

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
         LIMIT 10'
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

// --- Fetch pinned announcement for explorer portal ---
$pinnedAnnouncement = null;
try {
    $hasPinnedCol = false;
    try {
        $colCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "announcements" AND COLUMN_NAME = "is_pinned"'
        );
        $colCheck->execute();
        $hasPinnedCol = (int)$colCheck->fetchColumn() > 0;
    } catch (Throwable $e) {}

    if ($hasPinnedCol) {
        $pinnedStmt = $pdo->prepare(
            'SELECT a.*, l.name AS sender_name
             FROM announcements a
             LEFT JOIN leaders l ON l.id = a.sender_leader_id
             WHERE a.is_pinned = 1
               AND (a.team_id IS NULL OR a.team_id = ?)
             ORDER BY a.created_at DESC
             LIMIT 1'
        );
        $pinnedStmt->execute([(int)$team['id']]);
        $pinnedAnnouncement = $pinnedStmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    $pinnedAnnouncement = null;
}

// --- Fetch team members (young people) for this team ---
$teamMembers = [];
try {
    $stmt = $pdo->prepare(
        'SELECT id, name, photo_url
         FROM young_people
         WHERE team_id = ? AND is_active = 1
         ORDER BY name ASC'
    );
    $stmt->execute([(int)$team['id']]);
    $teamMembers = $stmt->fetchAll();
} catch (Throwable $e) {
    $teamMembers = [];
}

include __DIR__ . '/explorer_header.php';

$tokenParam = urlencode($token);
?>

<style>
    .portal-header {
        background: #7413dc;
        color: #fff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.25rem;
    }
    .portal-header h1 {
        font-weight: 900;
        font-size: 1.35rem;
        margin: 0 0 0.2rem;
    }
    .portal-header p {
        margin: 0;
        opacity: 0.85;
        font-size: 0.92rem;
    }

    .portal-team-card {
        background: #fff;
        border: 2px solid #d8d8d8;
        padding: 1.25rem;
        margin-bottom: 1.25rem;
    }
    .portal-team-card h2 {
        font-size: 1.05rem;
        font-weight: 900;
        margin: 0 0 0.75rem;
        color: #1d1d1d;
    }
    .portal-faces {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
    }
    .portal-face {
        width: 52px;
        height: 52px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #fff;
        box-shadow: 0 0 0 1px #d8d8d8;
        background: #f3f2f1;
    }
    .portal-face-placeholder {
        width: 52px;
        height: 52px;
        border-radius: 50%;
        border: 2px solid #fff;
        box-shadow: 0 0 0 1px #d8d8d8;
        background: #7413dc;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        font-weight: 900;
    }

    .portal-checkin-status {
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
        font-size: 0.95rem;
    }
    .portal-checkin-done {
        background: #e6f4ea;
        border-left: 6px solid #00703c;
    }
    .portal-checkin-done strong { color: #00703c; }
    .portal-checkin-needed {
        background: #fff7bf;
        border-left: 6px solid #b58900;
    }
    .portal-checkin-needed strong { color: #6b5200; }

    .portal-nav {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .portal-nav a {
        display: block;
        background: #fff;
        border: 2px solid #d8d8d8;
        padding: 1.1rem 1rem;
        text-decoration: none;
        color: #1d1d1d;
        position: relative;
    }
    .portal-nav a:hover,
    .portal-nav a:focus {
        border-color: #7413dc;
        text-decoration: none;
        color: #1d1d1d;
    }
    .portal-nav a strong {
        display: block;
        font-size: 0.95rem;
        color: #7413dc;
        margin-bottom: 0.2rem;
    }
    .portal-nav a span {
        font-size: 0.82rem;
        color: #505a5f;
        line-height: 1.35;
    }
    .portal-nav-badge {
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        background: #d4351c;
        color: #fff;
        font-size: 0.7rem;
        font-weight: 900;
        padding: 0.12rem 0.38rem;
    }

    .portal-unread-banner {
        border: 2px solid #7413dc;
        border-left: 6px solid #7413dc;
        background: #faf5ff;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
    }
    .portal-unread-banner h3 {
        margin: 0 0 0.4rem;
        font-weight: 900;
        color: #7413dc;
        font-size: 1.05rem;
    }
    .portal-unread-banner ul {
        margin: 0 0 0.75rem;
        padding-left: 1.1rem;
    }
    .portal-unread-banner li {
        margin-bottom: 0.25rem;
        font-size: 0.92rem;
    }

    .portal-pinned {
        border: 2px solid #7413dc;
        border-left: 6px solid #7413dc;
        background: #faf5ff;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
    }
    .portal-pinned h3 {
        margin: 0 0 0.3rem;
        font-weight: 900;
        color: #7413dc;
        font-size: 1rem;
    }
    .portal-pinned-content {
        line-height: 1.55;
        font-size: 0.92rem;
        margin-bottom: 0.4rem;
    }
    .portal-pinned-meta {
        color: #505a5f;
        font-size: 0.8rem;
        margin: 0;
    }

    .portal-history {
        background: #fff;
        border: 2px solid #d8d8d8;
        padding: 1.25rem;
        margin-bottom: 2rem;
    }
    .portal-history h2 {
        font-weight: 900;
        font-size: 1.1rem;
        margin-bottom: 0.75rem;
    }
    .portal-journey-map {
        height: 280px;
        border: 2px solid #1d1d1d;
        background: #f3f2f1;
        margin-bottom: 0.75rem;
    }
    .checkin-history-item {
        border-bottom: 1px solid #eee;
        padding: 0.6rem 0;
    }
    .checkin-history-item:last-child { border-bottom: none; }
    .checkin-history-date {
        font-weight: 800;
        font-size: 0.9rem;
    }
    .checkin-history-detail {
        color: #505a5f;
        font-size: 0.85rem;
    }
    .checkin-status-badge {
        display: inline-block;
        padding: 0.1rem 0.35rem;
        font-size: 0.75rem;
        font-weight: 800;
        border: 1px solid;
        margin-left: 0.3rem;
    }
    .checkin-status-pending { background: #fff7bf; border-color: #b58900; color: #6b5200; }
    .checkin-status-approved { background: #e6f4ea; border-color: #00703c; color: #00703c; }
    .checkin-status-rejected { background: #fff1f0; border-color: #d4351c; color: #d4351c; }

    @media (max-width: 575.98px) {
        .container { padding-left: 0.75rem; padding-right: 0.75rem; }
        .portal-header { padding: 1rem; }
        .portal-header h1 { font-size: 1.15rem; }
        .portal-team-card { padding: 1rem; }
        .portal-face, .portal-face-placeholder { width: 44px; height: 44px; font-size: 0.72rem; }
        .portal-nav { gap: 0.5rem; }
        .portal-nav a { padding: 0.9rem 0.75rem; }
        .portal-nav a strong { font-size: 0.88rem; }
        .portal-nav a span { font-size: 0.78rem; }
        .portal-history { padding: 1rem; }
        .portal-journey-map { height: 180px; }
    }
    @media (max-width: 380px) {
        .portal-nav { grid-template-columns: 1fr; }
    }
</style>

<div class="container" style="padding-top: 1rem; padding-bottom: 2rem;">

    <!-- Header -->
    <section class="portal-header">
        <h1><?= e($team['name']) ?></h1>
        <p>Your expedition hub</p>
    </section>

    <!-- Check-in status -->
    <?php if ($hasCheckedInToday): ?>
        <section class="portal-checkin-status portal-checkin-done">
            <strong>Checked in today</strong>
            Submitted at <?= e(format_datetime($todayCheckinTime)) ?>
        </section>
    <?php else: ?>
        <section class="portal-checkin-status portal-checkin-needed">
            <strong>You haven't checked in today</strong>
            <a href="<?= e(url('explorer_checkin.php?token=' . $tokenParam)) ?>" style="color: inherit; font-weight: 800;">Submit your check-in now &rarr;</a>
        </section>
    <?php endif; ?>

    <!-- Team members with photos -->
    <?php if (!empty($teamMembers)): ?>
        <section class="portal-team-card">
            <h2>Your team</h2>
            <div class="portal-faces">
                <?php foreach ($teamMembers as $member): ?>
                    <?php if (!empty($member['photo_url'])): ?>
                        <img
                            class="portal-face"
                            src="<?= e(url($member['photo_url'])) ?>"
                            alt="<?= e($member['name']) ?>"
                            title="<?= e($member['name']) ?>"
                            loading="lazy"
                        >
                    <?php else: ?>
                        <?php
                        $parts = preg_split('/\s+/', trim($member['name']));
                        $initials = '';
                        foreach ($parts as $p) { if ($p !== '') $initials .= strtoupper($p[0]); if (strlen($initials) >= 2) break; }
                        ?>
                        <span class="portal-face-placeholder" title="<?= e($member['name']) ?>"><?= e($initials ?: '?') ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Unread announcements -->
    <?php if (!empty($unreadAnnouncements)): ?>
        <section class="portal-unread-banner" role="alert">
            <h3><?= count($unreadAnnouncements) ?> unread announcement<?= count($unreadAnnouncements) > 1 ? 's' : '' ?></h3>
            <ul>
                <?php foreach (array_slice($unreadAnnouncements, 0, 3) as $ann): ?>
                    <li>
                        <strong><?= e($ann['title']) ?></strong>
                        <span style="color: #505a5f; font-size: 0.85rem;">&mdash; <?= e(format_datetime($ann['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (count($unreadAnnouncements) > 3): ?>
                    <li style="color: #505a5f;">and <?= count($unreadAnnouncements) - 3 ?> more</li>
                <?php endif; ?>
            </ul>
            <a href="<?= e(url('explorer_announcements.php?token=' . $tokenParam)) ?>" class="btn btn-primary btn-sm" style="border-radius: 0; font-weight: 800;">
                View announcements
            </a>
        </section>
    <?php endif; ?>

    <!-- Pinned announcement -->
    <?php if ($pinnedAnnouncement): ?>
        <section class="portal-pinned">
            <h3><?= e($pinnedAnnouncement['title']) ?></h3>
            <div class="portal-pinned-content">
                <?= nl2br(e($pinnedAnnouncement['content'])) ?>
            </div>
            <p class="portal-pinned-meta">
                <?= e($pinnedAnnouncement['sender_name'] ?? 'Leader') ?> &middot; <?= e(format_datetime($pinnedAnnouncement['created_at'])) ?>
            </p>
        </section>
    <?php endif; ?>

    <!-- Navigation -->
    <nav class="portal-nav">
        <a href="<?= e(url('explorer_checkin.php?token=' . $tokenParam)) ?>">
            <strong>Check in</strong>
            <span>Daily location &amp; welfare</span>
        </a>
        <a href="<?= e(url('explorer_announcements.php?token=' . $tokenParam)) ?>">
            <strong>Announcements</strong>
            <span>Messages from leaders</span>
            <?php if (!empty($unreadAnnouncements)): ?>
                <span class="portal-nav-badge"><?= count($unreadAnnouncements) ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= e(url('explorer_contact.php?token=' . $tokenParam)) ?>">
            <strong>Contact</strong>
            <span>Leader &amp; emergency numbers</span>
        </a>
        <a href="<?= e(url('explorer_emergencies.php?token=' . $tokenParam)) ?>">
            <strong>Emergencies</strong>
            <span>What to do if something goes wrong</span>
        </a>
    </nav>

    <!-- Check-in history -->
    <section class="portal-history">
        <h2>Check-in history</h2>

        <?php if (empty($recentCheckins)): ?>
            <p style="color: #505a5f; margin: 0;">No check-ins yet. Use the Check In page to submit your first one.</p>
        <?php else: ?>
            <?php
            $mapPoints = [];
            foreach ($recentCheckins as $ci) {
                if (!empty($ci['latitude']) && !empty($ci['longitude']) && is_numeric($ci['latitude']) && is_numeric($ci['longitude'])) {
                    $mapPoints[] = [
                        'lat' => (float)$ci['latitude'],
                        'lng' => (float)$ci['longitude'],
                        'label' => $ci['location_name'] . ' (' . date('d M', strtotime($ci['submitted_at'])) . ')',
                    ];
                }
            }
            ?>

            <?php if (!empty($mapPoints)): ?>
                <div id="portalJourneyMap" class="portal-journey-map"
                     data-lat="<?= e((string)$mapPoints[0]['lat']) ?>"
                     data-lng="<?= e((string)$mapPoints[0]['lng']) ?>"
                     data-points="<?= e(json_encode(array_reverse($mapPoints))) ?>">
                </div>
            <?php endif; ?>

            <?php foreach ($recentCheckins as $checkin): ?>
                <?php
                $statusClass = match ($checkin['status'] ?? 'pending') {
                    'reviewed' => 'checkin-status-approved',
                    'rejected' => 'checkin-status-rejected',
                    default => 'checkin-status-pending',
                };
                $statusLabel = match ($checkin['status'] ?? 'pending') {
                    'reviewed' => 'Reviewed',
                    'rejected' => 'Rejected',
                    default => 'Pending',
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
                            &middot; <?= e($checkin['submitted_by']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var mapEl = document.getElementById('portalJourneyMap');
    if (!mapEl || typeof L === 'undefined') return;

    var lat = parseFloat(mapEl.getAttribute('data-lat'));
    var lng = parseFloat(mapEl.getAttribute('data-lng'));
    var pointsJson = mapEl.getAttribute('data-points') || '[]';
    var points = [];

    try { points = JSON.parse(pointsJson); } catch (e) { points = []; }

    if (!isFinite(lat) || !isFinite(lng)) return;

    var map = L.map(mapEl).setView([lat, lng], 10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    if (points.length > 0) {
        var latLngs = points.map(function (p) { return [parseFloat(p.lat), parseFloat(p.lng)]; }).filter(function (p) { return isFinite(p[0]) && isFinite(p[1]); });
        latLngs.forEach(function (p, index) {
            var marker = L.marker(p).addTo(map);
            marker.bindPopup('<strong>' + (index + 1) + '.</strong> ' + (points[index].label || 'Check-in'));
        });
        if (latLngs.length > 1) {
            L.polyline(latLngs, { weight: 4, color: '#7413dc' }).addTo(map);
            map.fitBounds(latLngs, { padding: [30, 30] });
        }
    } else {
        L.marker([lat, lng]).addTo(map);
    }
});
</script>

<?php include __DIR__ . '/explorer_footer.php'; ?>
