<?php
/**
 * Explorer Portal - Token Validation Error Page
 *
 * Include this file when explorer token validation fails.
 * It renders a standalone error page (no Navigation_Header) and exits.
 *
 * Accepts an optional $error_message variable. If not set, a default message is used.
 *
 * Usage:
 *   $team = explorer_fetch_team($pdo, $token);
 *   if (!$team) {
 *       include __DIR__ . '/explorer_error.php';
 *   }
 */

http_response_code(404);

$error_message = $error_message
    ?? 'This link is not valid. Please check the link you were given or contact the leadership team.';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Portal Unavailable - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">

    <style>
        body {
            background: #f3f2f1;
            color: #1d1d1d;
        }

        .error-hero {
            background: #7413dc;
            color: #ffffff;
            padding: 1.5rem 0;
            margin-bottom: 2rem;
        }

        .error-hero h1 {
            color: #ffffff;
            font-weight: 900;
            margin: 0;
        }

        .error-panel {
            border: 2px solid #d8d8d8;
            background: #ffffff;
            padding: 2rem;
            max-width: 600px;
        }

        .error-panel h2 {
            font-weight: 900;
            margin-bottom: 1rem;
        }

        .error-panel p {
            font-size: 1.1rem;
            margin-bottom: 0;
        }
    </style>
</head>

<body>

<header class="error-hero">
    <div class="container">
        <h1><?= e(APP_NAME) ?></h1>
    </div>
</header>

<main class="container">
    <section class="error-panel">
        <h2>Unable to access portal</h2>
        <p><?= e($error_message) ?></p>
    </section>
</main>

</body>
</html>
<?php
exit;
