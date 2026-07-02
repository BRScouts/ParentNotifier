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
const ONBOARDING_VERSION = 'explorer-belt-2026-consent-v7';

$error = '';
$success = '';
$matchedPerson = null;
$submittedPerson = null;
$submittedEmails = [];
$confirmationQueued = false;
$downloadToken = '';
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


function unique_text_items(array $items): array
{
    $clean = [];
    $seen = [];

    foreach ($items as $item) {
        if (is_array($item)) {
            $item = implode(' | ', array_filter(array_map('clean_text', array_map('strval', $item))));
        }

        $item = clean_text((string)$item);

        if ($item === '') {
            continue;
        }

        $key = strtolower(preg_replace('/\s+/', ' ', $item));

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $clean[] = $item;
    }

    return $clean;
}

function condensed_health_legacy_json_items_from_post(): array
{
    /**
     * Keep the existing/legacy storage model for medication and allergy data.
     * These arrays feed young_people.medications_json and young_people.allergies_json,
     * which existing leader/admin screens can already read.
     */
    return [
        'medications' => unique_text_items(medications_from_post()),
        'allergies' => unique_text_items(allergies_from_post()),
    ];
}

function medication_rows_have_items(): bool
{
    return !empty(medications_from_post());
}

function allergy_rows_have_items(): bool
{
    return !empty(allergies_from_post());
}

function free_text_declares_issue(?string $value): int
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9 ]+/', '', $value);
    $value = preg_replace('/\s+/', ' ', (string)$value);

    if ($value === '' || in_array($value, ['no', 'none', 'na', 'n a', 'not applicable', 'nothing', 'nil'], true)) {
        return 0;
    }

    return 1;
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
            'physical_restriction_details' => clean_text($_POST['health_physical_restriction_details'] ?? ''),
            'medications' => condensed_health_legacy_json_items_from_post()['medications'],
            'allergies' => condensed_health_legacy_json_items_from_post()['allergies'],
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


function people_now(): DateTime
{
    return new DateTime('now', new DateTimeZone('Europe/Helsinki'));
}

function parent_form_completed(array $person): bool
{
    return !empty($person['parent_form_completed_at']) || !empty($person['parent_onboarding_completed_at']);
}

function person_age(?string $dob): string
{
    if (!$dob) {
        return 'Date of birth not recorded';
    }

    try {
        $birth = new DateTime($dob);
        $today = new DateTime('today');
        $age = $birth->diff($today)->y;

        return date('d M Y', strtotime($dob)) . ' - ' . $age . ' years old';
    } catch (Throwable $exception) {
        return date('d M Y', strtotime($dob));
    }
}

function get_latest_parent_onboarding_submission(PDO $pdo, int $personId): ?array
{
    if (!table_exists($pdo, 'parent_onboarding_submissions')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM parent_onboarding_submissions
             WHERE person_id = ?
             ORDER BY submitted_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$personId]);
        $submission = $stmt->fetch();

        return $submission ?: null;
    } catch (Throwable $exception) {
        return null;
    }
}

function latest_submission_snapshot(?array $submission): array
{
    if (!$submission || empty($submission['snapshot_json'])) {
        return [];
    }

    $decoded = json_decode((string)$submission['snapshot_json'], true);

    return is_array($decoded) ? $decoded : [];
}

function snapshot_value(array $snapshot, array $path): string
{
    $value = $snapshot;

    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return '';
        }

        $value = $value[$key];
    }

    if (is_array($value)) {
        return implode(', ', array_filter(array_map('strval', $value)));
    }

    return trim((string)$value);
}

function display_value($value, string $fallback = 'Not recorded'): string
{
    $value = trim((string)$value);

    return $value === '' ? $fallback : $value;
}

function value_from_person_or_snapshot(array $person, array $snapshot, string $column, array $snapshotPath = []): string
{
    $personValue = trim((string)($person[$column] ?? ''));

    if ($personValue !== '') {
        return $personValue;
    }

    return $snapshotPath ? snapshot_value($snapshot, $snapshotPath) : '';
}

function emergency_contact_phone(array $contact): string
{
    $mobile = trim((string)($contact['mobile_phone'] ?? ''));
    $home = trim((string)($contact['home_phone'] ?? ''));
    $legacy = trim((string)($contact['phone'] ?? ''));

    if ($mobile !== '' && $home !== '') {
        return 'Mobile: ' . $mobile . ' | Home/other: ' . $home;
    }

    if ($mobile !== '') {
        return 'Mobile: ' . $mobile;
    }

    if ($home !== '') {
        return 'Home/other: ' . $home;
    }

    return $legacy;
}

function health_pdf_clean_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

    return $converted === false ? $text : $converted;
}

function health_pdf_escape(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], health_pdf_clean_text($text));
}

function health_pdf_lines_from_text(string $text, int $maxChars): array
{
    $lines = [];
    $paragraphs = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($text)));

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);

        if ($paragraph === '') {
            $lines[] = '';
            continue;
        }

        foreach (explode("\n", wordwrap($paragraph, $maxChars, "\n", true)) as $line) {
            $lines[] = $line;
        }
    }

    return empty($lines) ? [''] : $lines;
}

function health_pdf_template_paths(): array
{
    return [
        __DIR__ . '/assets/pdf_templates/parental-consent-page-1.jpg',
        __DIR__ . '/assets/pdf_templates/parental-consent-page-2.jpg',
        __DIR__ . '/assets/pdf_templates/parental-consent-page-3.jpg',
    ];
}

function health_pdf_template_available(): bool
{
    foreach (health_pdf_template_paths() as $path) {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
    }

    return true;
}

function health_pdf_pdf_date(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? $value : date('d/m/Y', $timestamp);
}

function health_pdf_person_age_date(?string $dob): string
{
    $dob = trim((string)$dob);

    if ($dob === '') {
        return '';
    }

    $timestamp = strtotime($dob);

    return $timestamp === false ? $dob : date('d/m/Y', $timestamp);
}

function health_pdf_snapshot_path(array $snapshot, array $path): string
{
    $value = $snapshot;

    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return '';
        }

        $value = $value[$key];
    }

    if (is_array($value)) {
        return implode("\n", array_filter(array_map('strval', $value)));
    }

    return trim((string)$value);
}

function health_pdf_value(array $person, array $snapshot, string $column, array $snapshotPaths = []): string
{
    $personValue = trim((string)($person[$column] ?? ''));

    if ($personValue !== '') {
        return $personValue;
    }

    foreach ($snapshotPaths as $path) {
        $snapshotValue = health_pdf_snapshot_path($snapshot, $path);

        if ($snapshotValue !== '') {
            return $snapshotValue;
        }
    }

    return '';
}

function health_pdf_array_from_person_or_snapshot(array $person, array $snapshot, string $column, array $snapshotPath): array
{
    $items = json_items($person[$column] ?? null);

    if (!empty($items)) {
        return $items;
    }

    $value = $snapshot;

    foreach ($snapshotPath as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return [];
        }

        $value = $value[$key];
    }

    return is_array($value) ? $value : [];
}

function health_pdf_contact_value(array $contact, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string)($contact[$key] ?? ''));

        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function health_pdf_items_to_text(array $items): string
{
    $lines = [];

    foreach ($items as $item) {
        if (is_array($item)) {
            $item = implode(' | ', array_filter(array_map('strval', $item)));
        }

        $item = trim((string)$item);

        if ($item !== '') {
            $lines[] = $item;
        }
    }

    return implode("\n", $lines);
}

function health_pdf_contains_meaningful_text(string $value): bool
{
    $value = trim(strtolower($value));

    return $value !== '' && !in_array($value, ['none', 'no', 'n/a', 'na', 'not applicable', 'none recorded'], true);
}

