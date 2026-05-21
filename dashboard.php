<?php
require_once __DIR__ . '/auth.php';

$pdo = db();
$user = current_user();
$parentTeam = parent_access_team();

if (!$user && !$parentTeam) {
    redirect('403.php');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$error = '';
$success = '';
$isLeader = (bool)$user;
$isParentView = !$user && $parentTeam;

/**
 * CSRF helper
 */
if (empty($_SESSION['dashboard_csrf'])) {
    $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['dashboard_csrf'];

function dashboard_csrf_valid(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['dashboard_csrf'])
        && hash_equals((string)$_SESSION['dashboard_csrf'], (string)$_POST['csrf_token']);
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

    if ($html === strip_tags($html)) {
        return nl2br(e($html));
    }

    $allowedTags = '<p><br><strong><b><em><i><u><a><ol><ul><li><span><blockquote>';

    $html = strip_tags($html, $allowedTags);
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/href\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', 'href="#"', $html);

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

function clean_post_html_for_save(string $html): string
{
    $html = trim($html);

    if ($html === '') {
        return '';
    }

    $allowedTags = '<p><br><strong><b><em><i><u><a><ol><ul><li><span><blockquote>';

    $html = strip_tags($html, $allowedTags);
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/href\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', 'href="#"', $html);

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

function initials_from_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials ?: '?';
}

function osm_map_url($lat, $lng): string
{
    $lat = (float)$lat;
    $lng = (float)$lng;

    return 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string)$lat)
        . '&mlon=' . rawurlencode((string)$lng)
        . '#map=13/' . rawurlencode((string)$lat)
        . '/' . rawurlencode((string)$lng);
}

function is_location_post(array $post): bool
{
    return ($post['post_type'] ?? '') === 'check_in';
}

/**
 * Leader-only post actions.
 * Location/check-in posts cannot be edited/deleted from here.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLeader) {
    $action = $_POST['action'] ?? '';

    try {
        if (!dashboard_csrf_valid()) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        $postId = (int)($_POST['post_id'] ?? 0);

        if ($postId <= 0) {
            throw new RuntimeException('Invalid post selected.');
        }

        $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = ? LIMIT 1');
        $stmt->execute([$postId]);
        $postToManage = $stmt->fetch();

        if (!$postToManage) {
            throw new RuntimeException('Post not found.');
        }

        if (is_location_post($postToManage)) {
            throw new RuntimeException('Location check-ins cannot be edited from the dashboard.');
        }

        if ($action === 'toggle_pin') {
            $newPinnedState = (int)($_POST['new_pinned_state'] ?? 0) === 1 ? 1 : 0;

            if ($newPinnedState === 1) {
                $pdo->beginTransaction();

                $pdo->exec('UPDATE posts SET is_pinned = 0');

                $stmt = $pdo->prepare('UPDATE posts SET is_pinned = 1 WHERE id = ?');
                $stmt->execute([$postId]);

                $pdo->commit();
            } else {
                $stmt = $pdo->prepare('UPDATE posts SET is_pinned = 0 WHERE id = ?');
                $stmt->execute([$postId]);
            }

            redirect('dashboard.php#post-' . $postId);
        }

        if ($action === 'delete_post') {
            $pdo->beginTransaction();

            try {
                /**
                 * If post_photos has FK cascade this is harmless.
                 * If not, this keeps orphaned photo rows out of the database.
                 */
                try {
                    $stmt = $pdo->prepare('DELETE FROM post_photos WHERE post_id = ?');
                    $stmt->execute([$postId]);
                } catch (Throwable $ignored) {
                    // post_photos may not exist on older installs.
                }

                $stmt = $pdo->prepare('DELETE FROM posts WHERE id = ?');
                $stmt->execute([$postId]);

                $pdo->commit();

                redirect('dashboard.php');
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }
        }

        if ($action === 'edit_post') {
            $title = trim($_POST['title'] ?? '');
            $body = clean_post_html_for_save($_POST['body'] ?? '');
            $visibility = $_POST['visibility'] ?? 'public';
            $teamId = ($_POST['team_id'] ?? '') !== '' ? (int)$_POST['team_id'] : null;
            $postType = $_POST['post_type'] ?? 'general';
            $photoUrl = trim($_POST['photo_url'] ?? '');
            $isPinned = isset($_POST['is_pinned']) ? 1 : 0;

            if ($title === '') {
                throw new RuntimeException('Post title is required.');
            }

            if ($body === '') {
                throw new RuntimeException('Post content is required.');
            }

            if (!in_array($visibility, ['public', 'team'], true)) {
                $visibility = 'public';
            }

            if ($visibility === 'team' && !$teamId) {
                throw new RuntimeException('Choose a team for a team-only update.');
            }

            if (!in_array($postType, ['general', 'team_update', 'photo', 'important'], true)) {
                $postType = 'general';
            }

            $pdo->beginTransaction();

            try {
                if ($isPinned === 1) {
                    $pdo->exec('UPDATE posts SET is_pinned = 0');
                }

                $stmt = $pdo->prepare(
                    'UPDATE posts
                     SET title = ?,
                         body = ?,
                         visibility = ?,
                         team_id = ?,
                         post_type = ?,
                         photo_url = ?,
                         is_pinned = ?
                     WHERE id = ?'
                );

                $stmt->execute([
                    $title,
                    $body,
                    $visibility,
                    $teamId,
                    $postType,
                    $photoUrl,
                    $isPinned,
                    $postId,
                ]);

                $pdo->commit();

                redirect('dashboard.php#post-' . $postId);
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $exception->getMessage();
    }
}

