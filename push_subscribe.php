<?php
declare(strict_types=1);

/**
 * Push Subscription Endpoint
 * Accepts POST requests from the client-side push.js to save/remove push subscriptions.
 *
 * Expected JSON body:
 *   { "action": "subscribe", "endpoint": "...", "p256dh_key": "...", "auth_key": "..." }
 *   { "action": "unsubscribe", "endpoint": "..." }
 */

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Only leaders can subscribe to push notifications
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$user = current_user();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || empty($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body']);
    exit;
}

$pdo = db();
$leaderId = (int)$user['id'];
$action = trim((string)($input['action'] ?? ''));

// Ensure the push_subscriptions table exists
try {
    $tableCheck = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?'
    );
    $tableCheck->execute(['push_subscriptions']);

    if ((int)$tableCheck->fetchColumn() === 0) {
        $pdo->exec('
            CREATE TABLE push_subscriptions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                leader_id INT UNSIGNED NOT NULL,
                endpoint VARCHAR(500) NOT NULL,
                p256dh_key VARCHAR(255) NOT NULL,
                auth_key VARCHAR(255) NOT NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_push_leader_endpoint (leader_id, endpoint(191)),
                INDEX idx_push_leader (leader_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database setup failed']);
    exit;
}

// Handle subscribe
if ($action === 'subscribe') {
    $endpoint = trim((string)($input['endpoint'] ?? ''));
    $p256dhKey = trim((string)($input['p256dh_key'] ?? ''));
    $authKey = trim((string)($input['auth_key'] ?? ''));

    if ($endpoint === '' || $p256dhKey === '' || $authKey === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing subscription data']);
        exit;
    }

    // Validate endpoint is a URL
    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid endpoint URL']);
        exit;
    }

    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    try {
        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle re-subscriptions
        $stmt = $pdo->prepare(
            'INSERT INTO push_subscriptions
                (leader_id, endpoint, p256dh_key, auth_key, user_agent, created_at)
             VALUES
                (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                p256dh_key = VALUES(p256dh_key),
                auth_key = VALUES(auth_key),
                user_agent = VALUES(user_agent),
                created_at = NOW()'
        );

        $stmt->execute([
            $leaderId,
            $endpoint,
            $p256dhKey,
            $authKey,
            $userAgent,
        ]);

        echo json_encode(['success' => true, 'message' => 'Subscription saved']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save subscription']);
        exit;
    }
}

// Handle unsubscribe
if ($action === 'unsubscribe') {
    $endpoint = trim((string)($input['endpoint'] ?? ''));

    if ($endpoint === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing endpoint']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'DELETE FROM push_subscriptions
             WHERE leader_id = ? AND endpoint = ?'
        );
        $stmt->execute([$leaderId, $endpoint]);

        echo json_encode(['success' => true, 'message' => 'Subscription removed']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to remove subscription']);
        exit;
    }
}

// Unknown action
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
