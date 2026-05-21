<?php
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();

const PEOPLE_UPLOAD_DIR = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/people/';
const PEOPLE_UPLOAD_PUBLIC_PATH = 'assets/people/';

/**
 * This is the address parents are told to look out for.
 * Your cron/template sender should use the same From address.
 */
const ONBOARDING_CONFIRMATION_FROM_EMAIL = 'noreply@app.irvalscouts.org.uk';

$error = '';
$success = '';
$matchedPerson = null;
$submittedPerson = null;
$submittedEmails = [];
$confirmationQueued = false;

$verifiedPersonId = (int)($_SESSION['parent_onboarding_person_id'] ?? 0);

/**
 * Basic helpers
 */

function json_items(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function json_list_from_array(array $items): ?string
{
    $clean = [];

    foreach ($items as $item) {
        $item = trim((string)$item);

        if ($item !== '') {
            $clean[] = $item;
        }
    }

    return empty($clean) ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
}

function ensure_rows(array $items, int $minimum, $default): array
{
    if (empty($items)) {
        $items[] = $default;
    }

    while (count($items) < $minimum) {
        $items[] = $default;
    }

    return $items;
}

function normalise_last_name(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z\-\' ]/i', '', $value);
    $value = preg_replace('/\s+/', ' ', (string)$value);

    return trim((string)$value);
}

function last_name_from_full_name(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return '';
    }

    $parts = preg_split('/\s+/', $name);

    return normalise_last_name((string)end($parts));
}

function parse_dob_for_lookup(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $formats = [
        'Y-m-d',
        'd/m/Y',
        'd-m-Y',
        'd.m.Y',
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);

        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

function valid_unique_emails(array $emails): array
{
    $clean = [];

    foreach ($emails as $email) {
        $email = trim((string)$email);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $clean[strtolower($email)] = $email;
        }
    }

    return array_values($clean);
}

function emergency_contacts_from_post(): array
{
    $names = $_POST['contact_name'] ?? [];
    $relationships = $_POST['contact_relationship'] ?? [];
    $phones = $_POST['contact_phone'] ?? [];
    $emails = $_POST['contact_email'] ?? [];

    $contacts = [];

    foreach ($names as $index => $name) {
        if (count($contacts) >= 5) {
            break;
        }

        $name = trim((string)$name);
        $relationship = trim((string)($relationships[$index] ?? ''));
        $phone = trim((string)($phones[$index] ?? ''));
        $email = trim((string)($emails[$index] ?? ''));

        if ($name === '' && $relationship === '' && $phone === '' && $email === '') {
            continue;
        }

        $contacts[] = [
            'name' => $name,
            'relationship' => $relationship,
            'phone' => $phone,
            'email' => $email,
        ];
    }

    return $contacts;
}

function emergency_contacts_json_from_post(): ?string
{
    $contacts = emergency_contacts_from_post();

    return empty($contacts) ? null : json_encode($contacts, JSON_UNESCAPED_UNICODE);
}

function emergency_contact_emails(array $contacts): array
{
    $emails = [];

    foreach ($contacts as $contact) {
        if (!is_array($contact)) {
            continue;
        }

        $email = trim((string)($contact['email'] ?? ''));

        if ($email !== '') {
            $emails[] = $email;
        }
    }

    return valid_unique_emails($emails);
}

function additional_update_emails_from_post(): array
{
    $posted = $_POST['parent_emails'] ?? [];
    $posted = is_array($posted) ? array_slice($posted, 0, 5) : [];

    return valid_unique_emails($posted);
}

function merged_parent_update_emails(array $contacts, array $additionalEmails): array
{
    return valid_unique_emails(array_merge(
        emergency_contact_emails($contacts),
        $additionalEmails
    ));
}

/**
 * Data helpers
 */

function fetch_person(PDO $pdo, int $personId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT 
            yp.*,
            t.name AS team_name
         FROM young_people yp
         LEFT JOIN teams t ON t.id = yp.team_id
         WHERE yp.id = ?
         LIMIT 1'
    );

    $stmt->execute([$personId]);
    $person = $stmt->fetch();

    return $person ?: null;
}

function find_person_by_last_name_and_dob(PDO $pdo, string $lastName, string $dob): ?array
{
    $normalisedLastName = normalise_last_name($lastName);

    $stmt = $pdo->prepare(
        'SELECT 
            yp.*,
            t.name AS team_name
         FROM young_people yp
         LEFT JOIN teams t ON t.id = yp.team_id
         WHERE yp.dob = ?
           AND yp.is_active = 1
         ORDER BY yp.name ASC'
    );

    $stmt->execute([$dob]);
    $people = $stmt->fetchAll();

    $matches = [];

    foreach ($people as $person) {
        if (last_name_from_full_name($person['name']) === $normalisedLastName) {
            $matches[] = $person;
        }
    }

    if (count($matches) !== 1) {
        return null;
    }

    return $matches[0];
}

/**
 * Upload helper
 */

