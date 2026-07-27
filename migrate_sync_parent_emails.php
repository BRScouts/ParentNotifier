<?php
/**
 * One-time migration: Sync parent_emails_json with emergency contact emails.
 *
 * Problem: When leaders edited profiles via people.php, the emergency contact
 * emails were NOT merged into parent_emails_json. This meant parents whose
 * email was corrected on the profile still didn't receive update emails.
 *
 * This script scans all young_people records, extracts emails from
 * emergency_contacts_json, merges them with the existing parent_emails_json,
 * and updates the record if anything was missing.
 *
 * Safe to run multiple times - it only adds missing emails, never removes existing ones.
 *
 * Usage:
 *   php migrate_sync_parent_emails.php
 *   (or visit in browser if running under XAMPP)
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';

function output(string $message, bool $isCli): void
{
    if ($isCli) {
        echo $message . PHP_EOL;
    } else {
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '<br>' . PHP_EOL;
    }
}

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font-family: monospace; padding: 1rem;">';
}

output('=== Sync parent_emails_json with emergency contact emails ===', $isCli);
output('', $isCli);

$pdo = db();

$stmt = $pdo->query(
    'SELECT id, name, emergency_contacts_json, parent_emails_json
     FROM young_people
     ORDER BY id ASC'
);

$rows = $stmt->fetchAll();

$updated = 0;
$skipped = 0;
$errors = 0;

foreach ($rows as $row) {
    $personId = (int)$row['id'];
    $personName = trim((string)$row['name']);

    // Extract emails from emergency contacts
    $contactEmails = [];
    $emergencyJson = $row['emergency_contacts_json'];

    if ($emergencyJson !== null && $emergencyJson !== '') {
        $contacts = json_decode($emergencyJson, true);

        if (is_array($contacts)) {
            foreach ($contacts as $contact) {
                if (!is_array($contact)) {
                    continue;
                }

                $email = trim((string)($contact['email'] ?? ''));

                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $contactEmails[strtolower($email)] = $email;
                }
            }
        }
    }

    // Get existing parent_emails_json
    $existingEmails = [];
    $parentEmailsJson = $row['parent_emails_json'];

    if ($parentEmailsJson !== null && $parentEmailsJson !== '') {
        $decoded = json_decode($parentEmailsJson, true);

        if (is_array($decoded)) {
            foreach ($decoded as $email) {
                $email = trim((string)$email);

                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $existingEmails[strtolower($email)] = $email;
                }
            }
        }
    }

    // Merge: add any contact emails that are missing from parent_emails_json
    $merged = $existingEmails;
    $newlyAdded = [];

    foreach ($contactEmails as $key => $email) {
        if (!isset($merged[$key])) {
            $merged[$key] = $email;
            $newlyAdded[] = $email;
        }
    }

    if (empty($newlyAdded)) {
        $skipped++;
        continue;
    }

    // Update the record
    $newJson = json_encode(array_values($merged), JSON_UNESCAPED_UNICODE);

    try {
        $updateStmt = $pdo->prepare(
            'UPDATE young_people SET parent_emails_json = ? WHERE id = ?'
        );
        $updateStmt->execute([$newJson, $personId]);

        $addedList = implode(', ', $newlyAdded);
        output("UPDATED #{$personId} ({$personName}): added [{$addedList}]", $isCli);
        $updated++;
    } catch (Throwable $e) {
        output("ERROR #{$personId} ({$personName}): " . $e->getMessage(), $isCli);
        $errors++;
    }
}

output('', $isCli);
output("=== Complete ===", $isCli);
output("Records updated: {$updated}", $isCli);
output("Records already in sync: {$skipped}", $isCli);
output("Errors: {$errors}", $isCli);

if (!$isCli) {
    echo '</pre>';
}