function health_pdf_medication_allergy_text(array $allergies, string $explicit): string
{
    $lines = [];

    if (health_pdf_contains_meaningful_text($explicit)) {
        $lines[] = $explicit;
    }

    foreach ($allergies as $item) {
        if (is_array($item)) {
            $item = implode(' | ', array_filter(array_map('strval', $item)));
        }

        $item = trim((string)$item);

        if ($item !== '' && stripos($item, 'medication allergy') !== false) {
            $lines[] = $item;
        }
    }

    return implode("\n", array_unique($lines));
}

function health_pdf_submission_value(?array $submission, array $person, string $submissionColumn, string $personColumn): string
{
    $value = trim((string)($submission[$submissionColumn] ?? ''));

    if ($value !== '') {
        return $value;
    }

    return trim((string)($person[$personColumn] ?? ''));
}

function health_pdf_signature_data_url(?array $submission, array $person, string $submissionColumn, string $personColumn): string
{
    $value = trim((string)($submission[$submissionColumn] ?? ''));

    if ($value !== '') {
        return $value;
    }

    return trim((string)($person[$personColumn] ?? ''));
}

function health_pdf_template_data(array $person, array $snapshot, ?array $submission = null): array
{
    $contacts = health_pdf_array_from_person_or_snapshot($person, $snapshot, 'emergency_contacts_json', ['emergency_contacts']);
    $parentEmails = health_pdf_array_from_person_or_snapshot($person, $snapshot, 'parent_emails_json', ['update_emails']);
    $medications = health_pdf_array_from_person_or_snapshot($person, $snapshot, 'medications_json', ['health', 'medications']);
    $allergies = health_pdf_array_from_person_or_snapshot($person, $snapshot, 'allergies_json', ['health', 'allergies']);

    $additionalInfo = health_pdf_snapshot_path($snapshot, ['health', 'additional_information']);
    $medicalConditionDetails = health_pdf_value($person, $snapshot, 'health_medical_condition_details', [['health', 'medical_condition_details']]);
    $physicalRestrictionDetails = health_pdf_value($person, $snapshot, 'health_physical_restriction_details', [['health', 'physical_restriction_details']]);
    $medicationAllergyDetails = health_pdf_medication_allergy_text(
        $allergies,
        health_pdf_value($person, $snapshot, 'health_medication_allergy_details', [['health', 'medication_allergy_details']])
    );

    $healthLines = [];

    if (health_pdf_contains_meaningful_text($medicalConditionDetails)) {
        $healthLines[] = 'Medical condition: ' . $medicalConditionDetails;
    }

    if (!empty($medications)) {
        $healthLines[] = 'Medication: ' . str_replace("\n", '; ', health_pdf_items_to_text($medications));
    }

    if (!empty($allergies)) {
        $healthLines[] = 'Allergy/intolerance/dietary: ' . str_replace("\n", '; ', health_pdf_items_to_text($allergies));
    }

    if (health_pdf_contains_meaningful_text($additionalInfo)) {
        $healthLines[] = 'Additional welfare/medical information: ' . $additionalInfo;
    }

    $healthDetails = implode("\n", $healthLines);
    $healthAnswer = health_pdf_contains_meaningful_text($healthDetails) ? 'Yes' : 'No';
    $physicalAnswer = health_pdf_contains_meaningful_text($physicalRestrictionDetails) ? 'Yes' : 'No';
    $medicationAllergyAnswer = health_pdf_contains_meaningful_text($medicationAllergyDetails) ? 'Yes' : 'No';

    $submittedAt = trim((string)($submission['submitted_at'] ?? ''));

    if ($submittedAt === '') {
        $submittedAt = trim((string)($person['parent_form_completed_at'] ?? ''));
    }

    if ($submittedAt === '') {
        $submittedAt = health_pdf_snapshot_path($snapshot, ['submitted', 'submitted_at']);
    }

    return [
        'participant_name' => health_pdf_value($person, $snapshot, 'name', [['participant', 'name']]),
        'dob' => health_pdf_person_age_date(health_pdf_value($person, $snapshot, 'dob', [['participant', 'dob']])),
        'participant_phone' => health_pdf_value($person, $snapshot, 'participant_phone', [['participant', 'participant_phone']]),
        'passport_number' => health_pdf_value($person, $snapshot, 'passport_number', [['participant', 'passport_number'], ['personal', 'passport_number']]),
        'passport_expiry_date' => health_pdf_pdf_date(health_pdf_value($person, $snapshot, 'passport_expiry_date', [['participant', 'passport_expiry_date'], ['personal', 'passport_expiry_date']])),
        'passport_nationality' => health_pdf_value($person, $snapshot, 'passport_nationality', [['participant', 'passport_nationality'], ['personal', 'passport_nationality']]),
        'ehic_ghic_number' => health_pdf_value($person, $snapshot, 'ehic_ghic_number', [['participant', 'ehic_ghic_number'], ['personal', 'ehic_ghic_number']]),
        'ehic_ghic_expiry_date' => health_pdf_pdf_date(health_pdf_value($person, $snapshot, 'ehic_ghic_expiry_date', [['participant', 'ehic_ghic_expiry_date'], ['personal', 'ehic_ghic_expiry_date']])),
        'contacts' => $contacts,
        'update_emails' => $parentEmails,
        'health_answer' => $healthAnswer,
        'health_details' => $healthAnswer . ($healthDetails !== '' ? " - " . $healthDetails : ''),
        'physical_answer' => $physicalAnswer,
        'physical_details' => $physicalAnswer . ($physicalRestrictionDetails !== '' ? " - " . $physicalRestrictionDetails : ''),
        'medication_allergy_answer' => $medicationAllergyAnswer,
        'medication_allergy_details' => $medicationAllergyAnswer . ($medicationAllergyDetails !== '' ? " - " . $medicationAllergyDetails : ''),
        'family_doctor_name' => health_pdf_value($person, $snapshot, 'family_doctor_name', [['health', 'family_doctor_name']]),
        'family_doctor_phone' => health_pdf_value($person, $snapshot, 'family_doctor_phone', [['health', 'family_doctor_phone']]),
        'family_doctor_address' => health_pdf_value($person, $snapshot, 'family_doctor_address', [['health', 'family_doctor_address']]),
        'parent_guardian_name' => health_pdf_submission_value($submission, $person, 'parent_guardian_name', 'parent_guardian_name'),
        'parent_signature_data_url' => health_pdf_signature_data_url($submission, $person, 'parent_signature_data_url', 'parent_signature_data_url'),
        'young_person_name' => health_pdf_submission_value($submission, $person, 'young_person_name', 'young_person_declaration_name'),
        'young_person_signature_data_url' => health_pdf_signature_data_url($submission, $person, 'young_person_signature_data_url', 'young_person_signature_data_url'),
        'submitted_date' => health_pdf_pdf_date($submittedAt) ?: people_now()->format('d/m/Y'),
    ];
}

function health_pdf_image_object_from_jpeg_binary(array &$objects, int &$nextObjectId, string $binary): ?array
{
    $info = @getimagesizefromstring($binary);

    if (!$info || empty($info[0]) || empty($info[1])) {
        return null;
    }

    $objectId = $nextObjectId++;
    $objects[$objectId] = '<< /Type /XObject /Subtype /Image /Width ' . (int)$info[0] . ' /Height ' . (int)$info[1] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($binary) . " >>\nstream\n" . $binary . "\nendstream";

    return ['object_id' => $objectId, 'width' => (int)$info[0], 'height' => (int)$info[1]];
}

function health_pdf_png_paeth(int $a, int $b, int $c): int
{
    $p = $a + $b - $c;
    $pa = abs($p - $a);
    $pb = abs($p - $b);
    $pc = abs($p - $c);

    if ($pa <= $pb && $pa <= $pc) {
        return $a;
    }

    return $pb <= $pc ? $b : $c;
}

