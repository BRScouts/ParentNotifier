<?php
declare(strict_types=1);

/**
 * Push Notification Sender Utility
 *
 * Provides functions to send push notifications to subscribed leaders.
 * Include this file wherever you need to trigger notifications.
 *
 * Usage:
 *   require_once __DIR__ . '/push_send.php';
 *   push_notify_checkin_submitted($pdo, $team, $checkin);
 *   push_notify_all_leaders($pdo, $title, $body, $url);
 */

require_once __DIR__ . '/vendor/autoload.php';

if (!defined('VAPID_PUBLIC_KEY')) {
    require_once __DIR__ . '/config.php';
}

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Create a configured WebPush instance.
 */
function push_create_client(): WebPush
{
    $auth = [
        'VAPID' => [
            'subject' => VAPID_SUBJECT,
            'publicKey' => VAPID_PUBLIC_KEY,
            'privateKey' => VAPID_PRIVATE_KEY,
        ],
    ];

    $webPush = new WebPush($auth);
    $webPush->setReuseVAPIDHeaders(true);

    return $webPush;
}

/**
 * Fetch all active push subscriptions from the database.
 *
 * @return array Array of subscription rows
 */
function push_get_all_subscriptions(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            'SELECT ps.*, l.name AS leader_name
             FROM push_subscriptions ps
             INNER JOIN leaders l ON l.id = ps.leader_id AND l.is_active = 1
             ORDER BY ps.created_at DESC'
        );
        $results = $stmt->fetchAll();
        error_log('[Push] Found ' . count($results) . ' active subscription(s)');
        return $results;
    } catch (Throwable $e) {
        error_log('[Push] Error fetching subscriptions: ' . $e->getMessage());
        return [];
    }
}

/**
 * Fetch push subscriptions for specific leaders (e.g. on-duty leaders only).
 *
 * @param array $leaderIds Array of leader IDs
 * @return array Array of subscription rows
 */
function push_get_subscriptions_for_leaders(PDO $pdo, array $leaderIds): array
{
    if (empty($leaderIds)) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($leaderIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT ps.*, l.name AS leader_name
             FROM push_subscriptions ps
             INNER JOIN leaders l ON l.id = ps.leader_id AND l.is_active = 1
             WHERE ps.leader_id IN ({$placeholders})
             ORDER BY ps.created_at DESC"
        );
        $stmt->execute(array_values($leaderIds));
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Send a push notification to a list of subscriptions.
 *
 * @param array $subscriptions Subscription rows from the database
 * @param array $payload       Notification payload (title, body, icon, url, tag, actions)
 * @return array               Results with success/failure counts
 */
function push_send_to_subscriptions(PDO $pdo, array $subscriptions, array $payload): array
{
    if (empty($subscriptions)) {
        return ['sent' => 0, 'failed' => 0, 'expired' => 0];
    }

    $webPush = push_create_client();
    $payloadJson = json_encode($payload);

    $results = ['sent' => 0, 'failed' => 0, 'expired' => 0];
    $subscriptionMap = [];

    foreach ($subscriptions as $sub) {
        try {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'publicKey' => $sub['p256dh_key'],
                'authToken' => $sub['auth_key'],
            ]);

            $webPush->queueNotification($subscription, $payloadJson);
            $subscriptionMap[] = $sub;
        } catch (Throwable $e) {
            $results['failed']++;
            push_log_notification($pdo, $sub, $payload, 'failed', $e->getMessage());
        }
    }

    // Flush all queued notifications
    $index = 0;
    foreach ($webPush->flush() as $report) {
        $sub = $subscriptionMap[$index] ?? null;

        if ($report->isSuccess()) {
            $results['sent']++;
            if ($sub) {
                push_log_notification($pdo, $sub, $payload, 'sent');
            }
        } else {
            // Check if subscription has expired (410 Gone or 404)
            $statusCode = $report->getResponse()?->getStatusCode() ?? 0;

            if ($statusCode === 410 || $statusCode === 404) {
                $results['expired']++;
                // Remove expired subscription from database
                if ($sub) {
                    push_remove_subscription($pdo, (int)$sub['id']);
                    push_log_notification($pdo, $sub, $payload, 'expired', 'Subscription expired (HTTP ' . $statusCode . ')');
                }
            } else {
                $results['failed']++;
                $reason = $report->getReason() ?? 'Unknown error';
                if ($sub) {
                    push_log_notification($pdo, $sub, $payload, 'failed', $reason);
                }
            }
        }

        $index++;
    }

    return $results;
}

