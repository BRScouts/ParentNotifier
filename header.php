<?php
require_once __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();
$user = current_user();
$parentTeam = parent_access_team();

$profileError = '';
$profileSuccess = '';

$dashboardUrl = $user
    ? url('dashboard.php')
    : ($parentTeam ? url('dashboard.php?token=' . $parentTeam['parent_token']) : url('login.php'));

$leadersUrl = $parentTeam
    ? url('leaders.php?token=' . $parentTeam['parent_token'])
    : url('leaders.php');

$contactUrl = $parentTeam
    ? url('contact.php?token=' . $parentTeam['parent_token'])
    : url('contact.php');

function header_nav_active(string $filename): string
{
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return $current === $filename ? ' active' : '';
}

function header_nav_active_group(array $filenames): string
{
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return in_array($current, $filenames, true) ? ' active' : '';
}

function header_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function header_media_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    return url($path);
}

function header_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '') $letters .= strtoupper(substr($part, 0, 1));
        if (strlen($letters) >= 2) break;
    }
    return $letters !== '' ? $letters : '?';
}

function header_leader_bio_column(PDO $pdo): ?string
{
    foreach (['bio', 'blurb', 'profile', 'description'] as $column) {
        if (header_column_exists($pdo, 'leaders', $column)) return $column;
    }
    return null;
}

function header_fetch_leader(PDO $pdo, int $leaderId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM leaders WHERE id = ? LIMIT 1');
    $stmt->execute([$leaderId]);
    $leader = $stmt->fetch();
    return $leader ?: null;
}

function header_handle_profile_upload(string $fieldName, ?string $existingPath = null): ?string
{
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) return $existingPath;
    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $existingPath;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Profile photo upload failed.');
    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('Profile photo must be smaller than 5MB.');
    $tmpName = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmpName)) throw new RuntimeException('Invalid uploaded profile photo.');
    $imageInfo = getimagesize($tmpName);
    if ($imageInfo === false) throw new RuntimeException('Please upload a valid image file.');
    $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mimeType = $imageInfo['mime'] ?? '';
    if (!isset($allowedMimeTypes[$mimeType])) throw new RuntimeException('Profile photo must be JPG, PNG, WEBP or GIF.');
    $uploadDir = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/leaders/';
    $publicPath = 'assets/leaders/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) throw new RuntimeException('Could not create leader upload directory.');
    }
    $extension = $allowedMimeTypes[$mimeType];
    $filename = 'leader-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $destination = rtrim($uploadDir, '/') . '/' . $filename;
    if (!move_uploaded_file($tmpName, $destination)) throw new RuntimeException('Could not save uploaded profile photo.');
    return $publicPath . $filename;
}

if (empty($_SESSION['header_profile_csrf'])) {
    $_SESSION['header_profile_csrf'] = bin2hex(random_bytes(32));
}
$headerProfileCsrf = $_SESSION['header_profile_csrf'];

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['header_action'] ?? '') === 'update_profile') {
    try {
        if (empty($_POST['header_profile_csrf']) || !hash_equals($_SESSION['header_profile_csrf'], (string)$_POST['header_profile_csrf'])) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }
        $leaderId = (int)($user['id'] ?? 0);
        if ($leaderId <= 0) throw new RuntimeException('Leader profile could not be found.');
        $existingLeader = header_fetch_leader($pdo, $leaderId);
        if (!$existingLeader) throw new RuntimeException('Leader profile could not be found.');
        $name = trim($_POST['profile_name'] ?? '');
        $email = trim($_POST['profile_email'] ?? '');
        $bio = trim($_POST['profile_bio'] ?? '');
        if ($name === '') throw new RuntimeException('Name is required.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email address is not valid.');
        $bioColumn = header_leader_bio_column($pdo);
        $hasPhotoColumn = header_column_exists($pdo, 'leaders', 'photo_url');
        $updates = ['name = ?'];
        $params = [$name];
        if (header_column_exists($pdo, 'leaders', 'email')) { $updates[] = 'email = ?'; $params[] = $email !== '' ? $email : null; }
        if ($bioColumn) { $updates[] = $bioColumn . ' = ?'; $params[] = $bio !== '' ? $bio : null; }
        if ($hasPhotoColumn) {
            $newPhotoPath = header_handle_profile_upload('profile_photo', $existingLeader['photo_url'] ?? null);
            $updates[] = 'photo_url = ?'; $params[] = $newPhotoPath;
        }
        if (header_column_exists($pdo, 'leaders', 'updated_at')) $updates[] = 'updated_at = NOW()';
        $params[] = $leaderId;
        $stmt = $pdo->prepare('UPDATE leaders SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($params);
        $_SESSION['header_profile_success'] = 'Profile updated.';
        redirect(basename($_SERVER['REQUEST_URI'] ?? 'dashboard.php'));
    } catch (Throwable $exception) {
        $_SESSION['header_profile_error'] = $exception->getMessage();
        redirect(basename($_SERVER['REQUEST_URI'] ?? 'dashboard.php'));
    }
}

if (!empty($_SESSION['header_profile_error'])) { $profileError = $_SESSION['header_profile_error']; unset($_SESSION['header_profile_error']); }
if (!empty($_SESSION['header_profile_success'])) { $profileSuccess = $_SESSION['header_profile_success']; unset($_SESSION['header_profile_success']); }

