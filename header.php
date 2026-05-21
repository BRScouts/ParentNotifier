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

        $stmt->execute([
            $table,
            $column,
        ]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function header_media_url(?string $path): string
{
    $path = trim((string)$path);

    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return url($path);
}

function header_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $letters = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= strtoupper(substr($part, 0, 1));
        }

        if (strlen($letters) >= 2) {
            break;
        }
    }

    return $letters !== '' ? $letters : '?';
}

function header_leader_bio_column(PDO $pdo): ?string
{
    foreach (['bio', 'blurb', 'profile', 'description'] as $column) {
        if (header_column_exists($pdo, 'leaders', $column)) {
            return $column;
        }
    }

    return null;
}

function header_fetch_leader(PDO $pdo, int $leaderId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM leaders
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->execute([$leaderId]);
    $leader = $stmt->fetch();

    return $leader ?: null;
}

function header_handle_profile_upload(string $fieldName, ?string $existingPath = null): ?string
{
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return $existingPath;
    }

    $file = $_FILES[$fieldName];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existingPath;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile photo upload failed.');
    }

    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Profile photo must be smaller than 5MB.');
    }

    $tmpName = $file['tmp_name'] ?? '';

    if (!is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded profile photo.');
    }

    $imageInfo = getimagesize($tmpName);

    if ($imageInfo === false) {
        throw new RuntimeException('Please upload a valid image file.');
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $mimeType = $imageInfo['mime'] ?? '';

    if (!isset($allowedMimeTypes[$mimeType])) {
        throw new RuntimeException('Profile photo must be JPG, PNG, WEBP or GIF.');
    }

    $uploadDir = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/leaders/';
    $publicPath = 'assets/leaders/';

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create leader upload directory.');
        }
    }

    $extension = $allowedMimeTypes[$mimeType];
    $filename = 'leader-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $destination = rtrim($uploadDir, '/') . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Could not save uploaded profile photo.');
    }

    return $publicPath . $filename;
}

if (empty($_SESSION['header_profile_csrf'])) {
    $_SESSION['header_profile_csrf'] = bin2hex(random_bytes(32));
}

$headerProfileCsrf = $_SESSION['header_profile_csrf'];

if (
    $user
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['header_action'] ?? '') === 'update_profile'
) {
    try {
        if (
            empty($_POST['header_profile_csrf'])
            || !hash_equals($_SESSION['header_profile_csrf'], (string)$_POST['header_profile_csrf'])
        ) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        $leaderId = (int)($user['id'] ?? 0);

        if ($leaderId <= 0) {
            throw new RuntimeException('Leader profile could not be found.');
        }

        $existingLeader = header_fetch_leader($pdo, $leaderId);

        if (!$existingLeader) {
            throw new RuntimeException('Leader profile could not be found.');
        }

        $name = trim($_POST['profile_name'] ?? '');
        $email = trim($_POST['profile_email'] ?? '');
        $bio = trim($_POST['profile_bio'] ?? '');

        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email address is not valid.');
        }

        $bioColumn = header_leader_bio_column($pdo);
        $hasPhotoColumn = header_column_exists($pdo, 'leaders', 'photo_url');

        $updates = [
            'name = ?',
        ];

        $params = [
            $name,
        ];

        if (header_column_exists($pdo, 'leaders', 'email')) {
            $updates[] = 'email = ?';
            $params[] = $email !== '' ? $email : null;
        }

        if ($bioColumn) {
            $updates[] = $bioColumn . ' = ?';
            $params[] = $bio !== '' ? $bio : null;
        }

        if ($hasPhotoColumn) {
            $newPhotoPath = header_handle_profile_upload(
                'profile_photo',
                $existingLeader['photo_url'] ?? null
            );

            $updates[] = 'photo_url = ?';
            $params[] = $newPhotoPath;
        }

        if (header_column_exists($pdo, 'leaders', 'updated_at')) {
            $updates[] = 'updated_at = NOW()';
        }

        $params[] = $leaderId;

        $stmt = $pdo->prepare(
            'UPDATE leaders
             SET ' . implode(', ', $updates) . '
             WHERE id = ?'
        );

        $stmt->execute($params);

        $_SESSION['header_profile_success'] = 'Profile updated.';

        redirect(basename($_SERVER['REQUEST_URI'] ?? 'dashboard.php'));
    } catch (Throwable $exception) {
        $_SESSION['header_profile_error'] = $exception->getMessage();
        redirect(basename($_SERVER['REQUEST_URI'] ?? 'dashboard.php'));
    }
}