function handle_parent_profile_upload(string $fieldName): string
{
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        throw new RuntimeException('Please upload a clear photo of the participant.');
    }

    $file = $_FILES[$fieldName];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Please upload a clear photo of the participant.');
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The photo upload failed. Please try again.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('The photo must be smaller than 5MB.');
    }

    $tmpName = $file['tmp_name'] ?? '';

    if (!is_uploaded_file($tmpName)) {
        throw new RuntimeException('The uploaded photo was not valid.');
    }

    $imageInfo = getimagesize($tmpName);

    if ($imageInfo === false) {
        throw new RuntimeException('Please upload a valid image file.');
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $mimeType = $imageInfo['mime'] ?? '';

    if (!isset($allowedMimeTypes[$mimeType])) {
        throw new RuntimeException('Please upload a JPG, PNG, WEBP or GIF image.');
    }

    if (!is_dir(PEOPLE_UPLOAD_DIR)) {
        if (!mkdir(PEOPLE_UPLOAD_DIR, 0755, true) && !is_dir(PEOPLE_UPLOAD_DIR)) {
            throw new RuntimeException('The upload folder could not be created.');
        }
    }

    $extension = $allowedMimeTypes[$mimeType];
    $filename = 'person-parent-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $destination = rtrim(PEOPLE_UPLOAD_DIR, '/') . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('The uploaded photo could not be saved.');
    }

    return PEOPLE_UPLOAD_PUBLIC_PATH . $filename;
}

/**
 * Confirmation email queue
 */

function queue_onboarding_confirmation_email(
    PDO $pdo,
    string $toEmail,
    array $person,
    array $allUpdateEmails
): void {
    $subject = 'Explorer Belt details submitted';

    $teamName = $person['team_name'] ?? 'your team';

    $content =
        "Thank you. The Explorer Belt onboarding details for " . ($person['name'] ?? 'your participant') . " have been submitted.\n\n" .
        "What happens next:\n" .
        "- Prior to the event, you will be emailed a private team link.\n" .
        "- That link will let you see updates, photos and manually entered check-ins for " . $teamName . ".\n" .
        "- Confirmation messages are manually added by leaders and may not appear straight away.\n" .
        "- No news is not bad news. Updates may be delayed until all groups are confirmed settled for the night.\n\n" .
        "Emails currently listed for trip updates:\n" .
        implode("\n", $allUpdateEmails) . "\n\n" .
        "Please look out for emails from " . ONBOARDING_CONFIRMATION_FROM_EMAIL . ". " .
        "If you do not receive this confirmation within the next hour, please check your junk/spam folder or contact the trip team.";

    $stmt = $pdo->prepare(
        'INSERT INTO email_queue
            (to_email, subject, content, related_team_id)
         VALUES
            (?, ?, ?, ?)'
    );

    $stmt->execute([
        $toEmail,
        $subject,
        $content,
        !empty($person['team_id']) ? (int)$person['team_id'] : null,
    ]);
}

function queue_onboarding_confirmation_emails(PDO $pdo, array $person, array $emails): bool
{
    $queuedAny = false;

    foreach ($emails as $email) {
        try {
            queue_onboarding_confirmation_email($pdo, $email, $person, $emails);
            $queuedAny = true;
        } catch (Throwable $exception) {
            /**
             * Do not block the parent submission if email_queue is temporarily unavailable.
             */
        }
    }

    return $queuedAny;
}

/**
 * Audit log
 */

function insert_parent_audit_log(
    PDO $pdo,
    int $personId,
    string $additionalInformation,
    bool $photoUpdated,
    array $updateEmails
): void {
    try {
        $body = "Parent onboarding form completed.\n\n";

        if ($photoUpdated) {
            $body .= "A mandatory profile photo was uploaded or updated by the parent/guardian.\n\n";
        }

        if (!empty($updateEmails)) {
            $body .= "Update emails confirmed:\n";
            $body .= implode("\n", $updateEmails) . "\n\n";
        }

        if (trim($additionalInformation) !== '') {
            $body .= "Additional information provided by parent/guardian:\n";
            $body .= trim($additionalInformation);
        } else {
            $body .= "No additional information was provided.";
        }

        $stmt = $pdo->prepare(
            'INSERT INTO person_logs
                (person_id, leader_id, log_type, title, body, occurred_at)
             VALUES
                (?, NULL, "general", "Parent onboarding completed", ?, NOW())'
        );

        $stmt->execute([
            $personId,
            $body,
        ]);
    } catch (Throwable $exception) {
        /**
         * Do not block the parent if person_logs does not exist yet.
         */
    }
}

/**
 * Validation
 */

function validate_parent_form(): array
{
    $errors = [];

    $contacts = emergency_contacts_from_post();

    if (count($contacts) > 5) {
        $errors[] = 'You can add a maximum of 5 emergency contacts.';
    }

    $additionalEmails = $_POST['parent_emails'] ?? [];

    if (is_array($additionalEmails) && count(array_filter($additionalEmails, static function ($value) {
        return trim((string)$value) !== '';
    })) > 5) {
        $errors[] = 'You can add a maximum of 5 additional update email addresses.';
    }

    foreach ($contacts as $contact) {
        $email = trim((string)($contact['email'] ?? ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'One of the emergency contact email addresses is not valid.';
        }
    }

    foreach ((array)$additionalEmails as $email) {
        $email = trim((string)$email);

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'One of the additional update email addresses is not valid.';
        }
    }

    return $errors;
}

/**
 * Demo helpers
 */