$currentLeader = null;
$leaderBioColumn = null;
if ($user && !empty($user['id'])) {
    $currentLeader = header_fetch_leader($pdo, (int)$user['id']);
    $leaderBioColumn = header_leader_bio_column($pdo);
}

$leaderName = $currentLeader['name'] ?? ($user['name'] ?? 'Leader');
$leaderEmail = $currentLeader['email'] ?? ($user['email'] ?? '');
$leaderPhoto = header_media_url($currentLeader['photo_url'] ?? '');
$leaderBio = $leaderBioColumn ? (string)($currentLeader[$leaderBioColumn] ?? '') : '';

// Check if the current leader is on duty
$headerDutyStatus = null;
if ($user && !empty($user['id'])) {
    try {
        $headerDutyStatus = leader_duty_status($pdo, (int)$user['id']);
    } catch (Throwable $e) {
        $headerDutyStatus = ['on_duty' => false, 'next_duty_start' => null, 'hours_until_next' => null];
    }
}
$isLeaderOnDuty = $headerDutyStatus['on_duty'] ?? false;

// Fetch all on-duty leaders for today
$headerOnDutyLeaders = [];
if ($user) {
    try {
        $headerTz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Helsinki');
        $headerNow = new DateTime('now', $headerTz);
        $headerHour = (int)$headerNow->format('G');
        $headerDutyDate = ($headerHour < 9)
            ? (clone $headerNow)->modify('-1 day')->format('Y-m-d')
            : $headerNow->format('Y-m-d');
        $stmt = $pdo->prepare(
            'SELECT l.name FROM leader_duty_roster r JOIN leaders l ON l.id = r.leader_id
             WHERE r.duty_date = ? AND r.status = "on_duty" ORDER BY l.name ASC'
        );
        $stmt->execute([$headerDutyDate]);
        $headerOnDutyLeaders = $stmt->fetchAll();
    } catch (Throwable $e) {
        $headerOnDutyLeaders = [];
    }
}

