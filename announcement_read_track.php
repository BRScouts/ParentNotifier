<?php
/**
 * Announcement Read Tracker
 *
 * Records when a parent/team member views an announcement.
 * Can be called as:
 *   1. A tracking pixel: <img src="announcement_read_track.php?a=ID&t=TOKEN&n=NAME">
 *   2. An AJAX/fetch call from the explorer announcements page
 *   3. Directly from server-side code via include
 *
 * Parameters:
 *   - a (int): announcement_id
 *   - t (string): team explorer_token
 *   - n (string): reader name (URL-encoded)
 *   - e (string, optional): reader email (URL-encoded)
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Determine if this is being included internally or accessed as an endpoint
$isDirectAccess = (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'announcement_read_track.php');

$announcementId = (int)($_GET['a'] ?? $_POST['a'] ?? 0);
$teamToken = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$readerName = trim((string)($_GET['n'] ?? $_POST['n'] ?? ''));
$readerEmail = trim((string)($_GET['e'] ?? $_POST['e'] ?? ''));

// Validate required fields
if ($announcementId <= 0 || $teamToken === '' || $readerName === '') {
    if ($isDirectAccess) {
        // Return a 1x1 transparent pixel regardless (don't leak validation info)
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
    return;
}

try {
    $pdo = db();

    // Fetch team by explorer token
    $team = explorer_fetch_team($pdo, $teamToken);
    if (!$team) {
        if ($isDirectAccess) {
            header('Content-Type: image/gif');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            exit;
        }
        return;
    }

    $teamId = (int)$team['id'];

    // Verify the announcement exists and targets this team
    $stmt = $pdo->prepare(
        'SELECT id FROM announcements WHERE id = ? AND (team_id IS NULL OR team_id = ?)'
    );
    $stmt->execute([$announcementId, $teamId]);
    if (!$stmt->fetch()) {
        if ($isDirectAccess) {
            header('Content-Type: image/gif');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            exit;
        }
        return;
    }

    // Ensure announcement_reads table exists
    try {
        $tableCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = "announcement_reads"'
        );
        $tableCheck->execute();
        if ((int)$tableCheck->fetchColumn() === 0) {
            $pdo->exec('
                CREATE TABLE announcement_reads (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    announcement_id INT UNSIGNED NOT NULL,
                    team_id INT UNSIGNED NOT NULL,
                    reader_name VARCHAR(150) NOT NULL,
                    reader_email VARCHAR(255) NULL,
                    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_read_announcement_team_person (announcement_id, team_id, reader_name),
                    INDEX idx_reads_announcement (announcement_id),
                    INDEX idx_reads_team (team_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');
        }
    } catch (Throwable $e) {
        // Table may already exist
    }

    // INSERT IGNORE to avoid duplicates (unique key will prevent re-recording same person)
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO announcement_reads (announcement_id, team_id, reader_name, reader_email)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $announcementId,
        $teamId,
        $readerName,
        $readerEmail !== '' ? $readerEmail : null,
    ]);

} catch (Throwable $e) {
    // Fail silently — tracking should never break the user experience
}

if ($isDirectAccess) {
    // Check if this was an AJAX request
    $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($acceptHeader, 'application/json') !== false) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode(['ok' => true]);
        exit;
    }

    // Default: return a 1x1 transparent GIF (tracking pixel)
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}