function fetch_demo_leaders(PDO $pdo): array
{
    try {
        $hasSchedule = false;

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = "leader_schedules"'
        );
        $stmt->execute();
        $hasSchedule = (int)$stmt->fetchColumn() > 0;

        if ($hasSchedule) {
            $stmt = $pdo->query(
                'SELECT DISTINCT l.id, l.name, l.photo_url
                 FROM leaders l
                 INNER JOIN leader_schedules ls ON ls.leader_id = l.id
                 WHERE (l.is_active = 1 OR l.is_active IS NULL)
                   AND ls.schedule_type = "in_country"
                   AND (
                        ls.schedule_end IS NULL
                        OR ls.schedule_end >= NOW()
                   )
                 ORDER BY l.name ASC'
            );

            return $stmt->fetchAll();
        }
    } catch (Throwable $exception) {
        /**
         * Fallback below.
         */
    }

    try {
        $stmt = $pdo->query(
            'SELECT id, name, photo_url
             FROM leaders
             WHERE is_active = 1
             ORDER BY name ASC'
        );

        return $stmt->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
}

function media_url(?string $path): string
{
    $path = trim((string)$path);

    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return url($path);
}

/**
 * Reset onboarding session
 */

if (isset($_GET['reset'])) {
    unset($_SESSION['parent_onboarding_person_id']);
    redirect('parent_onboarding.php');
}

/**
 * Step 1: lookup child
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lookup') {
    $lastName = trim($_POST['last_name'] ?? '');
    $dobRaw = trim($_POST['dob'] ?? '');
    $dob = parse_dob_for_lookup($dobRaw);

    if ($lastName === '' || !$dob) {
        $error = 'Please enter the participant’s last name and date of birth.';
    } else {
        $person = find_person_by_last_name_and_dob($pdo, $lastName, $dob);

        if (!$person) {
            $error = 'We could not match those details. Please check the spelling and date of birth, or contact the trip team.';
        } else {
            $_SESSION['parent_onboarding_person_id'] = (int)$person['id'];
            redirect('parent_onboarding.php?step=form');
        }
    }
}

/**
 * Load matched person from session before rendering or saving.
 */

if ($verifiedPersonId > 0) {
    $matchedPerson = fetch_person($pdo, $verifiedPersonId);

    if (!$matchedPerson) {
        unset($_SESSION['parent_onboarding_person_id']);
        $verifiedPersonId = 0;
        $matchedPerson = null;
    }
}

/**
 * Step 2: save full details
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_details') {
    $personId = (int)($_POST['person_id'] ?? 0);

    if ($personId <= 0 || $personId !== $verifiedPersonId || !$matchedPerson) {
        $error = 'Your session has expired. Please start again.';
        unset($_SESSION['parent_onboarding_person_id']);
        $matchedPerson = null;
    } else {
        $formErrors = validate_parent_form();

        if (!empty($formErrors)) {
            $error = implode(' ', $formErrors);
        } else {
            try {
                $contactsArray = emergency_contacts_from_post();
                $contactsJson = empty($contactsArray) ? null : json_encode($contactsArray, JSON_UNESCAPED_UNICODE);

                $additionalEmails = additional_update_emails_from_post();
                $mergedParentEmails = merged_parent_update_emails($contactsArray, $additionalEmails);

                $phonesJson = null;
                $medicationsJson = json_list_from_array($_POST['medications'] ?? []);
                $allergiesJson = json_list_from_array($_POST['allergies'] ?? []);
                $additionalInformation = trim($_POST['additional_information'] ?? '');

                $photoPath = handle_parent_profile_upload('profile_image');
                $photoUpdated = true;

                $pdo->beginTransaction();

                try {
                    $stmt = $pdo->prepare(
                        'UPDATE young_people
                         SET photo_url = ?,
                             emergency_contacts_json = ?,
                             parent_emails_json = ?,
                             phones_json = ?,
                             medications_json = ?,
                             allergies_json = ?,
                             parent_onboarding_completed_at = NOW()
                         WHERE id = ?'
                    );

                    $stmt->execute([
                        $photoPath,
                        $contactsJson,
                        json_list_from_array($mergedParentEmails),
                        $phonesJson,
                        $medicationsJson,
                        $allergiesJson,
                        $personId,
                    ]);
                } catch (Throwable $exception) {
                    /**
                     * Fallback if parent_onboarding_completed_at has not been added.
                     */
                    $stmt = $pdo->prepare(
                        'UPDATE young_people
                         SET photo_url = ?,
                             emergency_contacts_json = ?,
                             parent_emails_json = ?,
                             phones_json = ?,
                             medications_json = ?,
                             allergies_json = ?
                         WHERE id = ?'
                    );

                    $stmt->execute([
                        $photoPath,
                        $contactsJson,
                        json_list_from_array($mergedParentEmails),
                        $phonesJson,
                        $medicationsJson,
                        $allergiesJson,
                        $personId,
                    ]);
                }

                insert_parent_audit_log(
                    $pdo,
                    $personId,
                    $additionalInformation,
                    $photoUpdated,
                    $mergedParentEmails
                );

                $submittedPerson = fetch_person($pdo, $personId) ?: $matchedPerson;
                $submittedEmails = $mergedParentEmails;

                $confirmationQueued = queue_onboarding_confirmation_emails(
                    $pdo,
                    $submittedPerson,
                    $mergedParentEmails
                );

                $pdo->commit();

                unset($_SESSION['parent_onboarding_person_id']);

                $success = 'Thank you. The details have been submitted to the trip team.';
                $matchedPerson = null;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $exception->getMessage();
            }
        }
    }
}

