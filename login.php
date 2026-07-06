<?php
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    $destination = $_SESSION['redirect_after_login'] ?? '';
    unset($_SESSION['redirect_after_login']);

    if ($destination !== '') {
        header('Location: ' . $destination);
        exit;
    }

    redirect('dashboard.php');
}

$error = '';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!empty($_SESSION['login_error'])) {
    $error = (string)$_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (login_leader($email, $password)) {
        $destination = $_SESSION['redirect_after_login'] ?? '';
        unset($_SESSION['redirect_after_login']);

        if ($destination !== '') {
            header('Location: ' . $destination);
            exit;
        }

        redirect('dashboard.php');
    }

    $error = 'The email address or password was not recognised.';
}

include __DIR__ . '/header.php';
?>

<style>
    .login-hero {
        background: #7413dc;
        color: #ffffff;
        padding: 1.5rem 0;
        margin-bottom: 2rem;
    }

    .login-hero h1,
    .login-hero p {
        color: #ffffff;
    }

    .login-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 360px;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 900px) {
        .login-layout {
            grid-template-columns: 1fr;
        }
    }

    .login-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.5rem;
    }

    .login-panel h2 {
        margin-top: 0;
        font-weight: 900;
    }

    .sso-button {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.65rem;
        width: 100%;
        border: 2px solid #1d1d1d;
        background: #ffffff;
        color: #1d1d1d;
        padding: 0.75rem 1rem;
        font-weight: 900;
        text-decoration: none;
    }

    .sso-button:hover,
    .sso-button:focus {
        background: #f3f2f1;
        color: #1d1d1d;
        text-decoration: none;
    }

    .sso-icon {
        width: 22px;
        height: 22px;
        display: inline-grid;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: 1fr 1fr;
        gap: 2px;
    }

    .sso-icon span {
        display: block;
    }

    .sso-red {
        background: #f25022;
    }

    .sso-green {
        background: #7fba00;
    }

    .sso-blue {
        background: #00a4ef;
    }

    .sso-yellow {
        background: #ffb900;
    }

    .login-divider {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin: 1.5rem 0;
        color: #505a5f;
        font-weight: 800;
    }

    .login-divider::before,
    .login-divider::after {
        content: "";
        height: 1px;
        background: #d8d8d8;
        flex: 1;
    }

    .login-note {
        border-left: 6px solid #1d70b8;
        background: #eef7ff;
        padding: 1rem;
    }

    .login-note p:last-child {
        margin-bottom: 0;
    }
</style>

<section class="login-hero">
    <div class="container">
        <h1>Leader login</h1>
        <p class="lead mb-0">
            Sign in to add Explorer Belt updates, team locations and leader schedule details.
        </p>
    </div>
</section>

<main id="main-content" class="container my-5">

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="login-layout">
        <section class="login-panel">
            <h2>Sign in with Microsoft</h2>

            

            <a class="sso-button" href="<?= e(url('microsoft_start.php')) ?>">
                <span class="sso-icon" aria-hidden="true">
                    <span class="sso-red"></span>
                    <span class="sso-green"></span>
                    <span class="sso-blue"></span>
                    <span class="sso-yellow"></span>
                </span>
                Continue with Microsoft
            </a>

            <div class="login-divider">
                or
            </div>

            <h2>Sign in with password</h2>

            <form method="post">
                <div class="form-group">
                    <label for="email">Email address</label>
                    <input
                        class="form-control"
                        id="email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        class="form-control"
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button class="btn btn-primary" type="submit">
                    Sign in
                </button>
            </form>
        </section>

        <aside class="login-note">
            <h2>Access note</h2>

            <p>
                Microsoft sign-in only works with a BR Scouts Office 365 Account.
            </p>

            <p>
                Existing leader passwords will continue to work.
            </p>
        </aside>
    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>