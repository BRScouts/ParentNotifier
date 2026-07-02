<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

$error = '';

const PEOPLE_UPLOAD_DIR = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/people/';
const PEOPLE_UPLOAD_PUBLIC_PATH = 'assets/people/';
const PEOPLE_TIMEZONE = 'Europe/Helsinki';

/**
 * Helpers
 */

function people_now(): DateTime
{
    return new DateTime('now', new DateTimeZone(PEOPLE_TIMEZONE));
}

function people_now_for_database(): string
{
    return people_now()->format('Y-m-d H:i:s');
}


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

function blank_to_null($value): ?string
{
    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function date_blank_to_null($value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? null : date('Y-m-d', $timestamp);
}

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

function emergency_contacts_from_post(): ?string
{
    $names = $_POST['contact_name'] ?? [];
    $relationships = $_POST['contact_relationship'] ?? [];
    $addresses = $_POST['contact_address'] ?? [];
    $homePhones = $_POST['contact_home_phone'] ?? [];
    $mobilePhones = $_POST['contact_mobile_phone'] ?? [];
    $legacyPhones = $_POST['contact_phone'] ?? [];
    $emails = $_POST['contact_email'] ?? [];

    $contacts = [];

    foreach ((array)$names as $index => $name) {
        $name = trim((string)$name);
        $relationship = trim((string)($relationships[$index] ?? ''));
        $address = trim((string)($addresses[$index] ?? ''));
        $homePhone = trim((string)($homePhones[$index] ?? ''));
        $mobilePhone = trim((string)($mobilePhones[$index] ?? ''));
        $legacyPhone = trim((string)($legacyPhones[$index] ?? ''));
        $email = trim((string)($emails[$index] ?? ''));

        if ($mobilePhone === '' && $legacyPhone !== '') {
            $mobilePhone = $legacyPhone;
        }

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

    return empty($contacts) ? null : json_encode($contacts, JSON_UNESCAPED_UNICODE);
}

function person_has_allergies(array $person): bool
{
    return count(json_items($person['allergies_json'] ?? null)) > 0;
}

function parent_form_completed(array $person): bool
{
    return !empty($person['parent_form_completed_at']);
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

function person_initials(string $name): string
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

function log_type_label(string $type): string
{
    $labels = [
        'first_aid' => 'First aid',
        'medication' => 'Medication',
        'behaviour' => 'Behaviour',
        'welfare' => 'Welfare',
        'general' => 'General note',
    ];

    return $labels[$type] ?? 'General note';
}

function log_type_class(string $type): string
{
    $classes = [
        'first_aid' => 'log-type-first-aid',
        'medication' => 'log-type-medication',
        'behaviour' => 'log-type-behaviour',
        'welfare' => 'log-type-welfare',
        'general' => 'log-type-general',
    ];

    return $classes[$type] ?? 'log-type-general';
}

function datetime_local_value(?string $datetime): string
{
    if (!$datetime) {
        return people_now()->format('Y-m-d\TH:i');
    }

    try {
        $dt = new DateTime($datetime, new DateTimeZone(PEOPLE_TIMEZONE));

        return $dt->format('Y-m-d\TH:i');
    } catch (Throwable $exception) {
        return date('Y-m-d\TH:i', strtotime($datetime));
    }
}

function handle_profile_upload(string $fieldName, ?string $existingPath = null): ?string
{
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return $existingPath;
    }

    $file = $_FILES[$fieldName];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existingPath;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be smaller than 5MB.');
    }

    $tmpName = $file['tmp_name'] ?? '';

    if (!is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded file.');
    }

    $imageInfo = getimagesize($tmpName);

    if ($imageInfo === false) {
        throw new RuntimeException('Uploaded file is not a valid image.');
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $mimeType = $imageInfo['mime'] ?? '';

    if (!isset($allowedMimeTypes[$mimeType])) {
        throw new RuntimeException('Image must be JPG, PNG, WEBP or GIF.');
    }

    if (!is_dir(PEOPLE_UPLOAD_DIR)) {
        if (!mkdir(PEOPLE_UPLOAD_DIR, 0755, true) && !is_dir(PEOPLE_UPLOAD_DIR)) {
            throw new RuntimeException('Could not create upload directory.');
        }
    }

    $extension = $allowedMimeTypes[$mimeType];
    $filename = 'person-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $destination = rtrim(PEOPLE_UPLOAD_DIR, '/') . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Could not save uploaded image.');
    }

    return PEOPLE_UPLOAD_PUBLIC_PATH . $filename;
}