/**
 * Remove an expired/invalid subscription from the database.
 */
function push_remove_subscription(PDO $pdo, int $subscriptionId): void
{
    try {
        $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?');
        $stmt->execute([$subscriptionId]);
    } catch (Throwable $e) {
        // Silent failure — will be cleaned up on next attempt
    }
}

/**
 * Log a push notification attempt (optional, for analytics/debugging).
 */
function push_log_notification(PDO $pdo, array $subscription, array $payload, string $status, ?string $error = null): void
{
    try {
        // Check if the log table exists before writing
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = "push_notification_log"'
        );
        $stmt->execute();

        if ((int)$stmt->fetchColumn() === 0) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO push_notification_log
                (subscription_id, leader_id, event_type, title, body, url, status, error_message, created_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );

        $stmt->execute([
            $subscription['id'] ?? null,
            $subscription['leader_id'] ?? null,
            $payload['tag'] ?? 'checkin_submitted',
            $payload['title'] ?? '',
            $payload['body'] ?? '',
            $payload['url'] ?? '',
            $status,
            $error,
        ]);
    } catch (Throwable $e) {
        // Do not block the send flow for logging failures
    }
}

// ============================================================
// Convenience functions for specific notification types
// ============================================================

/**
 * Notify all subscribed leaders that a team has submitted a check-in.
 *
 * @param PDO   $pdo     Database connection
 * @param array $team    Team row (must have 'name' key)
 * @param array $checkin Check-in row (must have 'id', 'location_name', 'submitted_at')
 * @return array         Send results
 */
function push_notify_checkin_submitted(PDO $pdo, array $team, array $checkin): array
{
    $teamName = $team['name'] ?? 'Unknown team';
    $locationName = $checkin['location_name'] ?? 'Unknown location';
    $checkinId = (int)($checkin['id'] ?? 0);

    $payload = [
        'title' => 'Check-in: ' . $teamName,
        'body' => $teamName . ' has checked in at ' . $locationName . '. Tap to review.',
        'icon' => '/assets/logo-generator-linear-blackwhite-png.png',
        'url' => '/add_location.php?checkin_id=' . $checkinId,
        'tag' => 'checkin-' . $checkinId,
        'requireInteraction' => true,
        'actions' => [
            ['action' => 'review', 'title' => 'Review'],
            ['action' => 'dismiss', 'title' => 'Dismiss'],
        ],
    ];

    // Send to all subscribed leaders
    $subscriptions = push_get_all_subscriptions($pdo);

    return push_send_to_subscriptions($pdo, $subscriptions, $payload);
}

/**
 * Notify specific leaders (e.g. on-duty only) about a check-in.
 *
 * @param PDO   $pdo       Database connection
 * @param array $team      Team row
 * @param array $checkin   Check-in row
 * @param array $leaderIds Array of leader IDs to notify
 * @return array           Send results
 */
function push_notify_checkin_to_leaders(PDO $pdo, array $team, array $checkin, array $leaderIds): array
{
    $teamName = $team['name'] ?? 'Unknown team';
    $locationName = $checkin['location_name'] ?? 'Unknown location';
    $checkinId = (int)($checkin['id'] ?? 0);

    $payload = [
        'title' => 'Check-in: ' . $teamName,
        'body' => $teamName . ' has checked in at ' . $locationName . '. Tap to review.',
        'icon' => '/assets/logo-generator-linear-blackwhite-png.png',
        'url' => '/add_location.php?checkin_id=' . $checkinId,
        'tag' => 'checkin-' . $checkinId,
        'requireInteraction' => true,
        'actions' => [
            ['action' => 'review', 'title' => 'Review'],
            ['action' => 'dismiss', 'title' => 'Dismiss'],
        ],
    ];

    $subscriptions = push_get_subscriptions_for_leaders($pdo, $leaderIds);

    return push_send_to_subscriptions($pdo, $subscriptions, $payload);
}

/**
 * Send a generic push notification to all subscribed leaders.
 */
function push_notify_all_leaders(PDO $pdo, string $title, string $body, string $url = '/dashboard.php'): array
{
    $payload = [
        'title' => $title,
        'body' => $body,
        'icon' => '/assets/logo-generator-linear-blackwhite-png.png',
        'url' => $url,
        'tag' => 'exbelt-' . time(),
    ];

    $subscriptions = push_get_all_subscriptions($pdo);

    return push_send_to_subscriptions($pdo, $subscriptions, $payload);
}
