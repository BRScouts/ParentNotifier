<?php
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();

const PEOPLE_UPLOAD_DIR = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/people/';
const PEOPLE_UPLOAD_PUBLIC_PATH = 'assets/people/';
const ONBOARDING_CONFIRMATION_FROM_EMAIL = 'noreply@app.irvalscouts.org.uk';
const DATA_PROTECTION_EMAIL = 'rammyexplorers@gmail.com';
const ONBOARDING_VERSION = 'explorer-belt-2026-consent-v1';

$error = '';
$success = '';
$matchedPerson = null;
$submittedPerson = null;
$submittedEmails = [];
$confirmationQueued = false;
$isLocked = false;
$lockedPerson = null;

$verifiedPersonId = (int)($_SESSION['parent_onboarding_person_id'] ?? 0);

/**
 * Database helpers
 */
function column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?'
        );
        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );
        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function completion_column(PDO $pdo): ?string
{
    if (column_exists($pdo, 'young_people', 'parent_form_completed_at')) {
        return 'parent_form_completed_at';
    }

    if (column_exists($pdo, 'young_people', 'parent_onboarding_completed_at')) {
        return 'parent_onboarding_completed_at';
    }

    return null;
}

function person_has_completed_onboarding(PDO $pdo, array $person): bool
{
    $column = completion_column($pdo);

    return $column !== null && !empty($person[$column]);
}

function update_young_person(PDO $pdo, int $personId, array $updates): void
{
    $sets = [];
    $values = [];

    foreach ($updates as $column => $value) {
        if (!column_exists($pdo, 'young_people', $column)) {
            continue;
        }

        $sets[] = '`' . str_replace('`', '``', $column) . '` = ?';
        $values[] = $value;
    }

    if (empty($sets)) {
        return;
    }

    $values[] = $personId;

    $stmt = $pdo->prepare(
        'UPDATE young_people
         SET ' . implode(', ', $sets) . '
         WHERE id = ?'
    );
    $stmt->execute($values);
}

/**
 * General helpers
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
        if (is_array($item)) {
            $item = implode(' | ', array_filter(array_map('trim', array_map('strval', $item))));
        }

        $item = trim((string)$item);

        if ($item !== '') {
            $clean[] = $item;
        }
    }

    return empty($clean) ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
}

function json_object(?array $data): ?string
{
    if (empty($data)) {
        return null;
    }

    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function clean_text($value): string
{
    return trim((string)$value);
}

function clean_text_or_null($value): ?string
{
    $value = clean_text($value);

    return $value === '' ? null : $value;
}

function clean_date_or_null($value): ?string
{
    $value = clean_text($value);

    if ($value === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);

    if (!$dt instanceof DateTime) {
        return null;
    }

    return $dt->format('Y-m-d');
}

function yes_no_to_bool(?string $value): ?int
{
    $value = strtolower(trim((string)$value));

    if ($value === 'yes') {
        return 1;
    }

    if ($value === 'no') {
        return 0;
    }

    return null;
}

function yes_no_from_person(array $person, string $column): string
{
    if (!array_key_exists($column, $person) || $person[$column] === null || $person[$column] === '') {
        return '';
    }

    return ((int)$person[$column]) === 1 ? 'yes' : 'no';
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

    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);

        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? null : date('Y-m-d', $timestamp);
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

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $letters = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= strtoupper(substr($part, 0, 1));
        }

        if (strlen($letters) >= 2) {
            break;
        }
    }

    return $letters !== '' ? $letters : '?';
}

function old_value(string $key, $fallback = ''): string
{
    return isset($_POST[$key]) ? (string)$_POST[$key] : (string)$fallback;
}

function old_checked(string $key, string $value, $fallback = ''): string
{
    $current = isset($_POST[$key]) ? (string)$_POST[$key] : (string)$fallback;

    return $current === $value ? 'checked' : '';
}

function old_checkbox(string $key, bool $fallback = false): string
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return !empty($_POST[$key]) ? 'checked' : '';
    }

    return $fallback ? 'checked' : '';
}

function old_selected(string $key, string $value, $fallback = ''): string
{
    $current = isset($_POST[$key]) ? (string)$_POST[$key] : (string)$fallback;

    return $current === $value ? 'selected' : '';
}

/**
 * Form extraction helpers
 */
function emergency_contacts_from_post(): array
{
    $names = $_POST['contact_name'] ?? [];
    $relationships = $_POST['contact_relationship'] ?? [];
    $addresses = $_POST['contact_address'] ?? [];
    $homePhones = $_POST['contact_home_phone'] ?? [];
    $mobilePhones = $_POST['contact_mobile_phone'] ?? [];
    $emails = $_POST['contact_email'] ?? [];

    $contacts = [];

    foreach ((array)$names as $index => $name) {
        if (count($contacts) >= 5) {
            break;
        }

        $name = clean_text($name);
        $relationship = clean_text($relationships[$index] ?? '');
        $address = clean_text($addresses[$index] ?? '');
        $homePhone = clean_text($homePhones[$index] ?? '');
        $mobilePhone = clean_text($mobilePhones[$index] ?? '');
        $email = clean_text($emails[$index] ?? '');

        if ($name === '' && $relationship === '' && $address === '' && $homePhone === '' && $mobilePhone === '' && $email === '') {
            continue;
        }

        $contacts[] = [
            'name' => $name,
            'relationship' => $relationship,
            'address' => $address,
            'home_phone' => $homePhone,
            'mobile_phone' => $mobilePhone,
            'phone' => $mobilePhone !== '' ? $mobilePhone : $homePhone,
            'email' => $email,
        ];
    }

    return $contacts;
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
    return valid_unique_emails(array_merge(emergency_contact_emails($contacts), $additionalEmails));
}

function medications_from_post(): array
{
    $names = $_POST['medication_name'] ?? [];
    $types = $_POST['medication_type'] ?? [];
    $dosages = $_POST['medication_dosage'] ?? [];
    $frequencies = $_POST['medication_frequency'] ?? [];
    $frequencyOther = $_POST['medication_frequency_other'] ?? [];
    $notes = $_POST['medication_notes'] ?? [];

    $items = [];

    foreach ((array)$names as $index => $name) {
        $name = clean_text($name);
        $type = clean_text($types[$index] ?? '');
        $dosage = clean_text($dosages[$index] ?? '');
        $frequency = clean_text($frequencies[$index] ?? '');
        $other = clean_text($frequencyOther[$index] ?? '');
        $note = clean_text($notes[$index] ?? '');

        if ($name === '' && $type === '' && $dosage === '' && $frequency === '' && $other === '' && $note === '') {
            continue;
        }

        if ($frequency === 'Other' && $other !== '') {
            $frequency = 'Other - ' . $other;
        }

        $items[] = trim(
            'Medication: ' . ($name !== '' ? $name : 'Not specified') .
            ' | Type: ' . ($type !== '' ? $type : 'Not specified') .
            ' | Dosage: ' . ($dosage !== '' ? $dosage : 'Not specified') .
            ' | Frequency: ' . ($frequency !== '' ? $frequency : 'Not specified') .
            ($note !== '' ? ' | Notes: ' . $note : '')
        );
    }

    return $items;
}

function allergies_from_post(): array
{
    $types = $_POST['allergy_type'] ?? [];
    $details = $_POST['allergy_detail'] ?? [];
    $severities = $_POST['allergy_severity'] ?? [];
    $notes = $_POST['allergy_notes'] ?? [];

    $items = [];

    foreach ((array)$details as $index => $detail) {
        $type = clean_text($types[$index] ?? '');
        $detail = clean_text($detail);
        $severity = clean_text($severities[$index] ?? '');
        $note = clean_text($notes[$index] ?? '');

        if ($type === '' && $detail === '' && $severity === '' && $note === '') {
            continue;
        }

        $items[] = trim(
            'Type: ' . ($type !== '' ? $type : 'Not specified') .
            ' | Detail: ' . ($detail !== '' ? $detail : 'Not specified') .
            ' | Severity: ' . ($severity !== '' ? $severity : 'Not specified') .
            ($note !== '' ? ' | Notes: ' . $note : '')
        );
    }

    return $items;
}

function posted_signature_data_url(string $key): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));

    if ($value === '') {
        return null;
    }

    if (!preg_match('/^data:image\/(png|jpeg|webp);base64,[a-z0-9+\/=]+$/i', $value)) {
        return null;
    }

    if (strlen($value) > 2000000) {
        return null;
    }

    return $value;
}