/**
 * Prepare form data if matched.
 */

$contacts = [];
$parentEmails = [];
$phones = [];
$medications = [];
$allergies = [];

if ($matchedPerson) {
    $contacts = ensure_rows(
        array_slice(json_items($matchedPerson['emergency_contacts_json'] ?? null), 0, 5),
        1,
        [
            'name' => '',
            'relationship' => '',
            'phone' => '',
            'email' => '',
        ]
    );

    $allStoredParentEmails = valid_unique_emails(json_items($matchedPerson['parent_emails_json'] ?? null));
    $contactEmails = emergency_contact_emails($contacts);
    $contactEmailKeys = array_map('strtolower', $contactEmails);

    $additionalOnlyEmails = [];

    foreach ($allStoredParentEmails as $email) {
        if (!in_array(strtolower($email), $contactEmailKeys, true)) {
            $additionalOnlyEmails[] = $email;
        }
    }

    $parentEmails = ensure_rows(array_slice($additionalOnlyEmails, 0, 5), 1, '');
    $phones = [];
    $medications = ensure_rows(json_items($matchedPerson['medications_json'] ?? null), 1, '');
    $allergies = ensure_rows(json_items($matchedPerson['allergies_json'] ?? null), 1, '');
}

$demoLeaders = $submittedPerson ? fetch_demo_leaders($pdo) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e(APP_NAME) ?> - Parent onboarding</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">

    <style>
        body {
            background: #f3f2f1;
        }

        .onboarding-header {
            background: #7413dc;
            color: #ffffff;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }

        .onboarding-header h1,
        .onboarding-header p {
            color: #ffffff;
        }

        .onboarding-panel {
            border: 2px solid #d8d8d8;
            background: #ffffff;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .demo-feed-card {
    border: 2px solid #d8d8d8;
    background: #ffffff;
    margin-bottom: 1rem;
}

.demo-feed-card-header {
    padding: 1rem 1rem 0.75rem;
    border-bottom: 1px solid #d8d8d8;
}

.demo-feed-card-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 900;
}

.demo-feed-card-body {
    padding: 1rem;
}

.demo-meta {
    color: #505a5f;
    font-size: 0.95rem;
    margin: 0.35rem 0 0;
}

.demo-meta span {
    padding: 0 0.35rem;
}

.demo-badge-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-top: 0.75rem;
}

.demo-badge {
    display: inline-block;
    border: 2px solid #1d1d1d;
    background: #f3f2f1;
    padding: 0.2rem 0.45rem;
    font-weight: 800;
    font-size: 0.85rem;
}

.demo-badge-location {
    background: #00703c;
    color: #ffffff;
    border-color: #00703c;
}

.demo-real-map {
    height: 240px;
    border: 2px solid #1d1d1d;
    background: #f3f2f1;
    margin-top: 1rem;
}

