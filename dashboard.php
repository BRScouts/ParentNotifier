<?php
require_once __DIR__ . '/auth.php';

$pdo = db();
$user = current_user();
$parentTeam = parent_access_team();

if (!$user && !$parentTeam) {
    redirect('403.php');
}

$error = '';
$isLeader = (bool)$user;
$isParentView = !$user && $parentTeam;

if ($isLeader) {
    $teams = $pdo->query('SELECT * FROM teams ORDER BY name ASC')->fetchAll();
} else {
    $teams = [$parentTeam];
}

/**
 * Helpers
 */

function safe_post_html(string $html): string
{
    $html = trim($html);

    if ($html === '') {
        return '';
    }

    /**
     * If this is an old plain-text post, escape it and preserve line breaks.
     */
    if ($html === strip_tags($html)) {
        return nl2br(e($html));
    }

    /**
     * Match the formatting allowed by add_update.php.
     */
    $allowedTags = '<p><br><strong><b><em><i><u><a><ol><ul><li><span><blockquote>';

    $html = strip_tags($html, $allowedTags);

    /**
     * Remove inline event handlers.
     */
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

    /**
     * Remove javascript: links.
     */
    $html = preg_replace('/href\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', 'href="#"', $html);

    /**
     * Only allow simple colour/background-colour styles.
     */
    $html = preg_replace_callback('/style\s*=\s*([\'"])(.*?)\1/i', function ($matches) {
        $style = $matches[2];
        $safeRules = [];

        foreach (explode(';', $style) as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            if (preg_match('/^(color|background-color)\s*:\s*(#[0-9a-f]{3,6}|rgb\([0-9,\s]+\)|[a-z]+)$/i', $rule)) {
                $safeRules[] = $rule;
            }
        }

        if (empty($safeRules)) {
            return '';
        }

        return ' style="' . e(implode('; ', $safeRules)) . '"';
    }, $html);

    return $html;
}

function media_url(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return url($path);
}

/**
 * Fetch feed.
 *
 * Leaders see all posts.
 * Parents/team-link users see:
 * - public posts
 * - posts for their team
 */
if ($isLeader) {
    $stmt = $pdo->query(
        'SELECT 
            p.*, 
            t.name AS team_name, 
            l.name AS leader_name 
         FROM posts p 
         LEFT JOIN teams t ON t.id = p.team_id 
         LEFT JOIN leaders l ON l.id = p.leader_id 
         WHERE p.is_published = 1
         ORDER BY p.is_pinned DESC, p.published_at DESC 
         LIMIT 50'
    );
    $feedPosts = $stmt->fetchAll();

    $stmt = $pdo->query(
        'SELECT 
            tl.*, 
            t.name AS team_name, 
            t.status AS team_status,
            l.name AS leader_name 
         FROM team_locations tl 
         INNER JOIN teams t ON t.id = tl.team_id 
         LEFT JOIN leaders l ON l.id = tl.leader_id 
         ORDER BY tl.checked_in_at DESC 
         LIMIT 100'
    );
    $recentLocations = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare(
        'SELECT 
            p.*, 
            t.name AS team_name, 
            l.name AS leader_name 
         FROM posts p 
         LEFT JOIN teams t ON t.id = p.team_id 
         LEFT JOIN leaders l ON l.id = p.leader_id 
         WHERE p.is_published = 1
           AND (
                p.visibility = "public"
                OR p.team_id = ?
           )
         ORDER BY p.is_pinned DESC, p.published_at DESC 
         LIMIT 50'
    );
    $stmt->execute([(int)$parentTeam['id']]);
    $feedPosts = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT 
            tl.*, 
            t.name AS team_name, 
            t.status AS team_status,
            l.name AS leader_name 
         FROM team_locations tl 
         INNER JOIN teams t ON t.id = tl.team_id 
         LEFT JOIN leaders l ON l.id = tl.leader_id 
         WHERE tl.team_id = ?
         ORDER BY tl.checked_in_at DESC 
         LIMIT 50'
    );
    $stmt->execute([(int)$parentTeam['id']]);
    $recentLocations = $stmt->fetchAll();
}

/**
 * Fetch multiple photos for the feed posts.
 */
$postPhotosByPostId = [];
$postIds = array_map(static function ($post) {
    return (int)$post['id'];
}, $feedPosts);

if (!empty($postIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));

        $stmt = $pdo->prepare(
            'SELECT *
             FROM post_photos
             WHERE post_id IN (' . $placeholders . ')
             ORDER BY sort_order ASC, id ASC'
        );

        $stmt->execute($postIds);

        foreach ($stmt->fetchAll() as $photo) {
            $postPhotosByPostId[(int)$photo['post_id']][] = $photo;
        }
    } catch (Throwable $exception) {
        /**
         * If post_photos has not been created yet, keep dashboard working
         * using the old posts.photo_url fallback.
         */
        $postPhotosByPostId = [];
    }
}

