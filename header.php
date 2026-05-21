<?php
require_once __DIR__ . '/auth.php';

$user = current_user();
$parentTeam = parent_access_team();

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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css?v=9')) ?>">

    <style>
        .site-header {
            background: #7413dc;
            height: 118px;
            min-height: 118px;
            max-height: 118px;
            overflow: visible;
        }

        .compact-navbar {
            height: 118px;
            min-height: 118px;
            max-height: 118px;
            padding-top: 0;
            padding-bottom: 0;
        }

        .site-brand {
            height: 118px;
            max-height: 118px;
            display: flex;
            align-items: center;
            padding: 0;
            margin: 0 1.5rem 0 0;
            background: transparent;
            border: 0;
            box-shadow: none;
            overflow: visible;
            line-height: 1;
            flex: 0 0 auto;
        }

        .site-logo-frame {
            width: 300px;
            height: 300px;
            max-height: 118px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            overflow: visible;
            background: transparent;
            border: 0;
            box-shadow: none;
            padding: 0;
            margin: 0;
            line-height: 1;
            flex: 0 0 300px;
        }

        .site-logo {
            width: 300px;
            height: auto;
            max-width: none;
            max-height: none;
            object-fit: contain;
            object-position: left center;
            display: block;
            background: transparent;
            border: 0;
            box-shadow: none;
            padding: 0;
            margin: 0;
            line-height: 1;
            transform: scale(1.18);
            transform-origin: left center;
        }

        .site-logo-placeholder {
            width: 72px;
            height: 72px;
            min-width: 72px;
            min-height: 72px;
            max-width: 72px;
            max-height: 72px;
            border: 2px solid #ffffff;
            background: transparent;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1.4rem;
            line-height: 1;
            box-shadow: none;
            padding: 0;
            margin: 0;
        }

        .compact-nav {
            align-items: center;
        }

        .compact-nav .nav-link {
            white-space: nowrap;
        }

        .compact-navbar-toggler {
            padding: 0.25rem 0.5rem;
            border-width: 1px;
        }

        @media (max-width: 991.98px) {
            .site-header {
                height: auto;
                max-height: none;
                overflow: visible;
            }

            .compact-navbar {
                height: auto;
                max-height: none;
                min-height: 118px;
            }

            .site-brand {
                height: 118px;
                max-height: 118px;
            }

            .site-logo-frame {
                width: 260px;
                flex-basis: 260px;
            }

            .site-logo {
                width: 260px;
                transform: scale(1.12);
            }

            #mainNav {
                background: #7413dc;
                padding-bottom: 1rem;
            }

            .compact-nav {
                align-items: flex-start;
            }
        }

        @media (max-width: 575.98px) {
            .site-logo-frame {
                width: 220px;
                flex-basis: 220px;
            }

            .site-logo {
                width: 220px;
                transform: scale(1.08);
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
                            src="https://exbelt2026.irvalscouts.org.uk/assets/logo-generator-linear-blackwhite-png.png"
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

            <div id="mainNav" class="collapse navbar-collapse">
                <ul class="navbar-nav ml-auto compact-nav">

                    <?php if ($user || $parentTeam): ?>
                        <li class="nav-item<?= header_nav_active('dashboard.php') ?>">
                            <a class="nav-link" href="<?= e($dashboardUrl) ?>">
                                Dashboard
                            </a>
                        </li>

                        <li class="nav-item<?= header_nav_active('leaders.php') ?>">
                            <a class="nav-link" href="<?= e($leadersUrl) ?>">
                                Leaders in country
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

                    <?php if ($user): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= e(url('logout.php')) ?>">
                                Sign out
                            </a>
                        </li>
                    <?php elseif (!$parentTeam): ?>
                        <li class="nav-item<?= header_nav_active('login.php') ?>">
                            <a class="nav-link" href="<?= e(url('login.php')) ?>">
                                Leader login
                            </a>
                        </li>
                    <?php endif; ?>

                </ul>
            </div>
        </nav>
    </div>
</header>