.demo-sidebar {
    border: 2px solid #d8d8d8;
    background: #ffffff;
    padding: 1rem;
}

        .onboarding-panel h2,
        .onboarding-panel h3 {
            margin-top: 0;
            font-weight: 900;
        }

        .onboarding-panel label {
            font-weight: 800;
        }

        .info-box {
            border-left: 8px solid #1d70b8;
            background: #eef7ff;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .warning-box {
            border-left: 8px solid #ffdd00;
            background: #fff7bf;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .success-box {
            border-left: 8px solid #00703c;
            background: #e9f8ef;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .dynamic-section {
            border: 2px solid #d8d8d8;
            background: #ffffff;
            padding: 1rem;
            margin-bottom: 1.25rem;
        }

        .dynamic-section h3 {
            margin-top: 0;
            font-weight: 900;
        }

        .dynamic-row {
            border: 2px solid #d8d8d8;
            background: #f8f8f8;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .dynamic-row-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr)) auto;
            gap: 0.5rem;
            align-items: end;
        }

        .dynamic-row-grid.simple {
            grid-template-columns: minmax(0, 1fr) auto;
        }

        @media (max-width: 900px) {
            .dynamic-row-grid,
            .dynamic-row-grid.simple {
                grid-template-columns: 1fr;
            }
        }

        .dynamic-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .child-summary {
            border: 2px solid #1d1d1d;
            background: #f8f8f8;
            padding: 1rem;
        }

        .child-summary h2 {
            margin-bottom: 0.25rem;
        }

        .current-photo {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border: 2px solid #1d1d1d;
            background: #f3f2f1;
        }

        .readonly-email-row {
            background: #eef7ff;
            border-left: 6px solid #1d70b8;
        }

        .email-note {
            color: #505a5f;
            font-size: 0.95rem;
            margin-top: 0.25rem;
        }

        .demo-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 320px;
            gap: 1rem;
            align-items: start;
        }

        @media (max-width: 900px) {
            .demo-layout {
                grid-template-columns: 1fr;
            }
        }

        .demo-post {
            border: 2px solid #d8d8d8;
            background: #ffffff;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .demo-post h3 {
            margin-top: 0;
            font-weight: 900;
        }

        .demo-meta {
            color: #505a5f;
            font-size: 0.95rem;
            margin-bottom: 0.75rem;
        }

        .demo-map {
            height: 180px;
            border: 2px solid #1d1d1d;
            background:
                radial-gradient(circle at center, rgba(29,112,184,0.28) 0, rgba(29,112,184,0.28) 34%, transparent 35%),
                linear-gradient(45deg, #e9f8ef 25%, transparent 25%),
                linear-gradient(-45deg, #e9f8ef 25%, transparent 25%),
                #f3f2f1;
            background-size: auto, 28px 28px, 28px 28px, auto;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            color: #1d1d1d;
            text-align: center;
            padding: 1rem;
        }

        .demo-leader-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
        }

        .demo-leader {
            border: 2px solid #d8d8d8;
            background: #ffffff;
            padding: 0.75rem;
            text-align: center;
        }

        .demo-leader img,
        .demo-leader-placeholder {
            width: 76px;
            height: 76px;
            object-fit: cover;
            border: 2px solid #1d1d1d;
            background: #f3f2f1;
            margin: 0 auto 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
        }

        .demo-leader-name {
            font-weight: 900;
            margin-bottom: 0;
        }

        .muted {
            color: #505a5f;
        }
    </style>
</head>

<body>

<header class="onboarding-header">
    <div class="container">
        <h1>Explorer Belt parent onboarding</h1>
        <p class="lead mb-0">
            Confirm medical, emergency contact and update email details for the trip.
        </p>
    </div>
</header>

<main class="container mb-5">

    <div class="info-box">
        <strong>Privacy note:</strong>
        This form is for trip administration only. Information submitted here will be available to authorised leaders supporting the trip.
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success && $submittedPerson): ?>

        <div class="success-box">
            <h2>Details submitted</h2>

            <p>
                <?= e($success) ?>
            </p>

            <p>
                A confirmation email has been queued for the update email addresses provided.
                Please look out for an email from
                <strong><?= e(ONBOARDING_CONFIRMATION_FROM_EMAIL) ?></strong>.
            </p>

            <p class="mb-0">
                If you do not receive an email within the next hour, please check your junk or spam folder.
                If it still has not arrived, please contact the trip team.
            </p>
        </div>

        <section class="onboarding-panel">
    <h2>Example of the updates page</h2>

    <p>
        Before the event, you will be emailed a private link to your participant’s team page.
        The page will show leader updates, photos, and approximate manually entered check-in locations.
    </p>

    <div class="warning-box">
        <strong>No news is not bad news.</strong>
        Check-ins are added manually and may not appear straight away. Updates may be delayed until all groups are confirmed settled for the night.
    </div>

    <div class="demo-layout">
        <div>
            <article class="demo-feed-card">
                <div class="demo-feed-card-header">
                    <h3>Evening update</h3>
                    <p class="demo-meta">
                        Example only
                        <span>|</span>
                        <?= e($submittedPerson['team_name'] ?: 'Team page') ?>
                        <span>|</span>
                        Leader update
                    </p>

                    <div class="demo-badge-row">
                        <span class="demo-badge">Team update</span>
                        <span class="demo-badge">Visible to your team link</span>
                    </div>
                </div>

                <div class="demo-feed-card-body">
                    <p>
                        The team have completed their route for today and are settled for the evening.
                        They were in good spirits when the leaders checked in.
                    </p>

                    <p class="mb-0">
                        A short update like this may appear alongside photos or notes from the leadership team.
                    </p>
                </div>
            </article>

            <article class="demo-feed-card">
                <div class="demo-feed-card-header">
                    <h3>Location check-in</h3>
                    <p class="demo-meta">
                        Example only
                        <span>|</span>
                        <?= e($submittedPerson['team_name'] ?: 'Team page') ?>
                        <span>|</span>
                        Approximate location
                    </p>

                    <div class="demo-badge-row">
                        <span class="demo-badge demo-badge-location">Location check-in</span>
                        <span class="demo-badge">Approximate area</span>
                    </div>
                </div>

                <div class="demo-feed-card-body">
                    <p>
                        This is an example of how a manually entered check-in may appear on the team page.
                    </p>

                    <div
                        id="demo-helsinki-map"
                        class="demo-real-map"
                        data-lat="60.1699"
                        data-lng="24.9384"
                    ></div>

                    <p class="muted mt-2 mb-0">
                        The blue circle shows an approximate 1 mile area around the entered location.
                        The actual trip check-ins will be entered manually by leaders.
                    </p>
                </div>
            </article>
        </div>

        <aside class="demo-sidebar">
            <h3>Leadership team preview</h3>
            <p class="muted">
                The team page may also show leaders supporting the trip. This preview only shows names and photos.
            </p>

            <?php if (!empty($demoLeaders)): ?>
                <div class="demo-leader-grid">
                    <?php foreach ($demoLeaders as $leader): ?>
                        <div class="demo-leader">
                            <?php $leaderPhoto = media_url($leader['photo_url'] ?? ''); ?>

                            <?php if ($leaderPhoto !== ''): ?>
                                <img src="<?= e($leaderPhoto) ?>" alt="Photo of <?= e($leader['name']) ?>">
                            <?php else: ?>
                                <div class="demo-leader-placeholder" aria-hidden="true">
                                    <?= e(strtoupper(substr((string)$leader['name'], 0, 1))) ?>
                                </div>
                            <?php endif; ?>

                            <p class="demo-leader-name">
                                <?= e($leader['name']) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted mb-0">
                    Leadership team preview will appear once leaders are scheduled.
                </p>
            <?php endif; ?>
        </aside>
    </div>