if (!empty($_SESSION['header_profile_error'])) {
    $profileError = $_SESSION['header_profile_error'];
    unset($_SESSION['header_profile_error']);
}

if (!empty($_SESSION['header_profile_success'])) {
    $profileSuccess = $_SESSION['header_profile_success'];
    unset($_SESSION['header_profile_success']);
}

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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css?v=11')) ?>">

    <style>
        .site-header {
            background: #7413dc;
            color: #ffffff;
            position: relative;
            z-index: 1030;
        }

        .compact-navbar {
            min-height: 80px;
            padding-top: 0;
            padding-bottom: 0;
        }

        .site-brand {
            display: flex;
            align-items: center;
            height: 80px;
            padding: 0;
            margin: 0 1.15rem 0 0;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            line-height: 1;
            flex: 0 0 auto;
        }

        .site-logo-frame {
            width: 225px;
            height: 80px;
            max-height: 80px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            overflow: visible;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            padding: 0;
            margin: 0;
            line-height: 1;
            flex: 0 0 225px;
        }

        .site-logo {
            width: 225px !important;
            height: auto !important;
            max-width: none !important;
            max-height: none !important;
            object-fit: contain;
            object-position: left center;
            display: block;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 0 !important;
            line-height: 1;
            transform: scale(1.14);
            transform-origin: left center;
        }

        .site-logo-placeholder {
            width: 58px;
            height: 58px;
            min-width: 58px;
            min-height: 58px;
            border: 2px solid #ffffff;
            background: transparent;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1.15rem;
            line-height: 1;
            box-shadow: none;
            padding: 0;
            margin: 0;
        }

        .main-nav-wrap {
            align-items: center;
        }

        .compact-nav {
            align-items: center;
            gap: 0.15rem;
        }

        .compact-nav .nav-link {
            color: #ffffff !important;
            font-weight: 850;
            font-size: 0.94rem;
            padding: 0.42rem 0.62rem !important;
            border-radius: 0;
            white-space: nowrap;
            text-decoration: none;
            line-height: 1.2;
            border: 2px solid transparent;
        }

        .compact-nav .nav-link:hover,
        .compact-nav .nav-link:focus {
            background: rgba(255, 255, 255, 0.16);
            color: #ffffff !important;
            text-decoration: none;
            border-color: rgba(255, 255, 255, 0.35);
        }

        .compact-nav .nav-item.active .nav-link {
            background: #ffffff;
            color: #7413dc !important;
            text-decoration: none;
            border-color: #ffffff;
            border-radius: 0;
        }

        .profile-menu {
            margin-left: 0.65rem;
            position: relative;
        }

        .profile-toggle {
            border: 2px solid rgba(255, 255, 255, 0.75);
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-radius: 0;
            padding: 0.2rem 0.35rem 0.2rem 0.2rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            line-height: 1;
            cursor: pointer;
        }

        .profile-toggle:hover,
        .profile-toggle:focus {
            background: rgba(255, 255, 255, 0.2);
            outline: 3px solid #ffdd00;
            outline-offset: 2px;
        }

        .profile-avatar {
            width: 42px;
            height: 42px;
            min-width: 42px;
            min-height: 42px;
            border-radius: 0;
            border: 2px solid #ffffff;
            object-fit: cover;
            background: #4d0b95;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 0.9rem;
            overflow: hidden;
        }

        .profile-dropdown {
            border-radius: 0;
            border: 2px solid #1d1d1d;
            padding: 0;
            min-width: 230px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.22);
        }

        .profile-dropdown-header {
            padding: 0.8rem;
            border-bottom: 2px solid #d8d8d8;
            background: #f8f8f8;
        }

        .profile-dropdown-name {
            display: block;
            font-weight: 900;
            color: #1d1d1d;
        }

        .profile-dropdown-email {
            display: block;
            color: #505a5f;
            font-size: 0.9rem;
            word-break: break-word;
        }

        .profile-dropdown .dropdown-item {
            font-weight: 800;
            padding: 0.75rem 0.9rem;
            color: #1d1d1d;
            text-decoration: none;
            border-radius: 0;
        }

        .profile-dropdown .dropdown-item:hover,
        .profile-dropdown .dropdown-item:focus {
            background: #f3f2f1;
            color: #1d1d1d;
        }

        .compact-navbar-toggler {
            padding: 0.25rem 0.5rem;
            border-width: 1px;
            margin-left: auto;
            border-radius: 0;
        }

        .profile-modal-photo {
            width: 96px;
            height: 96px;
            border-radius: 0;
            border: 2px solid #1d1d1d;
            object-fit: cover;
            background: #7413dc;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .profile-alert {
            border-radius: 0;
            border-width: 2px;
            margin: 1rem auto 0;
            max-width: 1140px;
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

        .profile-form-note {
            border-left: 6px solid #1d70b8;
            background: #eef7ff;
            padding: 0.75rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 1199.98px) {
            .site-logo-frame {
                width: 198px;
                flex-basis: 198px;
            }

            .site-logo {
                width: 198px !important;
                transform: scale(1.12);
            }

            .compact-nav .nav-link {
                font-size: 0.9rem;
                padding-left: 0.48rem !important;
                padding-right: 0.48rem !important;
            }
        }

        @media (max-width: 991.98px) {
            .compact-navbar {
                min-height: 74px;
                padding-top: 0;
                padding-bottom: 0;
            }

            .site-brand {
                height: 74px;
                max-height: 74px;
            }

            .site-logo-frame {
                width: 189px;
                height: 74px;
                max-height: 74px;
                flex-basis: 189px;
            }

            .site-logo {
                width: 189px !important;
                transform: scale(1.1);
            }

            #mainNav {
                background: #7413dc;
                border-top: 1px solid rgba(255, 255, 255, 0.28);
                padding: 0.75rem 0 1rem;
            }

            .compact-nav {
                align-items: stretch;
                gap: 0.25rem;
                margin-bottom: 0.75rem;
            }

            .compact-nav .nav-link {
                border-radius: 0;
                padding: 0.75rem 0.85rem !important;
                font-size: 1rem;
            }

            .compact-nav .nav-item.active .nav-link {
                border-left: 5px solid #ffdd00;
                background: rgba(255, 255, 255, 0.14);
                color: #ffffff !important;
            }

            .profile-menu {
                margin-left: 0;
                width: 100%;
            }

            .profile-toggle {
                width: 100%;
                justify-content: space-between;
                border-radius: 0;
                padding: 0.55rem 0.75rem;
            }

            .profile-toggle-inner {
                display: flex;
                align-items: center;
                gap: 0.55rem;
            }

            .profile-dropdown {
                position: static !important;
                float: none;
                width: 100%;
                transform: none !important;
                margin-top: 0.5rem;
                border-radius: 0;
            }
        }

        @media (max-width: 575.98px) {
            .site-logo-frame {
                width: 167px;
                flex-basis: 167px;
            }

            .site-logo {
                width: 167px !important;
                transform: scale(1.08);
            }

            .profile-avatar {
                width: 38px;
                height: 38px;
                min-width: 38px;
                min-height: 38px;
            }
        }

        @media (max-width: 380px) {
            .site-logo-frame {
                width: 144px;
                flex-basis: 144px;
            }

            .site-logo {
                width: 144px !important;
                transform: scale(1.06);
            }
        }
    </style>