// Pending check-in count for nav badge
$headerPendingCount = 0;
if ($user) {
    try {
        $stmt = $pdo->query('SELECT COUNT(*) FROM explorer_checkins WHERE status = "pending"');
        $headerPendingCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $headerPendingCount = 0;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#7413dc">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Explorer Belt">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?= e(APP_NAME) ?></title>

    <link rel="manifest" href="<?= e(url('manifest.json')) ?>">
    <link rel="icon" type="image/x-icon" href="<?= e(url('favicon.ico')) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= e(url('assets/logo-192.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(url('assets/logo-192.png')) ?>">
    <link rel="apple-touch-icon" sizes="192x192" href="<?= e(url('assets/logo-192.png')) ?>">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://unpkg.com" crossorigin>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.min.css')) ?>">

    <style>
        /* ===== HEADER BASE ===== */
        .site-header { background: #7413dc; color: #fff; position: relative; z-index: 1030; }
        .compact-navbar { min-height: 80px; padding-top: 0; padding-bottom: 0; }
        .site-brand { display: flex; align-items: center; height: 80px; padding: 0; margin: 0 1.15rem 0 0; background: transparent !important; border: 0 !important; box-shadow: none !important; line-height: 1; flex: 0 0 auto; }
        .site-logo-frame { width: 200px; height: 80px; max-height: 80px; display: flex; align-items: center; justify-content: flex-start; overflow: hidden; background: transparent !important; border: 0 !important; box-shadow: none !important; padding: 0; margin: 0; line-height: 1; flex: 0 0 200px; }
        .site-logo { width: 100% !important; height: auto !important; max-width: 200px !important; max-height: 70px !important; object-fit: contain; object-position: left center; display: block; background: transparent !important; border: 0 !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; line-height: 1; }
        .site-logo-placeholder { width: 58px; height: 58px; min-width: 58px; min-height: 58px; border: 2px solid #fff; background: transparent; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.15rem; line-height: 1; box-shadow: none; padding: 0; margin: 0; }

        /* ===== DESKTOP NAV ===== */
        .desktop-nav { display: flex; align-items: center; gap: 0.15rem; margin-left: auto; }
        .desktop-nav .nav-link { color: #fff !important; font-weight: 850; font-size: 0.94rem; padding: 0.42rem 0.62rem !important; border-radius: 0; white-space: nowrap; text-decoration: none; line-height: 1.2; border: 2px solid transparent; }
        .desktop-nav .nav-link:hover, .desktop-nav .nav-link:focus { background: rgba(255,255,255,0.16); color: #fff !important; text-decoration: none; border-color: rgba(255,255,255,0.35); }
        .desktop-nav .nav-item.active > .nav-link { background: #fff; color: #7413dc !important; text-decoration: none; border-color: #fff; }

        /* Desktop dropdown */
        .desktop-nav .nav-item.dropdown { position: relative; }
        .desktop-nav .dropdown-toggle::after { margin-left: 0.35rem; }
        .desktop-nav .dropdown-menu { border-radius: 0; border: 2px solid #1d1d1d; padding: 0; min-width: 180px; box-shadow: 0 8px 24px rgba(0,0,0,0.22); margin-top: 0; }
        .desktop-nav .dropdown-menu .dropdown-item { font-weight: 700; padding: 0.7rem 1rem; color: #1d1d1d; text-decoration: none; border-radius: 0; }
        .desktop-nav .dropdown-menu .dropdown-item:hover, .desktop-nav .dropdown-menu .dropdown-item:focus { background: #f3f2f1; color: #1d1d1d; }
        .desktop-nav .dropdown-menu .dropdown-item.active { background: #7413dc; color: #fff; }

        /* Pending badge */
        .nav-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px; background: #d4351c; color: #fff; font-size: 0.72rem; font-weight: 900; margin-left: 0.35rem; line-height: 1; }
        .nav-badge-pulse { animation: badgePulse 2s infinite; }
        @keyframes badgePulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }

        /* ===== PROFILE MENU (desktop) ===== */
        .profile-menu { margin-left: 0.65rem; position: relative; }
        .profile-toggle { border: 2px solid rgba(255,255,255,0.75); background: rgba(255,255,255,0.12); color: #fff; border-radius: 0; padding: 0.2rem 0.35rem 0.2rem 0.2rem; display: flex; align-items: center; gap: 0.4rem; line-height: 1; cursor: pointer; }
        .profile-toggle:hover, .profile-toggle:focus { background: rgba(255,255,255,0.2); outline: 3px solid #ffdd00; outline-offset: 2px; }
        .profile-avatar { width: 42px; height: 42px; min-width: 42px; min-height: 42px; border-radius: 0; border: 2px solid #fff; object-fit: cover; background: #4d0b95; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; font-size: 0.9rem; overflow: hidden; }
        .profile-avatar-on-duty { border: 3px solid #00703c !important; box-shadow: 0 0 0 2px rgba(0,112,60,0.35); }
        .profile-dropdown { border-radius: 0; border: 2px solid #1d1d1d; padding: 0; min-width: 230px; box-shadow: 0 8px 24px rgba(0,0,0,0.22); }
        .profile-dropdown-header { padding: 0.8rem; border-bottom: 2px solid #d8d8d8; background: #f8f8f8; }
        .profile-dropdown-name { display: block; font-weight: 900; color: #1d1d1d; }
        .profile-dropdown-email { display: block; color: #505a5f; font-size: 0.9rem; word-break: break-word; }
        .profile-dropdown .dropdown-item { font-weight: 800; padding: 0.75rem 0.9rem; color: #1d1d1d; text-decoration: none; border-radius: 0; }
        .profile-dropdown .dropdown-item:hover, .profile-dropdown .dropdown-item:focus { background: #f3f2f1; color: #1d1d1d; }

        /* ===== GLOBAL SEARCH ===== */
        .global-search-form { margin-left: 0.75rem; display: flex; align-items: center; }
        .global-search-wrap { display: flex; align-items: center; border: 2px solid rgba(255,255,255,0.6); background: rgba(255,255,255,0.12); border-radius: 0; overflow: hidden; }
        .global-search-input { border: 0; background: transparent; color: #fff; padding: 0.4rem 0.6rem; font-size: 0.88rem; width: 160px; outline: none; font-weight: 600; }
        .global-search-input::placeholder { color: rgba(255,255,255,0.7); }
        .global-search-input:focus { width: 220px; background: rgba(255,255,255,0.18); }
        .global-search-btn { border: 0; background: rgba(255,255,255,0.15); color: #fff; padding: 0.4rem 0.55rem; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; }
        .global-search-btn:hover, .global-search-btn:focus { background: rgba(255,255,255,0.3); outline: 2px solid #ffdd00; }

        /* ===== MOBILE HAMBURGER ===== */
        .mobile-menu-toggle { display: none; border: 2px solid rgba(255,255,255,0.6); background: transparent; color: #fff; padding: 0.4rem 0.6rem; min-width: 44px; min-height: 44px; margin-left: auto; cursor: pointer; align-items: center; justify-content: center; }
        .mobile-menu-toggle svg { width: 24px; height: 24px; }

        /* ===== MOBILE SIDEBAR (off-canvas) ===== */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9998; opacity: 0; transition: opacity 0.25s ease; }
        .sidebar-overlay.is-visible { display: block; opacity: 1; }

        .mobile-sidebar { position: fixed; top: 0; left: 0; bottom: 0; width: 300px; max-width: 85vw; background: #7413dc; z-index: 9999; transform: translateX(-100%); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); overflow-y: auto; -webkit-overflow-scrolling: touch; display: flex; flex-direction: column; }
        .mobile-sidebar.is-open { transform: translateX(0); }

        .sidebar-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem; border-bottom: 1px solid rgba(255,255,255,0.2); min-height: 70px; }
        .sidebar-header .site-logo { max-width: 150px !important; max-height: 50px !important; }
        .sidebar-close { background: transparent; border: 2px solid rgba(255,255,255,0.5); color: #fff; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 1.3rem; font-weight: 900; }
        .sidebar-close:hover { background: rgba(255,255,255,0.15); }

        .sidebar-body { flex: 1; padding: 0.75rem 0; overflow-y: auto; }
        .sidebar-nav { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav .sidebar-link { display: flex; align-items: center; padding: 0.85rem 1.25rem; color: #fff; font-weight: 800; font-size: 1rem; text-decoration: none; border-left: 4px solid transparent; min-height: 48px; }
        .sidebar-nav .sidebar-link:hover, .sidebar-nav .sidebar-link:focus { background: rgba(255,255,255,0.12); text-decoration: none; color: #fff; }
        .sidebar-nav .sidebar-link.active { border-left-color: #ffdd00; background: rgba(255,255,255,0.1); }

        /* Sidebar sub-menus */
        .sidebar-group-label { display: flex; align-items: center; justify-content: space-between; padding: 0.85rem 1.25rem; color: rgba(255,255,255,0.85); font-weight: 900; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.06em; cursor: pointer; border-left: 4px solid transparent; min-height: 48px; background: transparent; border-top: 0; border-right: 0; border-bottom: 0; width: 100%; text-align: left; }
        .sidebar-group-label:hover { background: rgba(255,255,255,0.08); }
        .sidebar-group-label.active { border-left-color: #ffdd00; }
        .sidebar-group-label .group-arrow { transition: transform 0.2s ease; font-size: 0.7rem; }
        .sidebar-group-label.expanded .group-arrow { transform: rotate(90deg); }
        .sidebar-sub { list-style: none; padding: 0; margin: 0; display: none; background: rgba(0,0,0,0.12); }
        .sidebar-sub.expanded { display: block; }
        .sidebar-sub .sidebar-link { padding-left: 2rem; font-size: 0.95rem; font-weight: 700; }

        .sidebar-divider { height: 1px; background: rgba(255,255,255,0.15); margin: 0.5rem 1rem; }

        /* Sidebar search */
        .sidebar-search { padding: 0.75rem 1rem; }
        .sidebar-search-wrap { display: flex; border: 2px solid rgba(255,255,255,0.5); background: rgba(255,255,255,0.1); overflow: hidden; }
        .sidebar-search-input { flex: 1; border: 0; background: transparent; color: #fff; padding: 0.55rem 0.75rem; font-size: 0.92rem; outline: none; min-height: 44px; }
        .sidebar-search-input::placeholder { color: rgba(255,255,255,0.6); }
        .sidebar-search-btn { border: 0; background: rgba(255,255,255,0.15); color: #fff; padding: 0.5rem 0.65rem; cursor: pointer; display: flex; align-items: center; }
        .sidebar-search-btn:hover { background: rgba(255,255,255,0.25); }

        /* Sidebar profile */
        .sidebar-profile { padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,0.2); margin-top: auto; display: flex; align-items: center; gap: 0.75rem; }
        .sidebar-profile-avatar { width: 44px; height: 44px; min-width: 44px; border: 2px solid #fff; object-fit: cover; background: #4d0b95; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; font-size: 0.85rem; overflow: hidden; }
        .sidebar-profile-info { flex: 1; min-width: 0; }
        .sidebar-profile-name { display: block; color: #fff; font-weight: 900; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-profile-duty { display: block; font-size: 0.78rem; color: rgba(255,255,255,0.75); margin-top: 0.15rem; }

        /* ===== ON-DUTY BAR ===== */
        .on-duty-global-bar { background: #1d70b8; color: #fff; font-size: 0.85rem; padding: 0.4rem 0; font-weight: 600; }
        .on-duty-global-bar strong { font-weight: 900; }

        /* ===== PROFILE MODAL ===== */
        .profile-modal-photo { width: 96px; height: 96px; border-radius: 0; border: 2px solid #1d1d1d; object-fit: cover; background: #7413dc; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.5rem; margin-bottom: 1rem; }
        .profile-alert { border-radius: 0; border-width: 2px; margin: 1rem auto 0; max-width: 1140px; }
        .modal-content { border-radius: 0; border: 2px solid #1d1d1d; }
        .modal-header { background: #7413dc; color: #fff; border-radius: 0; }
        .modal-header .close { color: #fff; opacity: 1; }
        .profile-form-note { border-left: 6px solid #1d70b8; background: #eef7ff; padding: 0.75rem; margin-bottom: 1rem; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1199.98px) {
            .site-logo-frame { width: 170px; flex-basis: 170px; }
            .site-logo { max-width: 170px !important; }
            .desktop-nav .nav-link { font-size: 0.9rem; padding-left: 0.48rem !important; padding-right: 0.48rem !important; }
        }

        @media (max-width: 991.98px) {
            /* Hide desktop nav, show hamburger */
            .desktop-nav, .global-search-form, .profile-menu { display: none !important; }
            .mobile-menu-toggle { display: flex; }
            .compact-navbar { min-height: 70px; }
            .site-brand { height: 70px; }
            .site-logo-frame { width: 160px; height: 70px; max-height: 70px; flex-basis: 160px; }
            .site-logo { max-width: 160px !important; max-height: 55px !important; }
        }

        @media (max-width: 575.98px) {
            .compact-navbar { min-height: 62px; }
            .site-brand { height: 62px; }
            .site-logo-frame { width: 150px; height: 62px; max-height: 62px; flex-basis: 150px; }
            .site-logo { max-width: 150px !important; max-height: 50px !important; }
            .mobile-sidebar { width: 280px; }
        }

        @media (max-width: 380px) {
            .compact-navbar { min-height: 56px; }
            .site-brand { height: 56px; }
            .site-logo-frame { width: 135px; height: 56px; max-height: 56px; flex-basis: 135px; }
            .site-logo { max-width: 135px !important; }
        }

        /* iOS safe-area */
        @supports (padding: env(safe-area-inset-top)) {
            .site-header { padding-top: env(safe-area-inset-top); }
            .mobile-sidebar { padding-top: env(safe-area-inset-top); padding-left: env(safe-area-inset-left); }
        }

        /* Landscape phones */
        @media (max-height: 500px) and (orientation: landscape) {
            .compact-navbar { min-height: 50px; }
            .site-brand { height: 50px; }
            .site-logo-frame { height: 50px; max-height: 50px; width: 140px; flex-basis: 140px; }
            .sidebar-nav .sidebar-link { padding: 0.6rem 1rem; min-height: 40px; font-size: 0.9rem; }
        }
    </style>
</head>

<body>
<?php if ($user && !empty($headerOnDutyLeaders)): ?>
<div class="on-duty-global-bar">
    <div class="container" style="padding-top:0;padding-bottom:0;">
        <strong>On duty:</strong>
        <?php $onDutyNames = array_map(function ($l) { return e($l['name']); }, $headerOnDutyLeaders); echo implode(', ', $onDutyNames); ?>
    </div>
</div>
<?php endif; ?>

<header class="site-header compact-site-header">
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-dark px-0 compact-navbar">
            <a class="navbar-brand site-brand compact-site-brand" href="<?= e($dashboardUrl) ?>" aria-label="<?= e(APP_NAME) ?> dashboard">
                <?php if (LOGO_URL !== ''): ?>
                    <span class="site-logo-frame">
                        <img src="<?= e(url('assets/logo-generator-linear-blackwhite-png.png')) ?>" alt="<?= e(APP_NAME) ?> logo" class="site-logo compact-site-logo">
                    </span>
                <?php else: ?>
                    <span class="site-logo-placeholder compact-logo-placeholder" aria-hidden="true">EB</span>
                <?php endif; ?>
            </a>

            <?php if ($user || $parentTeam): ?>
            <!-- Mobile hamburger button -->
            <button class="mobile-menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" id="mobileMenuBtn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
                <?php if ($user && $headerPendingCount > 0): ?>
                    <span class="nav-badge nav-badge-pulse" style="position:absolute;top:4px;right:4px;min-width:16px;height:16px;font-size:0.65rem;padding:0 3px;"><?= $headerPendingCount ?></span>
                <?php endif; ?>
            </button>
            <?php endif; ?>

            <!-- ===== DESKTOP NAV ===== -->
            <?php if ($user || $parentTeam): ?>
            <ul class="desktop-nav navbar-nav">
                <li class="nav-item<?= header_nav_active('dashboard.php') ?>">
                    <a class="nav-link" href="<?= e($dashboardUrl) ?>">Dashboard</a>
                </li>

                <li class="nav-item<?= header_nav_active('schedule.php') ?>">
                    <a class="nav-link" href="<?= e($parentTeam ? url('schedule.php?token=' . $parentTeam['parent_token']) : url('schedule.php')) ?>">Schedule</a>
                </li>

                <li class="nav-item<?= header_nav_active('leaders.php') ?>">
                    <a class="nav-link" href="<?= e($leadersUrl) ?>">Leaders</a>
                </li>

                <?php if ($user): ?>
                    <!-- Communication dropdown -->
                    <li class="nav-item dropdown<?= header_nav_active_group(['announcements_manage.php', 'email_all.php', 'contact.php']) ?>">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Communication</a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item<?= header_nav_active('announcements_manage.php') ?>" href="<?= e(url('announcements_manage.php')) ?>">Announcements</a>
                            <a class="dropdown-item<?= header_nav_active('email_all.php') ?>" href="<?= e(url('email_all.php')) ?>">Email</a>
                            <a class="dropdown-item<?= header_nav_active('contact.php') ?>" href="<?= e($contactUrl) ?>">Contact</a>
                        </div>
                    </li>

                    <!-- Teams dropdown -->
                    <li class="nav-item dropdown<?= header_nav_active_group(['team_links.php', 'team_locations.php', 'people.php', 'checkin_times_report.php']) ?>">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Teams</a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item<?= header_nav_active('team_links.php') ?>" href="<?= e(url('team_links.php')) ?>">Teams</a>
                            <a class="dropdown-item<?= header_nav_active('team_locations.php') ?>" href="<?= e(url('team_locations.php')) ?>">Locations</a>
                            <a class="dropdown-item<?= header_nav_active('people.php') ?>" href="<?= e(url('people.php')) ?>">People</a>
                            <a class="dropdown-item<?= header_nav_active('checkin_times_report.php') ?>" href="<?= e(url('checkin_times_report.php')) ?>">Check-in Times</a>
                        </div>
                    </li>

                    <!-- Finances dropdown -->
                    <li class="nav-item dropdown<?= header_nav_active_group(['leader_expenses_summary.php', 'expenses_manage.php', 'expenses_accounting.php']) ?>">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Finances</a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item<?= header_nav_active('leader_expenses_summary.php') ?>" href="<?= e(url('leader_expenses_summary.php')) ?>">Leader Expenses</a>
                            <a class="dropdown-item<?= header_nav_active('expenses_manage.php') ?>" href="<?= e(url('expenses_manage.php')) ?>">Explorer Expenses</a>
                            <a class="dropdown-item<?= header_nav_active('expenses_accounting.php') ?>" href="<?= e(url('expenses_accounting.php')) ?>">Accounting</a>
                        </div>
                    </li>

                    <!-- Pending check-ins -->
                    <?php if ($headerPendingCount > 0): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(url('team_links.php?tab=pending')) ?>">
                            Check-ins <span class="nav-badge nav-badge-pulse"><?= $headerPendingCount ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Parent view: simple contact link -->
                    <li class="nav-item<?= header_nav_active('contact.php') ?>">
                        <a class="nav-link" href="<?= e($contactUrl) ?>">Contact</a>
                    </li>
                <?php endif; ?>
            </ul>
            <?php endif; ?>

            <?php if ($user): ?>
                <!-- Desktop search -->
                <form class="global-search-form" action="<?= e(url('search.php')) ?>" method="get" role="search" aria-label="Search participants">
                    <div class="global-search-wrap">
                        <input type="search" name="q" class="global-search-input" placeholder="Search people..." aria-label="Search by name, email, phone, or DOB" autocomplete="off" value="<?= e($_GET['q'] ?? '') ?>">
                        <button type="submit" class="global-search-btn" aria-label="Search">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/></svg>
                        </button>
                    </div>
                </form>

                <!-- Desktop profile dropdown -->
                <div class="dropdown profile-menu">
                    <button class="profile-toggle dropdown-toggle" type="button" id="profileMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="profile-toggle-inner">
                            <?php if ($leaderPhoto !== ''): ?>
                                <img class="profile-avatar<?= $isLeaderOnDuty ? ' profile-avatar-on-duty' : '' ?>" src="<?= e($leaderPhoto) ?>" alt="Profile photo of <?= e($leaderName) ?>">
                            <?php else: ?>
                                <span class="profile-avatar<?= $isLeaderOnDuty ? ' profile-avatar-on-duty' : '' ?>" aria-hidden="true"><?= e(header_initials($leaderName)) ?></span>
                            <?php endif; ?>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right profile-dropdown" aria-labelledby="profileMenuButton">
                        <div class="profile-dropdown-header">
                            <span class="profile-dropdown-name"><?= e($leaderName) ?></span>
                            <?php if ($leaderEmail !== ''): ?>
                                <span class="profile-dropdown-email"><?= e($leaderEmail) ?></span>
                            <?php endif; ?>
                            <?php if ($headerDutyStatus): ?>
                                <span style="display:block;font-size:0.8rem;font-weight:800;margin-top:0.35rem;color:<?= $isLeaderOnDuty ? '#00703c' : '#505a5f' ?>;">
                                    <?php if ($isLeaderOnDuty): ?>
                                        &#x1F7E2; On duty
                                    <?php elseif ($headerDutyStatus['hours_until_next'] !== null): ?>
                                        <?php $hours = $headerDutyStatus['hours_until_next']; $dutyCountdown = $hours < 24 ? 'Next on duty in ' . $hours . ' hour' . ($hours !== 1 ? 's' : '') : 'Next on duty in ' . (int)floor($hours / 24) . ' day' . ((int)floor($hours / 24) !== 1 ? 's' : ''); ?>
                                        &#x26AA; <?= e($dutyCountdown) ?>
                                    <?php else: ?>
                                        &#x26AA; Off duty
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="dropdown-item" data-toggle="modal" data-target="#leaderProfileModal">Edit profile</button>
                        <a class="dropdown-item" href="<?= e(url('analytics.php')) ?>">Analytics</a>
                        <a class="dropdown-item" href="<?= e(url('parent_engagement_analytics.php')) ?>">Parent Engagement</a>
                        <button type="button" class="dropdown-item" data-push-role="toggle" id="pushToggleDropdown">Enable Notifications</button>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="<?= e(url('help.php')) ?>">Help Guide</a>
                        <a class="dropdown-item" href="<?= e(url('logout.php')) ?>">Sign out</a>
                    </div>
                </div>
            <?php endif; ?>
        </nav>
    </div>

    <?php if ($profileError): ?>
        <div class="container"><div class="alert alert-danger profile-alert"><?= e($profileError) ?></div></div>
    <?php endif; ?>
    <?php if ($profileSuccess): ?>
        <div class="container"><div class="alert alert-success profile-alert"><?= e($profileSuccess) ?></div></div>
    <?php endif; ?>
</header>



<!-- ===== MOBILE SIDEBAR ===== -->
<?php if ($user || $parentTeam): ?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="mobile-sidebar" id="mobileSidebar" aria-label="Main navigation" role="navigation">
    <div class="sidebar-header">
        <?php if (LOGO_URL !== ''): ?>
            <img src="<?= e(url('assets/logo-generator-linear-blackwhite-png.png')) ?>" alt="<?= e(APP_NAME) ?>" class="site-logo">
        <?php else: ?>
            <span style="color:#fff;font-weight:900;font-size:1.1rem;"><?= e(APP_NAME) ?></span>
        <?php endif; ?>
        <button class="sidebar-close" id="sidebarClose" aria-label="Close menu">&times;</button>
    </div>

    <?php if ($user): ?>
    <div class="sidebar-search">
        <form action="<?= e(url('search.php')) ?>" method="get" role="search">
            <div class="sidebar-search-wrap">
                <input type="search" name="q" class="sidebar-search-input" placeholder="Search people..." aria-label="Search" autocomplete="off" value="<?= e($_GET['q'] ?? '') ?>">
                <button type="submit" class="sidebar-search-btn" aria-label="Search">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/></svg>
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="sidebar-body">
        <ul class="sidebar-nav">
            <li><a class="sidebar-link<?= header_nav_active('dashboard.php') ?>" href="<?= e($dashboardUrl) ?>">Dashboard</a></li>
            <li><a class="sidebar-link<?= header_nav_active('schedule.php') ?>" href="<?= e($parentTeam ? url('schedule.php?token=' . $parentTeam['parent_token']) : url('schedule.php')) ?>">Schedule</a></li>
            <li><a class="sidebar-link<?= header_nav_active('leaders.php') ?>" href="<?= e($leadersUrl) ?>">Leaders</a></li>

            <?php if ($user): ?>
                <!-- Pending check-ins -->
                <?php if ($headerPendingCount > 0): ?>
                <li>
                    <a class="sidebar-link" href="<?= e(url('team_links.php?tab=pending')) ?>">
                        Check-ins <span class="nav-badge nav-badge-pulse"><?= $headerPendingCount ?></span>
                    </a>
                </li>
                <?php endif; ?>

                <li class="sidebar-divider"></li>

                <!-- Communication group -->
                <li>
                    <button class="sidebar-group-label<?= header_nav_active_group(['announcements_manage.php', 'email_all.php', 'contact.php']) ?>" aria-expanded="false" data-sidebar-group="communication">
                        Communication <span class="group-arrow">&#9654;</span>
                    </button>
                    <ul class="sidebar-sub" id="sidebarGroupCommunication">
                        <li><a class="sidebar-link<?= header_nav_active('announcements_manage.php') ?>" href="<?= e(url('announcements_manage.php')) ?>">Announcements</a></li>
                        <li><a class="sidebar-link<?= header_nav_active('email_all.php') ?>" href="<?= e(url('email_all.php')) ?>">Email</a></li>
                        <li><a class="sidebar-link<?= header_nav_active('contact.php') ?>" href="<?= e($contactUrl) ?>">Contact</a></li>
                    </ul>
                </li>

                <!-- Teams group -->
                <li>
                    <button class="sidebar-group-label<?= header_nav_active_group(['team_links.php', 'team_locations.php', 'people.php', 'checkin_times_report.php']) ?>" aria-expanded="false" data-sidebar-group="teams">
                        Teams <span class="group-arrow">&#9654;</span>
                    </button>
                    <ul class="sidebar-sub" id="sidebarGroupTeams">
                        <li><a class="sidebar-link<?= header_nav_active('team_links.php') ?>" href="<?= e(url('team_links.php')) ?>">Teams</a></li>
                        <li><a class="sidebar-link<?= header_nav_active('team_locations.php') ?>" href="<?= e(url('team_locations.php')) ?>">Locations</a></li>
                        <li><a class="sidebar-link<?= header_nav_active('people.php') ?>" href="<?= e(url('people.php')) ?>">People</a></li>
                        <li><a class="sidebar-link<?= header_nav_active('checkin_times_report.php') ?>" href="<?= e(url('checkin_times_report.php')) ?>">Check-in Times</a></li>
                    </ul>
                </li>

                <li class="sidebar-divider"></li>

                <!-- Finances group -->
                <li>
                    <button class="sidebar-group-label<?= header_nav_active_group(['leader_expenses_summary.php', 'expenses_manage.php', 'expenses_accounting.php']) ?>" aria-expanded="false" data-sidebar-group="finances">
                        Finances <span class="group-arrow">&#9654;</span>
                    </button>
                    <ul class="sidebar-sub" id="sidebarGroupFinances">
                        <li><a class="sidebar-link<?= header_nav_active('leader_expenses_summary.php') ?>" href="<?= e(url('leader_expenses_summary.php')) ?>">Leader Expenses</a></li>
                        <li><a class="sidebar-link<?= header_nav_active('expenses_manage.php') ?>" href="<?= e(url('expenses_manage.php')) ?>">Explorer Expenses</a></li>
                        <li><a class="sidebar-link<?= header_nav_active('expenses_accounting.php') ?>" href="<?= e(url('expenses_accounting.php')) ?>">Accounting</a></li>
                    </ul>
                </li>

                <li class="sidebar-divider"></li>

                <li><a class="sidebar-link<?= header_nav_active('analytics.php') ?>" href="<?= e(url('analytics.php')) ?>">Analytics</a></li>
                <li><a class="sidebar-link" href="<?= e(url('parent_engagement_analytics.php')) ?>">Parent Engagement</a></li>
                <li><a class="sidebar-link" href="<?= e(url('help.php')) ?>">Help Guide</a></li>

                <li class="sidebar-divider"></li>

                <li>
                    <button class="sidebar-link" style="width:100%;text-align:left;background:transparent;border:0;cursor:pointer;" data-toggle="modal" data-target="#leaderProfileModal" id="sidebarEditProfile">Edit Profile</button>
                </li>
                <li><a class="sidebar-link" href="<?= e(url('logout.php')) ?>">Sign out</a></li>
            <?php else: ?>
                <!-- Parent view -->
                <li><a class="sidebar-link<?= header_nav_active('contact.php') ?>" href="<?= e($contactUrl) ?>">Contact</a></li>
            <?php endif; ?>
        </ul>
    </div>

    <?php if ($user): ?>
    <div class="sidebar-profile">
        <?php if ($leaderPhoto !== ''): ?>
            <img class="sidebar-profile-avatar<?= $isLeaderOnDuty ? ' profile-avatar-on-duty' : '' ?>" src="<?= e($leaderPhoto) ?>" alt="">
        <?php else: ?>
            <span class="sidebar-profile-avatar<?= $isLeaderOnDuty ? ' profile-avatar-on-duty' : '' ?>"><?= e(header_initials($leaderName)) ?></span>
        <?php endif; ?>
        <div class="sidebar-profile-info">
            <span class="sidebar-profile-name"><?= e($leaderName) ?></span>
            <span class="sidebar-profile-duty"><?= $isLeaderOnDuty ? '&#x1F7E2; On duty' : '&#x26AA; Off duty' ?></span>
        </div>
    </div>
    <?php endif; ?>
</aside>
<?php endif; ?>

<?php if (!$user && !$parentTeam): ?>
<header class="site-header" style="display:none;"><!-- placeholder for unauthenticated, login link is shown inline --></header>
<?php endif; ?>

<?php if ($user): ?>
<!-- ===== PROFILE MODAL ===== -->
<div class="modal fade" id="leaderProfileModal" tabindex="-1" role="dialog" aria-labelledby="leaderProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="header_action" value="update_profile">
                <input type="hidden" name="header_profile_csrf" value="<?= e($headerProfileCsrf) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="leaderProfileModalLabel">Edit profile</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="profile-form-note">These details are used on leader profiles and update posts where applicable.</div>
                    <div class="text-center text-md-left">
                        <?php if ($leaderPhoto !== ''): ?>
                            <img class="profile-modal-photo" src="<?= e($leaderPhoto) ?>" alt="Profile photo of <?= e($leaderName) ?>">
                        <?php else: ?>
                            <span class="profile-modal-photo" aria-hidden="true"><?= e(header_initials($leaderName)) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="profile_name">Name</label>
                            <input class="form-control" id="profile_name" name="profile_name" value="<?= e($leaderName) ?>" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="profile_email">Email address</label>
                            <input class="form-control" id="profile_email" name="profile_email" type="email" value="<?= e($leaderEmail) ?>" disabled>
                        </div>
                    </div>
                    <?php if ($leaderBioColumn): ?>
                        <div class="form-group">
                            <label for="profile_bio">Bio / profile</label>
                            <textarea class="form-control" id="profile_bio" name="profile_bio" rows="5"><?= e($leaderBio) ?></textarea>
                        </div>
                    <?php endif; ?>
                    <?php if (header_column_exists($pdo, 'leaders', 'photo_url')): ?>
                        <div class="form-group">
                            <label for="profile_photo">Profile photo</label>
                            <input class="form-control" id="profile_photo" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                            <small class="form-text text-muted">JPG, PNG, WEBP or GIF. Maximum 5MB. Leave blank to keep the current photo.</small>
                        </div>
                    <?php endif; ?>
                    <?php if (!$leaderBioColumn): ?>
                        <div class="alert alert-warning">No bio/profile column was found on the leaders table. Add a column named <code>bio</code> if you want leaders to edit their profile text here.</div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save profile</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== SIDEBAR JS ===== -->
<script>
(function() {
    var btn = document.getElementById('mobileMenuBtn');
    var sidebar = document.getElementById('mobileSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var closeBtn = document.getElementById('sidebarClose');
    if (!btn || !sidebar) return;

    function openSidebar() {
        sidebar.classList.add('is-open');
        if (overlay) overlay.classList.add('is-visible');
        btn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        // Focus first link
        var firstLink = sidebar.querySelector('.sidebar-link, .sidebar-search-input');
        if (firstLink) setTimeout(function() { firstLink.focus(); }, 100);
    }

    function closeSidebar() {
        sidebar.classList.remove('is-open');
        if (overlay) overlay.classList.remove('is-visible');
        btn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        btn.focus();
    }

    btn.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('is-open')) closeSidebar();
    });

    // Sidebar accordion groups
    var groupBtns = sidebar.querySelectorAll('[data-sidebar-group]');
    groupBtns.forEach(function(groupBtn) {
        var groupName = groupBtn.getAttribute('data-sidebar-group');
        var subMenu = document.getElementById('sidebarGroup' + groupName.charAt(0).toUpperCase() + groupName.slice(1));
        // Auto-expand if has active child
        if (groupBtn.classList.contains('active') && subMenu) {
            subMenu.classList.add('expanded');
            groupBtn.classList.add('expanded');
            groupBtn.setAttribute('aria-expanded', 'true');
        }
        groupBtn.addEventListener('click', function() {
            if (!subMenu) return;
            var isExpanded = subMenu.classList.contains('expanded');
            subMenu.classList.toggle('expanded');
            groupBtn.classList.toggle('expanded');
            groupBtn.setAttribute('aria-expanded', String(!isExpanded));
        });
    });

    // Close sidebar when profile modal opens (mobile)
    var editProfileBtn = document.getElementById('sidebarEditProfile');
    if (editProfileBtn) {
        editProfileBtn.addEventListener('click', function() {
            closeSidebar();
        });
    }

    // Swipe to close
    var touchStartX = 0;
    sidebar.addEventListener('touchstart', function(e) {
        touchStartX = e.touches[0].clientX;
    }, { passive: true });
    sidebar.addEventListener('touchend', function(e) {
        var touchEndX = e.changedTouches[0].clientX;
        if (touchStartX - touchEndX > 60) closeSidebar();
    }, { passive: true });
})();
</script>
