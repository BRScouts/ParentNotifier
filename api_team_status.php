<?php
/**
 * AJAX endpoint to update a team's status.
 * Expects POST with JSON body: { "team_id": int, "status": string }
 * Returns JSON: { "success": bool, "error"?: string }
 */

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

if (is_readonly()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Read-only access cannot update team status.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$teamId = (int)($input['team_id'] ?? 0);
$status = trim((string)($input['status'] ?? ''));

$allowedStatuses = [
    'not_started',
    'on_route',
    'checked_in',
    'resting',
    'delayed',
    'needs_follow_up',
    'completed',
];

if ($teamId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid team ID.']);
    exit;
}

if (!in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid status value.']);
    exit;
}

$pdo = db();

$stmt = $pdo->prepare('UPDATE teams SET status = ? WHERE id = ?');
$stmt->execute([$status, $teamId]);

if ($stmt->rowCount() === 0) {
    // Could be the team doesn't exist or the status is already the same
    $check = $pdo->prepare('SELECT id FROM teams WHERE id = ? LIMIT 1');
    $check->execute([$teamId]);

    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Team not found.']);
        exit;
    }
}

echo json_encode(['success' => true]);
