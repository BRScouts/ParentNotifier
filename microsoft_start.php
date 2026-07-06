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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $provider = microsoft_provider();

    $authorizationUrl = $provider->getAuthorizationUrl([
        'scope' => ['openid', 'profile', 'email', 'User.Read'],
        'prompt' => 'select_account',
    ]);

    $_SESSION['ms_oauth_state'] = $provider->getState();

    /**
     * IMPORTANT:
     * This is an external Microsoft URL.
     * Do not use redirect(), because your redirect() wraps paths with BASE_URL.
     */
    redirect_external($authorizationUrl);
} catch (Throwable $exception) {
    $_SESSION['login_error'] = $exception->getMessage();
    redirect('login.php');
}