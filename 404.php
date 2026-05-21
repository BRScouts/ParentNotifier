<?php
require_once __DIR__ . '/config.php';

http_response_code(404);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Page not found - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">

    <style>
        body {
            background: #f3f2f1;
        }

        .error-page {
            min-height: 70vh;
            display: flex;
            align-items: center;
            padding: 3rem 0;
        }

        .error-panel {
            border: 2px solid #d8d8d8;
            background: #ffffff;
            padding: 2rem;
            max-width: 760px;
        }

        .error-code {
            display: inline-block;
            background: #7413dc;
            color: #ffffff;
            font-weight: 900;
            font-size: 1rem;
            padding: 0.35rem 0.65rem;
            margin-bottom: 1rem;
        }

        .error-panel h1 {
            font-weight: 900;
            margin-bottom: 0.75rem;
        }

        .error-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .error-note {
            border-left: 8px solid #1d70b8;
            background: #eef7ff;
            padding: 1rem;
            margin-top: 1.5rem;
        }
    </style>
</head>

<body>

<main id="main-content" class="error-page">
    <div class="container">
        <section class="error-panel">
            <span class="error-code">404</span>

            <h1>Page not found</h1>

            <p class="lead">
                The page you are looking for could not be found.
            </p>

            <p>
                The link may be incorrect, expired, or the page may have moved.
            </p>

            <div class="error-note">
                <strong>Parent links:</strong>
                If you are trying to view team updates, please use the private link provided by the trip team.
            </div>

            <div class="error-actions">
                <a class="btn btn-primary" href="<?= e(url('login.php')) ?>">
                    Leader login
                </a>

                <a class="btn btn-outline-primary" href="<?= e(url('contact.php')) ?>">
                    Contact us
                </a>
            </div>
        </section>
    </div>
</main>

</body>
</html>