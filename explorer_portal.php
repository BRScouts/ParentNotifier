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

include __DIR__ . '/explorer_header.php';

$tokenParam = urlencode($token);
?>

<style>
    .portal-unread-banner {
        border: 3px solid #7413dc;
        border-left: 8px solid #7413dc;
        background: #faf5ff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.25rem;
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
        border-left: 8px solid #00703c;
        background: #e6f4ea;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
    }
    .portal-checkin-warning strong {
        display: block;
        margin-bottom: 0.25rem;
        color: #00703c;
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
    .portal-journey-map {
        height: 300px;
        border: 2px solid #1d1d1d;
        background: #f3f2f1;
        margin-bottom: 1rem;
    }

    /* Portal hero */
    .portal-hero {
        background: #7413dc;
        color: #ffffff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    .portal-hero-logo {
        width: 48px;
        height: 48px;
        min-width: 48px;
        background: rgba(255,255,255,0.15);
        border: 2px solid rgba(255,255,255,0.4);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .portal-hero-logo img {
        width: 40px;
        height: 40px;
        object-fit: contain;
    }
    .portal-hero h1 {
        font-weight: 900;
        margin: 0;
        font-size: 1.4rem;
        line-height: 1.2;
    }
    .portal-hero-sub {
        opacity: 0.85;
        font-size: 0.9rem;
        margin-top: 0.15rem;
    }

    /* Status strip */
    .portal-status-strip {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-bottom: 1.25rem;
    }
    .portal-status-card {
        background: #ffffff;
        border: 2px solid #d8d8d8;
        padding: 1rem;
        text-align: center;
    }
    .portal-status-card-highlight {
        border-color: #00703c;
        background: #e6f4ea;
    }
    .portal-status-card-warn {
        border-color: #b58900;
        background: #fff7bf;
    }
    .portal-status-icon {
        font-size: 1.5rem;
        display: block;
        margin-bottom: 0.25rem;
    }
    .portal-status-label {
        font-size: 0.8rem;
        color: #505a5f;
        text-transform: uppercase;
        font-weight: 800;
        letter-spacing: 0.03em;
    }
    .portal-status-value {
        font-size: 1.1rem;
        font-weight: 900;
        color: #1d1d1d;
        margin-top: 0.1rem;
    }

    /* Quick link cards */
    .portal-links-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .portal-link-card {
        display: flex;
        flex-direction: column;
        background: #ffffff;
        border: 2px solid #d8d8d8;
        padding: 1.1rem 1rem;
        text-decoration: none;
        color: #1d1d1d;
        position: relative;
        transition: border-color 0.15s;
    }
    .portal-link-card:hover,
    .portal-link-card:focus {
        border-color: #7413dc;
        text-decoration: none;
        color: #1d1d1d;
        outline: 3px solid #ffdd00;
        outline-offset: 1px;
    }
    .portal-link-icon {
        font-size: 1.6rem;
        margin-bottom: 0.4rem;
        line-height: 1;
    }
    .portal-link-title {
        font-size: 1rem;
        font-weight: 900;
        color: #7413dc;
        margin-bottom: 0.25rem;
    }
    .portal-link-desc {
        font-size: 0.82rem;
        color: #505a5f;
        line-height: 1.35;
    }
    .portal-link-badge {
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        background: #d4351c;
        color: #fff;
        font-size: 0.72rem;
        font-weight: 900;
        padding: 0.15rem 0.4rem;
        min-width: 1.2rem;
        text-align: center;
    }

    /* Pinned announcement */
    .portal-pinned {
        border: 3px solid #7413dc;
        border-left: 10px solid #7413dc;
        background: #faf5ff;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
    }
    .portal-pinned h3 {
        margin: 0 0 0.35rem 0;
        font-weight: 900;
        color: #7413dc;
        font-size: 1.05rem;
    }
    .portal-pinned-content {
        line-height: 1.6;
        margin-bottom: 0.5rem;
        font-size: 0.95rem;
    }
    .portal-pinned-meta {
        color: #505a5f;
        font-size: 0.82rem;
    }

    /* Mobile optimisation */
    @media (max-width: 575.98px) {
        .container {
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }
        .portal-hero {
            padding: 1rem 1rem;
            gap: 0.75rem;
        }
        .portal-hero h1 {
            font-size: 1.2rem;
        }
        .portal-hero-logo {
            width: 40px;
            height: 40px;
            min-width: 40px;
        }
        .portal-hero-logo img {
            width: 32px;
            height: 32px;
        }
        .portal-status-strip {
            gap: 0.5rem;
        }
        .portal-status-card {
            padding: 0.75rem 0.5rem;
        }
        .portal-status-icon {
            font-size: 1.25rem;
        }
        .portal-status-value {
            font-size: 0.95rem;
        }
        .portal-status-label {
            font-size: 0.72rem;
        }
        .portal-links-grid {
            gap: 0.5rem;
        }
        .portal-link-card {
            padding: 0.85rem 0.75rem;
        }
        .portal-link-icon {
            font-size: 1.3rem;
            margin-bottom: 0.3rem;
        }
        .portal-link-title {
            font-size: 0.9rem;
        }
        .portal-link-desc {
            font-size: 0.78rem;
        }
        .portal-checkin-history {
            padding: 1rem;
        }
        .portal-journey-map {
            height: 200px;
        }
        .portal-pinned {
            padding: 0.85rem 1rem;
        }
        .portal-unread-banner {
            padding: 1rem 1rem;
        }
    }

    @media (max-width: 380px) {
        .portal-hero h1 {
            font-size: 1.05rem;
        }
        .portal-links-grid {
            grid-template-columns: 1fr;
        }
        .portal-status-strip {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="container" style="padding-top: 1rem; padding-bottom: 2rem;">

    <!-- Hero / Team Name Panel -->
    <section class="portal-hero">
        <div class="portal-hero-logo">
            <img src="<?= e(url('assets/logo-192.png')) ?>" alt="">
        </div>
        <div>
            <h1><?= e($team['name']) ?></h1>
            <div class="portal-hero-sub">Explorer Portal</div>
        </div>
    </section>

    <!-- Status strip - at-a-glance info -->
    <section class="portal-status-strip">
        <div class="portal-status-card <?= $hasCheckedInToday ? 'portal-status-card-highlight' : 'portal-status-card-warn' ?>">
            <span class="portal-status-icon"><?= $hasCheckedInToday ? '&#x2705;' : '&#x1F4CD;' ?></span>
            <div class="portal-status-label">Today's Check-in</div>
            <div class="portal-status-value"><?= $hasCheckedInToday ? 'Done' : 'Needed' ?></div>
        </div>
        <div class="portal-status-card <?= empty($unreadAnnouncements) ? '' : 'portal-status-card-warn' ?>">
            <span class="portal-status-icon">&#x1F4E2;</span>
            <div class="portal-status-label">Announcements</div>
            <div class="portal-status-value"><?= count($unreadAnnouncements) ?> unread</div>
        </div>
    </section>

    <!-- Unread Announcements Warning -->
    <?php if (!empty($unreadAnnouncements)): ?>
        <section class="portal-unread-banner" role="alert">
            <h3>You have <?= count($unreadAnnouncements) ?> unread announcement<?= count($unreadAnnouncements) > 1 ? 's' : '' ?></h3>
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
                View &amp; Acknowledge
            </a>
        </section>
    <?php endif; ?>

    <!-- Pinned Announcement (always shown regardless of acknowledgement) -->
    <?php if ($pinnedAnnouncement): ?>
        <section class="portal-pinned">
            <h3>&#x1F4CC; <?= e($pinnedAnnouncement['title']) ?></h3>
            <div class="portal-pinned-content">
                <?= nl2br(e($pinnedAnnouncement['content'])) ?>
            </div>
            <p class="portal-pinned-meta">
                Pinned by <?= e($pinnedAnnouncement['sender_name'] ?? 'Leader') ?> &middot; <?= e(format_datetime($pinnedAnnouncement['created_at'])) ?>
            </p>
        </section>
    <?php endif; ?>

    <!-- Today's Check-in Confirmation -->
    <?php if ($hasCheckedInToday): ?>
        <section class="portal-checkin-warning">
            <strong>&#x2713; Checked in today</strong>
            <span>Submitted at <?= e(format_datetime($todayCheckinTime)) ?>. You only need to check in once per day unless told otherwise.</span>
        </section>
    <?php endif; ?>

    <!-- Quick Links -->
    <section>
        <div class="portal-links-grid">
            <a href="<?= e(url('explorer_checkin.php?token=' . $tokenParam)) ?>" class="portal-link-card">
                <span class="portal-link-icon">&#x1F4CD;</span>
                <span class="portal-link-title">Check In</span>
                <span class="portal-link-desc">Submit your daily location and welfare check-in.</span>
            </a>
            <a href="<?= e(url('explorer_announcements.php?token=' . $tokenParam)) ?>" class="portal-link-card">
                <span class="portal-link-icon">&#x1F4E3;</span>
                <span class="portal-link-title">Announcements</span>
                <span class="portal-link-desc">Messages from the leadership team.</span>
                <?php if (!empty($unreadAnnouncements)): ?>
                    <span class="portal-link-badge"><?= count($unreadAnnouncements) ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= e(url('explorer_contact.php?token=' . $tokenParam)) ?>" class="portal-link-card">
                <span class="portal-link-icon">&#x1F6A8;</span>
                <span class="portal-link-title">Contact &amp; Emergency</span>
                <span class="portal-link-desc">Emergency numbers and on-duty leader info.</span>
            </a>
            <a href="<?= e(url('explorer_emergencies.php?token=' . $tokenParam)) ?>" class="portal-link-card">
                <span class="portal-link-icon">&#x2139;&#xFE0F;</span>
                <span class="portal-link-title">Emergencies</span>
                <span class="portal-link-desc">What to do in an emergency situation.</span>
            </a>
        </div>
    </section>

    <!-- Recent Check-in History -->
    <section class="portal-checkin-history">
        <h2>Your Check-in History & Journey</h2>

        <?php if (empty($recentCheckins)): ?>
            <p style="color: #505a5f; margin-bottom: 0;">No check-ins submitted yet. Use the Check In page to submit your first one.</p>
        <?php else: ?>
            <?php
            // Build map points from check-ins that have valid coordinates
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
                <p style="color: #505a5f; font-size: 0.9rem; margin-bottom: 1rem;">Your journey so far — most recent check-in shown first.</p>
            <?php endif; ?>

            <?php foreach ($recentCheckins as $checkin): ?>
                <?php
                $statusClass = match ($checkin['status'] ?? 'pending') {
                    'reviewed' => 'checkin-status-approved',
                    'rejected' => 'checkin-status-rejected',
                    default => 'checkin-status-pending',
                };
                $statusLabel = match ($checkin['status'] ?? 'pending') {
                    'reviewed' => 'Reviewed ✓',
                    'rejected' => 'Rejected',
                    default => 'Awaiting review',
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
