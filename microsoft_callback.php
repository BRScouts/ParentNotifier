<?php
require_once __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (is_logged_in()) {
    $destination = $_SESSION['redirect_after_login'] ?? '';
    unset($_SESSION['redirect_after_login']);

    if ($destination !== '') {
        header('Location: ' . $destination);
        exit;
    }

    redirect('dashboard.php');
}

try {
    if (!empty($_GET['error'])) {
        $description = $_GET['error_description'] ?? $_GET['error'];
        throw new RuntimeException((string)$description);
    }

    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';

    if ($code === '') {
        throw new RuntimeException('Microsoft did not return an authorisation code.');
    }

    microsoft_login_from_callback($code, $state);

    $destination = $_SESSION['redirect_after_login'] ?? '';
    unset($_SESSION['redirect_after_login']);

    if ($destination !== '') {
        header('Location: ' . $destination);
        exit;
    }

    redirect('dashboard.php');
} catch (Throwable $exception) {
    $_SESSION['login_error'] = $exception->getMessage();
    redirect('login.php');
}