</section>

<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    (function () {
        var mapElement = document.getElementById('demo-helsinki-map');

        if (!mapElement || typeof L === 'undefined') {
            return;
        }

        var lat = parseFloat(mapElement.dataset.lat);
        var lng = parseFloat(mapElement.dataset.lng);

        if (Number.isNaN(lat) || Number.isNaN(lng)) {
            return;
        }

        var map = L.map(mapElement, {
            scrollWheelZoom: false,
            dragging: false,
            touchZoom: false,
            doubleClickZoom: false,
            boxZoom: false,
            keyboard: false,
            zoomControl: false,
            attributionControl: false
        }).setView([lat, lng], 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19
        }).addTo(map);

        L.circle([lat, lng], {
            radius: 1609.34,
            color: '#1d70b8',
            fillColor: '#1d70b8',
            weight: 2,
            fillOpacity: 0.16
        }).addTo(map);

        setTimeout(function () {
            map.invalidateSize();
        }, 250);
    })();
</script>

    <?php elseif (!$matchedPerson): ?>

        <section class="onboarding-panel">
            <h2>Find your participant’s record</h2>

            <p>
                Please enter your participant’s last name and date of birth to open the confirmation form.
            </p>

            <form method="post">
                <input type="hidden" name="action" value="lookup">

                <div class="form-group">
                    <label for="last_name">Participant’s last name</label>
                    <input
                        class="form-control"
                        id="last_name"
                        name="last_name"
                        autocomplete="family-name"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="dob">Participant’s date of birth</label>
                    <input
                        class="form-control"
                        id="dob"
                        type="date"
                        name="dob"
                        required
                    >
                </div>

                <button class="btn btn-primary">
                    Continue
                </button>
            </form>
        </section>

    <?php else: ?>

        <section class="onboarding-panel">
            <div class="child-summary">
                <h2><?= e($matchedPerson['name']) ?></h2>

                <p class="mb-0">
                    <?= e($matchedPerson['team_name'] ?: 'Team not yet assigned') ?>

                    <?php if (!empty($matchedPerson['dob'])): ?>
                        <br>
                        <span class="muted">
                            Date of birth:
                            <?= e(date('d M Y', strtotime($matchedPerson['dob']))) ?>
                        </span>
                    <?php endif; ?>
                </p>
            </div>
        </section>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_details">
            <input type="hidden" name="person_id" value="<?= (int)$matchedPerson['id'] ?>">

            <section class="dynamic-section">
                <h3>Photo of your participant</h3>

                <p>
                    Please upload a clear, recent photo of your participant’s face. This is mandatory and helps the leadership team identify young people.
                </p>

                <div class="warning-box">
                    <strong>Photo guidance:</strong>
                    The photo should be of your participant only, ideally a clear head-and-shoulders photo. Please do not upload group photos.
                    This photo will be deleted following the event.
                </div>

                <?php if (!empty($matchedPerson['photo_url'])): ?>
                    <p class="muted">
                        A photo is already on file, but please upload a current one for this event.
                    </p>

                    <p>
                        <img
                            class="current-photo"
                            src="<?= e(url($matchedPerson['photo_url'])) ?>"
                            alt="Current photo of <?= e($matchedPerson['name']) ?>"
                        >
                    </p>
                <?php endif; ?>

                <div class="form-group">
                    <label for="profile_image">Upload photo</label>
                    <input
                        class="form-control"
                        id="profile_image"
                        type="file"
                        name="profile_image"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        required
                    >

                    <small class="form-text text-muted">
                        Accepted formats: JPG, PNG, WEBP or GIF. Maximum file size: 5MB.
                    </small>
                </div>
            </section>

            <section class="dynamic-section">
                <h3>Emergency contacts</h3>

                <p class="muted">
                    Add up to 5 people the trip team should contact in an emergency.
                    Any email address entered here will automatically be included for trip update emails.
                </p>

                <div id="contactsRows">
                    <?php foreach ($contacts as $contact): ?>
                        <div class="dynamic-row contact-row">
                            <div class="dynamic-row-grid">
                                <div class="form-group mb-0">
                                    <label>Name</label>
                                    <input class="form-control" name="contact_name[]" value="<?= e($contact['name'] ?? '') ?>">
                                </div>

                                <div class="form-group mb-0">
                                    <label>Relationship</label>
                                    <input class="form-control" name="contact_relationship[]" value="<?= e($contact['relationship'] ?? '') ?>">
                                </div>

                                <div class="form-group mb-0">
                                    <label>Phone</label>
                                    <input class="form-control" name="contact_phone[]" value="<?= e($contact['phone'] ?? '') ?>">
                                </div>

                                <div class="form-group mb-0">
                                    <label>Email</label>
                                    <input class="form-control contact-email-input" type="email" name="contact_email[]" value="<?= e($contact['email'] ?? '') ?>">
                                </div>

                                <button type="button" class="btn btn-outline-danger" data-remove-row>
                                    Remove
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="dynamic-actions">
                    <button type="button" class="btn btn-outline-primary" id="addContactRow">
                        Add contact
                    </button>
                    <span class="muted" id="contactLimitText"></span>
                </div>
            </section>

            <section class="dynamic-section">
                <h3>Email updates</h3>

                <p class="muted">
                    Emergency contact emails are automatically included below and cannot be edited here.
                    You can add up to 5 additional email addresses to receive progress updates and photos.
                </p>

                <h4>Automatically included from emergency contacts</h4>
                <div id="autoEmailRows"></div>

                <hr>

                <h4>Additional update emails</h4>
                <div id="parentEmailRows">
                    <?php foreach ($parentEmails as $email): ?>
                        <div class="dynamic-row additional-email-row">
                            <div class="dynamic-row-grid simple">
                                <div class="form-group mb-0">
                                    <label>Additional email address</label>
                                    <input class="form-control" type="email" name="parent_emails[]" value="<?= e((string)$email) ?>">
                                </div>

                                <button type="button" class="btn btn-outline-danger" data-remove-row>
                                    Remove
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="dynamic-actions">
                    <button type="button" class="btn btn-outline-primary" id="addParentEmailRow">
                        Add additional email
                    </button>
                    <span class="muted" id="additionalEmailLimitText"></span>
                </div>
            </section>

            <section class="dynamic-section">
                <h3>Medications</h3>

                <p class="muted">
                    Add medication name, dose, frequency, and any important instructions.
                </p>

                <div id="medicationRows">
                    <?php foreach ($medications as $medication): ?>
                        <div class="dynamic-row">
                            <div class="dynamic-row-grid simple">
                                <div class="form-group mb-0">
                                    <label>Medication</label>
                                    <input class="form-control" name="medications[]" value="<?= e((string)$medication) ?>">
                                </div>

                                <button type="button" class="btn btn-outline-danger" data-remove-row>
                                    Remove
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="dynamic-actions">
                    <button type="button" class="btn btn-outline-primary" id="addMedicationRow">
                        Add medication
                    </button>
                </div>
            </section>

            <section class="dynamic-section">
                <h3>Allergies</h3>

                <p class="muted">
                    Add food, medication, environmental or other allergies. Include severity where relevant.
                </p>

                <div id="allergyRows">
                    <?php foreach ($allergies as $allergy): ?>
                        <div class="dynamic-row">
                            <div class="dynamic-row-grid simple">
                                <div class="form-group mb-0">
                                    <label>Allergy</label>
                                    <input class="form-control" name="allergies[]" value="<?= e((string)$allergy) ?>">
                                </div>

                                <button type="button" class="btn btn-outline-danger" data-remove-row>
                                    Remove
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="dynamic-actions">
                    <button type="button" class="btn btn-outline-primary" id="addAllergyRow">
                        Add allergy
                    </button>
                </div>
            </section>

            <section class="dynamic-section">
                <h3>Additional medical or welfare information</h3>

                <div class="form-group">
                    <label for="additional_information">Anything else the leadership team should know?</label>
                    <textarea
                        class="form-control"
                        id="additional_information"
                        name="additional_information"
                        rows="5"
                    ></textarea>
                </div>
            </section>

            <section class="warning-box">
                <strong>Before submitting:</strong>
                Please check the information carefully. This will be used by the leadership team to support the young people safely during the trip.
            </section>

            <button class="btn btn-primary btn-lg">
                Save and submit details
            </button>

            <a class="btn btn-outline-secondary btn-lg" href="<?= e(url('parent_onboarding.php?reset=1')) ?>">
                Start again
            </a>
        </form>

    <?php endif; ?>