function get_person_logs(PDO $pdo, int $personId): array
{
    $stmt = $pdo->prepare(
        'SELECT 
            pl.*,
            l.name AS leader_name
         FROM person_logs pl
         LEFT JOIN leaders l ON l.id = pl.leader_id
         WHERE pl.person_id = ?
         ORDER BY pl.occurred_at DESC, pl.created_at DESC'
    );

    $stmt->execute([$personId]);

    return $stmt->fetchAll();
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
    $backgrounds = [];

    foreach (health_pdf_template_paths() as $index => $path) {
        $image = health_pdf_image_object_from_path($objects, $nextObjectId, $path);

        if ($image) {
            $name = 'BG' . ($index + 1);
            $image['name'] = $name;
            $backgrounds[$index + 1] = $image;
            $imageResources[$name] = $image['object_id'];
        }
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
    $resources = ['resource_dict' => $resourceDict];
    $pageObjectIds = [];

    if (count($backgrounds) < 3) {
        health_pdf_append_text_page($objects, $nextObjectId, $pageObjectIds, $resources, health_pdf_legacy_lines($person, $snapshot, $submission), $pageWidth, $pageHeight);
    } else {
        $contacts = $data['contacts'];
        $firstContact = is_array($contacts[0] ?? null) ? $contacts[0] : [];
        $secondContact = is_array($contacts[1] ?? null) ? $contacts[1] : [];

        $pages = [];

        $content = health_pdf_draw_image_command('BG1', 0, 0, $pageWidth, $pageHeight);
        $content .= health_pdf_wrapped_text_commands(170, 690, 360, 34, $data['participant_name'], 11, 12, 2);
        $content .= health_pdf_text_command(170, 657, 10, $data['dob']);
        $content .= health_pdf_text_command(170, 634, 10, $data['participant_phone']);
        $content .= health_pdf_text_command(155, 598, 9, $data['passport_number']);
        $content .= health_pdf_text_command(398, 598, 9, $data['ehic_ghic_number']);
        $content .= health_pdf_text_command(205, 571, 9, $data['passport_expiry_date']);
        $content .= health_pdf_text_command(398, 571, 9, $data['ehic_ghic_expiry_date']);
        $content .= health_pdf_text_command(205, 544, 9, $data['passport_nationality']);

        $content .= health_pdf_text_command(135, 433, 9, health_pdf_contact_value($firstContact, ['name']));
        $content .= health_pdf_wrapped_text_commands(135, 405, 405, 32, health_pdf_contact_value($firstContact, ['address']), 8.5, 10, 3);
        $content .= health_pdf_text_command(206, 369, 9, health_pdf_contact_value($firstContact, ['home_phone']));
        $content .= health_pdf_text_command(206, 333, 9, health_pdf_contact_value($firstContact, ['mobile_phone', 'phone']));
        $content .= health_pdf_text_command(206, 308, 9, health_pdf_contact_value($firstContact, ['email']));

        $content .= health_pdf_text_command(135, 264, 9, health_pdf_contact_value($secondContact, ['name']));
        $content .= health_pdf_wrapped_text_commands(135, 230, 405, 34, health_pdf_contact_value($secondContact, ['address']), 8.5, 10, 3);
        $content .= health_pdf_text_command(206, 172, 9, health_pdf_contact_value($secondContact, ['mobile_phone', 'phone']));
        $content .= health_pdf_text_command(206, 136, 9, health_pdf_contact_value($secondContact, ['home_phone']));
        $content .= health_pdf_text_command(206, 105, 8.5, health_pdf_contact_value($secondContact, ['email']));
        $pages[] = $content;

        $content = health_pdf_draw_image_command('BG2', 0, 0, $pageWidth, $pageHeight);
        $content .= health_pdf_wrapped_text_commands(90, 703, 455, 48, $data['health_details'], 7.7, 9.2, 5);
        $content .= health_pdf_wrapped_text_commands(90, 590, 455, 47, $data['physical_details'], 8, 9.5, 5);
        $content .= health_pdf_wrapped_text_commands(90, 477, 455, 48, $data['medication_allergy_details'], 8, 9.5, 5);
        $content .= health_pdf_text_command(200, 398, 10, $data['family_doctor_name']);
        $content .= health_pdf_text_command(200, 380, 10, $data['family_doctor_phone']);
        $content .= health_pdf_wrapped_text_commands(200, 355, 280, 52, $data['family_doctor_address'], 9, 11, 4);
        $pages[] = $content;

        $content = health_pdf_draw_image_command('BG3', 0, 0, $pageWidth, $pageHeight);
        $content .= health_pdf_text_command(210, 396, 10, $data['parent_guardian_name']);

        if ($parentSignatureImage) {
            $content .= health_pdf_draw_image_command('SIGP', 210, 350, 320, 45);
        } else {
            $content .= health_pdf_text_command(210, 372, 10, 'Signature captured electronically');
        }

        $content .= health_pdf_text_command(115, 326, 10, $data['submitted_date']);
        $content .= health_pdf_text_command(48, 238, 10, 'Young person acknowledgement');
        $content .= health_pdf_text_command(48, 214, 9, 'Name of young person:');
        $content .= health_pdf_text_command(183, 214, 10, $data['young_person_name']);
        $content .= health_pdf_text_command(48, 178, 9, 'Signature of young person:');

        if ($youngSignatureImage) {
            $content .= health_pdf_draw_image_command('SIGY', 183, 150, 355, 48);
        } else {
            $content .= health_pdf_text_command(183, 178, 10, 'Signature captured electronically');
        }

        $content .= health_pdf_text_command(48, 124, 9, 'Date:');
        $content .= health_pdf_text_command(88, 124, 10, $data['submitted_date']);
        $pages[] = $content;

        foreach ($pages as $content) {
            $contentId = $nextObjectId++;
            $pageId = $nextObjectId++;
            $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pageWidth . ' ' . $pageHeight . '] /Resources ' . $resourceDict . ' /Contents ' . $contentId . ' 0 R >>';
            $pageObjectIds[] = $pageId;
        }

        $appendixLines = [];

        if (count($data['contacts']) > 2) {
            $appendixLines[] = ['text' => 'Additional emergency contacts', 'size' => 14, 'bold' => true];

            foreach (array_slice($data['contacts'], 2) as $index => $contact) {
                if (!is_array($contact)) {
                    continue;
                }

                $appendixLines[] = ['text' => 'Contact ' . ($index + 3) . ': ' . health_pdf_contact_value($contact, ['name']), 'size' => 11, 'bold' => true];
                $appendixLines[] = ['text' => 'Address: ' . health_pdf_contact_value($contact, ['address']), 'size' => 10, 'bold' => false];
                $appendixLines[] = ['text' => 'Home/other: ' . health_pdf_contact_value($contact, ['home_phone']) . ' | Mobile: ' . health_pdf_contact_value($contact, ['mobile_phone', 'phone']) . ' | Email: ' . health_pdf_contact_value($contact, ['email']), 'size' => 10, 'bold' => false];
            }
        }

        if (!empty($data['update_emails'])) {
            $appendixLines[] = ['text' => '', 'size' => 10, 'bold' => false];
            $appendixLines[] = ['text' => 'Email addresses with access to Explorer Belt updates', 'size' => 14, 'bold' => true];
            $appendixLines[] = ['text' => implode(', ', array_map('strval', $data['update_emails'])), 'size' => 10, 'bold' => false];
        }

        $appendixLines[] = ['text' => '', 'size' => 10, 'bold' => false];
        $appendixLines[] = ['text' => 'Explorer Belt consent declarations', 'size' => 14, 'bold' => true];
        $appendixLines[] = ['text' => 'By signing this form, the parent/guardian and young person confirmed agreement to the following:', 'size' => 9, 'bold' => false];
        $appendixLines[] = ['text' => '', 'size' => 6, 'bold' => false];
        $appendixLines[] = ['text' => '- I consent to my son/daughter participating in the Explorer Belt and other activities while overseas.', 'size' => 9, 'bold' => false];
        $appendixLines[] = ['text' => '- I acknowledge the need for my son/daughter to behave responsibly.', 'size' => 9, 'bold' => false];
        $appendixLines[] = ['text' => '- I agree that, should my son/daughter withdraw, funds raised by them up until that date will be retained by the unit.', 'size' => 9, 'bold' => false];
        $appendixLines[] = ['text' => '- I am aware that any funds raised over the required amount will be retained by the unit to fund future Explorer Belts.', 'size' => 9, 'bold' => false];
        $appendixLines[] = ['text' => '- I am aware that if my son/daughter behaves in a way that raises safety or well-being concerns, they may be asked to withdraw.', 'size' => 9, 'bold' => false];
        $appendixLines[] = ['text' => '- I am aware that alcohol must not be consumed at all during the trip (including travel days and rest days, not just the expedition itself) and that doing so may result in the participant being asked to withdraw and may affect insurance cover.', 'size' => 9, 'bold' => false];
        $appendixLines[] = ['text' => '- I understand that successfully completing the expedition portion of Explorer Belt 2026 in person does not automatically mean the full Explorer Belt award has been earned. The Explorer Belt is awarded based on a combination of factors including a satisfactory logbook, a presentation, an interview and the expedition itself. The County Commissioner has final approval on whether the award is granted.', 'size' => 9, 'bold' => false];
        $appendixLines[] = ['text' => '- I understand the extent and limitations of the group\'s comprehensive insurance policy, including personal belongings, personal injury and public liability cover.', 'size' => 9, 'bold' => false];

        if (!empty($appendixLines)) {
            health_pdf_append_text_page($objects, $nextObjectId, $pageObjectIds, $resources, $appendixLines, $pageWidth, $pageHeight);
        }
    }

    if (empty($pageObjectIds)) {
        health_pdf_append_text_page($objects, $nextObjectId, $pageObjectIds, $resources, health_pdf_legacy_lines($person, $snapshot, $submission), $pageWidth, $pageHeight);
    }

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

function render_person_photo(array $person, string $className): void
{
    $name = $person['name'] ?? 'Young person';
    $completionClass = parent_form_completed($person)
        ? ' parent-form-complete'
        : ' parent-form-incomplete';

    $classes = trim($className . $completionClass);

    if (!empty($person['photo_url'])) {
        ?>
        <img class="<?= e($classes) ?>" src="<?= e(url($person['photo_url'])) ?>" alt="Photo of <?= e($name) ?>">
        <?php
    } else {
        ?>
        <div class="<?= e($classes) ?>" aria-hidden="true">
            <?= e(person_initials($name)) ?>
        </div>
        <?php
    }
}

function render_repeat_inputs(string $name, array $values, string $placeholder): void
{
    $values = !empty($values) ? $values : [''];

    foreach ($values as $value) {
        ?>
        <div class="repeat-row">
            <input
                class="form-control"
                name="<?= e($name) ?>[]"
                value="<?= e((string)$value) ?>"
                placeholder="<?= e($placeholder) ?>"
            >
            <button type="button" class="btn btn-outline-danger btn-sm js-remove-row">Remove</button>
        </div>
        <?php
    }
}

function render_people_form(array $teams, ?array $formPerson = null): void
{
    $isEdit = $formPerson !== null;

    $emergencyContacts = json_items($formPerson['emergency_contacts_json'] ?? null);
    $parentEmails = json_items($formPerson['parent_emails_json'] ?? null);
    $phones = json_items($formPerson['phones_json'] ?? null);
    $medications = json_items($formPerson['medications_json'] ?? null);
    $allergies = json_items($formPerson['allergies_json'] ?? null);

    if (empty($emergencyContacts)) {
        $emergencyContacts = [
            [
                'name' => '',
                'relationship' => '',
                'address' => '',
                'home_phone' => '',
                'mobile_phone' => '',
                'phone' => '',
                'email' => '',
            ],
        ];
    }
    ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="<?= $isEdit ? 'update_person' : 'add_person' ?>">

        <?php if ($isEdit): ?>
            <input type="hidden" name="person_id" value="<?= (int)$formPerson['id'] ?>">
        <?php endif; ?>

        <div class="form-section">
            <h3>Core details</h3>

            <div class="simple-grid">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input
                        class="form-control"
                        id="name"
                        name="name"
                        value="<?= e($formPerson['name'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="team_id">Team</label>
                    <select class="form-control" id="team_id" name="team_id">
                        <option value="">Not assigned</option>
                        <?php foreach ($teams as $team): ?>
                            <option
                                value="<?= (int)$team['id'] ?>"
                                <?= (int)($formPerson['team_id'] ?? 0) === (int)$team['id'] ? 'selected' : '' ?>
                            >
                                <?= e($team['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="dob">Date of birth</label>
                    <input
                        class="form-control"
                        id="dob"
                        name="dob"
                        type="date"
                        value="<?= e($formPerson['dob'] ?? '') ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="gender">Gender</label>
                    <select class="form-control" id="gender" name="gender">
                        <?php
                        $genderValue = $formPerson['gender'] ?? '';
                        $genderOptions = [
                            '' => 'Not recorded',
                            'Female' => 'Female',
                            'Male' => 'Male',
                            'Non-binary' => 'Non-binary',
                            'Prefer not to say' => 'Prefer not to say',
                            'Other' => 'Other',
                        ];
                        ?>

                        <?php foreach ($genderOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $genderValue === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="participant_email">Participant contact email</label>
                    <input
                        class="form-control"
                        id="participant_email"
                        name="participant_email"
                        type="email"
                        value="<?= e($formPerson['participant_email'] ?? '') ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="participant_phone">Participant phone number</label>
                    <input
                        class="form-control"
                        id="participant_phone"
                        name="participant_phone"
                        value="<?= e($formPerson['participant_phone'] ?? '') ?>"
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="home_address">Home address</label>
                <textarea
                    class="form-control"
                    id="home_address"
                    name="home_address"
                    rows="3"
                ><?= e($formPerson['home_address'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label for="profile_image">Profile photo</label>
                <input
                    class="form-control"
                    id="profile_image"
                    name="profile_image"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                >

                <?php if ($isEdit && !empty($formPerson['photo_url'])): ?>
                    <small class="form-text text-muted">
                        Current photo is kept unless a new one is uploaded.
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-check mb-3">
                <input
                    class="form-check-input"
                    type="checkbox"
                    id="is_active"
                    name="is_active"
                    <?= !$isEdit || (int)($formPerson['is_active'] ?? 1) === 1 ? 'checked' : '' ?>
                >
                <label class="form-check-label" for="is_active">
                    Active participant
                </label>
            </div>
        </div>

        <div class="form-section">
            <h3>Parent onboarding status</h3>

            <div class="form-check mb-3">
                <input
                    class="form-check-input"
                    type="checkbox"
                    id="parent_form_complete"
                    name="parent_form_complete"
                    <?= $isEdit && parent_form_completed($formPerson) ? 'checked' : '' ?>
                >
                <label class="form-check-label" for="parent_form_complete">
                    Parent form completed
                </label>
            </div>

            <p class="muted">
                When this box is ticked the parent has completed the onboarding form, unticking this box will allow them to complete it again.
            </p>
        </div>

        <div class="form-section">
            <h3>Travel documents</h3>

            <div class="simple-grid">
                <div class="form-group">
                    <label for="passport_number">Passport number</label>
                    <input class="form-control" id="passport_number" name="passport_number" value="<?= e($formPerson['passport_number'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="passport_expiry_date">Passport expiry date</label>
                    <input class="form-control" id="passport_expiry_date" name="passport_expiry_date" type="date" value="<?= e($formPerson['passport_expiry_date'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="passport_nationality">Passport nationality</label>
                    <input class="form-control" id="passport_nationality" name="passport_nationality" value="<?= e($formPerson['passport_nationality'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="ehic_ghic_number">EHIC/GHIC number</label>
                    <input class="form-control" id="ehic_ghic_number" name="ehic_ghic_number" value="<?= e($formPerson['ehic_ghic_number'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="ehic_ghic_expiry_date">EHIC/GHIC expiry date</label>
                    <input class="form-control" id="ehic_ghic_expiry_date" name="ehic_ghic_expiry_date" type="date" value="<?= e($formPerson['ehic_ghic_expiry_date'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="form-section">
            <h3>Emergency contacts</h3>

            <div id="contactRows">
                <?php foreach ($emergencyContacts as $contact): ?>
                    <div class="repeat-box contact-row">
                        <div class="simple-grid">
                            <div class="form-group">
                                <label>Name</label>
                                <input class="form-control" name="contact_name[]" value="<?= e($contact['name'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>Relationship</label>
                                <input class="form-control" name="contact_relationship[]" value="<?= e($contact['relationship'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>Address</label>
                                <textarea class="form-control" name="contact_address[]" rows="2"><?= e($contact['address'] ?? '') ?></textarea>
                            </div>

                            <div class="form-group">
                                <label>Home or other contact number</label>
                                <input class="form-control" name="contact_home_phone[]" value="<?= e($contact['home_phone'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>Mobile phone</label>
                                <input class="form-control" name="contact_mobile_phone[]" value="<?= e($contact['mobile_phone'] ?? ($contact['phone'] ?? '')) ?>">
                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <input class="form-control" type="email" name="contact_email[]" value="<?= e($contact['email'] ?? '') ?>">
                            </div>
                        </div>

                        <button type="button" class="btn btn-outline-danger btn-sm js-remove-contact">
                            Remove contact
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="btn btn-outline-primary btn-sm" id="addContactRow">
                Add another contact
            </button>
        </div>

        <div class="form-section">
            <h3>Parent update emails</h3>

            <div data-repeat-group="parent_emails">
                <?php render_repeat_inputs('parent_emails', $parentEmails, 'parent@example.com'); ?>
            </div>

            <button type="button" class="btn btn-outline-primary btn-sm js-add-repeat" data-repeat-name="parent_emails" data-placeholder="parent@example.com">
                Add email
            </button>
        </div>

        <div class="form-section">
            <h3>Phone numbers</h3>

            <div data-repeat-group="phones">
                <?php render_repeat_inputs('phones', $phones, 'Phone number'); ?>
            </div>

            <button type="button" class="btn btn-outline-primary btn-sm js-add-repeat" data-repeat-name="phones" data-placeholder="Phone number">
                Add phone
            </button>
        </div>

        <div class="form-section">
            <h3>Medical and health information</h3>

            <div class="simple-grid">
                <div>
                    <label>Medications</label>
                    <div data-repeat-group="medications">
                        <?php render_repeat_inputs('medications', $medications, 'Medication'); ?>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm js-add-repeat" data-repeat-name="medications" data-placeholder="Medication">
                        Add medication
                    </button>
                </div>

                <div>
                    <label>Allergies, intolerances and dietary needs</label>
                    <div data-repeat-group="allergies">
                        <?php render_repeat_inputs('allergies', $allergies, 'Allergy, intolerance or dietary need'); ?>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm js-add-repeat" data-repeat-name="allergies" data-placeholder="Allergy, intolerance or dietary need">
                        Add allergy / dietary need
                    </button>
                </div>
            </div>

            <div class="form-group mt-3">
                <label for="health_physical_restriction_details">Physical condition, injury or incapacity</label>
                <textarea
                    class="form-control"
                    id="health_physical_restriction_details"
                    name="health_physical_restriction_details"
                    rows="4"
                ><?= e($formPerson['health_physical_restriction_details'] ?? '') ?></textarea>
            </div>

            <h4>Family doctor</h4>
            <div class="simple-grid">
                <div class="form-group">
                    <label for="family_doctor_name">Name of family doctor</label>
                    <input class="form-control" id="family_doctor_name" name="family_doctor_name" value="<?= e($formPerson['family_doctor_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="family_doctor_phone">Telephone number</label>
                    <input class="form-control" id="family_doctor_phone" name="family_doctor_phone" value="<?= e($formPerson['family_doctor_phone'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="family_doctor_address">Doctor address</label>
                <textarea class="form-control" id="family_doctor_address" name="family_doctor_address" rows="3"><?= e($formPerson['family_doctor_address'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="form-section">
            <h3>Notes</h3>

            <div class="form-group">
                <label for="notes">Internal notes</label>
                <textarea
                    class="form-control"
                    id="notes"
                    name="notes"
                    rows="5"
                ><?= e($formPerson['notes'] ?? '') ?></textarea>
            </div>
        </div>

        <button class="btn btn-primary" type="submit">
            <?= $isEdit ? 'Save person' : 'Add person' ?>
        </button>
    </form>
    <?php
}

/**
 * Data for forms.
 */

$teams = $pdo
    ->query('SELECT * FROM teams ORDER BY name ASC')
    ->fetchAll();

/**
 * POST actions.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_person') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $dob = trim($_POST['dob'] ?? '');
        $participantEmail = trim($_POST['participant_email'] ?? '');
        $participantPhone = trim($_POST['participant_phone'] ?? '');
        $homeAddress = trim($_POST['home_address'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $passportNumber = trim($_POST['passport_number'] ?? '');
        $passportExpiryDate = trim($_POST['passport_expiry_date'] ?? '');
        $passportNationality = trim($_POST['passport_nationality'] ?? '');
        $ehicGhicNumber = trim($_POST['ehic_ghic_number'] ?? '');
        $ehicGhicExpiryDate = trim($_POST['ehic_ghic_expiry_date'] ?? '');
        $healthPhysicalRestrictionDetails = trim($_POST['health_physical_restriction_details'] ?? '');
        $familyDoctorName = trim($_POST['family_doctor_name'] ?? '');
        $familyDoctorPhone = trim($_POST['family_doctor_phone'] ?? '');
        $familyDoctorAddress = trim($_POST['family_doctor_address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $parentFormCompletedAt = isset($_POST['parent_form_complete']) ? people_now_for_database() : null;

        if ($name === '') {
            $error = 'Name is required.';
        } elseif ($participantEmail !== '' && !filter_var($participantEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Participant email address is not valid.';
        } else {
            try {
                $photoPath = handle_profile_upload('profile_image', null);

                $stmt = $pdo->prepare(
                    'INSERT INTO young_people
                        (
                            team_id,
                            name,
                            dob,
                            participant_email,
                            participant_phone,
                            home_address,
                            gender,
                            parent_form_completed_at,
                            passport_number,
                            passport_expiry_date,
                            passport_nationality,
                            ehic_ghic_number,
                            ehic_ghic_expiry_date,
                            health_physical_restriction,
                            health_physical_restriction_details,
                            family_doctor_name,
                            family_doctor_phone,
                            family_doctor_address,
                            photo_url,
                            emergency_contacts_json,
                            parent_emails_json,
                            phones_json,
                            medications_json,
                            allergies_json,
                            notes,
                            is_active
                        )
                     VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $teamId > 0 ? $teamId : null,
                    $name,
                    $dob !== '' ? $dob : null,
                    $participantEmail !== '' ? $participantEmail : null,
                    $participantPhone !== '' ? $participantPhone : null,
                    $homeAddress !== '' ? $homeAddress : null,
                    $gender !== '' ? $gender : null,
                    $parentFormCompletedAt,
                    $passportNumber !== '' ? $passportNumber : null,
                    date_blank_to_null($passportExpiryDate),
                    $passportNationality !== '' ? $passportNationality : null,
                    $ehicGhicNumber !== '' ? $ehicGhicNumber : null,
                    date_blank_to_null($ehicGhicExpiryDate),
                    $healthPhysicalRestrictionDetails !== '' ? 1 : 0,
                    $healthPhysicalRestrictionDetails !== '' ? $healthPhysicalRestrictionDetails : null,
                    $familyDoctorName !== '' ? $familyDoctorName : null,
                    $familyDoctorPhone !== '' ? $familyDoctorPhone : null,
                    $familyDoctorAddress !== '' ? $familyDoctorAddress : null,
                    $photoPath,
                    emergency_contacts_from_post(),
                    json_list_from_array($_POST['parent_emails'] ?? []),
                    json_list_from_array($_POST['phones'] ?? []),
                    json_list_from_array($_POST['medications'] ?? []),
                    json_list_from_array($_POST['allergies'] ?? []),
                    $notes,
                    $isActive,
                ]);

                $newPersonId = (int)$pdo->lastInsertId();

                redirect('people.php?person_id=' . $newPersonId);
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }

    if ($action === 'update_person') {
        $personId = (int)($_POST['person_id'] ?? 0);
        $teamId = (int)($_POST['team_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $dob = trim($_POST['dob'] ?? '');
        $participantEmail = trim($_POST['participant_email'] ?? '');
        $participantPhone = trim($_POST['participant_phone'] ?? '');
        $homeAddress = trim($_POST['home_address'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $passportNumber = trim($_POST['passport_number'] ?? '');
        $passportExpiryDate = trim($_POST['passport_expiry_date'] ?? '');
        $passportNationality = trim($_POST['passport_nationality'] ?? '');
        $ehicGhicNumber = trim($_POST['ehic_ghic_number'] ?? '');
        $ehicGhicExpiryDate = trim($_POST['ehic_ghic_expiry_date'] ?? '');
        $healthPhysicalRestrictionDetails = trim($_POST['health_physical_restriction_details'] ?? '');
        $familyDoctorName = trim($_POST['family_doctor_name'] ?? '');
        $familyDoctorPhone = trim($_POST['family_doctor_phone'] ?? '');
        $familyDoctorAddress = trim($_POST['family_doctor_address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $existing = $personId > 0 ? fetch_person($pdo, $personId) : null;

        if (!$existing) {
            $error = 'Person not found.';
        } elseif ($name === '') {
            $error = 'Name is required.';
        } elseif ($participantEmail !== '' && !filter_var($participantEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Participant email address is not valid.';
        } else {
            try {
                $photoPath = handle_profile_upload('profile_image', $existing['photo_url'] ?? null);

                if (isset($_POST['parent_form_complete'])) {
                    $parentFormCompletedAt = !empty($existing['parent_form_completed_at'])
                        ? $existing['parent_form_completed_at']
                        : people_now_for_database();
                } else {
                    $parentFormCompletedAt = null;
                }

                $stmt = $pdo->prepare(
                    'UPDATE young_people
                     SET team_id = ?,
                         name = ?,
                         dob = ?,
                         participant_email = ?,
                         participant_phone = ?,
                         home_address = ?,
                         gender = ?,
                         parent_form_completed_at = ?,
                         passport_number = ?,
                         passport_expiry_date = ?,
                         passport_nationality = ?,
                         ehic_ghic_number = ?,
                         ehic_ghic_expiry_date = ?,
                         health_physical_restriction = ?,
                         health_physical_restriction_details = ?,
                         family_doctor_name = ?,
                         family_doctor_phone = ?,
                         family_doctor_address = ?,
                         photo_url = ?,
                         emergency_contacts_json = ?,
                         parent_emails_json = ?,
                         phones_json = ?,
                         medications_json = ?,
                         allergies_json = ?,
                         notes = ?,
                         is_active = ?
                     WHERE id = ?'
                );

                $stmt->execute([
                    $teamId > 0 ? $teamId : null,
                    $name,
                    $dob !== '' ? $dob : null,
                    $participantEmail !== '' ? $participantEmail : null,
                    $participantPhone !== '' ? $participantPhone : null,
                    $homeAddress !== '' ? $homeAddress : null,
                    $gender !== '' ? $gender : null,
                    $parentFormCompletedAt,
                    $passportNumber !== '' ? $passportNumber : null,
                    date_blank_to_null($passportExpiryDate),
                    $passportNationality !== '' ? $passportNationality : null,
                    $ehicGhicNumber !== '' ? $ehicGhicNumber : null,
                    date_blank_to_null($ehicGhicExpiryDate),
                    $healthPhysicalRestrictionDetails !== '' ? 1 : 0,
                    $healthPhysicalRestrictionDetails !== '' ? $healthPhysicalRestrictionDetails : null,
                    $familyDoctorName !== '' ? $familyDoctorName : null,
                    $familyDoctorPhone !== '' ? $familyDoctorPhone : null,
                    $familyDoctorAddress !== '' ? $familyDoctorAddress : null,
                    $photoPath,
                    emergency_contacts_from_post(),
                    json_list_from_array($_POST['parent_emails'] ?? []),
                    json_list_from_array($_POST['phones'] ?? []),
                    json_list_from_array($_POST['medications'] ?? []),
                    json_list_from_array($_POST['allergies'] ?? []),
                    $notes,
                    $isActive,
                    $personId,
                ]);

                redirect('people.php?person_id=' . $personId);
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }

    if ($action === 'add_log') {
        $personId = (int)($_POST['person_id'] ?? 0);
        $logType = $_POST['log_type'] ?? 'general';
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $occurredAt = trim($_POST['occurred_at'] ?? '');

        $allowedTypes = [
            'first_aid',
            'medication',
            'behaviour',
            'welfare',
            'general',
        ];

        if (!in_array($logType, $allowedTypes, true)) {
            $logType = 'general';
        }

        if ($personId <= 0) {
            $error = 'Person is required.';
        } elseif ($title === '' || $body === '') {
            $error = 'Log title and notes are required.';
        } else {
            $occurredAtForDb = $occurredAt !== ''
                ? str_replace('T', ' ', $occurredAt)
                : people_now_for_database();

            $stmt = $pdo->prepare(
                'INSERT INTO person_logs
                    (person_id, leader_id, log_type, title, body, occurred_at, created_at)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $personId,
                $user['id'] ?? null,
                $logType,
                $title,
                $body,
                $occurredAtForDb,
                people_now_for_database(),
            ]);

            redirect('people.php?person_id=' . $personId . '#logs');
        }
    }

    if ($action === 'delete_log') {
        $personId = (int)($_POST['person_id'] ?? 0);
        $logId = (int)($_POST['log_id'] ?? 0);

        if ($personId > 0 && $logId > 0) {
            $stmt = $pdo->prepare('DELETE FROM person_logs WHERE id = ? AND person_id = ?');
            $stmt->execute([$logId, $personId]);
        }

        redirect('people.php?person_id=' . $personId . '#logs');
    }
}

/**
 * Fetch page data.
 */

$people = $pdo->query(
    'SELECT 
        yp.*, 
        t.name AS team_name
     FROM young_people yp
     LEFT JOIN teams t ON t.id = yp.team_id
     ORDER BY yp.name ASC'
)->fetchAll();

$view = $_GET['view'] ?? 'list';
$personId = (int)($_GET['person_id'] ?? 0);

if ($personId > 0 && $view === 'list') {
    $view = 'profile';
}

$currentPerson = null;
$personLogs = [];

if ($personId > 0) {
    $currentPerson = fetch_person($pdo, $personId);

    if (!$currentPerson) {
        redirect('people.php');
    }

    $personLogs = get_person_logs($pdo, $personId);
}

if ($view === 'health_pdf' && $currentPerson) {
    $latestSubmission = get_latest_parent_onboarding_submission($pdo, (int)$currentPerson['id']);
    $latestSnapshot = latest_submission_snapshot($latestSubmission);
    send_health_form_pdf($currentPerson, $latestSnapshot, $latestSubmission);
}

if ($view === 'print' && $currentPerson) {
    $allergies = json_items($currentPerson['allergies_json'] ?? null);
    $medications = json_items($currentPerson['medications_json'] ?? null);
    $phones = json_items($currentPerson['phones_json'] ?? null);
    $parentEmails = json_items($currentPerson['parent_emails_json'] ?? null);
    $emergencyContacts = json_items($currentPerson['emergency_contacts_json'] ?? null);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Person record - <?= e($currentPerson['name']) ?></title>
        <style>
            body {
                font-family: Arial, sans-serif;
                color: #1d1d1d;
                margin: 24px;
                font-size: 13px;
            }

            h1, h2, h3 {
                margin-bottom: 6px;
            }

            .section {
                border-top: 2px solid #1d1d1d;
                padding-top: 12px;
                margin-top: 18px;
            }

            .muted {
                color: #505a5f;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 8px;
            }

            th, td {
                border: 1px solid #b1b4b6;
                padding: 6px;
                vertical-align: top;
            }

            th {
                background: #f3f2f1;
            }

            @media print {
                .no-print {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
        <button class="no-print" onclick="window.print()">Print</button>

        <h1><?= e($currentPerson['name']) ?></h1>
        <p class="muted">
            <?= e($currentPerson['team_name'] ?: 'Not assigned') ?>
            |
            <?= e(person_age($currentPerson['dob'] ?? null)) ?>
        </p>

        <div class="section">
            <h2>Participant details</h2>
            <p><strong>Gender:</strong> <?= e($currentPerson['gender'] ?: 'Not recorded') ?></p>
            <p><strong>Participant email:</strong> <?= e($currentPerson['participant_email'] ?: 'Not recorded') ?></p>
            <p><strong>Participant phone:</strong> <?= e($currentPerson['participant_phone'] ?: 'Not recorded') ?></p>
            <p><strong>Home address:</strong><br><?= nl2br(e($currentPerson['home_address'] ?: 'Not recorded')) ?></p>
            <p><strong>Parent form:</strong> <?= parent_form_completed($currentPerson) ? 'Completed' : 'Not completed' ?></p>
        </div>

        <div class="section">
            <h2>Medical and welfare information</h2>
            <p><strong>Allergies:</strong> <?= e(implode(', ', array_map('strval', $allergies)) ?: 'None recorded') ?></p>
            <p><strong>Medications:</strong> <?= e(implode(', ', array_map('strval', $medications)) ?: 'None recorded') ?></p>
            <p><strong>Notes:</strong><br><?= nl2br(e($currentPerson['notes'] ?? '')) ?></p>
        </div>

        <div class="section">
            <h2>Contact details</h2>
            <p><strong>Phone numbers:</strong> <?= e(implode(', ', array_map('strval', $phones)) ?: 'None recorded') ?></p>
            <p><strong>Parent emails:</strong> <?= e(implode(', ', array_map('strval', $parentEmails)) ?: 'None recorded') ?></p>
        </div>

        <div class="section">
            <h2>Emergency contacts</h2>

            <?php if (empty($emergencyContacts)): ?>
                <p>No emergency contacts recorded.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Relationship</th>
                            <th>Phone</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($emergencyContacts as $contact): ?>
                            <tr>
                                <td><?= e($contact['name'] ?? '') ?></td>
                                <td><?= e($contact['relationship'] ?? '') ?></td>
                                <td><?= e($contact['phone'] ?? '') ?></td>
                                <td><?= e($contact['email'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="section">
            <h2>Logs</h2>

            <?php if (empty($personLogs)): ?>
                <p>No logs recorded.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date/time</th>
                            <th>Type</th>
                            <th>Leader</th>
                            <th>Title</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($personLogs as $log): ?>
                            <tr>
                                <td><?= e(format_datetime($log['occurred_at'])) ?></td>
                                <td><?= e(log_type_label($log['log_type'])) ?></td>
                                <td><?= e($log['leader_name'] ?: 'Unknown') ?></td>
                                <td><?= e($log['title']) ?></td>
                                <td><?= nl2br(e($log['body'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
            window.print();
        </script>
    </body>
    </html>
    <?php
    exit;
}

include __DIR__ . '/header.php';
?>

<style>
    .page-hero,
    .page-hero h1,
    .page-hero h2,
    .page-hero h3,
    .page-hero p,
    .page-hero .lead {
        color: #ffffff !important;
    }

    .people-shell {
        max-width: 1240px;
    }

    .people-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
    }

    .people-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .people-panel h2,
    .people-panel h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .people-panel label {
        font-weight: 800;
    }

    .people-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 360px;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 980px) {
        .people-layout {
            grid-template-columns: 1fr;
        }
    }

    .people-table {
        width: 100%;
        border-collapse: collapse;
        background: #ffffff;
        border: 2px solid #d8d8d8;
    }

    .people-table th,
    .people-table td {
        border-bottom: 1px solid #d8d8d8;
        padding: 0.8rem;
        vertical-align: middle;
    }

    .people-table th {
        background: #f3f2f1;
        font-weight: 900;
    }

    .person-row-link {
        color: #1d1d1d;
        text-decoration: none;
        font-weight: 900;
    }

    .person-row-link:hover,
    .person-row-link:focus {
        text-decoration: underline;
    }

    .person-face {
        width: 44px !important;
        height: 44px !important;
        min-width: 44px !important;
        min-height: 44px !important;
        max-width: 44px !important;
        max-height: 44px !important;
        border: 3px solid #1d1d1d;
        object-fit: cover;
        background: #7413dc;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        font-weight: 900;
        text-decoration: none;
        overflow: hidden;
        border-radius: 50%;
    }

    .parent-form-complete {
        border-color: #1d1d1d !important;
    }

    .parent-form-incomplete {
        border-color: #d4351c !important;
    }

    .person-face img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .profile-header {
        display: grid;
        grid-template-columns: 110px minmax(0, 1fr);
        gap: 1rem;
        align-items: start;
    }

    @media (max-width: 600px) {
        .profile-header {
            grid-template-columns: 80px minmax(0, 1fr);
        }
    }

    .profile-photo {
        width: 110px !important;
        height: 110px !important;
        min-width: 110px !important;
        min-height: 110px !important;
        max-width: 110px !important;
        max-height: 110px !important;
        object-fit: cover;
        border: 3px solid #1d1d1d;
        background: #7413dc;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        font-size: 2.5rem;
        border-radius: 50%;
    }

    @media (max-width: 600px) {
        .profile-photo {
            width: 80px !important;
            height: 80px !important;
            min-width: 80px !important;
            min-height: 80px !important;
            max-width: 80px !important;
            max-height: 80px !important;
            font-size: 2rem;
        }
    }

    .record-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }

    @media (max-width: 780px) {
        .record-grid {
            grid-template-columns: 1fr;
        }
    }

    .record-box {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
    }

    .record-box h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .record-box-wide {
        grid-column: 1 / -1;
    }

    .contact-card-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }

    @media (max-width: 780px) {
        .contact-card-grid {
            grid-template-columns: 1fr;
        }
    }

    .contact-card {
        border: 2px solid #d8d8d8;
        background: #f8f8f8;
        padding: 0.75rem;
    }

    .contact-card h4 {
        margin-top: 0;
        font-weight: 900;
    }

    .allergy-warning {
        display: inline-block;
        background: #d4351c;
        color: #ffffff;
        border: 2px solid #d4351c;
        font-weight: 900;
        padding: 0.15rem 0.35rem;
        margin-right: 0.3rem;
    }

    .allergy-panel {
        border-left: 8px solid #d4351c;
        background: #fff1f0;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .person-log {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .person-log h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .log-meta {
        color: #505a5f;
        margin-bottom: 0.5rem;
    }

    .log-type {
        display: inline-block;
        border: 2px solid #1d1d1d;
        font-weight: 900;
        padding: 0.25rem 0.45rem;
        margin-bottom: 0.5rem;
    }

    .log-type-first-aid {
        background: #d4351c;
        color: #ffffff;
        border-color: #d4351c;
    }

    .log-type-medication {
        background: #1d70b8;
        color: #ffffff;
        border-color: #1d70b8;
    }

    .log-type-behaviour {
        background: #f47738;
        color: #1d1d1d;
        border-color: #f47738;
    }

    .log-type-welfare {
        background: #00703c;
        color: #ffffff;
        border-color: #00703c;
    }

    .log-type-general {
        background: #f3f2f1;
        color: #1d1d1d;
    }

    .simple-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }

    @media (max-width: 700px) {
        .simple-grid {
            grid-template-columns: 1fr;
        }
    }

    .repeat-box {
        border: 2px solid #d8d8d8;
        background: #f8f8f8;
        padding: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .repeat-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.5rem;
        margin-bottom: 0.5rem;
    }

    @media (max-width: 600px) {
        .repeat-row {
            grid-template-columns: 1fr;
        }
    }

    .form-section {
        border-top: 2px solid #d8d8d8;
        padding-top: 1rem;
        margin-top: 1rem;
    }

    .form-section:first-of-type {
        border-top: 0;
        padding-top: 0;
        margin-top: 0;
    }

    .completion-pill {
        display: inline-block;
        border: 2px solid #1d1d1d;
        font-weight: 900;
        padding: 0.25rem 0.45rem;
    }

    .completion-complete {
        background: #00703c;
        color: #ffffff;
        border-color: #00703c;
    }

    .completion-incomplete {
        background: #d4351c;
        color: #ffffff;
        border-color: #d4351c;
    }

    .muted {
        color: #505a5f;
    }

    .empty-box {
        border: 2px dashed #b1b4b6;
        background: #f8f8f8;
        padding: 1rem;
        font-weight: 700;
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>People</h1>
        <p class="lead">
            Leader-only young people records and welfare logs.
        </p>
    </div>
</section>

<main id="main-content" class="container my-5 people-shell">

    <div class="people-actions">
        <a class="btn btn-outline-primary" href="<?= e(url('people.php')) ?>">All people</a>
        <a class="btn btn-outline-primary" href="<?= e(url('people.php?view=add')) ?>">Add person</a>
        <a class="btn btn-outline-primary" href="<?= e(url('team_links.php')) ?>">Teams</a>
        <a class="btn btn-outline-primary" href="<?= e(url('people_validation.php')) ?>">Excel View</a>

        <?php if ($currentPerson): ?>
            <a class="btn btn-outline-primary" href="<?= e(url('people.php?view=edit&person_id=' . (int)$currentPerson['id'])) ?>">Edit details</a>
            <a class="btn btn-outline-primary" href="<?= e(url('people.php?view=health_pdf&person_id=' . (int)$currentPerson['id'])) ?>">Download completed consent form PDF</a>
            <a class="btn btn-outline-primary" href="<?= e(url('people.php?view=print&person_id=' . (int)$currentPerson['id'])) ?>" target="_blank">Print record</a>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($view === 'add'): ?>

        <section class="people-panel">
            <h2>Add young person</h2>
            <?php render_people_form($teams, null); ?>
        </section>

    <?php elseif ($view === 'edit' && $currentPerson): ?>

        <section class="people-panel">
            <h2>Edit <?= e($currentPerson['name']) ?></h2>
            <?php render_people_form($teams, $currentPerson); ?>
        </section>

    <?php elseif ($currentPerson): ?>

        <?php
        $allergies = json_items($currentPerson['allergies_json'] ?? null);
        $medications = json_items($currentPerson['medications_json'] ?? null);
        $phones = json_items($currentPerson['phones_json'] ?? null);
        $parentEmails = json_items($currentPerson['parent_emails_json'] ?? null);
        $emergencyContacts = json_items($currentPerson['emergency_contacts_json'] ?? null);
        $latestSubmission = get_latest_parent_onboarding_submission($pdo, (int)$currentPerson['id']);
        $latestSnapshot = latest_submission_snapshot($latestSubmission);
        $additionalMedicalInfo = snapshot_value($latestSnapshot, ['health', 'additional_information']);
        ?>

        <section class="people-panel">
            <div class="profile-header">
                <div>
                    <?php render_person_photo($currentPerson, 'profile-photo'); ?>
                </div>

                <div>
                    <h2>
                        <?php if (!empty($allergies)): ?>
                            <span class="allergy-warning">!</span>
                        <?php endif; ?>

                        <?= e($currentPerson['name']) ?>
                    </h2>

                    <p class="muted">
                        <?= e($currentPerson['team_name'] ?: 'Not assigned to a team') ?>
                    </p>

                    <p>
                        <strong>Date of birth:</strong>
                        <?= e(person_age($currentPerson['dob'] ?? null)) ?>
                    </p>

                    <p>
                        <?php if (parent_form_completed($currentPerson)): ?>
                            <span class="completion-pill completion-complete">Parent form completed</span>
                        <?php else: ?>
                            <span class="completion-pill completion-incomplete">Parent form not completed</span>
                        <?php endif; ?>
                    </p>

                    <?php if ((int)$currentPerson['is_active'] !== 1): ?>
                        <p>
                            <span class="status-pill status-delayed">Inactive</span>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if (!empty($allergies)): ?>
            <section class="allergy-panel">
                <h2>Allergies</h2>
                <ul class="mb-0">
                    <?php foreach ($allergies as $allergy): ?>
                        <li><?= e((string)$allergy) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <section class="people-panel">
            <h2>Person record</h2>

            <div class="record-grid">
                <div class="record-box">
                    <h3>Participant details</h3>

                    <p><strong>Gender:</strong><br><?= e(display_value($currentPerson['gender'] ?? '')) ?></p>

                    <p>
                        <strong>Contact email:</strong><br>
                        <?php if (!empty($currentPerson['participant_email'])): ?>
                            <a href="mailto:<?= e($currentPerson['participant_email']) ?>"><?= e($currentPerson['participant_email']) ?></a>
                        <?php else: ?>
                            <span class="muted">Not recorded</span>
                        <?php endif; ?>
                    </p>

                    <p><strong>Phone number:</strong><br><?= e(display_value($currentPerson['participant_phone'] ?? '')) ?></p>
                    <p class="mb-0"><strong>Home address:</strong><br><?= nl2br(e(display_value($currentPerson['home_address'] ?? ''))) ?></p>
                </div>

                <div class="record-box">
                    <h3>Travel documents</h3>
                    <p><strong>Passport number:</strong><br><?= e(display_value(value_from_person_or_snapshot($currentPerson, $latestSnapshot, 'passport_number', ['personal', 'passport_number']))) ?></p>
                    <p><strong>Passport expiry date:</strong><br><?= e(display_value(value_from_person_or_snapshot($currentPerson, $latestSnapshot, 'passport_expiry_date', ['personal', 'passport_expiry_date']))) ?></p>
                    <p><strong>Passport nationality:</strong><br><?= e(display_value(value_from_person_or_snapshot($currentPerson, $latestSnapshot, 'passport_nationality', ['personal', 'passport_nationality']))) ?></p>
                    <p><strong>EHIC/GHIC number:</strong><br><?= e(display_value(value_from_person_or_snapshot($currentPerson, $latestSnapshot, 'ehic_ghic_number', ['personal', 'ehic_ghic_number']))) ?></p>
                    <p class="mb-0"><strong>EHIC/GHIC expiry date:</strong><br><?= e(display_value(value_from_person_or_snapshot($currentPerson, $latestSnapshot, 'ehic_ghic_expiry_date', ['personal', 'ehic_ghic_expiry_date']))) ?></p>
                </div>

                <div class="record-box record-box-wide">
                    <h3>Emergency contacts</h3>

                    <?php if (empty($emergencyContacts)): ?>
                        <p class="muted mb-0">No emergency contacts recorded.</p>
                    <?php else: ?>
                        <div class="contact-card-grid">
                            <?php foreach ($emergencyContacts as $contact): ?>
                                <?php if (!is_array($contact)) { continue; } ?>
                                <div class="contact-card">
                                    <h4><?= e(display_value($contact['name'] ?? '', 'Unnamed contact')) ?></h4>
                                    <p><strong>Relationship:</strong> <?= e(display_value($contact['relationship'] ?? '')) ?></p>
                                    <p><strong>Address:</strong><br><?= nl2br(e(display_value($contact['address'] ?? ''))) ?></p>
                                    <p><strong>Home or other contact number:</strong><br><?= e(display_value($contact['home_phone'] ?? '')) ?></p>
                                    <p><strong>Mobile phone:</strong><br><?= e(display_value($contact['mobile_phone'] ?? ($contact['phone'] ?? ''))) ?></p>
                                    <p class="mb-0"><strong>Email:</strong><br>
                                        <?php if (!empty($contact['email'])): ?>
                                            <a href="mailto:<?= e($contact['email']) ?>"><?= e($contact['email']) ?></a>
                                        <?php else: ?>
                                            <span class="muted">Not recorded</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="record-box">
                    <h3>Update emails</h3>
                    <p class="muted">These addresses can receive Explorer Belt updates and access the private trip update page.</p>
                    <?php if (empty($parentEmails)): ?>
                        <p class="muted mb-0">No update emails recorded.</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($parentEmails as $email): ?>
                                <li><a href="mailto:<?= e((string)$email) ?>"><?= e((string)$email) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="record-box">
                    <h3>Phone numbers</h3>
                    <?php if (empty($phones)): ?>
                        <p class="muted mb-0">No additional phone numbers recorded.</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($phones as $phone): ?>
                                <li><?= e((string)$phone) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="record-box">
                    <h3>Medications</h3>
                    <?php if (empty($medications)): ?>
                        <p class="muted mb-0">No medications recorded.</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($medications as $medication): ?>
                                <li><?= e((string)$medication) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="record-box">
                    <h3>Allergies, intolerances and dietary needs</h3>
                    <?php if (empty($allergies)): ?>
                        <p class="muted mb-0">No allergies, intolerances or dietary needs recorded.</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($allergies as $allergy): ?>
                                <li><?= e((string)$allergy) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="record-box">
                    <h3>Physical condition / injury / incapacity</h3>
                    <p class="mb-0"><?= nl2br(e(display_value(value_from_person_or_snapshot($currentPerson, $latestSnapshot, 'health_physical_restriction_details', ['health', 'physical_restriction_details'])))) ?></p>
                </div>

                <div class="record-box">
                    <h3>Family doctor</h3>
                    <p><strong>Name:</strong><br><?= e(display_value(value_from_person_or_snapshot($currentPerson, $latestSnapshot, 'family_doctor_name', ['health', 'family_doctor_name']))) ?></p>
                    <p><strong>Telephone:</strong><br><?= e(display_value(value_from_person_or_snapshot($currentPerson, $latestSnapshot, 'family_doctor_phone', ['health', 'family_doctor_phone']))) ?></p>
                    <p class="mb-0"><strong>Address:</strong><br><?= nl2br(e(display_value(value_from_person_or_snapshot($currentPerson, $latestSnapshot, 'family_doctor_address', ['health', 'family_doctor_address'])))) ?></p>
                </div>

                <div class="record-box">
                    <h3>Additional medical or welfare information</h3>
                    <p class="mb-0"><?= nl2br(e(display_value($additionalMedicalInfo))) ?></p>
                </div>
            </div>

            <?php if (!empty($currentPerson['notes'])): ?>
                <hr>
                <h3>Internal notes</h3>
                <p><?= nl2br(e($currentPerson['notes'])) ?></p>
            <?php endif; ?>
        </section>

        <section class="people-panel" id="logs">
            <h2>Add log</h2>

            <form method="post">
                <input type="hidden" name="action" value="add_log">
                <input type="hidden" name="person_id" value="<?= (int)$currentPerson['id'] ?>">

                <div class="simple-grid">
                    <div class="form-group">
                        <label>Log type</label>
                        <select class="form-control" name="log_type">
                            <option value="first_aid">First aid</option>
                            <option value="medication">Medication</option>
                            <option value="behaviour">Behaviour</option>
                            <option value="welfare">Welfare</option>
                            <option value="general">General note</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Date/time</label>
                        <input
                            class="form-control"
                            type="datetime-local"
                            name="occurred_at"
                            value="<?= e(datetime_local_value(people_now_for_database())) ?>"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label>Title</label>
                    <input class="form-control" name="title" required>
                </div>

                <div class="form-group">
                    <label>Notes</label>
                    <textarea class="form-control" name="body" rows="4" required></textarea>
                </div>

                <button class="btn btn-primary">Add log</button>
            </form>
        </section>

        <section class="people-panel">
            <h2>Profile logs</h2>

            <?php if (empty($personLogs)): ?>
                <div class="empty-box">No logs have been added for this person yet.</div>
            <?php else: ?>
                <?php foreach ($personLogs as $log): ?>
                    <article class="person-log">
                        <span class="log-type <?= e(log_type_class($log['log_type'])) ?>">
                            <?= e(log_type_label($log['log_type'])) ?>
                        </span>

                        <h3><?= e($log['title']) ?></h3>

                        <p class="log-meta">
                            <?= e(format_datetime($log['occurred_at'])) ?>
                            <?php if (!empty($log['leader_name'])): ?>
                                | <?= e($log['leader_name']) ?>
                            <?php endif; ?>
                        </p>

                        <p><?= nl2br(e($log['body'])) ?></p>

                        
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

    <?php else: ?>

        <section class="people-panel">
            <h2>Young people</h2>

            <p class="muted">
                Red photo border means the parent onboarding form has not been completed. Black photo border means it has been completed.
            </p>

            <?php if (empty($people)): ?>
                <div class="empty-box">No young people have been added yet.</div>
            <?php else: ?>
                <table class="people-table">
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>Name</th>
                            <th>Team</th>
                            <th>Date of birth</th>
                            <th>Alerts</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($people as $person): ?>
                            <?php
                            $profileUrl = url('people.php?person_id=' . (int)$person['id']);
                            ?>

                            <tr>
                                <td>
                                    <a href="<?= e($profileUrl) ?>">
                                        <?php render_person_photo($person, 'person-face'); ?>
                                    </a>
                                </td>

                                <td>
                                    <a class="person-row-link" href="<?= e($profileUrl) ?>">
                                        <?= e($person['name']) ?>
                                    </a>
                                </td>

                                <td><?= e($person['team_name'] ?: 'Not assigned') ?></td>

                                <td><?= e(person_age($person['dob'] ?? null)) ?></td>


                                <td>
                                    <?php if (person_has_allergies($person)): ?>
                                        <span class="allergy-warning">Allergies</span>
                                    <?php else: ?>
                                        <span class="muted">None recorded</span>
                                    <?php endif; ?>
                                </td>

                               
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

    <?php endif; ?>

</main>

<script>
    (function () {
        function createRepeatRow(name, placeholder) {
            var row = document.createElement('div');
            row.className = 'repeat-row';

            row.innerHTML =
                '<input class="form-control" name="' + name + '[]" placeholder="' + placeholder.replace(/"/g, '&quot;') + '">' +
                '<button type="button" class="btn btn-outline-danger btn-sm js-remove-row">Remove</button>';

            return row;
        }

        document.querySelectorAll('.js-add-repeat').forEach(function (button) {
            button.addEventListener('click', function () {
                var name = button.dataset.repeatName;
                var placeholder = button.dataset.placeholder || '';
                var group = document.querySelector('[data-repeat-group="' + name + '"]');

                if (!group) {
                    return;
                }

                group.appendChild(createRepeatRow(name, placeholder));
            });
        });

        document.addEventListener('click', function (event) {
            if (event.target.classList.contains('js-remove-row')) {
                var row = event.target.closest('.repeat-row');

                if (row) {
                    row.remove();
                }
            }

            if (event.target.classList.contains('js-remove-contact')) {
                var contact = event.target.closest('.contact-row');

                if (contact) {
                    contact.remove();
                }
            }
        });

        var addContactButton = document.getElementById('addContactRow');
        var contactRows = document.getElementById('contactRows');

        if (addContactButton && contactRows) {
            addContactButton.addEventListener('click', function () {
                var div = document.createElement('div');
                div.className = 'repeat-box contact-row';

                div.innerHTML =
                    '<div class="simple-grid">' +
                        '<div class="form-group">' +
                            '<label>Name</label>' +
                            '<input class="form-control" name="contact_name[]">' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label>Relationship</label>' +
                            '<input class="form-control" name="contact_relationship[]">' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label>Address</label>' +
                            '<textarea class="form-control" name="contact_address[]" rows="2"></textarea>' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label>Home or other contact number</label>' +
                            '<input class="form-control" name="contact_home_phone[]">' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label>Mobile phone</label>' +
                            '<input class="form-control" name="contact_mobile_phone[]">' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label>Email</label>' +
                            '<input class="form-control" type="email" name="contact_email[]">' +
                        '</div>' +
                    '</div>' +
                    '<button type="button" class="btn btn-outline-danger btn-sm js-remove-contact">Remove contact</button>';

                contactRows.appendChild(div);
            });
        }
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>