function signature_text_from_post(string $signatureKey, string $nameKey): string
{
    $signature = clean_text($_POST[$signatureKey] ?? '');

    if ($signature !== '') {
        return $signature;
    }

    $name = clean_text($_POST[$nameKey] ?? '');

    return $name !== '' ? $name . ' (drawn signature captured)' : '';
}

function build_submission_snapshot(array $person, array $contacts, array $updateEmails): array
{
    return [
        'version' => ONBOARDING_VERSION,
        'participant' => [
            'id' => (int)($person['id'] ?? 0),
            'name' => $person['name'] ?? '',
            'dob' => $person['dob'] ?? '',
            'team_id' => $person['team_id'] ?? null,
            'team_name' => $person['team_name'] ?? '',
            'participant_email' => clean_text($_POST['participant_email'] ?? ''),
            'participant_phone' => clean_text($_POST['participant_phone'] ?? ''),
            'home_address' => clean_text($_POST['home_address'] ?? ''),
            'gender' => clean_text($_POST['gender'] ?? ''),
            'passport_number' => clean_text($_POST['passport_number'] ?? ''),
            'passport_expiry_date' => clean_text($_POST['passport_expiry_date'] ?? ''),
            'passport_nationality' => clean_text($_POST['passport_nationality'] ?? ''),
            'ehic_ghic_number' => clean_text($_POST['ehic_ghic_number'] ?? ''),
            'ehic_ghic_expiry_date' => clean_text($_POST['ehic_ghic_expiry_date'] ?? ''),
        ],
        'emergency_contacts' => $contacts,
        'update_emails' => $updateEmails,
        'health' => [
            'medical_condition' => clean_text($_POST['health_medical_condition'] ?? ''),
            'medical_condition_details' => clean_text($_POST['health_medical_condition_details'] ?? ''),
            'physical_restriction' => clean_text($_POST['health_physical_restriction'] ?? ''),
            'physical_restriction_details' => clean_text($_POST['health_physical_restriction_details'] ?? ''),
            'medication_allergy' => clean_text($_POST['health_medication_allergy'] ?? ''),
            'medication_allergy_details' => clean_text($_POST['health_medication_allergy_details'] ?? ''),
            'medications' => medications_from_post(),
            'allergies' => allergies_from_post(),
            'family_doctor_name' => clean_text($_POST['family_doctor_name'] ?? ''),
            'family_doctor_phone' => clean_text($_POST['family_doctor_phone'] ?? ''),
            'family_doctor_address' => clean_text($_POST['family_doctor_address'] ?? ''),
            'additional_information' => clean_text($_POST['additional_information'] ?? ''),
        ],
        'declarations' => [
            'medical_declaration_agreement' => !empty($_POST['medical_declaration_agreement']),
            'final_declaration_agreement' => !empty($_POST['final_declaration_agreement']),
            'privacy_acknowledgement' => !empty($_POST['privacy_acknowledgement']),
        ],
        'signatures' => [
            'parent_guardian_name' => clean_text($_POST['parent_guardian_name'] ?? ''),
            'parent_guardian_signature' => signature_text_from_post('parent_guardian_signature', 'parent_guardian_name'),
            'parent_signature_data_url_present' => posted_signature_data_url('parent_signature_data_url') !== null,
            'young_person_name' => clean_text($_POST['young_person_name'] ?? ''),
            'young_person_signature' => signature_text_from_post('young_person_signature', 'young_person_name'),
            'young_person_signature_data_url_present' => posted_signature_data_url('young_person_signature_data_url') !== null,
        ],
        'submitted' => [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'submitted_at' => date('c'),
        ],
    ];
}

/**
 * Data helpers
 */
function fetch_person(PDO $pdo, int $personId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            yp.*,
            t.name AS team_name,
            t.parent_token
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
            t.name AS team_name,
            t.parent_token
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

    return fetch_person($pdo, (int)$matches[0]['id']);
}

function team_parent_link(array $person): string
{
    if (empty($person['parent_token'])) {
        return url('dashboard.php');
    }

    return url('dashboard.php?token=' . $person['parent_token']);
}

/**
 * Upload helper
 */
function handle_parent_profile_upload(string $fieldName, ?string $existingPath = null): ?string
{
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return $existingPath;
    }

    $file = $_FILES[$fieldName];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existingPath;
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
 * Email queue
 */
function queue_onboarding_confirmation_email(PDO $pdo, string $toEmail, array $person, array $allUpdateEmails): void
{
    if (!table_exists($pdo, 'email_queue')) {
        return;
    }

    $teamName = $person['team_name'] ?? 'your participant’s team';
    $privateTeamLink = team_parent_link($person);
    $subject = 'Explorer Belt onboarding completed';

    $content =
        '<p>Thank you. The Explorer Belt onboarding and consent details for <strong>' . e($person['name'] ?? 'your participant') . '</strong> have been submitted.</p>' .
        '<p>The trip team has received the personal details, health declaration, emergency contacts and signed declarations.</p>' .
        '<p>Your private teams update page is:</p>' .
        '<p><a href="' . e($privateTeamLink) . '">' . e($privateTeamLink) . '</a></p>' .
        '<p>This is where updates, photos and evening check-in locations will be provided during the event for ' . e($teamName) . '.</p>' .
        '<p><strong>No news is not bad news.</strong> Due to signal, time to process updates, and the need to ensure all teams have checked in, updates may not appear immediately.</p>' .
        '<p>During the event, please contact the Home Contact shown on the contact page rather than contacting the team directly.</p>' .
        '<hr>' .
        '<p><strong>Email addresses that will receive updates during the trip:</strong></p><ul>';

    foreach ($allUpdateEmails as $email) {
        $content .= '<li>' . e($email) . '</li>';
    }

    $content .= '</ul>' .
        '<p>Please look out for emails from <strong>' . e(ONBOARDING_CONFIRMATION_FROM_EMAIL) . '</strong> and consider adding this email address to your safe senders list.</p>';

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
            /** Do not block the form if the email queue is temporarily unavailable. */
        }
    }

    return $queuedAny;
}

/**
 * Audit / submission log
 */
function insert_parent_audit_log(PDO $pdo, int $personId, string $additionalInformation, bool $photoUpdated, array $updateEmails, string $signatureHash): void
{
    if (!table_exists($pdo, 'person_logs')) {
        return;
    }

    try {
        $body = "Parent onboarding and consent form completed.\n\n";

        if ($photoUpdated) {
            $body .= "A profile photo was uploaded or updated by the parent/guardian.\n\n";
        }

        if (!empty($updateEmails)) {
            $body .= "Update emails confirmed:\n";
            $body .= implode("\n", $updateEmails) . "\n\n";
        }

        $body .= "Parent/guardian signature: " . signature_text_from_post('parent_guardian_signature', 'parent_guardian_name') . "\n";
        $body .= "Young person signature: " . signature_text_from_post('young_person_signature', 'young_person_name') . "\n";
        $body .= "Signature hash: " . $signatureHash . "\n\n";

        if (trim($additionalInformation) !== '') {
            $body .= "Additional information provided by parent/guardian:\n" . trim($additionalInformation);
        } else {
            $body .= "No additional information was provided.";
        }

        $stmt = $pdo->prepare(
            'INSERT INTO person_logs
                (person_id, leader_id, log_type, title, body, occurred_at)
             VALUES
                (?, NULL, "general", "Parent onboarding completed", ?, NOW())'
        );

        $stmt->execute([$personId, $body]);
    } catch (Throwable $exception) {
        /** Do not block the form if person_logs is unavailable. */
    }
}