</head>

<body>
<header class="site-header compact-site-header">
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-dark px-0 compact-navbar">
            <a
                class="navbar-brand site-brand compact-site-brand"
                href="<?= e($dashboardUrl) ?>"
                aria-label="<?= e(APP_NAME) ?> dashboard"
            >
                <?php if (LOGO_URL !== ''): ?>
                    <span class="site-logo-frame">
                        <img
                            src="https://exbelt2026.irvalscouts.org.uk/assets/logo.png"
                            alt="<?= e(APP_NAME) ?> logo"
                            class="site-logo compact-site-logo"
                        >
                    </span>
                <?php else: ?>
                    <span
                        class="site-logo-placeholder compact-logo-placeholder"
                        aria-hidden="true"
                    >
                        EB
                    </span>
                <?php endif; ?>
            </a>

            <button
                class="navbar-toggler compact-navbar-toggler"
                type="button"
                data-toggle="collapse"
                data-target="#mainNav"
                aria-controls="mainNav"
                aria-expanded="false"
                aria-label="Toggle navigation"
            >
                <span class="navbar-toggler-icon"></span>
            </button>

            <div id="mainNav" class="collapse navbar-collapse main-nav-wrap">
                <ul class="navbar-nav ml-auto compact-nav">

                    <?php if ($user || $parentTeam): ?>
                        <li class="nav-item<?= header_nav_active('dashboard.php') ?>">
                            <a class="nav-link" href="<?= e($dashboardUrl) ?>">
                                Dashboard
                            </a>
                        </li>

                        <li class="nav-item<?= header_nav_active('leaders.php') ?>">
                            <a class="nav-link" href="<?= e($leadersUrl) ?>">
                                Leaders
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($user): ?>
                        <li class="nav-item<?= header_nav_active('team_links.php') ?>">
                            <a class="nav-link" href="<?= e(url('team_links.php')) ?>">
                                Teams
                            </a>
                        </li>

                        <li class="nav-item<?= header_nav_active('team_locations.php') ?>">
                            <a class="nav-link" href="<?= e(url('team_locations.php')) ?>">
                                Locations
                            </a>
                        </li>

                        <li class="nav-item<?= header_nav_active('email_all.php') ?>">
                            <a class="nav-link" href="<?= e(url('email_all.php')) ?>">
                                Email
                            </a>
                        </li>

                        <li class="nav-item<?= header_nav_active('people.php') ?>">
                            <a class="nav-link" href="<?= e(url('people.php')) ?>">
                                People
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($user || $parentTeam): ?>
                        <li class="nav-item<?= header_nav_active('contact.php') ?>">
                            <a class="nav-link" href="<?= e($contactUrl) ?>">
                                Contact
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if (!$user && !$parentTeam): ?>
                        <li class="nav-item<?= header_nav_active('login.php') ?>">
                            <a class="nav-link" href="<?= e(url('login.php')) ?>">
                                Leader login
                            </a>
                        </li>
                    <?php endif; ?>

                </ul>

                <?php if ($user): ?>
                    <div class="dropdown profile-menu">
                        <button
                            class="profile-toggle dropdown-toggle"
                            type="button"
                            id="profileMenuButton"
                            data-toggle="dropdown"
                            aria-haspopup="true"
                            aria-expanded="false"
                        >
                            <span class="profile-toggle-inner">
                                <?php if ($leaderPhoto !== ''): ?>
                                    <img
                                        class="profile-avatar"
                                        src="<?= e($leaderPhoto) ?>"
                                        alt="Profile photo of <?= e($leaderName) ?>"
                                    >
                                <?php else: ?>
                                    <span class="profile-avatar" aria-hidden="true">
                                        <?= e(header_initials($leaderName)) ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </button>

                        <div class="dropdown-menu dropdown-menu-right profile-dropdown" aria-labelledby="profileMenuButton">
                            <div class="profile-dropdown-header">
                                <span class="profile-dropdown-name">
                                    <?= e($leaderName) ?>
                                </span>

                                <?php if ($leaderEmail !== ''): ?>
                                    <span class="profile-dropdown-email">
                                        <?= e($leaderEmail) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <button
                                type="button"
                                class="dropdown-item"
                                data-toggle="modal"
                                data-target="#leaderProfileModal"
                            >
                                Edit profile
                            </button>

                            <a class="dropdown-item" href="<?= e(url('logout.php')) ?>">
                                Sign out
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </nav>
    </div>

    <?php if ($profileError): ?>
        <div class="container">
            <div class="alert alert-danger profile-alert">
                <?= e($profileError) ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($profileSuccess): ?>
        <div class="container">
            <div class="alert alert-success profile-alert">
                <?= e($profileSuccess) ?>
            </div>
        </div>
    <?php endif; ?>