function health_pdf_image_object_from_png_binary(array &$objects, int &$nextObjectId, string $binary): ?array
{
    if (substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        return null;
    }

    $offset = 8;
    $width = 0;
    $height = 0;
    $bitDepth = 0;
    $colorType = 0;
    $interlace = 0;
    $idat = '';

    while ($offset + 8 <= strlen($binary)) {
        $length = unpack('N', substr($binary, $offset, 4))[1];
        $type = substr($binary, $offset + 4, 4);
        $data = substr($binary, $offset + 8, $length);
        $offset += 12 + $length;

        if ($type === 'IHDR') {
            $parts = unpack('Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace', $data);
            $width = (int)$parts['width'];
            $height = (int)$parts['height'];
            $bitDepth = (int)$parts['bitDepth'];
            $colorType = (int)$parts['colorType'];
            $interlace = (int)$parts['interlace'];
        } elseif ($type === 'IDAT') {
            $idat .= $data;
        } elseif ($type === 'IEND') {
            break;
        }
    }

    if ($width <= 0 || $height <= 0 || $bitDepth !== 8 || $interlace !== 0 || $idat === '') {
        return null;
    }

    $channels = $colorType === 6 ? 4 : ($colorType === 2 ? 3 : ($colorType === 4 ? 2 : ($colorType === 0 ? 1 : 0)));

    if ($channels === 0) {
        return null;
    }

    $decoded = @zlib_decode($idat);

    if ($decoded === false) {
        return null;
    }

    $rowLength = $width * $channels;
    $pos = 0;
    $prev = array_fill(0, $rowLength, 0);
    $rgb = '';
    $alpha = '';
    $hasAlpha = $colorType === 6 || $colorType === 4;

    for ($y = 0; $y < $height; $y++) {
        if ($pos >= strlen($decoded)) {
            return null;
        }

        $filter = ord($decoded[$pos++]);
        $scan = [];

        for ($i = 0; $i < $rowLength; $i++) {
            $raw = $pos < strlen($decoded) ? ord($decoded[$pos++]) : 0;
            $left = $i >= $channels ? $scan[$i - $channels] : 0;
            $up = $prev[$i] ?? 0;
            $upperLeft = $i >= $channels ? ($prev[$i - $channels] ?? 0) : 0;

            if ($filter === 1) {
                $value = ($raw + $left) & 0xff;
            } elseif ($filter === 2) {
                $value = ($raw + $up) & 0xff;
            } elseif ($filter === 3) {
                $value = ($raw + intdiv($left + $up, 2)) & 0xff;
            } elseif ($filter === 4) {
                $value = ($raw + health_pdf_png_paeth($left, $up, $upperLeft)) & 0xff;
            } else {
                $value = $raw;
            }

            $scan[$i] = $value;
        }

        for ($x = 0; $x < $width; $x++) {
            $base = $x * $channels;

            if ($colorType === 6) {
                $rgb .= chr($scan[$base]) . chr($scan[$base + 1]) . chr($scan[$base + 2]);
                $alpha .= chr($scan[$base + 3]);
            } elseif ($colorType === 2) {
                $rgb .= chr($scan[$base]) . chr($scan[$base + 1]) . chr($scan[$base + 2]);
            } elseif ($colorType === 4) {
                $v = chr($scan[$base]);
                $rgb .= $v . $v . $v;
                $alpha .= chr($scan[$base + 1]);
            } elseif ($colorType === 0) {
                $v = chr($scan[$base]);
                $rgb .= $v . $v . $v;
            }
        }

        $prev = $scan;
    }

    $maskObjectId = null;

    if ($hasAlpha && $alpha !== '') {
        $compressedAlpha = gzcompress($alpha);
        $maskObjectId = $nextObjectId++;
        $objects[$maskObjectId] = '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height ' . $height . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($compressedAlpha) . " >>\nstream\n" . $compressedAlpha . "\nendstream";
    }

    $compressedRgb = gzcompress($rgb);
    $objectId = $nextObjectId++;
    $smask = $maskObjectId ? ' /SMask ' . $maskObjectId . ' 0 R' : '';
    $objects[$objectId] = '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height ' . $height . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode' . $smask . ' /Length ' . strlen($compressedRgb) . " >>\nstream\n" . $compressedRgb . "\nendstream";

    return ['object_id' => $objectId, 'width' => $width, 'height' => $height];
}

function health_pdf_image_object_from_path(array &$objects, int &$nextObjectId, string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $binary = file_get_contents($path);

    if ($binary === false) {
        return null;
    }

    $info = @getimagesizefromstring($binary);
    $mime = $info['mime'] ?? '';

    if ($mime === 'image/jpeg') {
        return health_pdf_image_object_from_jpeg_binary($objects, $nextObjectId, $binary);
    }

    if ($mime === 'image/png') {
        return health_pdf_image_object_from_png_binary($objects, $nextObjectId, $binary);
    }

    return null;
}

function health_pdf_image_object_from_data_url(array &$objects, int &$nextObjectId, string $dataUrl): ?array
{
    $dataUrl = trim($dataUrl);

    if ($dataUrl === '' || !preg_match('/^data:image\/(png|jpe?g);base64,/i', $dataUrl, $matches)) {
        return null;
    }

    $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);

    if ($binary === false || $binary === '') {
        return null;
    }

    $type = strtolower($matches[1]);

    if ($type === 'jpg' || $type === 'jpeg') {
        return health_pdf_image_object_from_jpeg_binary($objects, $nextObjectId, $binary);
    }

    return health_pdf_image_object_from_png_binary($objects, $nextObjectId, $binary);
}

function health_pdf_text_command(float $x, float $y, int $size, string $text, string $font = 'F1'): string
{
    return 'BT /' . $font . ' ' . $size . ' Tf 1 0 0 1 ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . health_pdf_escape($text) . ") Tj ET\n";
}

function health_pdf_wrapped_text_commands(float $x, float $topY, float $width, float $height, string $text, int $size = 9, float $lineHeight = 10.5, int $maxLinesOverride = 0): string
{
    $maxChars = max(8, (int)floor($width / max(3.4, $size * 0.47)));
    $lines = health_pdf_lines_from_text($text, $maxChars);
    $maxLines = $maxLinesOverride > 0 ? $maxLinesOverride : max(1, (int)floor($height / $lineHeight));

    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $last = rtrim((string)end($lines));
        $lines[$maxLines - 1] = strlen($last) > 3 ? substr($last, 0, max(1, $maxChars - 3)) . '...' : $last;
    }

    $content = '';
    $y = $topY - $size;

    foreach ($lines as $line) {
        $content .= health_pdf_text_command($x, $y, $size, (string)$line);
        $y -= $lineHeight;
    }

    return $content;
}

function health_pdf_draw_image_command(string $name, float $x, float $y, float $width, float $height): string
{
    return 'q ' . number_format($width, 2, '.', '') . ' 0 0 ' . number_format($height, 2, '.', '') . ' ' . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' cm /' . $name . " Do Q\n";
}