function insert_onboarding_submission(PDO $pdo, int $personId, array $snapshot, string $signatureHash): void
{
    if (!table_exists($pdo, 'parent_onboarding_submissions')) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO parent_onboarding_submissions
            (
                person_id,
                snapshot_json,
                parent_guardian_name,
                parent_guardian_signature,
                parent_signature_data_url,
                young_person_name,
                young_person_signature,
                young_person_signature_data_url,
                signature_hash,
                submitted_ip,
                submitted_user_agent,
                submitted_at
            )
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );

    $stmt->execute([
        $personId,
        json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        clean_text($_POST['parent_guardian_name'] ?? ''),
        signature_text_from_post('parent_guardian_signature', 'parent_guardian_name'),
        posted_signature_data_url('parent_signature_data_url'),
        clean_text($_POST['young_person_name'] ?? ''),
        signature_text_from_post('young_person_signature', 'young_person_name'),
        posted_signature_data_url('young_person_signature_data_url'),
        $signatureHash,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

/**
 * Validation
 */
function validate_parent_form(PDO $pdo, ?array $person): array
{
    $errors = [];
    $contacts = emergency_contacts_from_post();

    if (count($contacts) > 5) {
        $errors[] = 'You can add a maximum of 5 emergency contacts.';
    }

    if (count($contacts) < 2) {
        $errors[] = 'Please provide at least two emergency contacts.';
    }

    foreach ($contacts as $contact) {
        $name = trim((string)($contact['name'] ?? ''));
        $homePhone = trim((string)($contact['home_phone'] ?? ''));
        $mobilePhone = trim((string)($contact['mobile_phone'] ?? ''));
        $email = trim((string)($contact['email'] ?? ''));

        if ($name === '') {
            $errors[] = 'Each emergency contact must have a name.';
        }

        if (trim((string)($contact['relationship'] ?? '')) === '') {
            $errors[] = 'Each emergency contact must have a relationship.';
        }

        if (trim((string)($contact['address'] ?? '')) === '') {
            $errors[] = 'Each emergency contact must have an address.';
        }

        if ($homePhone === '') {
            $errors[] = 'Each emergency contact must have a home telephone number.';
        }

        if ($mobilePhone === '') {
            $errors[] = 'Each emergency contact must have a mobile telephone number.';
        }

        if ($email === '') {
            $errors[] = 'Each emergency contact must have an email address.';
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'One of the emergency contact email addresses is not valid.';
        }
    }

    $additionalEmails = $_POST['parent_emails'] ?? [];

    if (is_array($additionalEmails) && count(array_filter($additionalEmails, static function ($value) {
        return trim((string)$value) !== '';
    })) > 5) {
        $errors[] = 'You can add a maximum of 5 additional update email addresses.';
    }

    foreach ((array)$additionalEmails as $email) {
        $email = trim((string)$email);

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'One of the additional update email addresses is not valid.';
        }
    }

    $participantEmail = trim($_POST['participant_email'] ?? '');

    if ($participantEmail !== '' && !filter_var($participantEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'The participant contact email address is not valid.';
    }

    if (empty($_FILES['profile_image']) || !is_array($_FILES['profile_image']) || (($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
        $errors[] = 'Please upload a clear, recent photo of the participant.';
    }

    $requiredTextFields = [
        'participant_email' => 'Participant contact email',
        'participant_phone' => 'Participant mobile number',
        'home_address' => 'Home address',
        'gender' => 'Gender',
        'passport_number' => 'Passport number',
        'passport_expiry_date' => 'Passport expiry date',
        'passport_nationality' => 'Passport nationality',
        'ehic_ghic_number' => 'EHIC/GHIC number',
        'ehic_ghic_expiry_date' => 'EHIC/GHIC expiry date',
        'family_doctor_address' => 'Family doctor address',
        'additional_information' => 'Additional medical or welfare information',
    ];

    foreach ($requiredTextFields as $field => $label) {
        if (clean_text($_POST[$field] ?? '') === '') {
            $errors[] = $label . ' is required.';
        }
    }

    $dateFields = [
        'passport_expiry_date' => 'Passport expiry date',
        'ehic_ghic_expiry_date' => 'EHIC/GHIC expiry date',
    ];

    foreach ($dateFields as $field => $label) {
        $value = trim($_POST[$field] ?? '');

        if ($value !== '' && clean_date_or_null($value) === null) {
            $errors[] = $label . ' must be a valid date.';
        }
    }

    foreach (['health_medical_condition', 'health_physical_restriction', 'health_medication_allergy'] as $field) {
        if (!in_array($_POST[$field] ?? '', ['yes', 'no'], true)) {
            $errors[] = 'Please answer all health declaration yes/no questions.';
            break;
        }
    }

    if (($_POST['health_medical_condition'] ?? '') === 'yes' && clean_text($_POST['health_medical_condition_details'] ?? '') === '') {
        $errors[] = 'Please provide details of the medical condition, allergy or intolerance.';
    }

    if (($_POST['health_physical_restriction'] ?? '') === 'yes' && clean_text($_POST['health_physical_restriction_details'] ?? '') === '') {
        $errors[] = 'Please provide details of the physical condition, injury or incapacity.';
    }

    if (($_POST['health_medication_allergy'] ?? '') === 'yes' && clean_text($_POST['health_medication_allergy_details'] ?? '') === '') {
        $errors[] = 'Please provide details of the medication allergy.';
    }

    if (clean_text($_POST['family_doctor_name'] ?? '') === '') {
        $errors[] = 'Please provide the family doctor name.';
    }

    if (clean_text($_POST['family_doctor_phone'] ?? '') === '') {
        $errors[] = 'Please provide the family doctor telephone number.';
    }

    $requiredChecks = [
        'medical_declaration_agreement' => 'Please confirm the medical consent declaration.',
        'final_declaration_agreement' => 'Please confirm the final Explorer Belt declaration.',
        'privacy_acknowledgement' => 'Please confirm that you have read the privacy notice.',
    ];

    foreach ($requiredChecks as $field => $message) {
        if (empty($_POST[$field])) {
            $errors[] = $message;
        }
    }

    if (clean_text($_POST['parent_guardian_name'] ?? '') === '') {
        $errors[] = 'Please enter the parent/guardian name.';
    }

    if (posted_signature_data_url('parent_signature_data_url') === null) {
        $errors[] = 'Please draw the parent/guardian signature in the signature box.';
    }

    if (clean_text($_POST['young_person_name'] ?? '') === '') {
        $errors[] = 'Please enter the young person name for the declaration.';
    }

    if (posted_signature_data_url('young_person_signature_data_url') === null) {
        $errors[] = 'Please draw the young person signature in the signature box.';
    }

    return array_values(array_unique($errors));
}

/**
 * Save orchestration
 */
function build_young_person_updates(PDO $pdo, array $person, ?string $photoPath, array $contacts, array $mergedEmails, string $signatureHash): array
{
    $now = date('Y-m-d H:i:s');
    $completionColumn = completion_column($pdo);
    $updates = [
        'participant_email' => clean_text_or_null($_POST['participant_email'] ?? ''),
        'participant_phone' => clean_text_or_null($_POST['participant_phone'] ?? ''),
        'home_address' => clean_text_or_null($_POST['home_address'] ?? ''),
        'gender' => clean_text_or_null($_POST['gender'] ?? ''),
        'photo_url' => $photoPath,
        'emergency_contacts_json' => empty($contacts) ? null : json_encode($contacts, JSON_UNESCAPED_UNICODE),
        'parent_emails_json' => json_list_from_array($mergedEmails),
        'passport_number' => clean_text_or_null($_POST['passport_number'] ?? ''),
        'passport_expiry_date' => clean_date_or_null($_POST['passport_expiry_date'] ?? ''),
        'passport_nationality' => clean_text_or_null($_POST['passport_nationality'] ?? ''),
        'ehic_ghic_number' => clean_text_or_null($_POST['ehic_ghic_number'] ?? ''),
        'ehic_ghic_expiry_date' => clean_date_or_null($_POST['ehic_ghic_expiry_date'] ?? ''),
        'health_medical_condition' => yes_no_to_bool($_POST['health_medical_condition'] ?? null),
        'health_medical_condition_details' => clean_text_or_null($_POST['health_medical_condition_details'] ?? ''),
        'health_physical_restriction' => yes_no_to_bool($_POST['health_physical_restriction'] ?? null),
        'health_physical_restriction_details' => clean_text_or_null($_POST['health_physical_restriction_details'] ?? ''),
        'health_medication_allergy' => yes_no_to_bool($_POST['health_medication_allergy'] ?? null),
        'health_medication_allergy_details' => clean_text_or_null($_POST['health_medication_allergy_details'] ?? ''),
        'family_doctor_name' => clean_text_or_null($_POST['family_doctor_name'] ?? ''),
        'family_doctor_phone' => clean_text_or_null($_POST['family_doctor_phone'] ?? ''),
        'family_doctor_address' => clean_text_or_null($_POST['family_doctor_address'] ?? ''),
        'medical_consent_given_at' => $now,
        'final_consent_given_at' => $now,
        'parent_guardian_name' => clean_text_or_null($_POST['parent_guardian_name'] ?? ''),
        'parent_guardian_signature' => signature_text_from_post('parent_guardian_signature', 'parent_guardian_name'),
        'parent_signature_data_url' => posted_signature_data_url('parent_signature_data_url'),
        'young_person_declaration_name' => clean_text_or_null($_POST['young_person_name'] ?? ''),
        'young_person_signature' => signature_text_from_post('young_person_signature', 'young_person_name'),
        'young_person_signature_data_url' => posted_signature_data_url('young_person_signature_data_url'),
        'onboarding_declaration_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'onboarding_declaration_user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'onboarding_signature_hash' => $signatureHash,
        'onboarding_version' => ONBOARDING_VERSION,
    ];

    if ($completionColumn !== null) {
        $updates[$completionColumn] = $now;
    }

    return $updates;
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
        } elseif (person_has_completed_onboarding($pdo, $person)) {
            $isLocked = true;
            $lockedPerson = $person;
            unset($_SESSION['parent_onboarding_person_id']);
        } else {
            $_SESSION['parent_onboarding_person_id'] = (int)$person['id'];
            redirect('parent_onboarding.php?step=form');
        }
    }
}

/**
 * Load matched person from session.
 */
if ($verifiedPersonId > 0) {
    $matchedPerson = fetch_person($pdo, $verifiedPersonId);

    if (!$matchedPerson) {
        unset($_SESSION['parent_onboarding_person_id']);
        $verifiedPersonId = 0;
        $matchedPerson = null;
    } elseif (person_has_completed_onboarding($pdo, $matchedPerson)) {
        $isLocked = true;
        $lockedPerson = $matchedPerson;
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
    } elseif (person_has_completed_onboarding($pdo, $matchedPerson)) {
        $isLocked = true;
        $lockedPerson = $matchedPerson;
        $error = 'This onboarding form has already been completed.';
        unset($_SESSION['parent_onboarding_person_id']);
        $matchedPerson = null;
    } else {
        $formErrors = validate_parent_form($pdo, $matchedPerson);

        if (!empty($formErrors)) {
            $error = implode(' ', $formErrors);
        } else {
            try {
                $contactsArray = emergency_contacts_from_post();
                $additionalEmails = additional_update_emails_from_post();
                $mergedParentEmails = merged_parent_update_emails($contactsArray, $additionalEmails);
                $photoPath = handle_parent_profile_upload('profile_image', $matchedPerson['photo_url'] ?? null);
                $photoUpdated = $photoPath !== ($matchedPerson['photo_url'] ?? null);

                $snapshot = build_submission_snapshot($matchedPerson, $contactsArray, $mergedParentEmails);
                $signatureHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $additionalInformation = trim($_POST['additional_information'] ?? '');

                $pdo->beginTransaction();

                update_young_person(
                    $pdo,
                    $personId,
                    build_young_person_updates($pdo, $matchedPerson, $photoPath, $contactsArray, $mergedParentEmails, $signatureHash)
                );

                insert_onboarding_submission($pdo, $personId, $snapshot, $signatureHash);

                insert_parent_audit_log(
                    $pdo,
                    $personId,
                    $additionalInformation,
                    $photoUpdated,
                    $mergedParentEmails,
                    $signatureHash
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

                $success = 'Thank you. The details and consent declarations have been submitted to the trip team.';
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
$medications = [];
$allergies = [];

if ($matchedPerson) {
    $storedContacts = array_slice(json_items($matchedPerson['emergency_contacts_json'] ?? null), 0, 5);
    $normalisedContacts = [];

    foreach ($storedContacts as $contact) {
        $contact = is_array($contact) ? $contact : [];
        $normalisedContacts[] = [
            'name' => $contact['name'] ?? '',
            'relationship' => $contact['relationship'] ?? '',
            'address' => $contact['address'] ?? '',
            'home_phone' => $contact['home_phone'] ?? '',
            'mobile_phone' => $contact['mobile_phone'] ?? ($contact['phone'] ?? ''),
            'email' => $contact['email'] ?? '',
        ];
    }

    $contacts = ensure_rows(
        $normalisedContacts,
        2,
        [
            'name' => '',
            'relationship' => '',
            'address' => '',
            'home_phone' => '',
            'mobile_phone' => '',
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

    $parentEmails = array_slice($additionalOnlyEmails, 0, 5);
}

$privateTeamLink = $submittedPerson ? team_parent_link($submittedPerson) : '';
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
        body { background: #f3f2f1; }
        .onboarding-header { background: #7413dc; color: #fff; padding: 2rem 0; margin-bottom: 2rem; }
        .onboarding-header h1, .onboarding-header p { color: #fff; }
        .onboarding-panel, .dynamic-section { border: 2px solid #d8d8d8; background: #fff; padding: 1.5rem; margin-bottom: 1.5rem; }
        .onboarding-panel h2, .dynamic-section h2, .dynamic-section h3, .dynamic-section h4 { margin-top: 0; font-weight: 900; }
        .onboarding-panel label, .dynamic-section label { font-weight: 800; }
        .info-box { border-left: 8px solid #1d70b8; background: #eef7ff; padding: 1rem; margin-bottom: 1.5rem; }
        .warning-box { border-left: 8px solid #ffdd00; background: #fff7bf; padding: 1rem; margin-bottom: 1.5rem; }
        .success-box { border-left: 8px solid #00703c; background: #e9f8ef; padding: 1rem; margin-bottom: 1.5rem; }
        .locked-box { border-left: 8px solid #d4351c; background: #fff1f0; padding: 1rem; margin-bottom: 1.5rem; }
        .child-summary { border: 2px solid #1d1d1d; background: #f8f8f8; padding: 1rem; }
        .muted { color: #505a5f; }
        .step-indicator { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; list-style: none; padding: 0; margin: 0 0 1.5rem; }
        .step-indicator li { border: 2px solid #d8d8d8; background: #fff; padding: .8rem; font-weight: 900; }
        .step-indicator li.active { border-color: #7413dc; box-shadow: inset 0 -6px 0 #7413dc; }
        .wizard-step[hidden] { display: none !important; }
        .dynamic-row { border: 2px solid #d8d8d8; background: #f8f8f8; padding: .75rem; margin-bottom: .75rem; }
        .dynamic-row-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) auto; gap: .75rem; align-items: end; }
        .dynamic-row-grid.contact { grid-template-columns: repeat(6, minmax(0, 1fr)) auto; }
        .dynamic-row-grid.simple { grid-template-columns: minmax(0, 1fr) auto; }
        .dynamic-row-grid.medication { grid-template-columns: repeat(5, minmax(0, 1fr)) auto; }
        .dynamic-row-grid.allergy { grid-template-columns: repeat(4, minmax(0, 1fr)) auto; }
        @media (max-width: 1200px) { .dynamic-row-grid, .dynamic-row-grid.contact, .dynamic-row-grid.simple, .dynamic-row-grid.medication, .dynamic-row-grid.allergy { grid-template-columns: 1fr; } }
        .dynamic-actions, .wizard-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1rem; }
        .readonly-email-row { background: #eef7ff; border-left: 6px solid #1d70b8; }
        .email-note { color: #505a5f; font-size: .95rem; margin-top: .25rem; }
        .current-photo { width: 120px; height: 120px; object-fit: cover; border: 2px solid #1d1d1d; background: #f3f2f1; }
        .private-link-box { border: 2px solid #00703c; background: #e9f8ef; padding: 1rem; word-break: break-all; margin: 1rem 0; }
        .signature-pad { border: 2px solid #1d1d1d; background: #fff; width: 100%; height: 180px; touch-action: none; display: block; }
        .signature-pad.signature-error { border-color: #d4351c; box-shadow: 0 0 0 3px rgba(212, 53, 28, .2); }
        .declaration-list { padding-left: 1.2rem; margin-bottom: 1rem; }
        .declaration-list li { margin-bottom: .65rem; }
        .signature-help { font-size: .95rem; color: #505a5f; margin-top: .35rem; }
        .yes-no-group { display: flex; gap: 1.5rem; flex-wrap: wrap; }
    </style>
</head>
<body>

<header class="onboarding-header">
    <div class="container">
        <h1>Explorer Belt parent onboarding</h1>
        <p class="lead mb-0">Confirm personal details, health data, emergency contacts and consent declarations.</p>
    </div>
</header>

<main class="container mb-5">
    <div class="info-box">
        <strong>Privacy note:</strong>
        This form is used to safely run Explorer Belt 2026. Information submitted here will be available to authorised leaders supporting the trip.
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($isLocked && $lockedPerson): ?>
        <section class="locked-box">
            <h2>Form already completed</h2>
            <p>The onboarding form for <strong><?= e($lockedPerson['name']) ?></strong> has already been submitted.</p>
            <p class="mb-0">
                For security and data protection reasons, this form cannot be viewed again once submitted.
                To change anything, please email <a href="mailto:<?= e(DATA_PROTECTION_EMAIL) ?>"><?= e(DATA_PROTECTION_EMAIL) ?></a> and ask for the form to be unlocked.
            </p>
        </section>
        <p><a class="btn btn-outline-primary" href="<?= e(url('parent_onboarding.php?reset=1')) ?>">Start again</a></p>

    <?php elseif ($success && $submittedPerson): ?>
        <section class="success-box">
            <h2>Details submitted</h2>
            <p><?= e($success) ?></p>
            <?php if ($confirmationQueued): ?>
                <p>A confirmation email has been queued for the update email addresses provided. Please look out for an email from <strong><?= e(ONBOARDING_CONFIRMATION_FROM_EMAIL) ?></strong>.</p>
            <?php else: ?>
                <p>The details were saved. A confirmation email could not be queued automatically, so please contact the trip team if you need confirmation.</p>
            <?php endif; ?>
            <p class="mb-0">If you do not receive an email within the next hour, please check your junk or spam folder.</p>
        </section>

        <section class="onboarding-panel">
            <h2>Your private team page</h2>
            <p>Please save this link. This is where updates, photos and evening location check-ins will be provided during the event.</p>
            <div class="private-link-box">
                <strong><?= e($submittedPerson['team_name'] ?: 'Team page') ?></strong><br>
                <a href="<?= e($privateTeamLink) ?>"><?= e($privateTeamLink) ?></a>
            </div>
            <div class="warning-box mb-0">
                <strong>No news is not bad news.</strong>
                <p class="mb-0">Due to signal, time to process updates, and the need to ensure all teams have checked in safely, updates may not happen immediately.</p>
            </div>
        </section>

    <?php elseif (!$matchedPerson): ?>
        <section class="onboarding-panel">
            <h2>Find your participant’s record</h2>
            <p>Please enter your participant’s last name and date of birth to open the onboarding form.</p>
            <form method="post">
                <input type="hidden" name="action" value="lookup">
                <div class="form-group">
                    <label for="last_name">Participant’s last name</label>
                    <input class="form-control" id="last_name" name="last_name" autocomplete="family-name" required>
                </div>
                <div class="form-group">
                    <label for="dob">Participant’s date of birth</label>
                    <input class="form-control" id="dob" type="date" name="dob" required>
                </div>
                <button class="btn btn-primary">Continue</button>
            </form>
        </section>

    <?php else: ?>
        <section class="onboarding-panel">
            <div class="child-summary">
                <h2><?= e($matchedPerson['name']) ?></h2>
                <p class="mb-0">
                    <?= e($matchedPerson['team_name'] ?: 'Team not yet assigned') ?>
                    <?php if (!empty($matchedPerson['dob'])): ?><br><span class="muted">Date of birth: <?= e(date('d M Y', strtotime($matchedPerson['dob']))) ?></span><?php endif; ?>
                </p>
            </div>
        </section>

        <form method="post" enctype="multipart/form-data" id="onboardingForm">
            <input type="hidden" name="action" value="save_details">
            <input type="hidden" name="person_id" value="<?= (int)$matchedPerson['id'] ?>">
            <input type="hidden" name="parent_signature_data_url" id="parent_signature_data_url">
            <input type="hidden" name="young_person_signature_data_url" id="young_person_signature_data_url">
            <input type="hidden" name="parent_guardian_signature" id="parent_guardian_signature">
            <input type="hidden" name="young_person_signature" id="young_person_signature">

            <ol class="step-indicator" aria-label="Onboarding steps">
                <li class="active" data-step-label="1">1. Personal details</li>
                <li data-step-label="2">2. Health data</li>
                <li data-step-label="3">3. Declarations</li>
            </ol>

            <section class="wizard-step" data-step="1">
                <div class="dynamic-section">
                    <h2>Personal details</h2>
                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select class="form-control" id="gender" name="gender" required>
                            <?php $genderValue = $matchedPerson['gender'] ?? ''; ?>
                            <option value="Prefer not to say" <?= old_selected('gender', 'Prefer not to say', $genderValue) ?>>Prefer not to say / not recorded</option>
                            <option value="Female" <?= old_selected('gender', 'Female', $genderValue) ?>>Female</option>
                            <option value="Male" <?= old_selected('gender', 'Male', $genderValue) ?>>Male</option>
                            <option value="Non-binary" <?= old_selected('gender', 'Non-binary', $genderValue) ?>>Non-binary</option>
                            <option value="Other" <?= old_selected('gender', 'Other', $genderValue) ?>>Other</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="participant_email">Participant contact email</label>
                            <input class="form-control" id="participant_email" name="participant_email" type="email" required value="<?= e(old_value('participant_email', $matchedPerson['participant_email'] ?? '')) ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="participant_phone">Participant mobile number</label>
                            <input class="form-control" id="participant_phone" name="participant_phone" required value="<?= e(old_value('participant_phone', $matchedPerson['participant_phone'] ?? '')) ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="home_address">Home address</label>
                        <textarea class="form-control" id="home_address" name="home_address" rows="3" required><?= e(old_value('home_address', $matchedPerson['home_address'] ?? '')) ?></textarea>
                    </div>

                    <h3>Travel documents</h3>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="passport_number">Passport number</label>
                            <input class="form-control" id="passport_number" name="passport_number" required value="<?= e(old_value('passport_number', $matchedPerson['passport_number'] ?? '')) ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="passport_expiry_date">Passport expiry date</label>
                            <input class="form-control" id="passport_expiry_date" type="date" name="passport_expiry_date" required value="<?= e(old_value('passport_expiry_date', $matchedPerson['passport_expiry_date'] ?? '')) ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="passport_nationality">Passport nationality</label>
                            <input class="form-control" id="passport_nationality" name="passport_nationality" required value="<?= e(old_value('passport_nationality', $matchedPerson['passport_nationality'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="ehic_ghic_number">EHIC/GHIC number</label>
                            <input class="form-control" id="ehic_ghic_number" name="ehic_ghic_number" required value="<?= e(old_value('ehic_ghic_number', $matchedPerson['ehic_ghic_number'] ?? '')) ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="ehic_ghic_expiry_date">EHIC/GHIC expiry date</label>
                            <input class="form-control" id="ehic_ghic_expiry_date" type="date" name="ehic_ghic_expiry_date" required value="<?= e(old_value('ehic_ghic_expiry_date', $matchedPerson['ehic_ghic_expiry_date'] ?? '')) ?>">
                        </div>
                    </div>
                </div>

                <div class="dynamic-section">
                    <h3>Photo of participant</h3>
                    <p class="muted">Upload a clear, recent photo for this event. A new upload is required so leaders have an up-to-date identification photo.</p>
                    <?php if (!empty($matchedPerson['photo_url'])): ?>
                        <p><img class="current-photo" src="<?= e(url($matchedPerson['photo_url'])) ?>" alt="Current photo of <?= e($matchedPerson['name']) ?>"></p>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="profile_image">Upload photo</label>
                        <input class="form-control" id="profile_image" type="file" name="profile_image" accept="image/jpeg,image/png,image/webp,image/gif" required>
                        <small class="form-text text-muted">Accepted formats: JPG, PNG, WEBP or GIF. Maximum file size: 5MB.</small>
                    </div>
                </div>

                <div class="dynamic-section">
                    <h3>Emergency contacts</h3>
                    <p class="muted">Add at least two emergency contacts. Any email entered here will automatically receive trip update emails.</p>
                    <div id="contactsRows">
                        <?php foreach ($contacts as $contact): ?>
                            <div class="dynamic-row contact-row">
                                <div class="dynamic-row-grid contact">
                                    <div class="form-group mb-0"><label>Name</label><input class="form-control" name="contact_name[]" required value="<?= e($contact['name'] ?? '') ?>"></div>
                                    <div class="form-group mb-0"><label>Relationship</label><input class="form-control" name="contact_relationship[]" required value="<?= e($contact['relationship'] ?? '') ?>"></div>
                                    <div class="form-group mb-0"><label>Address</label><input class="form-control" name="contact_address[]" required value="<?= e($contact['address'] ?? '') ?>"></div>
                                    <div class="form-group mb-0"><label>Home phone</label><input class="form-control" name="contact_home_phone[]" required value="<?= e($contact['home_phone'] ?? '') ?>"></div>
                                    <div class="form-group mb-0"><label>Mobile phone</label><input class="form-control" name="contact_mobile_phone[]" required value="<?= e($contact['mobile_phone'] ?? '') ?>"></div>
                                    <div class="form-group mb-0"><label>Email</label><input class="form-control contact-email-input" type="email" name="contact_email[]" required value="<?= e($contact['email'] ?? '') ?>"></div>
                                    <button type="button" class="btn btn-outline-danger" data-remove-row>Remove</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="dynamic-actions"><button type="button" class="btn btn-outline-primary" id="addContactRow">Add contact</button><span class="muted" id="contactLimitText"></span></div>
                </div>

                <div class="dynamic-section">
                    <h3>Email updates</h3>
                    <div class="info-box">
                        <strong>Who receives updates?</strong>
                        We will send Explorer Belt updates to these email addresses while the young people are in Finland. Updates may include where the team is, when they are safe for the evening, trip photos, general progress updates and logistical information. These addresses do not have to be parents or guardians, but anyone added here will be able to access the private trip update page, including photos and all trip updates.
                    </div>
                    <div class="warning-box"><strong>Important:</strong> Please make sure you have permission from each person before adding their email address for updates.</div>
                    <h4>Automatically included from emergency contacts</h4>
                    <div id="autoEmailRows"></div>
                    <hr>
                    <h4>Additional update emails</h4>
                    <div id="parentEmailRows">
                        <?php foreach ($parentEmails as $email): ?>
                            <div class="dynamic-row additional-email-row">
                                <div class="dynamic-row-grid simple">
                                    <div class="form-group mb-0"><label>Additional email address</label><input class="form-control" type="email" name="parent_emails[]" required value="<?= e((string)$email) ?>"></div>
                                    <button type="button" class="btn btn-outline-danger" data-remove-row>Remove</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="dynamic-actions"><button type="button" class="btn btn-outline-primary" id="addParentEmailRow">Add additional email</button><span class="muted" id="additionalEmailLimitText"></span></div>
                </div>

                <div class="wizard-actions">
                    <button type="button" class="btn btn-primary btn-lg js-next">Continue to health data</button>
                    <a class="btn btn-outline-secondary btn-lg" href="<?= e(url('parent_onboarding.php?reset=1')) ?>">Start again</a>
                </div>
            </section>

            <section class="wizard-step" data-step="2" hidden>
                <div class="dynamic-section">
                    <h2>Health declaration</h2>

                    <?php $medicalCondition = yes_no_from_person($matchedPerson, 'health_medical_condition'); ?>
                    <div class="form-group">
                        <label>To the best of your knowledge, has your son/daughter any medical condition, allergy or intolerance?</label>
                        <div class="yes-no-group">
                            <label><input type="radio" name="health_medical_condition" value="yes" <?= old_checked('health_medical_condition', 'yes', $medicalCondition) ?> required> Yes</label>
                            <label><input type="radio" name="health_medical_condition" value="no" <?= old_checked('health_medical_condition', 'no', $medicalCondition) ?> required> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="health_medical_condition_details">Details, including medication taken</label>
                        <textarea class="form-control" id="health_medical_condition_details" name="health_medical_condition_details" rows="4" data-required-if="health_medical_condition:yes"><?= e(old_value('health_medical_condition_details', $matchedPerson['health_medical_condition_details'] ?? '')) ?></textarea>
                    </div>

                    <?php $physicalRestriction = yes_no_from_person($matchedPerson, 'health_physical_restriction'); ?>
                    <div class="form-group">
                        <label>Has your son/daughter any physical condition, injury or incapacity that may restrict them taking part in the proposed activities?</label>
                        <div class="yes-no-group">
                            <label><input type="radio" name="health_physical_restriction" value="yes" <?= old_checked('health_physical_restriction', 'yes', $physicalRestriction) ?> required> Yes</label>
                            <label><input type="radio" name="health_physical_restriction" value="no" <?= old_checked('health_physical_restriction', 'no', $physicalRestriction) ?> required> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="health_physical_restriction_details">Details</label>
                        <textarea class="form-control" id="health_physical_restriction_details" name="health_physical_restriction_details" rows="4" data-required-if="health_physical_restriction:yes"><?= e(old_value('health_physical_restriction_details', $matchedPerson['health_physical_restriction_details'] ?? '')) ?></textarea>
                    </div>

                    <?php $medicationAllergy = yes_no_from_person($matchedPerson, 'health_medication_allergy'); ?>
                    <div class="form-group">
                        <label>Is your son/daughter allergic to any medication?</label>
                        <div class="yes-no-group">
                            <label><input type="radio" name="health_medication_allergy" value="yes" <?= old_checked('health_medication_allergy', 'yes', $medicationAllergy) ?> required> Yes</label>
                            <label><input type="radio" name="health_medication_allergy" value="no" <?= old_checked('health_medication_allergy', 'no', $medicationAllergy) ?> required> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="health_medication_allergy_details">Medication allergy details</label>
                        <textarea class="form-control" id="health_medication_allergy_details" name="health_medication_allergy_details" rows="4" data-required-if="health_medication_allergy:yes"><?= e(old_value('health_medication_allergy_details', $matchedPerson['health_medication_allergy_details'] ?? '')) ?></textarea>
                    </div>
                </div>

                <div class="info-box">
                    <strong>Medical information:</strong>
                    Please use the details boxes above for medication, allergies, intolerances, dietary needs and anything that could affect participation. This avoids asking for the same medical information twice.
                </div>

                <div class="dynamic-section">
                    <h3>Doctor’s details</h3>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label for="family_doctor_name">Name of family doctor</label><input class="form-control" id="family_doctor_name" name="family_doctor_name" value="<?= e(old_value('family_doctor_name', $matchedPerson['family_doctor_name'] ?? '')) ?>" required></div>
                        <div class="form-group col-md-6"><label for="family_doctor_phone">Telephone number</label><input class="form-control" id="family_doctor_phone" name="family_doctor_phone" value="<?= e(old_value('family_doctor_phone', $matchedPerson['family_doctor_phone'] ?? '')) ?>" required></div>
                    </div>
                    <div class="form-group"><label for="family_doctor_address">Address</label><textarea class="form-control" id="family_doctor_address" name="family_doctor_address" rows="3" required><?= e(old_value('family_doctor_address', $matchedPerson['family_doctor_address'] ?? '')) ?></textarea></div>
                </div>

                <div class="dynamic-section">
                    <h3>Additional medical or welfare information</h3>
                    <div class="form-group"><label for="additional_information">Anything else the leadership team should know?</label><textarea class="form-control" id="additional_information" name="additional_information" rows="5" required><?= e(old_value('additional_information')) ?></textarea></div>
                </div>

                <div class="wizard-actions">
                    <button type="button" class="btn btn-outline-secondary btn-lg js-prev">Back</button>
                    <button type="button" class="btn btn-primary btn-lg js-next">Continue to declarations</button>
                </div>
            </section>

            <section class="wizard-step" data-step="3" hidden>
                <div class="dynamic-section">
                    <h2>Medical consent</h2>
                    <p>Please read the medical consent statements below. The parent/guardian signature captures agreement to this whole section.</p>
                    <ul class="declaration-list">
                        <li>I declare that all medical information on this form is true and that I have not withheld any relevant information.</li>
                        <li>In the event of an emergency, and if the Explorer Scout group are unable to contact me, I give permission for any medical treatment deemed necessary to maintain my son/daughter’s well-being.</li>
                        <li>I consent to the disclosure of this health data to third parties in order to facilitate and administer this visit and for the group to comply with legal obligations.</li>
                        <li>I will inform the visit organiser as soon as possible of any changes in medical condition or other circumstances that may affect participation.</li>
                    </ul>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="medical_declaration_agreement" name="medical_declaration_agreement" value="1" <?= old_checkbox('medical_declaration_agreement') ?> required>
                        <label class="form-check-label" for="medical_declaration_agreement">I confirm that I have read and agree to the medical consent statements above.</label>
                    </div>
                </div>

                <div class="dynamic-section">
                    <h2>Final Explorer Belt consent</h2>
                    <p>Please read the final Explorer Belt consent statements below. The parent/guardian and young person signatures capture agreement to this whole section.</p>
                    <ul class="declaration-list">
                        <li>I consent to my son/daughter participating in the Explorer Belt and other activities while overseas.</li>
                        <li>I acknowledge the need for my son/daughter to behave responsibly.</li>
                        <li>I agree that, should my son/daughter withdraw, funds raised by them up until that date will be retained by the unit to fund this and future Explorer Belts.</li>
                        <li>I am aware that any funds raised over the required amount for this expedition will be retained by the unit to fund future Explorer Belts.</li>
                        <li>I am aware that if my son/daughter behaves in a way that raises safety or well-being concerns, they may be asked to withdraw.</li>
                        <li>I am aware that alcohol must not be consumed during any portion of the expedition and that doing so may affect insurance cover.</li>
                        <li>I understand the extent and limitations of the group’s comprehensive insurance policy, including personal belongings, personal injury and public liability cover.</li>
                    </ul>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="final_declaration_agreement" name="final_declaration_agreement" value="1" <?= old_checkbox('final_declaration_agreement') ?> required>
                        <label class="form-check-label" for="final_declaration_agreement">I confirm that the parent/guardian and young person have read and agree to the final Explorer Belt consent statements above.</label>
                    </div>
                </div>

                <div class="dynamic-section">
                    <h2>Digital signatures</h2>
                    <div class="warning-box">Both the parent/guardian and the young person must draw their signature in the boxes below before the form can be submitted.</div>

                    <div class="form-group"><label for="parent_guardian_name">Name of parent/guardian</label><input class="form-control" id="parent_guardian_name" name="parent_guardian_name" value="<?= e(old_value('parent_guardian_name', $matchedPerson['parent_guardian_name'] ?? '')) ?>" required></div>
                    <div class="form-group">
                        <label for="parentSignatureCanvas">Draw parent/guardian signature</label>
                        <canvas class="signature-pad" id="parentSignatureCanvas" width="900" height="180" data-signature-label="parent/guardian"></canvas>
                        <div class="signature-help" id="parentSignatureHelp">Use a mouse, trackpad or finger to sign inside the box.</div>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-2" data-clear-signature="parent">Clear parent signature</button>
                    </div>

                    <div class="form-group"><label for="young_person_name">Name of young person</label><input class="form-control" id="young_person_name" name="young_person_name" value="<?= e(old_value('young_person_name', $matchedPerson['young_person_declaration_name'] ?? $matchedPerson['name'] ?? '')) ?>" required></div>
                    <div class="form-group">
                        <label for="youngSignatureCanvas">Draw young person signature</label>
                        <canvas class="signature-pad" id="youngSignatureCanvas" width="900" height="180" data-signature-label="young person"></canvas>
                        <div class="signature-help" id="youngSignatureHelp">Use a mouse, trackpad or finger to sign inside the box.</div>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-2" data-clear-signature="young">Clear young person signature</button>
                    </div>

                    <div class="form-check"><input class="form-check-input" type="checkbox" id="privacy_acknowledgement" name="privacy_acknowledgement" value="1" <?= old_checkbox('privacy_acknowledgement') ?> required><label class="form-check-label" for="privacy_acknowledgement">I confirm that I have read the <a href="<?= e(url('privacy.php')) ?>" target="_blank" rel="noopener">privacy notice</a>.</label></div>
                </div>

                <section class="warning-box">
                    <strong>Before submitting:</strong>
                    Please check the information carefully. Once submitted, this form cannot be viewed again unless the trip team unlocks it.
                </section>

                <div class="wizard-actions">
                    <button type="button" class="btn btn-outline-secondary btn-lg js-prev">Back</button>
                    <button class="btn btn-primary btn-lg">Save and submit details</button>
                </div>
            </section>
        </form>
    <?php endif; ?>
</main>

<template id="contactRowTemplate">
    <div class="dynamic-row contact-row">
        <div class="dynamic-row-grid contact">
            <div class="form-group mb-0"><label>Name</label><input class="form-control" name="contact_name[]" required></div>
            <div class="form-group mb-0"><label>Relationship</label><input class="form-control" name="contact_relationship[]" required></div>
            <div class="form-group mb-0"><label>Address</label><input class="form-control" name="contact_address[]" required></div>
            <div class="form-group mb-0"><label>Home phone</label><input class="form-control" name="contact_home_phone[]" required></div>
            <div class="form-group mb-0"><label>Mobile phone</label><input class="form-control" name="contact_mobile_phone[]" required></div>
            <div class="form-group mb-0"><label>Email</label><input class="form-control contact-email-input" type="email" name="contact_email[]" required></div>
            <button type="button" class="btn btn-outline-danger" data-remove-row>Remove</button>
        </div>
    </div>
</template>

<template id="simpleEmailRowTemplate">
    <div class="dynamic-row additional-email-row"><div class="dynamic-row-grid simple"><div class="form-group mb-0"><label>Additional email address</label><input class="form-control" type="email" name="parent_emails[]" required></div><button type="button" class="btn btn-outline-danger" data-remove-row>Remove</button></div></div>
</template>





<script>
(function () {
    var currentStep = 1;
    var maxContacts = 5;
    var maxAdditionalEmails = 5;

    function showStep(step) {
        currentStep = step;
        document.querySelectorAll('.wizard-step').forEach(function (section) {
            section.hidden = Number(section.getAttribute('data-step')) !== step;
        });
        document.querySelectorAll('[data-step-label]').forEach(function (label) {
            label.classList.toggle('active', Number(label.getAttribute('data-step-label')) === step);
        });
        if (step === 3) {
            window.setTimeout(function () {
                if (parentPad && parentPad.resize) parentPad.resize();
                if (youngPad && youngPad.resize) youngPad.resize();
            }, 0);
        }
        window.scrollTo({top: 0, behavior: 'smooth'});
    }

    function syncConditionalRequired(section) {
        (section || document).querySelectorAll('[data-required-if]').forEach(function (field) {
            var parts = String(field.getAttribute('data-required-if') || '').split(':');
            var controller = document.querySelector('[name="' + parts[0] + '"]:checked');
            field.required = !!(controller && controller.value === parts[1]);
        });
    }

    function updateSignatureNameFields() {
        var parentName = document.getElementById('parent_guardian_name');
        var youngName = document.getElementById('young_person_name');
        var parentSignature = document.getElementById('parent_guardian_signature');
        var youngSignature = document.getElementById('young_person_signature');
        if (parentSignature && parentName) parentSignature.value = parentName.value.trim() ? parentName.value.trim() + ' (drawn signature captured)' : '';
        if (youngSignature && youngName) youngSignature.value = youngName.value.trim() ? youngName.value.trim() + ' (drawn signature captured)' : '';
    }

    function validateSignatureCanvas(canvasId, hiddenId) {
        var canvas = document.getElementById(canvasId);
        var hidden = document.getElementById(hiddenId);
        if (!canvas || !hidden) return true;
        var valid = hidden.value.trim() !== '';
        canvas.classList.toggle('signature-error', !valid);
        if (!valid) {
            canvas.scrollIntoView({block: 'center'});
            var label = canvas.getAttribute('data-signature-label') || 'signature';
            alert('Please draw the ' + label + ' signature before continuing.');
            return false;
        }
        return true;
    }

    function validateSection(section) {
        if (!section) return true;
        syncConditionalRequired(section);
        updateSignatureNameFields();
        var fields = section.querySelectorAll('input, select, textarea');
        for (var i = 0; i < fields.length; i++) {
            if (!fields[i].checkValidity()) {
                fields[i].reportValidity();
                return false;
            }
        }
        if (section.getAttribute('data-step') === '1' && countRows('.contact-row') < 2) {
            alert('Please provide at least two emergency contacts.');
            return false;
        }
        if (section.getAttribute('data-step') === '3') {
            if (!validateSignatureCanvas('parentSignatureCanvas', 'parent_signature_data_url')) return false;
            if (!validateSignatureCanvas('youngSignatureCanvas', 'young_person_signature_data_url')) return false;
        }
        return true;
    }

    function validateVisibleStep() {
        return validateSection(document.querySelector('.wizard-step[data-step="' + currentStep + '"]'));
    }

    function validateAllSteps() {
        var sections = document.querySelectorAll('.wizard-step');
        for (var i = 0; i < sections.length; i++) {
            var step = Number(sections[i].getAttribute('data-step'));
            showStep(step);
            if (!validateSection(sections[i])) {
                return false;
            }
        }
        return true;
    }

    document.addEventListener('click', function (event) {
        if (event.target.classList.contains('js-next')) {
            if (validateVisibleStep()) showStep(Math.min(3, currentStep + 1));
        }
        if (event.target.classList.contains('js-prev')) {
            showStep(Math.max(1, currentStep - 1));
        }
    });

    function countRows(selector) { return document.querySelectorAll(selector).length; }

    function addRow(buttonId, targetId, templateId, maxRows, rowSelector, limitTextId, limitMessage) {
        var button = document.getElementById(buttonId);
        var target = document.getElementById(targetId);
        var template = document.getElementById(templateId);
        var limitText = document.getElementById(limitTextId);
        if (!button || !target || !template) return;

        function updateLimitState() {
            var atLimit = countRows(rowSelector) >= maxRows;
            button.disabled = atLimit;
            if (limitText) limitText.textContent = atLimit ? limitMessage : '';
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
        if (!button || !target || !template) return;
        button.addEventListener('click', function () { target.appendChild(template.content.cloneNode(true)); });
    }

    function normaliseEmail(email) { return String(email || '').trim().toLowerCase(); }

    function updateAutoEmails() {
        var target = document.getElementById('autoEmailRows');
        if (!target) return;
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
            row.innerHTML = '<div class="dynamic-row-grid simple"><div class="form-group mb-0"><label>Included from emergency contact</label><input class="form-control" type="email" value="' + email.replace(/"/g, '&quot;') + '" disabled><div class="email-note">This is copied automatically from the emergency contacts section.</div></div><span class="muted">Automatic</span></div>';
            target.appendChild(row);
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-remove-row]');
        if (!button) return;
        var row = button.closest('.dynamic-row');
        if (row) row.remove();
        updateAutoEmails();
        var contactButton = document.getElementById('addContactRow');
        var contactLimitText = document.getElementById('contactLimitText');
        if (contactButton) {
            var atContactLimit = countRows('.contact-row') >= maxContacts;
            contactButton.disabled = atContactLimit;
            if (contactLimitText) contactLimitText.textContent = atContactLimit ? 'Maximum 5 emergency contacts reached.' : '';
        }
        var emailButton = document.getElementById('addParentEmailRow');
        var emailLimitText = document.getElementById('additionalEmailLimitText');
        if (emailButton) {
            var atEmailLimit = countRows('.additional-email-row') >= maxAdditionalEmails;
            emailButton.disabled = atEmailLimit;
            if (emailLimitText) emailLimitText.textContent = atEmailLimit ? 'Maximum 5 additional email addresses reached.' : '';
        }
    });

    document.addEventListener('input', function (event) {
        if (event.target && event.target.classList.contains('contact-email-input')) updateAutoEmails();
        syncConditionalRequired(document);
        updateSignatureNameFields();
    });

    document.addEventListener('change', function () {
        syncConditionalRequired(document);
        updateSignatureNameFields();
    });

    addRow('addContactRow', 'contactsRows', 'contactRowTemplate', maxContacts, '.contact-row', 'contactLimitText', 'Maximum 5 emergency contacts reached.');
    addRow('addParentEmailRow', 'parentEmailRows', 'simpleEmailRowTemplate', maxAdditionalEmails, '.additional-email-row', 'additionalEmailLimitText', 'Maximum 5 additional email addresses reached.');
    updateAutoEmails();

    function attachSignaturePad(canvasId, hiddenId) {
        var canvas = document.getElementById(canvasId);
        var hidden = document.getElementById(hiddenId);
        if (!canvas || !hidden) return null;

        var ctx = canvas.getContext('2d');
        var drawing = false;
        var hasInk = false;
        var lastPoint = null;

        function setCanvasSize() {
            var rect = canvas.getBoundingClientRect();
            var ratio = window.devicePixelRatio || 1;
            var previousImage = hasInk ? canvas.toDataURL('image/png') : null;

            canvas.width = Math.max(1, Math.round(rect.width * ratio));
            canvas.height = Math.max(1, Math.round(rect.height * ratio));
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.lineWidth = Math.max(2, 2 * ratio);
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';

            if (previousImage) {
                var img = new Image();
                img.onload = function () {
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                    hidden.value = canvas.toDataURL('image/png');
                };
                img.src = previousImage;
            }
        }

        function getPoint(event) {
            var rect = canvas.getBoundingClientRect();
            var clientX = event.clientX;
            var clientY = event.clientY;

            if (event.touches && event.touches.length) {
                clientX = event.touches[0].clientX;
                clientY = event.touches[0].clientY;
            } else if (event.changedTouches && event.changedTouches.length) {
                clientX = event.changedTouches[0].clientX;
                clientY = event.changedTouches[0].clientY;
            }

            return {
                x: (clientX - rect.left) * (canvas.width / rect.width),
                y: (clientY - rect.top) * (canvas.height / rect.height)
            };
        }

        function save() {
            hidden.value = hasInk ? canvas.toDataURL('image/png') : '';
            canvas.classList.toggle('signature-error', !hasInk);
        }

        function startDrawing(event) {
            event.preventDefault();
            drawing = true;
            hasInk = true;
            lastPoint = getPoint(event);
            ctx.beginPath();
            ctx.moveTo(lastPoint.x, lastPoint.y);
            ctx.lineTo(lastPoint.x + 0.01, lastPoint.y + 0.01);
            ctx.stroke();
            save();
        }

        function draw(event) {
            if (!drawing) return;
            event.preventDefault();
            var point = getPoint(event);
            ctx.beginPath();
            ctx.moveTo(lastPoint.x, lastPoint.y);
            ctx.lineTo(point.x, point.y);
            ctx.stroke();
            lastPoint = point;
            save();
        }

        function stopDrawing(event) {
            if (!drawing) return;
            if (event) event.preventDefault();
            drawing = false;
            lastPoint = null;
            save();
        }

        if (window.PointerEvent) {
            canvas.addEventListener('pointerdown', function (event) {
                canvas.setPointerCapture(event.pointerId);
                startDrawing(event);
            });
            canvas.addEventListener('pointermove', draw);
            canvas.addEventListener('pointerup', stopDrawing);
            canvas.addEventListener('pointercancel', stopDrawing);
            canvas.addEventListener('pointerleave', stopDrawing);
        } else {
            canvas.addEventListener('mousedown', startDrawing);
            canvas.addEventListener('mousemove', draw);
            document.addEventListener('mouseup', stopDrawing);
            canvas.addEventListener('touchstart', startDrawing, {passive: false});
            canvas.addEventListener('touchmove', draw, {passive: false});
            canvas.addEventListener('touchend', stopDrawing, {passive: false});
            canvas.addEventListener('touchcancel', stopDrawing, {passive: false});
        }

        setCanvasSize();
        window.addEventListener('resize', setCanvasSize);

        return {
            clear: function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                hidden.value = '';
                hasInk = false;
                canvas.classList.add('signature-error');
            },
            save: save,
            resize: setCanvasSize
        };
    }

    var parentPad = attachSignaturePad('parentSignatureCanvas', 'parent_signature_data_url');
    var youngPad = attachSignaturePad('youngSignatureCanvas', 'young_person_signature_data_url');

    document.addEventListener('click', function (event) {
        var target = event.target.getAttribute('data-clear-signature');
        if (target === 'parent' && parentPad) parentPad.clear();
        if (target === 'young' && youngPad) youngPad.clear();
    });

    var form = document.getElementById('onboardingForm');
    syncConditionalRequired(document);
    updateSignatureNameFields();

    if (form) {
        form.addEventListener('submit', function (event) {
            if (parentPad) parentPad.save();
            if (youngPad) youngPad.save();
            updateSignatureNameFields();

            if (!validateAllSteps()) {
                event.preventDefault();
            }
        });
    }
})();
</script>

</body>
</html>
