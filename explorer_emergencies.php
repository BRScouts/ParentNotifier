<?php
/**
 * Explorer Portal - Emergencies Page (Redirect)
 *
 * This page has been merged into explorer_contact.php.
 * Redirect any existing bookmarks or links.
 */

require_once __DIR__ . '/config.php';

$token = trim($_GET['token'] ?? '');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($token === '') {
    $token = $_SESSION['explorer_portal_token'] ?? '';
}

header('Location: ' . url('explorer_contact.php?token=' . urlencode($token)));
exit;