function health_pdf_legacy_lines(array $person, array $snapshot, ?array $submission = null): array
{
    $data = health_pdf_template_data($person, $snapshot, $submission);
    $contacts = $data['contacts'];
    $updateEmails = $data['update_emails'];

    $lines = [];
    $lines[] = ['text' => 'Explorer Belt completed consent and health form', 'size' => 18, 'bold' => true];
    $lines[] = ['text' => 'Generated: ' . people_now()->format('d M Y H:i'), 'size' => 9, 'bold' => false];
    $lines[] = ['text' => '', 'size' => 9, 'bold' => false];

    $addField = static function (string $label, $value) use (&$lines): void {
        $value = trim((string)$value);
        $lines[] = ['text' => $label . ': ' . ($value !== '' ? $value : 'Not recorded'), 'size' => 10, 'bold' => false];
    };

    $addField('Name', $data['participant_name']);
    $addField('Date of birth', $data['dob']);
    $addField('Explorer mobile number', $data['participant_phone']);
    $addField('Passport no', $data['passport_number']);
    $addField('Passport expiry date', $data['passport_expiry_date']);
    $addField('Passport nationality', $data['passport_nationality']);
    $addField('EHIC/GHIC no', $data['ehic_ghic_number']);
    $addField('EHIC/GHIC expiry date', $data['ehic_ghic_expiry_date']);
    $lines[] = ['text' => '', 'size' => 9, 'bold' => false];
    $lines[] = ['text' => 'Emergency contacts', 'size' => 13, 'bold' => true];

    foreach ($contacts as $index => $contact) {
        if (!is_array($contact)) {
            continue;
        }

        $addField('Contact ' . ($index + 1) . ' name', $contact['name'] ?? '');
        $addField('Contact ' . ($index + 1) . ' address', $contact['address'] ?? '');
        $addField('Contact ' . ($index + 1) . ' home/other number', $contact['home_phone'] ?? '');
        $addField('Contact ' . ($index + 1) . ' mobile number', health_pdf_contact_value($contact, ['mobile_phone', 'phone']));
        $addField('Contact ' . ($index + 1) . ' email', $contact['email'] ?? '');
    }

    $lines[] = ['text' => '', 'size' => 9, 'bold' => false];
    $lines[] = ['text' => 'Health declaration', 'size' => 13, 'bold' => true];
    $addField('Medical condition, allergy, intolerance or medication', $data['health_details']);
    $addField('Physical condition, injury or incapacity', $data['physical_details']);
    $addField('Medication allergy', $data['medication_allergy_details']);
    $addField('Family doctor', $data['family_doctor_name']);
    $addField('Doctor phone', $data['family_doctor_phone']);
    $addField('Doctor address', $data['family_doctor_address']);
    $addField('Parent/guardian name', $data['parent_guardian_name']);
    $addField('Young person name', $data['young_person_name']);
    $addField('Submitted date', $data['submitted_date']);
    $addField('Update emails', implode(', ', array_map('strval', $updateEmails)));

    $lines[] = ['text' => '', 'size' => 9, 'bold' => false];
    $lines[] = ['text' => 'Explorer Belt consent declarations', 'size' => 13, 'bold' => true];
    $lines[] = ['text' => 'By signing this form, the parent/guardian and young person confirmed agreement to the following:', 'size' => 9, 'bold' => false];
    $lines[] = ['text' => '', 'size' => 6, 'bold' => false];
    $lines[] = ['text' => '- I consent to my son/daughter participating in the Explorer Belt and other activities while overseas.', 'size' => 9, 'bold' => false];
    $lines[] = ['text' => '- I acknowledge the need for my son/daughter to behave responsibly.', 'size' => 9, 'bold' => false];
    $lines[] = ['text' => '- I agree that, should my son/daughter withdraw, funds raised by them up until that date will be retained by the unit.', 'size' => 9, 'bold' => false];
    $lines[] = ['text' => '- I am aware that any funds raised over the required amount will be retained by the unit to fund future Explorer Belts.', 'size' => 9, 'bold' => false];
    $lines[] = ['text' => '- I am aware that if my son/daughter behaves in a way that raises safety or well-being concerns, they may be asked to withdraw.', 'size' => 9, 'bold' => false];
    $lines[] = ['text' => '- I am aware that alcohol must not be consumed at all during the trip (including travel days and rest days, not just the expedition itself) and that doing so may result in the participant being asked to withdraw and may affect insurance cover.', 'size' => 9, 'bold' => false];
    $lines[] = ['text' => '- I understand that successfully completing the expedition portion of Explorer Belt 2026 in person does not automatically mean the full Explorer Belt award has been earned. The Explorer Belt is awarded based on a combination of factors including a satisfactory logbook, a presentation, an interview and the expedition itself. The County Commissioner has final approval on whether the award is granted.', 'size' => 9, 'bold' => false];
    $lines[] = ['text' => '- I understand the extent and limitations of the group\'s comprehensive insurance policy, including personal belongings, personal injury and public liability cover.', 'size' => 9, 'bold' => false];

    return $lines;
}

function health_pdf_append_text_page(array &$objects, int &$nextObjectId, array &$pageObjectIds, array $resources, array $lineSpecs, float $pageWidth, float $pageHeight): void
{
    $margin = 42;
    $y = $pageHeight - $margin;
    $content = '';

    foreach ($lineSpecs as $spec) {
        $text = (string)($spec['text'] ?? '');
        $size = (int)($spec['size'] ?? 10);
        $bold = !empty($spec['bold']);
        $font = $bold ? 'F2' : 'F1';
        $lineHeight = max(12, $size + 4);
        $maxChars = max(35, (int)floor(($pageWidth - ($margin * 2)) / max(4.8, $size * 0.50)));

        foreach (health_pdf_lines_from_text($text, $maxChars) as $line) {
            if ($y < $margin + $lineHeight) {
                $contentId = $nextObjectId++;
                $pageId = $nextObjectId++;
                $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
                $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pageWidth . ' ' . $pageHeight . '] /Resources ' . $resources['resource_dict'] . ' /Contents ' . $contentId . ' 0 R >>';
                $pageObjectIds[] = $pageId;
                $content = '';
                $y = $pageHeight - $margin;
            }

            $content .= health_pdf_text_command($margin, $y, $size, $line, $font);
            $y -= $lineHeight;
        }
    }

    if ($content !== '') {
        $contentId = $nextObjectId++;
        $pageId = $nextObjectId++;
        $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pageWidth . ' ' . $pageHeight . '] /Resources ' . $resources['resource_dict'] . ' /Contents ' . $contentId . ' 0 R >>';
        $pageObjectIds[] = $pageId;
    }
}