</main>

<template id="contactRowTemplate">
    <div class="dynamic-row contact-row">
        <div class="dynamic-row-grid">
            <div class="form-group mb-0">
                <label>Name</label>
                <input class="form-control" name="contact_name[]">
            </div>

            <div class="form-group mb-0">
                <label>Relationship</label>
                <input class="form-control" name="contact_relationship[]">
            </div>

            <div class="form-group mb-0">
                <label>Phone</label>
                <input class="form-control" name="contact_phone[]">
            </div>

            <div class="form-group mb-0">
                <label>Email</label>
                <input class="form-control contact-email-input" type="email" name="contact_email[]">
            </div>

            <button type="button" class="btn btn-outline-danger" data-remove-row>
                Remove
            </button>
        </div>
    </div>
</template>

<template id="simpleEmailRowTemplate">
    <div class="dynamic-row additional-email-row">
        <div class="dynamic-row-grid simple">
            <div class="form-group mb-0">
                <label>Additional email address</label>
                <input class="form-control" type="email" name="parent_emails[]">
            </div>

            <button type="button" class="btn btn-outline-danger" data-remove-row>
                Remove
            </button>
        </div>
    </div>
</template>

<template id="medicationRowTemplate">
    <div class="dynamic-row">
        <div class="dynamic-row-grid simple">
            <div class="form-group mb-0">
                <label>Medication</label>
                <input class="form-control" name="medications[]">
            </div>

            <button type="button" class="btn btn-outline-danger" data-remove-row>
                Remove
            </button>
        </div>
    </div>