/**
 * Latest location by team.
 */
$latestLocationByTeam = [];

foreach ($recentLocations as $location) {
    $teamId = (int)$location['team_id'];

    if (!isset($latestLocationByTeam[$teamId])) {
        $latestLocationByTeam[$teamId] = $location;
    }
}

/**
 * Locations grouped by team, newest first.
 */
$locationsByTeam = [];

foreach ($recentLocations as $location) {
    $teamId = (int)$location['team_id'];

    if (!isset($locationsByTeam[$teamId])) {
        $locationsByTeam[$teamId] = [];
    }

    $locationsByTeam[$teamId][] = $location;
}

/**
 * Match check-in posts to the closest location record for that team.
 */
$postLocationByPostId = [];

foreach ($feedPosts as $post) {
    if (($post['post_type'] ?? '') !== 'check_in') {
        continue;
    }

    if (empty($post['team_id']) || empty($post['published_at'])) {
        continue;
    }

    $postId = (int)$post['id'];
    $teamId = (int)$post['team_id'];
    $postTime = strtotime($post['published_at']);

    if (!$postTime || empty($locationsByTeam[$teamId])) {
        continue;
    }

    $bestLocation = null;
    $bestDifference = PHP_INT_MAX;

    foreach ($locationsByTeam[$teamId] as $location) {
        if (empty($location['checked_in_at'])) {
            continue;
        }

        if ($location['latitude'] === null || $location['longitude'] === null) {
            continue;
        }

        $locationTime = strtotime($location['checked_in_at']);

        if (!$locationTime) {
            continue;
        }

        $difference = abs($postTime - $locationTime);

        if ($difference < $bestDifference) {
            $bestDifference = $difference;
            $bestLocation = $location;
        }
    }

    /**
     * Only attach if within 6 hours.
     */
    if ($bestLocation && $bestDifference <= 21600) {
        $postLocationByPostId[$postId] = $bestLocation;
    }
}

$parentLatestLocation = null;

if ($isParentView && !empty($parentTeam['id'])) {
    $parentLatestLocation = $latestLocationByTeam[(int)$parentTeam['id']] ?? null;
}

include __DIR__ . '/header.php';
?>

<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  
>