function send_health_form_pdf(array $person, array $snapshot = [], ?array $submission = null): void
{
    $pageWidth = 595.28;
    $pageHeight = 841.89;
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $nextObjectId = 5;

    $imageResources = [];

    $logoPath = __DIR__ . '/assets/logo.png';
    $logoImage = health_pdf_image_object_from_path($objects, $nextObjectId, $logoPath);

    if ($logoImage) {
        $logoImage['name'] = 'LOGO';
        $imageResources['LOGO'] = $logoImage['object_id'];
    }

    $data = health_pdf_template_data($person, $snapshot, $submission);
    $parentSignatureImage = health_pdf_image_object_from_data_url($objects, $nextObjectId, $data['parent_signature_data_url']);

    if ($parentSignatureImage) {
        $parentSignatureImage['name'] = 'SIGP';
        $imageResources['SIGP'] = $parentSignatureImage['object_id'];
    }

    $youngSignatureImage = health_pdf_image_object_from_data_url($objects, $nextObjectId, $data['young_person_signature_data_url']);

    if ($youngSignatureImage) {
        $youngSignatureImage['name'] = 'SIGY';
        $imageResources['SIGY'] = $youngSignatureImage['object_id'];
    }

    $xObjectEntries = '';
    foreach ($imageResources as $name => $objectId) {
        $xObjectEntries .= ' /' . $name . ' ' . $objectId . ' 0 R';
    }

    $resourceDict = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($xObjectEntries !== '' ? ' /XObject <<' . $xObjectEntries . ' >>' : '') . ' >>';
    $pageObjectIds = [];

    $contacts = $data['contacts'];
    $margin = 42;
    $headerHeight = 60;
    $contentTopY = $pageHeight - $margin - $headerHeight - 12;
    $rightEdge = $pageWidth - $margin;

    $drawHeader = static function () use ($pageWidth, $pageHeight, $margin, $headerHeight, $logoImage): string {
        $headerY = $pageHeight - $margin - $headerHeight;
        $cmd = "q 0.455 0.075 0.863 rg\n0 " . number_format($headerY, 2, '.', '') . ' ' . number_format($pageWidth, 2, '.', '') . ' ' . number_format($headerHeight, 2, '.', '') . " re f\nQ\n";
        if ($logoImage) {
            $h = $headerHeight - 16;
            $w = $h * ($logoImage['width'] / max(1, $logoImage['height']));
            $cmd .= health_pdf_draw_image_command('LOGO', $margin, $headerY + 8, $w, $h);
        }
        return $cmd;
    };

    $drawLine = static function (float $x1, float $y, float $x2): string {
        return "q 0.75 0.75 0.75 RG 0.5 w\n" . number_format($x1, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' m ' . number_format($x2, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . " l S\nQ\n";
    };

    $drawSectionHeading = static function (string $title, float &$y, float $margin, float $rightEdge) use ($drawLine): string {
        $cmd = health_pdf_text_command($margin, $y, 11, $title, 'F2');
        $y -= 4;
        $cmd .= $drawLine($margin, $y, $rightEdge);
        $y -= 14;
        return $cmd;
    };

    $drawField = static function (string $label, string $value, float &$y, float $margin) use ($drawLine, $rightEdge): string {
        $value = $value !== '' ? $value : 'Not recorded';
        $cmd = health_pdf_text_command($margin, $y, 8, $label, 'F2');
        $cmd .= health_pdf_text_command($margin + 140, $y, 9, $value, 'F1');
        $y -= 4;
        $cmd .= $drawLine($margin + 140, $y, $rightEdge);
        $y -= 12;
        return $cmd;
    };

    $y = $contentTopY;
    $content = $drawHeader();

    $ensureSpace = static function (float $needed) use (&$y, &$content, &$objects, &$nextObjectId, &$pageObjectIds, $pageWidth, $pageHeight, $resourceDict, $margin, $contentTopY, $drawHeader): void {
        if ($y < $margin + $needed) {
            $contentId = $nextObjectId++;
            $pageId = $nextObjectId++;
            $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pageWidth . ' ' . $pageHeight . '] /Resources ' . $resourceDict . ' /Contents ' . $contentId . ' 0 R >>';
            $pageObjectIds[] = $pageId;
            $content = $drawHeader();
            $y = $contentTopY;
        }
    };

    $content .= health_pdf_text_command($margin, $y, 14, 'Parental Consent & Health Form', 'F2');
    $y -= 16;
    $content .= health_pdf_text_command($margin, $y, 8, 'Explorer Belt 2026  |  Generated: ' . people_now()->format('d M Y H:i'), 'F1');
    $y -= 20;

    $ensureSpace(100);
    $content .= $drawSectionHeading('1. Personal details', $y, $margin, $rightEdge);
    $content .= $drawField('Name', $data['participant_name'], $y, $margin);
    $content .= $drawField('Date of birth', $data['dob'], $y, $margin);
    $content .= $drawField('Explorer mobile', $data['participant_phone'], $y, $margin);

    $ensureSpace(100);
    $content .= $drawSectionHeading('2. Travel documents', $y, $margin, $rightEdge);
    $content .= $drawField('Passport number', $data['passport_number'], $y, $margin);
    $content .= $drawField('Passport expiry', $data['passport_expiry_date'], $y, $margin);
    $content .= $drawField('Nationality', $data['passport_nationality'], $y, $margin);
    $content .= $drawField('EHIC/GHIC number', $data['ehic_ghic_number'], $y, $margin);
    $content .= $drawField('EHIC/GHIC expiry', $data['ehic_ghic_expiry_date'], $y, $margin);

    $ensureSpace(80);
    $content .= $drawSectionHeading('3. Emergency contacts', $y, $margin, $rightEdge);
    foreach ($contacts as $index => $contact) {
        if (!is_array($contact)) { continue; }
        $ensureSpace(70);
        $content .= health_pdf_text_command($margin, $y, 9, 'Contact ' . ($index + 1), 'F2');
        $y -= 13;
        $content .= $drawField('Name', health_pdf_contact_value($contact, ['name']), $y, $margin);
        $content .= $drawField('Address', health_pdf_contact_value($contact, ['address']), $y, $margin);
        $content .= $drawField('Home/other phone', health_pdf_contact_value($contact, ['home_phone']), $y, $margin);
        $content .= $drawField('Mobile phone', health_pdf_contact_value($contact, ['mobile_phone', 'phone']), $y, $margin);
        $content .= $drawField('Email', health_pdf_contact_value($contact, ['email']), $y, $margin);
    }
    if (!empty($data['update_emails'])) {
        $ensureSpace(30);
        $content .= $drawField('Update emails', implode(', ', array_map('strval', $data['update_emails'])), $y, $margin);
    }

    $ensureSpace(100);
    $content .= $drawSectionHeading('4. Health declaration', $y, $margin, $rightEdge);
    $content .= $drawField('Medical/allergy/medication', $data['health_details'], $y, $margin);
    $content .= $drawField('Physical restriction', $data['physical_details'], $y, $margin);
    $content .= $drawField('Medication allergy', $data['medication_allergy_details'], $y, $margin);
    $content .= $drawField('Family doctor', $data['family_doctor_name'], $y, $margin);
    $content .= $drawField('Doctor phone', $data['family_doctor_phone'], $y, $margin);
    $content .= $drawField('Doctor address', $data['family_doctor_address'], $y, $margin);

    $ensureSpace(120);
    $content .= $drawSectionHeading('5. Consent declarations', $y, $margin, $rightEdge);
    $declarations = [
        'I consent to my son/daughter participating in the Explorer Belt and other activities while overseas.',
        'I acknowledge the need for my son/daughter to behave responsibly.',
        'I agree that, should my son/daughter withdraw, funds raised will be retained by the unit.',
        'I am aware that any funds raised over the required amount will be retained for future Explorer Belts.',
        'I am aware that if my son/daughter behaves in a way that raises safety or well-being concerns, they may be asked to withdraw.',
        'I am aware that alcohol must not be consumed at all during the trip (including travel and rest days) and that doing so may result in withdrawal and affect insurance cover.',
        'I understand that completing the expedition does not automatically earn the full Explorer Belt award. The award is based on logbook, presentation, interview and expedition. The County Commissioner has final approval.',
        'I understand the extent and limitations of the group\'s insurance policy, including personal belongings, personal injury and public liability cover.',
    ];
    foreach ($declarations as $decl) {
        $ensureSpace(24);
        $maxChars = (int)floor(($rightEdge - $margin - 10) / (7.5 * 0.47));
        foreach (health_pdf_lines_from_text($decl, $maxChars) as $line) {
            $ensureSpace(12);
            $content .= health_pdf_text_command($margin + 8, $y, 7.5, $line, 'F1');
            $y -= 10;
        }
        $y -= 3;
    }
    $ensureSpace(16);
    $content .= health_pdf_text_command($margin, $y, 8, 'All declarations confirmed and agreed by both parent/guardian and young person.', 'F2');
    $y -= 20;

    $ensureSpace(140);
    $content .= $drawSectionHeading('6. Signatures', $y, $margin, $rightEdge);
    $content .= health_pdf_text_command($margin, $y, 9, 'Parent/guardian name:', 'F2');
    $content .= health_pdf_text_command($margin + 140, $y, 10, $data['parent_guardian_name'] ?: 'Not recorded', 'F1');
    $y -= 16;
    $content .= health_pdf_text_command($margin, $y, 9, 'Parent/guardian signature:', 'F2');
    $y -= 4;
    if ($parentSignatureImage) {
        $ensureSpace(55);
        $content .= $drawLine($margin + 140, $y, $rightEdge);
        $y -= 2;
        $content .= health_pdf_draw_image_command('SIGP', $margin + 145, $y - 42, 280, 40);
        $y -= 48;
        $content .= $drawLine($margin + 140, $y, $rightEdge);
        $y -= 14;
    } else {
        $content .= health_pdf_text_command($margin + 140, $y, 9, 'Signature captured electronically', 'F1');
        $y -= 16;
    }
    $content .= health_pdf_text_command($margin, $y, 9, 'Date signed:', 'F2');
    $content .= health_pdf_text_command($margin + 140, $y, 9, $data['submitted_date'] ?: 'Not recorded', 'F1');
    $y -= 24;

    $ensureSpace(100);
    $content .= health_pdf_text_command($margin, $y, 9, 'Young person name:', 'F2');
    $content .= health_pdf_text_command($margin + 140, $y, 10, $data['young_person_name'] ?: 'Not recorded', 'F1');
    $y -= 16;
    $content .= health_pdf_text_command($margin, $y, 9, 'Young person signature:', 'F2');
    $y -= 4;
    if ($youngSignatureImage) {
        $ensureSpace(55);
        $content .= $drawLine($margin + 140, $y, $rightEdge);
        $y -= 2;
        $content .= health_pdf_draw_image_command('SIGY', $margin + 145, $y - 42, 280, 40);
        $y -= 48;
        $content .= $drawLine($margin + 140, $y, $rightEdge);
        $y -= 14;
    } else {
        $content .= health_pdf_text_command($margin + 140, $y, 9, 'Signature captured electronically', 'F1');
        $y -= 16;
    }
    $content .= health_pdf_text_command($margin, $y, 9, 'Date signed:', 'F2');
    $content .= health_pdf_text_command($margin + 140, $y, 9, $data['submitted_date'] ?: 'Not recorded', 'F1');
    $y -= 20;

    $ensureSpace(20);
    $content .= health_pdf_text_command($margin, $y, 7, 'This form was completed and signed electronically via the Explorer Belt parent portal.', 'F1');

    $contentId = $nextObjectId++;
    $pageId = $nextObjectId++;
    $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
    $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pageWidth . ' ' . $pageHeight . '] /Resources ' . $resourceDict . ' /Contents ' . $contentId . ' 0 R >>';
    $pageObjectIds[] = $pageId;

    $kids = implode(' ', array_map(static fn($id) => $id . ' 0 R', $pageObjectIds));
    $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageObjectIds) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0 => 0];
    $maxObjectId = max(array_keys($objects));

    for ($i = 1; $i <= $maxObjectId; $i++) {
        if (!array_key_exists($i, $objects)) {
            continue;
        }

        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxObjectId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= $maxObjectId; $i++) {
        if (array_key_exists($i, $offsets)) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        } else {
            $pdf .= "0000000000 65535 f \n";
        }
    }

    $pdf .= "trailer\n<< /Size " . ($maxObjectId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

    $filenameName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($person['name'] ?? 'participant'));
    $filename = trim($filenameName, '_') ?: 'participant';
    $filename .= '_completed_consent_form.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
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
            $errors[] = 'Each emergency contact must have a home or other contact number.';
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
        'health_physical_restriction_details' => 'Physical condition, injury or incapacity details',
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

    $medicationNames = (array)($_POST['medication_name'] ?? []);
    $medicationTypes = (array)($_POST['medication_type'] ?? []);
    $medicationDosages = (array)($_POST['medication_dosage'] ?? []);
    $medicationFrequencies = (array)($_POST['medication_frequency'] ?? []);
    $medicationFrequencyOther = (array)($_POST['medication_frequency_other'] ?? []);
    $medicationNotes = (array)($_POST['medication_notes'] ?? []);

    foreach ($medicationNames as $index => $name) {
        $name = clean_text($name);
        $type = clean_text($medicationTypes[$index] ?? '');
        $dosage = clean_text($medicationDosages[$index] ?? '');
        $frequency = clean_text($medicationFrequencies[$index] ?? '');
        $other = clean_text($medicationFrequencyOther[$index] ?? '');
        $note = clean_text($medicationNotes[$index] ?? '');

        if ($name === '' && $type === '' && $dosage === '' && $frequency === '' && $other === '' && $note === '') {
            continue;
        }

        if ($name === '' || $type === '' || $dosage === '' || $frequency === '') {
            $errors[] = 'Please complete all required fields for each medication row you add.';
            break;
        }

        if ($frequency === 'Other' && $other === '') {
            $errors[] = 'Please explain the medication frequency when selecting Other.';
            break;
        }
    }

    $allergyTypes = (array)($_POST['allergy_type'] ?? []);
    $allergyDetails = (array)($_POST['allergy_detail'] ?? []);
    $allergySeverities = (array)($_POST['allergy_severity'] ?? []);
    $allergyNotes = (array)($_POST['allergy_notes'] ?? []);

    foreach ($allergyDetails as $index => $detail) {
        $type = clean_text($allergyTypes[$index] ?? '');
        $detail = clean_text($detail);
        $severity = clean_text($allergySeverities[$index] ?? '');
        $note = clean_text($allergyNotes[$index] ?? '');

        if ($type === '' && $detail === '' && $severity === '' && $note === '') {
            continue;
        }

        if ($type === '' || $detail === '' || $severity === '') {
            $errors[] = 'Please complete all required fields for each allergy, intolerance or dietary need row you add.';
            break;
        }
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
    $legacyHealthItems = condensed_health_legacy_json_items_from_post();

    $updates = [
        'participant_email' => clean_text_or_null($_POST['participant_email'] ?? ''),
        'participant_phone' => clean_text_or_null($_POST['participant_phone'] ?? ''),
        'home_address' => clean_text_or_null($_POST['home_address'] ?? ''),
        'gender' => clean_text_or_null($_POST['gender'] ?? ''),
        'photo_url' => $photoPath,
        'emergency_contacts_json' => empty($contacts) ? null : json_encode($contacts, JSON_UNESCAPED_UNICODE),
        'parent_emails_json' => json_list_from_array($mergedEmails),
        'medications_json' => json_list_from_array($legacyHealthItems['medications']),
        'allergies_json' => json_list_from_array($legacyHealthItems['allergies']),
        'passport_number' => clean_text_or_null($_POST['passport_number'] ?? ''),
        'passport_expiry_date' => clean_date_or_null($_POST['passport_expiry_date'] ?? ''),
        'passport_nationality' => clean_text_or_null($_POST['passport_nationality'] ?? ''),
        'ehic_ghic_number' => clean_text_or_null($_POST['ehic_ghic_number'] ?? ''),
        'ehic_ghic_expiry_date' => clean_date_or_null($_POST['ehic_ghic_expiry_date'] ?? ''),
        'health_medical_condition' => medication_rows_have_items() || allergy_rows_have_items() ? 1 : 0,
        'health_medical_condition_details' => null,
        'health_physical_restriction' => free_text_declares_issue($_POST['health_physical_restriction_details'] ?? ''),
        'health_physical_restriction_details' => clean_text_or_null($_POST['health_physical_restriction_details'] ?? ''),
        'health_medication_allergy' => null,
        'health_medication_allergy_details' => null,
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
 * Parent download of completed health form PDF.
 * This is deliberately session-token based so the completed form is available
 * immediately after submission without making health data publicly accessible.
 */
if (isset($_GET['download_health_form'])) {
    $token = trim((string)$_GET['download_health_form']);
    $downloads = $_SESSION['parent_onboarding_downloads'] ?? [];
    $download = is_array($downloads) ? ($downloads[$token] ?? null) : null;

    if (!is_array($download) || (int)($download['expires'] ?? 0) < time()) {
        http_response_code(403);
        echo 'This completed health form download link has expired. Please contact the trip team if you need a copy.';
        exit;
    }

    $person = fetch_person($pdo, (int)($download['person_id'] ?? 0));

    if (!$person) {
        http_response_code(404);
        echo 'Participant record not found.';
        exit;
    }

    $latestSubmission = get_latest_parent_onboarding_submission($pdo, (int)$person['id']);
    $latestSnapshot = latest_submission_snapshot($latestSubmission);
    send_health_form_pdf($person, $latestSnapshot, $latestSubmission);
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

                $downloadToken = bin2hex(random_bytes(16));
                $_SESSION['parent_onboarding_downloads'][$downloadToken] = [
                    'person_id' => $personId,
                    'expires' => time() + 86400,
                ];

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
    $medications = ensure_rows(json_items($matchedPerson['medications_json'] ?? null), 1, '');
    $allergies = ensure_rows(json_items($matchedPerson['allergies_json'] ?? null), 1, '');
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
        .required-label::after { content: ' *'; color: #d4351c; font-weight: 900; }
        .required-note { color: #d4351c; font-weight: 800; }
        .optional-note { color: #505a5f; font-weight: 400; }
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
            <p>If you do not receive an email within the next hour, please check your junk or spam folder.</p>

            <?php if ($downloadToken !== ''): ?>
                <p class="mb-0">
                    <a class="btn btn-primary" href="<?= e(url('parent_onboarding.php?download_health_form=' . urlencode($downloadToken))) ?>">
                        Download completed consent form PDF
                    </a>
                </p>
                <p class="muted mt-2 mb-0">
                    This download link is available in this browser session for 24 hours.
                </p>
            <?php endif; ?>
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

            <p class="required-note">Fields marked with * are mandatory.</p>

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
                                    <div class="form-group mb-0"><label>Address</label><div class="input-group"><input class="form-control contact-address-input" name="contact_address[]" required value="<?= e($contact['address'] ?? '') ?>"><div class="input-group-append"><button type="button" class="btn btn-outline-secondary" data-copy-participant-address>Copy from participant</button></div></div></div>
                                    <div class="form-group mb-0"><label>Home or other contact number</label><input class="form-control" name="contact_home_phone[]" required value="<?= e($contact['home_phone'] ?? '') ?>"></div>
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
                                    <div class="form-group mb-0"><label>Additional email address</label><input class="form-control" type="email" name="parent_emails[]" value="<?= e((string)$email) ?>"></div>
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
                    <h2>Health data</h2>
                    <p class="muted">Medication, allergies, intolerances and dietary needs are captured in the existing format used elsewhere in the system. If a section is not applicable, leave the row blank. If you add a row, complete the required fields in that row.</p>

                    <h3>Medication</h3>
                    <p class="muted">Add any prescribed or non-prescribed medication. Include dosage and how often it is taken.</p>
                    <div id="medicationRows">
                        <?php foreach ($medications as $medication): ?>
                            <div class="dynamic-row medication-row">
                                <div class="dynamic-row-grid medication">
                                    <div class="form-group mb-0">
                                        <label>Medication name</label>
                                        <input class="form-control" name="medication_name[]" value="<?= e((string)$medication) ?>">
                                    </div>
                                    <div class="form-group mb-0">
                                        <label>Type</label>
                                        <select class="form-control" name="medication_type[]">
                                            <option value="">Select</option>
                                            <option value="Prescribed">Prescribed</option>
                                            <option value="Non-prescribed">Non-prescribed</option>
                                            <option value="Over the counter">Over the counter</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label>Dosage</label>
                                        <input class="form-control" name="medication_dosage[]" placeholder="Example: 10mg">
                                    </div>
                                    <div class="form-group mb-0">
                                        <label>How often?</label>
                                        <select class="form-control medication-frequency" name="medication_frequency[]">
                                            <option value="">Select</option>
                                            <option value="As and when">As and when</option>
                                            <option value="Daily">Daily</option>
                                            <option value="Twice a day">Twice a day</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label>Other / notes</label>
                                        <input class="form-control" name="medication_frequency_other[]" placeholder="If other, explain">
                                        <input class="form-control mt-1" name="medication_notes[]" placeholder="Additional instructions">
                                    </div>
                                    <button type="button" class="btn btn-outline-danger" data-remove-row>Remove</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="dynamic-actions">
                        <button type="button" class="btn btn-outline-primary" id="addMedicationRow">Add medication</button>
                    </div>
                </div>

                <div class="dynamic-section">
                    <h3>Allergies, intolerances and dietary needs</h3>
                    <p class="muted">Add allergies, intolerances and dietary needs. These are saved back to the existing allergy record field for leader/admin screens.</p>
                    <div id="allergyRows">
                        <?php foreach ($allergies as $allergy): ?>
                            <div class="dynamic-row allergy-row">
                                <div class="dynamic-row-grid allergy">
                                    <div class="form-group mb-0">
                                        <label>Type</label>
                                        <select class="form-control" name="allergy_type[]">
                                            <option value="">Select</option>
                                            <option value="Allergy">Allergy</option>
                                            <option value="Intolerance">Intolerance</option>
                                            <option value="Dietary need">Dietary need</option>
                                            <option value="Medication allergy">Medication allergy</option>
                                            <option value="Environmental allergy">Environmental allergy</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label>Details</label>
                                        <input class="form-control" name="allergy_detail[]" value="<?= e((string)$allergy) ?>">
                                    </div>
                                    <div class="form-group mb-0">
                                        <label>Severity</label>
                                        <select class="form-control" name="allergy_severity[]">
                                            <option value="">Select</option>
                                            <option value="Mild">Mild</option>
                                            <option value="Moderate">Moderate</option>
                                            <option value="Severe">Severe</option>
                                            <option value="Anaphylaxis risk">Anaphylaxis risk</option>
                                            <option value="Not sure">Not sure</option>
                                        </select>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label>Notes</label>
                                        <input class="form-control" name="allergy_notes[]" placeholder="Reaction, treatment or dietary instruction">
                                    </div>
                                    <button type="button" class="btn btn-outline-danger" data-remove-row>Remove</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="dynamic-actions">
                        <button type="button" class="btn btn-outline-primary" id="addAllergyRow">Add allergy / intolerance / dietary need</button>
                    </div>
                </div>

                <div class="dynamic-section">
                    <h3>Physical condition, injury or incapacity</h3>
                    <p class="muted">Please state anything that may restrict participation in the proposed activities. If there is nothing to declare, enter "None".</p>
                    <div class="form-group">
                        <label for="health_physical_restriction_details">Physical condition, injury or incapacity details</label>
                        <textarea class="form-control" id="health_physical_restriction_details" name="health_physical_restriction_details" rows="4" required><?= e(old_value('health_physical_restriction_details', $matchedPerson['health_physical_restriction_details'] ?? '')) ?></textarea>
                    </div>
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
                    <h3>Additional medical or welfare information <span class="optional-note">(optional)</span></h3>
                    <div class="form-group"><label for="additional_information">Anything else the leadership team should know?</label><textarea class="form-control" id="additional_information" name="additional_information" rows="5"><?= e(old_value('additional_information')) ?></textarea></div>
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
                        <li>I am aware that alcohol must not be consumed at all during the trip (including travel days and rest days, not just the expedition itself) and that doing so may result in the participant being asked to withdraw and may affect insurance cover.</li>
                        <li>I understand that successfully completing the expedition portion of Explorer Belt 2026 in person does not automatically mean the full Explorer Belt award has been earned. The Explorer Belt is awarded based on a combination of factors including a satisfactory logbook, a presentation, an interview and the expedition itself. The County Commissioner has final approval on whether the award is granted.</li>
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
            <div class="form-group mb-0"><label>Address</label><div class="input-group"><input class="form-control contact-address-input" name="contact_address[]" required><div class="input-group-append"><button type="button" class="btn btn-outline-secondary" data-copy-participant-address>Copy from participant</button></div></div></div>
            <div class="form-group mb-0"><label>Home or other contact number</label><input class="form-control" name="contact_home_phone[]" required></div>
            <div class="form-group mb-0"><label>Mobile phone</label><input class="form-control" name="contact_mobile_phone[]" required></div>
            <div class="form-group mb-0"><label>Email</label><input class="form-control contact-email-input" type="email" name="contact_email[]" required></div>
            <button type="button" class="btn btn-outline-danger" data-remove-row>Remove</button>
        </div>
    </div>
</template>

<template id="simpleEmailRowTemplate">
    <div class="dynamic-row additional-email-row"><div class="dynamic-row-grid simple"><div class="form-group mb-0"><label>Additional email address</label><input class="form-control" type="email" name="parent_emails[]"></div><button type="button" class="btn btn-outline-danger" data-remove-row>Remove</button></div></div>
</template>

<template id="medicationRowTemplate">
    <div class="dynamic-row medication-row">
        <div class="dynamic-row-grid medication">
            <div class="form-group mb-0"><label>Medication name</label><input class="form-control" name="medication_name[]"></div>
            <div class="form-group mb-0"><label>Type</label><select class="form-control" name="medication_type[]"><option value="">Select</option><option value="Prescribed">Prescribed</option><option value="Non-prescribed">Non-prescribed</option><option value="Over the counter">Over the counter</option><option value="Other">Other</option></select></div>
            <div class="form-group mb-0"><label>Dosage</label><input class="form-control" name="medication_dosage[]" placeholder="Example: 10mg"></div>
            <div class="form-group mb-0"><label>How often?</label><select class="form-control medication-frequency" name="medication_frequency[]"><option value="">Select</option><option value="As and when">As and when</option><option value="Daily">Daily</option><option value="Twice a day">Twice a day</option><option value="Other">Other</option></select></div>
            <div class="form-group mb-0"><label>Other / notes</label><input class="form-control" name="medication_frequency_other[]" placeholder="If other, explain"><input class="form-control mt-1" name="medication_notes[]" placeholder="Additional instructions"></div>
            <button type="button" class="btn btn-outline-danger" data-remove-row>Remove</button>
        </div>
    </div>
</template>

<template id="allergyRowTemplate">
    <div class="dynamic-row allergy-row">
        <div class="dynamic-row-grid allergy">
            <div class="form-group mb-0"><label>Type</label><select class="form-control" name="allergy_type[]"><option value="">Select</option><option value="Allergy">Allergy</option><option value="Intolerance">Intolerance</option><option value="Dietary need">Dietary need</option><option value="Medication allergy">Medication allergy</option><option value="Environmental allergy">Environmental allergy</option><option value="Other">Other</option></select></div>
            <div class="form-group mb-0"><label>Details</label><input class="form-control" name="allergy_detail[]"></div>
            <div class="form-group mb-0"><label>Severity</label><select class="form-control" name="allergy_severity[]"><option value="">Select</option><option value="Mild">Mild</option><option value="Moderate">Moderate</option><option value="Severe">Severe</option><option value="Anaphylaxis risk">Anaphylaxis risk</option><option value="Not sure">Not sure</option></select></div>
            <div class="form-group mb-0"><label>Notes</label><input class="form-control" name="allergy_notes[]" placeholder="Reaction, treatment or dietary instruction"></div>
            <button type="button" class="btn btn-outline-danger" data-remove-row>Remove</button>
        </div>
    </div>
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
        markRequiredLabels(section || document);
    }

    function markRequiredLabels(root) {
        (root || document).querySelectorAll('input[required], select[required], textarea[required]').forEach(function (field) {
            if (field.type === 'hidden') return;
            var label = null;
            if (field.id) {
                label = document.querySelector('label[for="' + field.id + '"]');
            }
            if (!label) {
                var wrapper = field.closest('.form-group, .form-check');
                if (wrapper) label = wrapper.querySelector('label');
            }
            if (label) label.classList.add('required-label');
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

    function rowHasValue(row) {
        return Array.prototype.some.call(row.querySelectorAll('input, select, textarea'), function (field) {
            return field.type !== 'hidden' && String(field.value || '').trim() !== '';
        });
    }

    function requireField(field, message) {
        if (!field || String(field.value || '').trim() !== '') return true;
        alert(message);
        field.focus();
        return false;
    }

    function validateStartedMedicationRows(section) {
        var rows = section.querySelectorAll('.medication-row');
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            if (!rowHasValue(row)) continue;
            if (!requireField(row.querySelector('[name="medication_name[]"]'), 'Please enter the medication name, or leave the whole medication row blank.')) return false;
            if (!requireField(row.querySelector('[name="medication_type[]"]'), 'Please select the medication type, or leave the whole medication row blank.')) return false;
            if (!requireField(row.querySelector('[name="medication_dosage[]"]'), 'Please enter the medication dosage, or leave the whole medication row blank.')) return false;
            var frequency = row.querySelector('[name="medication_frequency[]"]');
            if (!requireField(frequency, 'Please select how often the medication is taken, or leave the whole medication row blank.')) return false;
            if (frequency && frequency.value === 'Other') {
                if (!requireField(row.querySelector('[name="medication_frequency_other[]"]'), 'Please explain the medication frequency when selecting Other.')) return false;
            }
        }
        return true;
    }

    function validateStartedAllergyRows(section) {
        var rows = section.querySelectorAll('.allergy-row');
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            if (!rowHasValue(row)) continue;
            if (!requireField(row.querySelector('[name="allergy_type[]"]'), 'Please select the allergy/intolerance/dietary type, or leave the whole row blank.')) return false;
            if (!requireField(row.querySelector('[name="allergy_detail[]"]'), 'Please enter the allergy/intolerance/dietary details, or leave the whole row blank.')) return false;
            if (!requireField(row.querySelector('[name="allergy_severity[]"]'), 'Please select the severity, or leave the whole row blank.')) return false;
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
        if (section.getAttribute('data-step') === '2') {
            if (!validateStartedMedicationRows(section)) return false;
            if (!validateStartedAllergyRows(section)) return false;
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
            markRequiredLabels(target);
        });
        updateLimitState();
    }

    function addUnlimitedRow(buttonId, targetId, templateId) {
        var button = document.getElementById(buttonId);
        var target = document.getElementById(targetId);
        var template = document.getElementById(templateId);
        if (!button || !target || !template) return;
        button.addEventListener('click', function () {
            target.appendChild(template.content.cloneNode(true));
            markRequiredLabels(target);
        });
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
        var copyButton = event.target.closest('[data-copy-participant-address]');
        if (!copyButton) return;
        var participantAddress = document.getElementById('home_address');
        var row = copyButton.closest('.dynamic-row');
        var contactAddress = row ? row.querySelector('[name="contact_address[]"]') : null;
        if (!participantAddress || !contactAddress) return;
        if (participantAddress.value.trim() === '') {
            alert('Please enter the participant home address first.');
            participantAddress.focus();
            return;
        }
        contactAddress.value = participantAddress.value.trim();
        contactAddress.dispatchEvent(new Event('input', {bubbles: true}));
    });

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
    addUnlimitedRow('addMedicationRow', 'medicationRows', 'medicationRowTemplate');
    addUnlimitedRow('addAllergyRow', 'allergyRows', 'allergyRowTemplate');
    updateAutoEmails();
    markRequiredLabels(document);

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