</template>

<template id="allergyRowTemplate">
    <div class="dynamic-row">
        <div class="dynamic-row-grid simple">
            <div class="form-group mb-0">
                <label>Allergy</label>
                <input class="form-control" name="allergies[]">
            </div>

            <button type="button" class="btn btn-outline-danger" data-remove-row>
                Remove
            </button>
        </div>
    </div>
</template>

<script>
    (function () {
        var maxContacts = 5;
        var maxAdditionalEmails = 5;

        function countRows(selector) {
            return document.querySelectorAll(selector).length;
        }

        function addRow(buttonId, targetId, templateId, maxRows, rowSelector, limitTextId, limitMessage) {
            var button = document.getElementById(buttonId);
            var target = document.getElementById(targetId);
            var template = document.getElementById(templateId);
            var limitText = document.getElementById(limitTextId);

            if (!button || !target || !template) {
                return;
            }

            function updateLimitState() {
                var count = countRows(rowSelector);
                var atLimit = count >= maxRows;

                button.disabled = atLimit;

                if (limitText) {
                    limitText.textContent = atLimit ? limitMessage : '';
                }
            }

            button.addEventListener('click', function () {
                if (countRows(rowSelector) >= maxRows) {
                    updateLimitState();
                    return;
                }

                target.appendChild(template.content.cloneNode(true));
                updateLimitState();
                updateAutoEmails();
            });

            updateLimitState();
        }

        function addUnlimitedRow(buttonId, targetId, templateId) {
            var button = document.getElementById(buttonId);
            var target = document.getElementById(targetId);
            var template = document.getElementById(templateId);

            if (!button || !target || !template) {
                return;
            }

            button.addEventListener('click', function () {
                target.appendChild(template.content.cloneNode(true));
            });
        }

        function normaliseEmail(email) {
            return String(email || '').trim().toLowerCase();
        }

        function updateAutoEmails() {
            var target = document.getElementById('autoEmailRows');

            if (!target) {
                return;
            }

            var emails = [];
            var seen = {};

            document.querySelectorAll('.contact-email-input').forEach(function (input) {
                var raw = input.value.trim();
                var key = normaliseEmail(raw);

                if (raw !== '' && !seen[key]) {
                    seen[key] = true;
                    emails.push(raw);
                }
            });

            target.innerHTML = '';

            if (!emails.length) {
                var empty = document.createElement('p');
                empty.className = 'muted';
                empty.textContent = 'No emergency contact emails have been entered yet.';
                target.appendChild(empty);
                return;
            }

            emails.forEach(function (email) {
                var row = document.createElement('div');
                row.className = 'dynamic-row readonly-email-row';

                row.innerHTML =
                    '<div class="dynamic-row-grid simple">' +
                        '<div class="form-group mb-0">' +
                            '<label>Included from emergency contact</label>' +
                            '<input class="form-control" type="email" value="' + email.replace(/"/g, '&quot;') + '" disabled>' +
                            '<div class="email-note">This is copied automatically from the emergency contacts section.</div>' +
                        '</div>' +
                        '<span class="muted">Automatic</span>' +
                    '</div>';

                target.appendChild(row);
            });
        }

        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-remove-row]');

            if (!button) {
                return;
            }

            var row = button.closest('.dynamic-row');

            if (row) {
                row.remove();
            }

            updateAutoEmails();

            var contactButton = document.getElementById('addContactRow');
            var contactLimitText = document.getElementById('contactLimitText');

            if (contactButton) {
                var atContactLimit = countRows('.contact-row') >= maxContacts;
                contactButton.disabled = atContactLimit;

                if (contactLimitText) {
                    contactLimitText.textContent = atContactLimit ? 'Maximum 5 emergency contacts reached.' : '';
                }
            }

            var emailButton = document.getElementById('addParentEmailRow');
            var emailLimitText = document.getElementById('additionalEmailLimitText');

            if (emailButton) {
                var atEmailLimit = countRows('.additional-email-row') >= maxAdditionalEmails;
                emailButton.disabled = atEmailLimit;

                if (emailLimitText) {
                    emailLimitText.textContent = atEmailLimit ? 'Maximum 5 additional email addresses reached.' : '';
                }
            }
        });

        document.addEventListener('input', function (event) {
            if (event.target && event.target.classList.contains('contact-email-input')) {
                updateAutoEmails();
            }
        });

        addRow(
            'addContactRow',
            'contactsRows',
            'contactRowTemplate',
            maxContacts,
            '.contact-row',
            'contactLimitText',
            'Maximum 5 emergency contacts reached.'
        );

        addRow(
            'addParentEmailRow',
            'parentEmailRows',
            'simpleEmailRowTemplate',
            maxAdditionalEmails,
            '.additional-email-row',
            'additionalEmailLimitText',
            'Maximum 5 additional email addresses reached.'
        );

        addUnlimitedRow('addMedicationRow', 'medicationRows', 'medicationRowTemplate');
        addUnlimitedRow('addAllergyRow', 'allergyRows', 'allergyRowTemplate');

        updateAutoEmails();
    })();
</script>

</body>
</html>