<style>
    .page-hero,
    .page-hero h1,
    .page-hero h2,
    .page-hero h3,
    .page-hero p,
    .page-hero .lead {
        color: #ffffff !important;
    }

    .dashboard-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 380px;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 980px) {
        .dashboard-layout {
            grid-template-columns: 1fr;
        }
    }

    .dashboard-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
    }

    .feed-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        margin-bottom: 1rem;
        scroll-margin-top: 1rem;
    }

    .feed-card-pinned {
        border-color: #7413dc;
        box-shadow: inset 8px 0 0 #7413dc;
    }

    .feed-card-header {
        padding: 1rem 1rem 0.75rem;
        border-bottom: 1px solid #d8d8d8;
    }

    .feed-card-header h2 {
        margin: 0;
        font-size: 1.35rem;
        font-weight: 900;
    }

    .feed-meta {
        color: #505a5f;
        margin: 0.35rem 0 0;
        font-size: 0.95rem;
    }

    .feed-card-body {
        padding: 1rem;
    }

    .feed-content {
        line-height: 1.55;
    }

    .feed-content p {
        margin-bottom: 0.85rem;
    }

    .feed-content p:last-child {
        margin-bottom: 0;
    }

    .feed-content ul,
    .feed-content ol {
        margin-top: 0.5rem;
        margin-bottom: 0.85rem;
        padding-left: 1.4rem;
    }

    .feed-content blockquote {
        border-left: 6px solid #7413dc;
        background: #f3f2f1;
        padding: 0.75rem 1rem;
        margin: 1rem 0;
    }

    .feed-content a {
        font-weight: 800;
        text-decoration: underline;
    }

    .feed-photo {
        max-width: 100%;
        height: auto;
        border: 2px solid #d8d8d8;
        margin-top: 0.75rem;
        background: #f3f2f1;
    }

    .feed-photo-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.5rem;
        margin-top: 1rem;
    }

    .feed-photo-thumb {
        width: 100%;
        height: 220px;
        object-fit: cover;
        border: 2px solid #d8d8d8;
        background: #f3f2f1;
        display: block;
    }

    @media (max-width: 700px) {
        .feed-photo-grid {
            grid-template-columns: 1fr;
        }

        .feed-photo-thumb {
            height: 210px;
        }
    }

    .feed-badge-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        margin-top: 0.75rem;
    }

    .feed-badge {
        display: inline-block;
        border: 2px solid #1d1d1d;
        background: #f3f2f1;
        padding: 0.2rem 0.45rem;
        font-weight: 800;
        font-size: 0.85rem;
    }

    .feed-badge-pinned {
        background: #7413dc;
        color: #ffffff;
        border-color: #7413dc;
    }

    .feed-badge-location {
        background: #00703c;
        color: #ffffff;
        border-color: #00703c;
    }

    .feed-map {
        height: 230px;
        border: 2px solid #1d1d1d;
        margin-top: 1rem;
        background: #f3f2f1;
    }

    .sidebar-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem;
        margin-bottom: 1rem;
    }

    .sidebar-panel h2 {
        margin-top: 0;
        font-weight: 900;
    }

    .location-summary {
        border-top: 1px solid #d8d8d8;
        padding-top: 0.85rem;
        margin-top: 0.85rem;
    }

    .location-summary:first-of-type {
        border-top: 0;
        padding-top: 0;
        margin-top: 0;
    }

    .location-summary h3 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 900;
    }

    .location-note {
        border-left: 6px solid #1d70b8;
        background: #eef7ff;
        padding: 0.75rem;
        margin-bottom: 1rem;
        font-size: 0.95rem;
    }

    .location-note p {
        margin-bottom: 0;
    }

    .parent-map {
        height: 280px;
        border: 2px solid #1d1d1d;
        margin-top: 0.75rem;
        background: #f3f2f1;
    }

    .map-caption {
        color: #505a5f;
        font-size: 0.95rem;
        margin-top: 0.5rem;
        margin-bottom: 0;
    }

    .muted {
        color: #505a5f;
    }

    .empty-feed {
        border: 2px dashed #b1b4b6;
        background: #f8f8f8;
        padding: 1.5rem;
        font-weight: 700;
    }

    .meta-separator {
        color: #505a5f;
        padding: 0 0.35rem;
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>
            <?= $isLeader ? 'Leader dashboard' : e($parentTeam['name'] . ' updates') ?>
        </h1>
        <p class="lead">
            <?= $isLeader
                ? 'View team updates, check-ins and latest manually entered locations.'
                : 'Latest updates and check-ins for your team.' ?>
        </p>
    </div>
</section>

<main id="main-content" class="container my-5">

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($isLeader): ?>
        <div class="dashboard-actions">
            <a class="btn btn-primary" href="<?= e(url('add_update.php')) ?>">
                Add update
            </a>

            <a class="btn btn-primary" href="<?= e(url('add_location.php')) ?>">
                Add team location
            </a>

            <a class="btn btn-outline-primary" href="<?= e(url('team_links.php')) ?>">
                Manage teams
            </a>

            <a class="btn btn-outline-primary" href="<?= e(url('leaders.php')) ?>">
                Manage leaders
            </a>
        </div>
    <?php endif; ?>

    <div class="dashboard-layout">

        <div>
            <section class="mb-4">
                <h2>Updates feed</h2>

                <?php if (empty($feedPosts)): ?>
                    <div class="empty-feed">
                        No updates have been posted yet.
                    </div>
                <?php endif; ?>

                <?php foreach ($feedPosts as $post): ?>
                    <?php
                    $postId = (int)$post['id'];
                    $isPinned = (int)$post['is_pinned'] === 1;
                    $isLocation = ($post['post_type'] ?? '') === 'check_in';
                    $locationForPost = $postLocationByPostId[$postId] ?? null;
                    $postPhotos = $postPhotosByPostId[$postId] ?? [];
                    ?>

                    <article
                        id="post-<?= $postId ?>"
                        class="feed-card <?= $isPinned ? 'feed-card-pinned' : '' ?>"
                    >
                        <div class="feed-card-header">
                            <h2><?= e($post['title']) ?></h2>

                            <p class="feed-meta">
                                <?= e(format_datetime($post['published_at'])) ?>
                                <span class="meta-separator">|</span>
                                <?= e($post['team_name'] ?: 'All teams') ?>
                                <span class="meta-separator">|</span>
                                <?= e($post['leader_name'] ?: 'Leader') ?>
                            </p>

                            <div class="feed-badge-row">
                                <?php if ($isPinned): ?>
                                    <span class="feed-badge feed-badge-pinned">Pinned</span>
                                <?php endif; ?>

                                <?php if ($isLocation): ?>
                                    <span class="feed-badge feed-badge-location">Location check-in</span>
                                <?php endif; ?>

                                <?php if ($post['visibility'] === 'team'): ?>
                                    <span class="feed-badge">Team only</span>
                                <?php else: ?>
                                    <span class="feed-badge">All teams</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="feed-card-body">
                            <div class="feed-content">
                                <?= safe_post_html((string)$post['body']) ?>
                            </div>

                            <?php if (!empty($postPhotos)): ?>
                                <div class="feed-photo-grid">
                                    <?php foreach ($postPhotos as $photo): ?>
                                        <?php if (!empty($photo['photo_url'])): ?>
                                            <a href="<?= e(media_url($photo['photo_url'])) ?>" target="_blank" rel="noopener">
                                                <img
                                                    class="feed-photo-thumb"
                                                    src="<?= e(media_url($photo['photo_url'])) ?>"
                                                    alt=""
                                                >
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif (!empty($post['photo_url'])): ?>
                                <a href="<?= e(media_url($post['photo_url'])) ?>" target="_blank" rel="noopener">
                                    <img
                                        class="feed-photo"
                                        src="<?= e(media_url($post['photo_url'])) ?>"
                                        alt=""
                                    >
                                </a>
                            <?php endif; ?>

                            <?php if ($isLocation && $locationForPost): ?>
                                <div
                                    class="feed-map js-location-map"
                                    data-lat="<?= e($locationForPost['latitude']) ?>"
                                    data-lng="<?= e($locationForPost['longitude']) ?>"
                                    data-label="<?= e($locationForPost['location_name']) ?>"
                                    data-zoom="12"
                                ></div>

                                <p class="map-caption">
                                    The blue circle shows an approximate 1 mile area around their evening location.
                                </p>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>

        <aside>

            <section class="sidebar-panel">
                <h2>Latest location</h2>

                <div class="location-note">
                    <p>
                        Locations are manually entered by leaders. If there is no location for a particular day,
                        this does not necessarily indicate an issue. There may simply be a delay in entering the update.
                    </p>
                </div>

                <?php if ($isParentView): ?>
                    <?php if ($parentLatestLocation): ?>
                        <div class="location-summary">
                            <h3><?= e($parentLatestLocation['team_name']) ?></h3>

                            <p class="mb-1">
                                <span class="status-pill <?= e(status_class($parentLatestLocation['team_status'])) ?>">
                                    <?= e(status_label($parentLatestLocation['team_status'])) ?>
                                </span>
                            </p>

                            <p class="mb-1">
                                <?= e($parentLatestLocation['location_name']) ?>
                            </p>

                            <p class="muted mb-0">
                                <?= e(format_datetime($parentLatestLocation['checked_in_at'])) ?>
                            </p>

                            <div
                                id="parent-location-map"
                                class="parent-map js-location-map"
                                data-lat="<?= e($parentLatestLocation['latitude']) ?>"
                                data-lng="<?= e($parentLatestLocation['longitude']) ?>"
                                data-label="<?= e($parentLatestLocation['location_name']) ?>"
                                data-zoom="11"
                            ></div>

                            <p class="map-caption">
                                The blue circle shows an approximate 1 mile area around their evening location.
                            </p>
                        </div>
                    <?php else: ?>
                        <p class="muted mb-0">
                            No location has been entered for this team yet.
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (empty($teams)): ?>
                        <p class="muted mb-0">No teams found.</p>
                    <?php endif; ?>

                    <?php foreach ($teams as $team): ?>
                        <?php $latestLocation = $latestLocationByTeam[(int)$team['id']] ?? null; ?>

                        <div class="location-summary">
                            <h3><?= e($team['name']) ?></h3>

                            <p class="mb-1">
                                <span class="status-pill <?= e(status_class($team['status'])) ?>">
                                    <?= e(status_label($team['status'])) ?>
                                </span>
                            </p>

                            <?php if ($latestLocation): ?>
                                <p class="mb-1">
                                    <?= e($latestLocation['location_name']) ?>
                                </p>
                                <p class="muted mb-0">
                                    <?= e(format_datetime($latestLocation['checked_in_at'])) ?>
                                </p>
                            <?php else: ?>
                                <p class="muted mb-0">
                                    No location check-in yet.
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

        </aside>

    </div>

</main>

<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin=""
></script>

<script>
    (function () {
        if (typeof L === 'undefined') {
            return;
        }

        var mapElements = document.querySelectorAll('.js-location-map');

        mapElements.forEach(function (mapElement) {
            var lat = parseFloat(mapElement.dataset.lat);
            var lng = parseFloat(mapElement.dataset.lng);
            var zoom = parseInt(mapElement.dataset.zoom || '11', 10);

            if (Number.isNaN(lat) || Number.isNaN(lng)) {
                return;
            }

            var map = L.map(mapElement, {
                scrollWheelZoom: false,
                dragging: false,
                touchZoom: false,
                doubleClickZoom: false,
                boxZoom: false,
                keyboard: false,
                zoomControl: false,
                attributionControl: false
            }).setView([lat, lng], zoom);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19
            }).addTo(map);

            L.circle([lat, lng], {
                radius: 1609.34,
                color: '#1d70b8',
                fillColor: '#1d70b8',
                weight: 2,
                fillOpacity: 0.16
            }).addTo(map);

            setTimeout(function () {
                map.invalidateSize();
            }, 250);
        });
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>