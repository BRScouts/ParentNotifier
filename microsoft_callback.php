<?php
require_once __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';

try {
    $state = $_GET['state'] ?? '';
    $expectedState = $_SESSION['ms_oauth_state'] ?? '';

    unset($_SESSION['ms_oauth_state']);

    if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
        throw new RuntimeException('Microsoft sign-in could not be verified. Please try again.');
    }

    if (!empty($_GET['error'])) {
        $description = $_GET['error_description'] ?? $_GET['error'];
        throw new RuntimeException((string)$description);
    }

    $code = $_GET['code'] ?? '';

    if ($code === '') {
        throw new RuntimeException('Microsoft did not return an authorisation code.');
    }

    microsoft_login_with_code($code);

    redirect('dashboard.php');
} catch (Throwable $exception) {
    $_SESSION['login_error'] = $exception->getMessage();
    redirect('login.php');
}