<?php
/**
 * Explorer Portal - Shared Navigation Header
 *
 * Expects the following variables to be defined before inclusion:
 *   $pdo   - PDO database connection
 *   $team  - Team array from explorer_fetch_team()
 *   $token - The explorer token string
 *
 * Renders: <!doctype html>, <html>, <head>, <body>, and the navigation bar.
 * Does NOT close </body></html> — that is handled by explorer_footer.php.
 */

// Ensure announcements tables exist (all portal pages include this header)
ensure_announcements_tables($pdo);

// --- Badge count: unacknowledged announcements for this team ---
$explorerBadgeCount = 0;

if ($team) {
    try {
        $badgeStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM announcements a
             WHERE (a.team_id = :team_id OR a.team_id IS NULL)
               AND a.id NOT IN (
                   SELECT announcement_id FROM announcement_acknowledgements WHERE team_id = :team_id2
               )'
        );
        $badgeStmt->execute([
            ':team_id' => (int)$team['id'],
            ':team_id2' => (int)$team['id'],
        ]);
        $explorerBadgeCount = (int)$badgeStmt->fetchColumn();
    } catch (Throwable $e) {
        // Silently fail — badge will show 0
        $explorerBadgeCount = 0;
    }
}

// --- Active tab detection ---
$explorerCurrentPage = basename($_SERVER['SCRIPT_FILENAME'] ?? '');

function explorer_nav_active(string $filename): string
{
    global $explorerCurrentPage;
    return $explorerCurrentPage === $filename ? ' active' : '';
}

// --- Navigation links with token ---
$explorerTokenParam = urlencode($token);
$explorerNavLinks = [
    ['label' => 'Home',            'file' => 'explorer_portal.php'],
    ['label' => 'Check In',        'file' => 'explorer_checkin.php'],
    ['label' => 'Announcements',   'file' => 'explorer_announcements.php', 'badge' => true],
    ['label' => 'Contact & Emergency', 'file' => 'explorer_contact.php'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#7413dc">
    <title><?= e(APP_NAME) ?> - Explorer Portal</title>

    <link rel="manifest" href="<?= e(url('manifest.json')) ?>">
    <link rel="apple-touch-icon" href="<?= e(url('assets/logo.png')) ?>">

    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <?php if (!empty($loadLeaflet)): ?>
    <link rel="preconnect" href="https://unpkg.com" crossorigin>
    <?php endif; ?>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <?php if (!empty($loadLeaflet)): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.min.css')) ?>">

    <style>
        .explorer-navbar {
            background: #7413dc;
        }

        .explorer-navbar .navbar-brand {
            color: #ffffff;
            font-weight: 900;
            font-size: 1.05rem;
            white-space: nowrap;
            padding: 0.25rem 0;
        }

        .explorer-navbar .navbar-brand:hover,
        .explorer-navbar .navbar-brand:focus {
            color: #ffffff;
        }

        .explorer-navbar .navbar-brand img {
            height: 56px;
            width: auto;
            max-width: 200px;
            object-fit: contain;
        }

        .explorer-navbar .nav-link {
            color: #ffffff !important;
            font-weight: 800;
            font-size: 0.95rem;
            padding: 0.45rem 0.6rem !important;
            border: 2px solid transparent;
        }

        .explorer-navbar .nav-link:hover,
        .explorer-navbar .nav-link:focus {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff !important;
            text-decoration: none;
            border-color: rgba(255, 255, 255, 0.3);
        }

        .explorer-navbar .nav-item.active .nav-link {
            background: #ffffff;
            color: #7413dc !important;
            text-decoration: none;
            border-color: #ffffff;
        }

        .explorer-navbar .navbar-toggler {
            border-color: rgba(255, 255, 255, 0.6);
            padding: 0.4rem 0.6rem;
        }

        .explorer-navbar .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' width='30' height='30' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 0.9%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }

        .explorer-badge {
            font-size: 0.75rem;
            padding: 0.2em 0.5em;
            vertical-align: top;
            margin-left: 0.25rem;
        }

        @media (max-width: 767.98px) {
            .explorer-navbar .navbar-brand img {
                height: 48px;
                max-width: 180px;
            }

            .explorer-navbar .navbar-nav {
                margin-top: 0.5rem;
                padding-bottom: 0.5rem;
            }

            .explorer-navbar .nav-link {
                padding: 0.75rem 1rem !important;
                font-size: 1.05rem;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }

            .explorer-navbar .nav-item:last-child .nav-link {
                border-bottom: none;
            }

            .explorer-navbar .nav-item.active .nav-link {
                border-left: 5px solid #ffdd00;
                background: rgba(255, 255, 255, 0.12);
                color: #ffffff !important;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
        }
    </style>

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js').catch(function () {
                    // Service worker registration failed - not critical
                });
            });
        }
    </script>
</head>

<body>

<header>
    <nav class="navbar navbar-expand-md navbar-dark explorer-navbar">
        <div class="container">
            <a class="navbar-brand" href="<?= e(url('explorer_portal.php?token=' . $explorerTokenParam)) ?>">
                <?php if (defined('LOGO_URL') && LOGO_URL !== ''): ?>
                    <img src="<?= e(LOGO_URL) ?>" alt="<?= e(APP_NAME) ?>">
                <?php else: ?>
                    <?= e(APP_NAME) ?>
                <?php endif; ?>
            </a>

            <button
                class="navbar-toggler"
                type="button"
                data-toggle="collapse"
                data-target="#explorerNav"
                aria-controls="explorerNav"
                aria-expanded="false"
                aria-label="Toggle navigation"
            >
                <span class="navbar-toggler-icon"></span>
            </button>

            <div id="explorerNav" class="collapse navbar-collapse">
                <ul class="navbar-nav ml-auto">
                    <?php foreach ($explorerNavLinks as $navLink): ?>
                        <li class="nav-item<?= explorer_nav_active($navLink['file']) ?>">
                            <a class="nav-link" href="<?= e(url($navLink['file'] . '?token=' . $explorerTokenParam)) ?>">
                                <?= e($navLink['label']) ?>
                                <?php if (!empty($navLink['badge']) && $explorerBadgeCount > 0): ?>
                                    <span class="badge badge-warning explorer-badge"><?= (int)$explorerBadgeCount ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </nav>
</header>