</header>

<?php if ($user): ?>
    <div
        class="modal fade"
        id="leaderProfileModal"
        tabindex="-1"
        role="dialog"
        aria-labelledby="leaderProfileModalLabel"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="header_action" value="update_profile">
                    <input type="hidden" name="header_profile_csrf" value="<?= e($headerProfileCsrf) ?>">

                    <div class="modal-header">
                        <h5 class="modal-title" id="leaderProfileModalLabel">
                            Edit profile
                        </h5>

                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body">
                        <div class="profile-form-note">
                            These details are used on leader profiles and update posts where applicable.
                        </div>

                        <div class="text-center text-md-left">
                            <?php if ($leaderPhoto !== ''): ?>
                                <img
                                    class="profile-modal-photo"
                                    src="<?= e($leaderPhoto) ?>"
                                    alt="Profile photo of <?= e($leaderName) ?>"
                                >
                            <?php else: ?>
                                <span class="profile-modal-photo" aria-hidden="true">
                                    <?= e(header_initials($leaderName)) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="profile_name">Name</label>
                                <input
                                    class="form-control"
                                    id="profile_name"
                                    name="profile_name"
                                    value="<?= e($leaderName) ?>"
                                    required
                                >
                            </div>

                            <div class="form-group col-md-6">
                                <label for="profile_email">Email address</label>
                                <input 
                                    class="form-control"
                                    id="profile_email"
                                    name="profile_email"
                                    type="email"
                                    value="<?= e($leaderEmail) ?>" disabled
                                >
                            </div>
                        </div>

                        <?php if ($leaderBioColumn): ?>
                            <div class="form-group">
                                <label for="profile_bio">Bio / profile</label>
                                <textarea
                                    class="form-control"
                                    id="profile_bio"
                                    name="profile_bio"
                                    rows="5"
                                ><?= e($leaderBio) ?></textarea>
                            </div>
                        <?php endif; ?>

                        <?php if (header_column_exists($pdo, 'leaders', 'photo_url')): ?>
                            <div class="form-group">
                                <label for="profile_photo">Profile photo</label>
                                <input
                                    class="form-control"
                                    id="profile_photo"
                                    name="profile_photo"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,image/gif"
                                >

                                <small class="form-text text-muted">
                                    JPG, PNG, WEBP or GIF. Maximum 5MB. Leave blank to keep the current photo.
                                </small>
                            </div>
                        <?php endif; ?>

                        <?php if (!$leaderBioColumn): ?>
                            <div class="alert alert-warning">
                                No bio/profile column was found on the leaders table. Add a column named
                                <code>bio</code> if you want leaders to edit their profile text here.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">
                            Cancel
                        </button>

                        <button type="submit" class="btn btn-primary">
                            Save profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>