/**
 * Teams visible to viewer.
 */
if ($isLeader) {
    $teams = $pdo->query('SELECT * FROM teams ORDER BY name ASC')->fetchAll();
} else {
    $teams = [$parentTeam];
}

$visibleTeamIds = [];

foreach ($teams as $team) {
    if (!empty($team['id'])) {
        $visibleTeamIds[] = (int)$team['id'];
    }
}

/**
 * Fetch feed.
 */
if ($isLeader) {
    $stmt = $pdo->query(
        'SELECT 
            p.*, 
            t.name AS team_name, 
            l.name AS leader_name,
            l.photo_url AS leader_photo_url
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
            l.name AS leader_name,
            l.photo_url AS leader_photo_url
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
 * Fetch multiple photos for posts.
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
        $postPhotosByPostId = [];
    }
}

/**
 * Team members for visible teams.
 */
$teamMembersByTeam = [];

if (!empty($visibleTeamIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($visibleTeamIds), '?'));

        $stmt = $pdo->prepare(
            'SELECT id, team_id, name, photo_url, allergies_json
             FROM young_people
             WHERE team_id IN (' . $placeholders . ')
               AND is_active = 1
             ORDER BY name ASC'
        );

        $stmt->execute($visibleTeamIds);

        foreach ($stmt->fetchAll() as $member) {
            $teamId = (int)$member['team_id'];

            if (!isset($teamMembersByTeam[$teamId])) {
                $teamMembersByTeam[$teamId] = [];
            }

            $teamMembersByTeam[$teamId][] = $member;
        }
    } catch (Throwable $exception) {
        $teamMembersByTeam = [];
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
 * Match check-in posts to closest location record for that team.
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

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

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

    .feed-heading-row {
        display: grid;
        grid-template-columns: 46px minmax(0, 1fr) auto;
        gap: 0.75rem;
        align-items: start;
    }

    @media (max-width: 640px) {
        .feed-heading-row {
            grid-template-columns: 42px minmax(0, 1fr);
        }

        .feed-admin-actions {
            grid-column: 1 / -1;
            justify-content: flex-start;
        }
    }

    .leader-avatar {
        width: 46px;
        height: 46px;
        max-width: 46px;
        max-height: 46px;
        border-radius: 50%;
        border: 2px solid #1d1d1d;
        object-fit: cover;
        background: #f3f2f1;
        display: block;
    }

    .leader-avatar-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        background: #7413dc;
        color: #ffffff;
        font-weight: 900;
        font-size: 0.9rem;
    }

    .feed-title-block h2 {
        margin: 0;
        font-size: 1.35rem;
        font-weight: 900;
    }

    .feed-admin-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        justify-content: flex-end;
    }

    .feed-admin-actions .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.85rem;
        font-weight: 800;
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

    .team-faces {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
        margin: 0 0 0.75rem;
    }

    .team-face,
    .team-face-placeholder {
        width: 34px;
        height: 34px;
        max-width: 34px;
        max-height: 34px;
        border-radius: 50%;
        border: 2px solid #ffffff;
        box-shadow: 0 0 0 1px #1d1d1d;
        object-fit: cover;
        background: #f3f2f1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 900;
        color: #1d1d1d;
        text-decoration: none;
    }

    .team-face-link:hover,
    .team-face-link:focus {
        transform: translateY(-1px);
        text-decoration: none;
    }

    .feed-map {
        height: 230px;
        border: 2px solid #1d1d1d;
        margin-top: 1rem;
        background: #f3f2f1;
    }

    .map-action-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
        margin-top: 0.75rem;
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

    .modal-content {
        border-radius: 0;
        border: 2px solid #1d1d1d;
    }

    .modal-header {
        background: #7413dc;
        color: #ffffff;
        border-radius: 0;
    }

    .modal-header .close {
        color: #ffffff;
        opacity: 1;
    }

    .edit-help {
        border-left: 6px solid #1d70b8;
        background: #eef7ff;
        padding: 0.75rem;
        margin-bottom: 1rem;
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

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= e($success) ?>
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
            <a class="btn btn-primary" href="<?= e(url('email_all.php')) ?>">
                Email to all
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
                    $leaderName = $post['leader_name'] ?: 'Leader';
                    $leaderPhoto = !empty($post['leader_photo_url']) ? media_url($post['leader_photo_url']) : '';
                    $postTeamMembers = !empty($post['team_id']) ? ($teamMembersByTeam[(int)$post['team_id']] ?? []) : [];
                    ?>

                    <article
                        id="post-<?= $postId ?>"
                        class="feed-card <?= $isPinned ? 'feed-card-pinned' : '' ?>"
                    >
                        <div class="feed-card-header">
                            <div class="feed-heading-row">
                                <div>
                                    <?php if ($leaderPhoto !== ''): ?>
                                        <img
                                            class="leader-avatar"
                                            src="<?= e($leaderPhoto) ?>"
                                            alt="Photo of <?= e($leaderName) ?>"
                                        >
                                    <?php else: ?>
                                        <div class="leader-avatar leader-avatar-placeholder" aria-hidden="true">
                                            <?= e(initials_from_name($leaderName)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="feed-title-block">
                                    <h2><?= e($post['title']) ?></h2>

                                    <p class="feed-meta">
                                        <?= e(format_datetime($post['published_at'])) ?>
                                        <span class="meta-separator">|</span>
                                        <?= e($post['team_name'] ?: 'All teams') ?>
                                        <span class="meta-separator">|</span>
                                        <?= e($leaderName) ?>
                                    </p>
                                </div>

                                <?php if ($isLeader && !$isLocation): ?>
                                    <div class="feed-admin-actions">
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm"
                                            data-toggle="modal"
                                            data-target="#editPostModal<?= $postId ?>"
                                        >
                                            Edit
                                        </button>

                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle_pin">
                                            <input type="hidden" name="post_id" value="<?= $postId ?>">
                                            <input type="hidden" name="new_pinned_state" value="<?= $isPinned ? '0' : '1' ?>">

                                            <button class="btn btn-outline-primary btn-sm" type="submit">
                                                <?= $isPinned ? 'Unpin' : 'Pin' ?>
                                            </button>
                                        </form>

                                        <form
                                            method="post"
                                            class="d-inline"
                                            onsubmit="return confirm('Delete this update? This cannot be undone.');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete_post">
                                            <input type="hidden" name="post_id" value="<?= $postId ?>">

                                            <button class="btn btn-outline-danger btn-sm" type="submit">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>

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
                            <?php if ($isLocation && !empty($postTeamMembers)): ?>
                                <div class="team-faces" aria-label="Team members">
                                    <?php foreach ($postTeamMembers as $member): ?>
                                        <?php
                                        $memberPhoto = !empty($member['photo_url']) ? media_url($member['photo_url']) : '';
                                        $memberName = (string)$member['name'];
                                        ?>
                                        <?php if ($isLeader): ?>
                                            <a
                                                class="team-face-link"
                                                href="<?= e(url('people.php?person_id=' . (int)$member['id'])) ?>"
                                                title="<?= e($memberName) ?>"
                                            >
                                                <?php if ($memberPhoto !== ''): ?>
                                                    <img class="team-face" src="<?= e($memberPhoto) ?>" alt="<?= e($memberName) ?>">
                                                <?php else: ?>
                                                    <span class="team-face-placeholder" aria-hidden="true">
                                                        <?= e(initials_from_name($memberName)) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </a>
                                        <?php else: ?>
                                            <?php if ($memberPhoto !== ''): ?>
                                                <img class="team-face" src="<?= e($memberPhoto) ?>" alt="">
                                            <?php else: ?>
                                                <span class="team-face-placeholder" aria-hidden="true">
                                                    <?= e(initials_from_name($memberName)) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

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

                                <div class="map-action-row">
                                    <a
                                        class="btn btn-outline-primary btn-sm"
                                        href="<?= e(osm_map_url($locationForPost['latitude'], $locationForPost['longitude'])) ?>"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        View on map
                                    </a>

                                    <p class="map-caption mb-0">
                                        The blue circle shows an approximate 1 mile area around their evening location.
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>

                    <?php if ($isLeader && !$isLocation): ?>
                        <div
                            class="modal fade"
                            id="editPostModal<?= $postId ?>"
                            tabindex="-1"
                            role="dialog"
                            aria-labelledby="editPostModalLabel<?= $postId ?>"
                            aria-hidden="true"
                        >
                            <div class="modal-dialog modal-lg" role="document">
                                <div class="modal-content">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="edit_post">
                                        <input type="hidden" name="post_id" value="<?= $postId ?>">

                                        <div class="modal-header">
                                            <h5 class="modal-title" id="editPostModalLabel<?= $postId ?>">
                                                Edit update
                                            </h5>

                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="edit-help">
                                                Edit normal updates here. Location check-ins are managed separately through location tools.
                                            </div>

                                            <div class="form-group">
                                                <label for="title<?= $postId ?>">Title</label>
                                                <input
                                                    class="form-control"
                                                    id="title<?= $postId ?>"
                                                    name="title"
                                                    value="<?= e($post['title']) ?>"
                                                    required
                                                >
                                            </div>

                                            <div class="form-group">
                                                <label for="body<?= $postId ?>">Update</label>
                                                <textarea
                                                    class="form-control"
                                                    id="body<?= $postId ?>"
                                                    name="body"
                                                    rows="7"
                                                    required
                                                ><?= e((string)$post['body']) ?></textarea>
                                                <small class="form-text text-muted">
                                                    Existing rich text is saved as HTML. Keep formatting tags if needed.
                                                </small>
                                            </div>

                                            <div class="form-row">
                                                <div class="form-group col-md-6">
                                                    <label for="visibility<?= $postId ?>">Visibility</label>
                                                    <select class="form-control" id="visibility<?= $postId ?>" name="visibility">
                                                        <option value="public" <?= $post['visibility'] === 'public' ? 'selected' : '' ?>>
                                                            All team parent links
                                                        </option>
                                                        <option value="team" <?= $post['visibility'] === 'team' ? 'selected' : '' ?>>
                                                            One team only
                                                        </option>
                                                    </select>
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label for="team_id<?= $postId ?>">Team</label>
                                                    <select class="form-control" id="team_id<?= $postId ?>" name="team_id">
                                                        <option value="">No specific team</option>
                                                        <?php foreach ($teams as $team): ?>
                                                            <option
                                                                value="<?= (int)$team['id'] ?>"
                                                                <?= (int)($post['team_id'] ?? 0) === (int)$team['id'] ? 'selected' : '' ?>
                                                            >
                                                                <?= e($team['name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="form-row">
                                                <div class="form-group col-md-6">
                                                    <label for="post_type<?= $postId ?>">Post type</label>
                                                    <select class="form-control" id="post_type<?= $postId ?>" name="post_type">
                                                        <option value="general" <?= $post['post_type'] === 'general' ? 'selected' : '' ?>>
                                                            General update
                                                        </option>
                                                        <option value="team_update" <?= $post['post_type'] === 'team_update' ? 'selected' : '' ?>>
                                                            Team update
                                                        </option>
                                                        <option value="photo" <?= $post['post_type'] === 'photo' ? 'selected' : '' ?>>
                                                            Photo
                                                        </option>
                                                        <option value="important" <?= $post['post_type'] === 'important' ? 'selected' : '' ?>>
                                                            Important
                                                        </option>
                                                    </select>
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label for="photo_url<?= $postId ?>">External photo URL</label>
                                                    <input
                                                        class="form-control"
                                                        id="photo_url<?= $postId ?>"
                                                        name="photo_url"
                                                        type="url"
                                                        value="<?= e($post['photo_url'] ?? '') ?>"
                                                    >
                                                </div>
                                            </div>

                                            <div class="form-check">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    id="is_pinned<?= $postId ?>"
                                                    name="is_pinned"
                                                    <?= $isPinned ? 'checked' : '' ?>
                                                >
                                                <label class="form-check-label" for="is_pinned<?= $postId ?>">
                                                    Pin this update
                                                </label>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">
                                                Cancel
                                            </button>

                                            <button type="submit" class="btn btn-primary">
                                                Save update
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
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

                            <p class="muted mb-2">
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

                            <div class="map-action-row">
                                <a
                                    class="btn btn-outline-primary btn-sm"
                                    href="<?= e(osm_map_url($parentLatestLocation['latitude'], $parentLatestLocation['longitude'])) ?>"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    View on map
                                </a>

                                <p class="map-caption mb-0">
                                    The blue circle shows an approximate 1 mile area around their evening location.
                                </p>
                            </div>
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
                                <p class="muted mb-2">
                                    <?= e(format_datetime($latestLocation['checked_in_at'])) ?>
                                </p>

                                <a
                                    class="btn btn-outline-primary btn-sm"
                                    href="<?= e(osm_map_url($latestLocation['latitude'], $latestLocation['longitude'])) ?>"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    View on map
                                </a>
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

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

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