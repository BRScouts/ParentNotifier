<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function open_tracking_now_for_database(): string
{
    $timezone = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Helsinki';

    return (new DateTime('now', new DateTimeZone($timezone)))->format('Y-m-d H:i:s');
}

function open_tracking_ip_hash(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($ip === '') {
        return null;
    }

    $secret = defined('APP_SECRET')
        ? APP_SECRET
        : (defined('DB_PASS') ? DB_PASS : 'exbelt-analytics');

    return hash_hmac('sha256', $ip, $secret);
}

function output_tracking_pixel(): void
{
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo base64_decode('R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');
    exit;
}

try {
    $openToken = trim((string)($_GET['ot'] ?? ''));

    if ($openToken === '') {
        output_tracking_pixel();
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT *
         FROM email_tracking_tokens
         WHERE open_token = ?
         LIMIT 1'
    );

    $stmt->execute([$openToken]);
    $tracking = $stmt->fetch();

    if (!$tracking) {
        output_tracking_pixel();
    }

    $now = open_tracking_now_for_database();

    $stmt = $pdo->prepare(
        'UPDATE email_tracking_tokens
         SET open_count = open_count + 1,
             first_opened_at = COALESCE(first_opened_at, ?),
             last_opened_at = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $now,
        $now,
        (int)$tracking['id'],
    ]);

    $stmt = $pdo->prepare(
        'INSERT INTO email_tracking_events
            (
                email_tracking_token_id,
                email_queue_id,
                event_type,
                recipient_email,
                related_team_id,
                related_post_id,
                tracked_url,
                request_path,
                session_id,
                ip_hash,
                user_agent,
                referrer,
                created_at
            )
         VALUES
            (?, ?, "open", ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        (int)$tracking['id'],
        (int)$tracking['email_queue_id'],
        $tracking['recipient_email'] ?? null,
        $tracking['related_team_id'] ?? null,
        $tracking['related_post_id'] ?? null,
        '/email_open.php',
        session_status() === PHP_SESSION_ACTIVE ? session_id() : null,
        open_tracking_ip_hash(),
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2000),
        mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 2000),
        $now,
    ]);
} catch (Throwable $exception) {
    error_log('Email open tracking failed: ' . $exception->getMessage());
}

output_tracking_pixel();