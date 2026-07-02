<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();

/**
 * People validation dashboard.
 *
 * Shows an Excel-style validation sheet for leader contingency checks and exports the same data as .xlsx.
 * No database changes are required for this page.
 */

const PEOPLE_VALIDATION_TIMEZONE = 'Europe/Helsinki';
const PEOPLE_VALIDATION_DEFAULT_SCHENGEN_EXIT_DATE = '2026-08-31'; // Change this default if your planned Schengen exit date differs.
const PEOPLE_UPLOAD_DIR = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/people/';

/**
 * General helpers
 */
function pv_now(): DateTime
{
    return new DateTime('now', new DateTimeZone(PEOPLE_VALIDATION_TIMEZONE));
}

function pv_column_exists(PDO $pdo, string $table, string $column): bool
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

function pv_table_exists(PDO $pdo, string $table): bool
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

function pv_clean_text($value): string
{
    $value = trim((string)$value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[ \t]+/', ' ', $value);

    return trim((string)$value);
}

function pv_json_items($json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode((string)$json, true);

    return is_array($decoded) ? $decoded : [];
}

function pv_snapshot(array $submission = null): array
{
    if (!$submission || empty($submission['snapshot_json'])) {
        return [];
    }

    $decoded = json_decode((string)$submission['snapshot_json'], true);

    return is_array($decoded) ? $decoded : [];
}

function pv_snapshot_path(array $snapshot, array $path)
{
    $value = $snapshot;

    foreach ($path as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }

        $value = $value[$part];
    }

    return $value;
}

function pv_value(array $person, array $snapshot, string $column, array $snapshotPaths = []): string
{
    if (array_key_exists($column, $person) && pv_clean_text($person[$column]) !== '') {
        return pv_clean_text($person[$column]);
    }

    foreach ($snapshotPaths as $path) {
        $snapshotValue = pv_snapshot_path($snapshot, $path);

        if (!is_array($snapshotValue) && pv_clean_text($snapshotValue) !== '') {
            return pv_clean_text($snapshotValue);
        }
    }

    return '';
}

function pv_array_from_person_or_snapshot(array $person, array $snapshot, string $column, array $snapshotPath): array
{
    $personItems = pv_json_items($person[$column] ?? null);

    if (!empty($personItems)) {
        return $personItems;
    }

    $snapshotItems = pv_snapshot_path($snapshot, $snapshotPath);

    return is_array($snapshotItems) ? $snapshotItems : [];
}

function pv_items_to_text(array $items, string $separator = "\n"): string
{
    $clean = [];

    foreach ($items as $item) {
        if (is_array($item)) {
            $parts = [];

            foreach ($item as $key => $value) {
                $value = pv_clean_text($value);

                if ($value !== '') {
                    $parts[] = ucfirst(str_replace('_', ' ', (string)$key)) . ': ' . $value;
                }
            }

            $item = implode(' | ', $parts);
        }

        $item = pv_clean_text($item);

        if ($item !== '') {
            $clean[] = $item;
        }
    }

    return implode($separator, $clean);
}

function pv_contact_value(array $contact, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $contact) && pv_clean_text($contact[$key]) !== '') {
            return pv_clean_text($contact[$key]);
        }
    }

    return '';
}

function pv_format_date(?string $date): string
{
    $date = pv_clean_text($date);

    if ($date === '') {
        return '';
    }

    try {
        return (new DateTime($date))->format('d/m/Y');
    } catch (Throwable $exception) {
        return $date;
    }
}

function pv_parse_date(?string $date): ?DateTime
{
    $date = pv_clean_text($date);

    if ($date === '') {
        return null;
    }

    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $date, new DateTimeZone(PEOPLE_VALIDATION_TIMEZONE));

        if ($dt instanceof DateTime) {
            $errors = DateTime::getLastErrors();

            if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                $dt->setTime(0, 0, 0);
                return $dt;
            }
        }
    }

    try {
        $dt = new DateTime($date, new DateTimeZone(PEOPLE_VALIDATION_TIMEZONE));
        $dt->setTime(0, 0, 0);

        return $dt;
    } catch (Throwable $exception) {
        return null;
    }
}

function pv_requested_schengen_exit_date(): string
{
    $requested = pv_clean_text($_GET['schengen_exit_date'] ?? '');

    if ($requested !== '' && pv_parse_date($requested)) {
        return pv_parse_date($requested)->format('Y-m-d');
    }

    return PEOPLE_VALIDATION_DEFAULT_SCHENGEN_EXIT_DATE;
}

function pv_person_age(?string $dob): string
{
    $dob = pv_clean_text($dob);

    if ($dob === '') {
        return 'Not recorded';
    }

    try {
        $birth = new DateTime($dob);
        $today = new DateTime('today', new DateTimeZone(PEOPLE_VALIDATION_TIMEZONE));
        $age = $birth->diff($today)->y;

        return $birth->format('d/m/Y') . ' (' . $age . ')';
    } catch (Throwable $exception) {
        return $dob;
    }
}

function pv_initials(string $name): string
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

function pv_parent_form_completed(array $person): bool
{
    return !empty($person['parent_form_completed_at']) || !empty($person['parent_onboarding_completed_at']);
}

function pv_completion_date(array $person): string
{
    $value = $person['parent_form_completed_at'] ?? ($person['parent_onboarding_completed_at'] ?? '');

    return pv_format_date($value);
}

function pv_photo_src(array $person): string
{
    $path = pv_clean_text($person['photo_url'] ?? '');

    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return function_exists('url') ? url($path) : $path;
}

function pv_photo_local_path(array $person): string
{
    $path = pv_clean_text($person['photo_url'] ?? '');

    if ($path === '') {
        return '';
    }

    if (is_file($path)) {
        return $path;
    }

    $relative = ltrim($path, '/');
    $candidates = [
        __DIR__ . '/' . $relative,
        rtrim(PEOPLE_UPLOAD_DIR, '/') . '/' . basename($relative),
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function pv_level_weight(string $level): int
{
    return [
        'ok' => 0,
        'info' => 1,
        'warn' => 2,
        'bad' => 3,
    ][$level] ?? 1;
}

function pv_status(string $level, string $label, string $details = ''): array
{
    return [
        'level' => $level,
        'label' => $label,
        'details' => $details,
    ];
}

function pv_nationality_status(string $nationality): array
{
    $nationality = pv_clean_text($nationality);

    if ($nationality === '') {
        return pv_status('warn', 'Missing', 'Passport nationality has not been recorded.');
    }

    $normalised = strtolower($nationality);
    $normalised = preg_replace('/[^a-z0-9 ]+/', ' ', (string)$normalised);
    $normalised = preg_replace('/\s+/', ' ', (string)$normalised);
    $normalised = trim((string)$normalised);

    $acceptedExact = [
        'gb',
        'gbr',
        'uk',
        'british',
        'britain',
        'great britain',
        'united kingdom',
        'england',
        'english',
        'scotland',
        'scottish',
        'wales',
        'welsh',
        'northern ireland',
        'northern irish',
        'british citizen',
        'british national',
    ];

    if (in_array($normalised, $acceptedExact, true)) {
        return pv_status('ok', 'Accepted', $nationality);
    }

    foreach ($acceptedExact as $accepted) {
        if ($accepted !== 'uk' && strlen($accepted) > 2 && strpos($normalised, $accepted) !== false) {
            return pv_status('ok', 'Accepted', $nationality);
        }
    }

    return pv_status('bad', 'Review', 'Passport nationality is not GB/British/UK/England/Britain: ' . $nationality);
}

function pv_passport_expiry_status(string $expiry, string $schengenExitDate): array
{
    $expiryDate = pv_parse_date($expiry);
    $exitDate = pv_parse_date($schengenExitDate);

    if (!$exitDate) {
        return pv_status('bad', 'Invalid exit date', 'Set a valid Schengen exit date.');
    }

    $requiredDate = clone $exitDate;
    $requiredDate->modify('+3 months');

    if (!$expiryDate) {
        return pv_status('warn', 'Missing', 'Passport expiry date has not been recorded. Required on or after ' . $requiredDate->format('d/m/Y') . '.');
    }

    if ($expiryDate < $requiredDate) {
        return pv_status('bad', 'Review', 'Passport expires ' . $expiryDate->format('d/m/Y') . '; required on or after ' . $requiredDate->format('d/m/Y') . '.');
    }

    return pv_status('ok', 'OK', 'Expiry ' . $expiryDate->format('d/m/Y') . ' is at least 3 months after Schengen exit.');
}

function pv_ehic_status(string $expiry, string $number, string $schengenExitDate): array
{
    $number = pv_clean_text($number);
    $expiryDate = pv_parse_date($expiry);
    $exitDate = pv_parse_date($schengenExitDate);

    if ($number === '' && !$expiryDate) {
        return pv_status('warn', 'Missing', 'EHIC/GHIC number and expiry date have not been recorded.');
    }

    if (!$expiryDate) {
        return pv_status('warn', 'Missing expiry', 'EHIC/GHIC expiry date has not been recorded.');
    }

    if ($exitDate && $expiryDate < $exitDate) {
        return pv_status('bad', 'Review', 'EHIC/GHIC expires before the Schengen exit date.');
    }

    return pv_status('ok', 'OK', 'EHIC/GHIC expiry recorded.');
}

function pv_has_text(string $value): bool
{
    $value = pv_clean_text($value);

    if ($value === '') {
        return false;
    }

    $lower = strtolower($value);

    return !in_array($lower, ['none', 'no', 'n/a', 'na', 'not applicable', 'nil'], true);
}

function pv_attention_push(array &$flags, string $flag): void
{
    $flag = pv_clean_text($flag);

    if ($flag !== '' && !in_array($flag, $flags, true)) {
        $flags[] = $flag;
    }
}

function pv_fetch_people(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            yp.*,
            t.name AS team_name
         FROM young_people yp
         LEFT JOIN teams t ON t.id = yp.team_id
         ORDER BY t.name ASC, yp.name ASC'
    );

    return $stmt->fetchAll();
}

function pv_fetch_latest_submissions(PDO $pdo): array
{
    if (!pv_table_exists($pdo, 'parent_onboarding_submissions')) {
        return [];
    }

    try {
        $stmt = $pdo->query(
            'SELECT s.*
             FROM parent_onboarding_submissions s
             INNER JOIN (
                 SELECT person_id, MAX(id) AS max_id
                 FROM parent_onboarding_submissions
                 GROUP BY person_id
             ) latest ON latest.max_id = s.id'
        );

        $rows = $stmt->fetchAll();
        $byPerson = [];

        foreach ($rows as $row) {
            $byPerson[(int)$row['person_id']] = $row;
        }

        return $byPerson;
    } catch (Throwable $exception) {
        return [];
    }
}

function pv_contact_count_with_details(array $contacts): int
{
    $count = 0;

    foreach ($contacts as $contact) {
        if (!is_array($contact)) {
            continue;
        }

        $hasName = pv_contact_value($contact, ['name']) !== '';
        $hasPhone = pv_contact_value($contact, ['mobile_phone', 'phone', 'home_phone']) !== '';
        $hasEmail = pv_contact_value($contact, ['email']) !== '';

        if ($hasName && ($hasPhone || $hasEmail)) {
            $count++;
        }
    }

    return $count;
}

function pv_build_validation_row(array $person, ?array $submission, string $schengenExitDate): array
{
    $snapshot = pv_snapshot($submission);
    $contacts = pv_array_from_person_or_snapshot($person, $snapshot, 'emergency_contacts_json', ['emergency_contacts']);
    $parentEmails = pv_array_from_person_or_snapshot($person, $snapshot, 'parent_emails_json', ['update_emails']);
    $phones = pv_json_items($person['phones_json'] ?? null);
    $medications = pv_array_from_person_or_snapshot($person, $snapshot, 'medications_json', ['health', 'medications']);
    $allergies = pv_array_from_person_or_snapshot($person, $snapshot, 'allergies_json', ['health', 'allergies']);

    $passportNumber = pv_value($person, $snapshot, 'passport_number', [['participant', 'passport_number'], ['personal', 'passport_number']]);
    $passportExpiry = pv_value($person, $snapshot, 'passport_expiry_date', [['participant', 'passport_expiry_date'], ['personal', 'passport_expiry_date']]);
    $passportNationality = pv_value($person, $snapshot, 'passport_nationality', [['participant', 'passport_nationality'], ['personal', 'passport_nationality']]);
    $ehicNumber = pv_value($person, $snapshot, 'ehic_ghic_number', [['participant', 'ehic_ghic_number'], ['personal', 'ehic_ghic_number']]);
    $ehicExpiry = pv_value($person, $snapshot, 'ehic_ghic_expiry_date', [['participant', 'ehic_ghic_expiry_date'], ['personal', 'ehic_ghic_expiry_date']]);

    $medicalConditionDetails = pv_value($person, $snapshot, 'health_medical_condition_details', [['health', 'medical_condition_details']]);
    $physicalRestrictionDetails = pv_value($person, $snapshot, 'health_physical_restriction_details', [['health', 'physical_restriction_details']]);
    $medicationAllergyDetails = pv_value($person, $snapshot, 'health_medication_allergy_details', [['health', 'medication_allergy_details']]);
    $additionalWelfare = pv_clean_text(pv_snapshot_path($snapshot, ['health', 'additional_information']));

    $doctorName = pv_value($person, $snapshot, 'family_doctor_name', [['health', 'family_doctor_name']]);
    $doctorPhone = pv_value($person, $snapshot, 'family_doctor_phone', [['health', 'family_doctor_phone']]);
    $doctorAddress = pv_value($person, $snapshot, 'family_doctor_address', [['health', 'family_doctor_address']]);

    $nationalityStatus = pv_nationality_status($passportNationality);
    $passportExpiryStatus = pv_passport_expiry_status($passportExpiry, $schengenExitDate);
    $ehicStatus = pv_ehic_status($ehicExpiry, $ehicNumber, $schengenExitDate);

    $flags = [];

    if (!pv_parent_form_completed($person)) {
        pv_attention_push($flags, 'Parent onboarding not completed');
    }

    if (pv_clean_text($person['photo_url'] ?? '') === '') {
        pv_attention_push($flags, 'No participant photo');
    }

    if ($passportNumber === '') {
        pv_attention_push($flags, 'Passport number missing');
    }

    if (pv_level_weight($nationalityStatus['level']) >= 2) {
        pv_attention_push($flags, $nationalityStatus['details']);
    }

    if (pv_level_weight($passportExpiryStatus['level']) >= 2) {
        pv_attention_push($flags, $passportExpiryStatus['details']);
    }

    if (pv_level_weight($ehicStatus['level']) >= 2) {
        pv_attention_push($flags, $ehicStatus['details']);
    }

    if (pv_contact_count_with_details($contacts) < 2) {
        pv_attention_push($flags, 'Fewer than two complete emergency contacts');
    }

    foreach ($contacts as $index => $contact) {
        if (!is_array($contact)) {
            continue;
        }

        $contactLabel = 'Emergency contact ' . ($index + 1);

        if (pv_contact_value($contact, ['name']) === '') {
            pv_attention_push($flags, $contactLabel . ' name missing');
        }

        if (pv_contact_value($contact, ['address']) === '') {
            pv_attention_push($flags, $contactLabel . ' address missing');
        }

        if (pv_contact_value($contact, ['home_phone']) === '') {
            pv_attention_push($flags, $contactLabel . ' home/other number missing');
        }

        if (pv_contact_value($contact, ['mobile_phone', 'phone']) === '') {
            pv_attention_push($flags, $contactLabel . ' mobile number missing');
        }

        if (pv_contact_value($contact, ['email']) === '') {
            pv_attention_push($flags, $contactLabel . ' email missing');
        }
    }

    if (empty($parentEmails)) {
        pv_attention_push($flags, 'No update email recipients');
    }

    if (!empty($medications)) {
        pv_attention_push($flags, 'Medication recorded');
    }

    if (!empty($allergies)) {
        pv_attention_push($flags, 'Allergy/intolerance/dietary need recorded');
    }

    if (pv_has_text($medicalConditionDetails) || (isset($person['health_medical_condition']) && (int)$person['health_medical_condition'] === 1)) {
        pv_attention_push($flags, 'Medical condition details recorded');
    }

    if (pv_has_text($physicalRestrictionDetails) || (isset($person['health_physical_restriction']) && (int)$person['health_physical_restriction'] === 1)) {
        pv_attention_push($flags, 'Physical condition/injury/incapacity recorded');
    }

    if (pv_has_text($medicationAllergyDetails) || (isset($person['health_medication_allergy']) && (int)$person['health_medication_allergy'] === 1)) {
        pv_attention_push($flags, 'Medication allergy details recorded');
    }

    if (pv_has_text($additionalWelfare)) {
        pv_attention_push($flags, 'Additional medical/welfare information recorded');
    }

    if ($doctorName === '' || $doctorPhone === '' || $doctorAddress === '') {
        pv_attention_push($flags, 'Family doctor details incomplete');
    }

    $columns = [
        'Photo' => pv_photo_src($person),
        'Participant' => pv_clean_text($person['name'] ?? ''),
        'Team' => pv_clean_text($person['team_name'] ?? 'Not assigned'),
        'Attention' => empty($flags) ? 'No attention flags' : implode("\n", $flags),
        'Parent form' => pv_parent_form_completed($person) ? 'Completed' : 'Not completed',
        'Completed date' => pv_completion_date($person),
        'Date of birth / age' => pv_person_age($person['dob'] ?? null),
        'Passport nationality check' => $nationalityStatus['label'] . ($nationalityStatus['details'] ? ' - ' . $nationalityStatus['details'] : ''),
        'Passport expiry check' => $passportExpiryStatus['label'] . ($passportExpiryStatus['details'] ? ' - ' . $passportExpiryStatus['details'] : ''),
        'EHIC/GHIC check' => $ehicStatus['label'] . ($ehicStatus['details'] ? ' - ' . $ehicStatus['details'] : ''),
        'Passport number' => $passportNumber,
        'Passport nationality' => $passportNationality,
        'Passport expiry' => pv_format_date($passportExpiry),
        'EHIC/GHIC number' => $ehicNumber,
        'EHIC/GHIC expiry' => pv_format_date($ehicExpiry),
        'Participant phone' => pv_value($person, $snapshot, 'participant_phone', [['participant', 'participant_phone']]),
        'Participant email' => pv_value($person, $snapshot, 'participant_email', [['participant', 'participant_email']]),
        'Home address' => pv_value($person, $snapshot, 'home_address', [['participant', 'home_address']]),
        'Update emails' => pv_items_to_text($parentEmails),
        'Other phone numbers' => pv_items_to_text($phones),
        'Medications' => pv_items_to_text($medications),
        'Allergies / dietary' => pv_items_to_text($allergies),
        'Medical condition details' => $medicalConditionDetails,
        'Physical condition / injury / incapacity' => $physicalRestrictionDetails,
        'Medication allergy details' => $medicationAllergyDetails,
        'Additional welfare information' => $additionalWelfare,
        'Doctor name' => $doctorName,
        'Doctor phone' => $doctorPhone,
        'Doctor address' => $doctorAddress,
    ];

    for ($i = 0; $i < 5; $i++) {
        $contact = is_array($contacts[$i] ?? null) ? $contacts[$i] : [];
        $number = $i + 1;

        $columns['Emergency contact ' . $number . ' name'] = pv_contact_value($contact, ['name']);
        $columns['Emergency contact ' . $number . ' relationship'] = pv_contact_value($contact, ['relationship']);
        $columns['Emergency contact ' . $number . ' address'] = pv_contact_value($contact, ['address']);
        $columns['Emergency contact ' . $number . ' home/other'] = pv_contact_value($contact, ['home_phone']);
        $columns['Emergency contact ' . $number . ' mobile'] = pv_contact_value($contact, ['mobile_phone', 'phone']);
        $columns['Emergency contact ' . $number . ' email'] = pv_contact_value($contact, ['email']);
    }

    return [
        'person_id' => (int)($person['id'] ?? 0),
        'person' => $person,
        'columns' => $columns,
        'flags' => $flags,
        'severity' => empty($flags) ? 'ok' : 'bad',
        'status' => [
            'nationality' => $nationalityStatus,
            'passport_expiry' => $passportExpiryStatus,
            'ehic' => $ehicStatus,
        ],
        'photo_local_path' => pv_photo_local_path($person),
    ];
}

function pv_build_validation_rows(array $people, array $submissions, string $schengenExitDate): array
{
    $rows = [];

    foreach ($people as $person) {
        $submission = $submissions[(int)($person['id'] ?? 0)] ?? null;
        $rows[] = pv_build_validation_row($person, $submission, $schengenExitDate);
    }

    return $rows;
}

function pv_filter_rows(array $rows, string $filter): array
{
    if ($filter === 'attention') {
        return array_values(array_filter($rows, static function (array $row): bool {
            return !empty($row['flags']);
        }));
    }

    if ($filter === 'complete') {
        return array_values(array_filter($rows, static function (array $row): bool {
            return empty($row['flags']);
        }));
    }

    return $rows;
}

function pv_count_attention(array $rows): int
{
    $count = 0;

    foreach ($rows as $row) {
        if (!empty($row['flags'])) {
            $count++;
        }
    }

    return $count;
}

/**
 * XLSX helpers
 */
function pv_xlsx_xml(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$value);

    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function pv_xlsx_col_name(int $index): string
{
    $index++;
    $name = '';

    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = (int)(($index - $mod) / 26);
    }

    return $name;
}

function pv_xlsx_cell_ref(int $row, int $col): string
{
    return pv_xlsx_col_name($col) . $row;
}

function pv_xlsx_cell(int $row, int $col, string $value, int $style = 0): string
{
    $ref = pv_xlsx_cell_ref($row, $col);
    $value = function_exists('mb_substr') ? mb_substr($value, 0, 32700) : substr($value, 0, 32700);
    $stylePart = $style > 0 ? ' s="' . $style . '"' : '';

    if ($value === '') {
        return '<c r="' . $ref . '"' . $stylePart . '/>';
    }

    return '<c r="' . $ref . '" t="inlineStr"' . $stylePart . '><is><t xml:space="preserve">' . pv_xlsx_xml($value) . '</t></is></c>';
}

function pv_xlsx_row(int $rowNumber, array $values, int $style, array $cellStyles = []): string
{
    $height = $rowNumber === 1 ? 24 : 30;
    $xml = '<row r="' . $rowNumber . '" ht="' . $height . '" customHeight="1">';

    foreach ($values as $col => $value) {
        $cellStyle = $cellStyles[$col] ?? $style;
        $xml .= pv_xlsx_cell($rowNumber, (int)$col, (string)$value, $cellStyle);
    }

    $xml .= '</row>';

    return $xml;
}

function pv_xlsx_local_image(string $path): ?array
{
    if ($path === '' || !is_file($path)) {
        return null;
    }

    $info = @getimagesize($path);

    if (!$info || empty($info['mime'])) {
        return null;
    }

    $mime = $info['mime'];
    $extension = null;

    if ($mime === 'image/jpeg') {
        $extension = 'jpg';
    } elseif ($mime === 'image/png') {
        $extension = 'png';
    }

    if (!$extension) {
        return null;
    }

    return [
        'path' => $path,
        'extension' => $extension,
        'mime' => $mime,
    ];
}

function pv_xlsx_content_types(array $images): string
{
    $hasJpg = false;
    $hasPng = false;

    foreach ($images as $image) {
        if (($image['extension'] ?? '') === 'jpg') {
            $hasJpg = true;
        }

        if (($image['extension'] ?? '') === 'png') {
            $hasPng = true;
        }
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        ($hasJpg ? '<Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="jpeg" ContentType="image/jpeg"/>' : '') .
        ($hasPng ? '<Default Extension="png" ContentType="image/png"/>' : '') .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        (!empty($images) ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '') .
        '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
        '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
        '</Types>';
}

function pv_xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<fonts count="4">' .
        '<font><sz val="10"/><name val="Arial"/></font>' .
        '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>' .
        '<font><b/><sz val="10"/><color rgb="FF1D1D1D"/><name val="Arial"/></font>' .
        '<font><b/><sz val="10"/><color rgb="FFD4351C"/><name val="Arial"/></font>' .
        '</fonts>' .
        '<fills count="6">' .
        '<fill><patternFill patternType="none"/></fill>' .
        '<fill><patternFill patternType="gray125"/></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FF7413DC"/><bgColor indexed="64"/></patternFill></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FFF8F8F8"/><bgColor indexed="64"/></patternFill></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF1F0"/><bgColor indexed="64"/></patternFill></fill>' .
        '</fills>' .
        '<borders count="2">' .
        '<border><left/><right/><top/><bottom/><diagonal/></border>' .
        '<border><left style="thin"><color rgb="FFD8D8D8"/></left><right style="thin"><color rgb="FFD8D8D8"/></right><top style="thin"><color rgb="FFD8D8D8"/></top><bottom style="thin"><color rgb="FFD8D8D8"/></bottom><diagonal/></border>' .
        '</borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="6">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '</cellXfs>' .
        '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
        '</styleSheet>';
}

function pv_xlsx_sheet_xml(array $headers, array $rows, array $imageRefs): string
{
    $maxCol = count($headers) - 1;
    $dimension = 'A1:' . pv_xlsx_col_name($maxCol) . max(1, count($rows) + 1);

    $widths = [8, 26, 16, 42, 15, 15, 17, 32, 36, 30, 18, 22, 16, 18, 16, 18, 24, 32, 34, 24, 34, 34, 34, 34, 34, 34, 22, 20, 34];
    $cols = '<cols>';

    for ($i = 0; $i <= $maxCol; $i++) {
        $width = $widths[$i] ?? 24;
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
    }

    $cols .= '</cols>';

    $sheetData = '<sheetData>';
    $sheetData .= pv_xlsx_row(1, $headers, 1);

    foreach ($rows as $rowIndex => $row) {
        $baseStyle = $rowIndex % 2 === 0 ? 2 : 3;
        $values = [];
        $styles = [];

        foreach ($headers as $colIndex => $header) {
            $value = (string)($row['columns'][$header] ?? '');
            $values[] = $header === 'Photo' ? '' : $value;

            if ($header === 'Attention' && !empty($row['flags'])) {
                $styles[$colIndex] = 4;
            } elseif (in_array($header, ['Participant', 'Team'], true)) {
                $styles[$colIndex] = 5;
            }
        }

        $sheetData .= pv_xlsx_row($rowIndex + 2, $values, $baseStyle, $styles);
    }

    $sheetData .= '</sheetData>';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<dimension ref="' . $dimension . '"/>' .
        '<sheetViews><sheetView workbookViewId="0"><pane xSplit="2" ySplit="1" topLeftCell="C2" activePane="bottomRight" state="frozen"/><selection pane="bottomRight" activeCell="C2" sqref="C2"/></sheetView></sheetViews>' .
        '<sheetFormatPr defaultRowHeight="18"/>' .
        $cols .
        $sheetData .
        '<autoFilter ref="A1:' . pv_xlsx_col_name($maxCol) . '1"/>' .
        (!empty($imageRefs) ? '<drawing r:id="rId1"/>' : '') .
        '</worksheet>';
}

function pv_xlsx_drawing_xml(array $imageRefs): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    foreach ($imageRefs as $index => $image) {
        $rowZero = (int)$image['row_zero_based'];
        $name = 'Participant photo ' . ($index + 1);
        $rid = 'rId' . ($index + 1);

        $xml .= '<xdr:oneCellAnchor>' .
            '<xdr:from><xdr:col>0</xdr:col><xdr:colOff>57150</xdr:colOff><xdr:row>' . $rowZero . '</xdr:row><xdr:rowOff>57150</xdr:rowOff></xdr:from>' .
            '<xdr:ext cx="323850" cy="323850"/>' .
            '<xdr:pic>' .
            '<xdr:nvPicPr><xdr:cNvPr id="' . ($index + 1) . '" name="' . pv_xlsx_xml($name) . '"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>' .
            '<xdr:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>' .
            '<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>' .
            '</xdr:pic>' .
            '<xdr:clientData/>' .
            '</xdr:oneCellAnchor>';
    }

    $xml .= '</xdr:wsDr>';

    return $xml;
}

function pv_xlsx_drawing_rels(array $imageRefs): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    foreach ($imageRefs as $index => $image) {
        $xml .= '<Relationship Id="rId' . ($index + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . pv_xlsx_xml($image['filename']) . '"/>';
    }

    $xml .= '</Relationships>';

    return $xml;
}

function pv_xlsx_root_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
        '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
        '</Relationships>';
}

function pv_xlsx_workbook_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Validation" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>';
}

function pv_xlsx_workbook_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>' .
        '</Relationships>';
}

function pv_xlsx_sheet_rels(array $imageRefs): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        (!empty($imageRefs) ? '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>' : '') .
        '</Relationships>';
}

function pv_xlsx_app_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' .
        '<Application>ParentNotifier</Application>' .
        '</Properties>';
}

function pv_xlsx_core_xml(): string
{
    $now = gmdate('Y-m-d\TH:i:s\Z');

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
        '<dc:title>Explorer Belt validation sheet</dc:title>' .
        '<dc:creator>ParentNotifier</dc:creator>' .
        '<cp:lastModifiedBy>ParentNotifier</cp:lastModifiedBy>' .
        '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>' .
        '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>' .
        '</cp:coreProperties>';
}

function pv_xlsx_theme_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office Theme">' .
        '<a:themeElements>' .
        '<a:clrScheme name="ParentNotifier"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1><a:dk2><a:srgbClr val="1F2937"/></a:dk2><a:lt2><a:srgbClr val="F3F4F6"/></a:lt2><a:accent1><a:srgbClr val="7413DC"/></a:accent1><a:accent2><a:srgbClr val="D4351C"/></a:accent2><a:accent3><a:srgbClr val="00703C"/></a:accent3><a:accent4><a:srgbClr val="1D70B8"/></a:accent4><a:accent5><a:srgbClr val="FFDD00"/></a:accent5><a:accent6><a:srgbClr val="505A5F"/></a:accent6><a:hlink><a:srgbClr val="0563C1"/></a:hlink><a:folHlink><a:srgbClr val="954F72"/></a:folHlink></a:clrScheme>' .
        '<a:fontScheme name="Office"><a:majorFont><a:latin typeface="Arial"/></a:majorFont><a:minorFont><a:latin typeface="Arial"/></a:minorFont></a:fontScheme>' .
        '<a:fmtScheme name="Office"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst><a:lnStyleLst><a:ln w="6350" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln></a:lnStyleLst><a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst><a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst></a:fmtScheme>' .
        '</a:themeElements>' .
        '</a:theme>';
}

function pv_export_xlsx(array $rows, string $schengenExitDate): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ZipArchive is not enabled on this PHP installation, so XLSX export cannot be generated.';
        exit;
    }

    $headers = [];

    if (!empty($rows)) {
        $headers = array_keys($rows[0]['columns']);
    } else {
        $headers = [
            'Photo',
            'Participant',
            'Team',
            'Attention',
            'Parent form',
            'Passport nationality check',
            'Passport expiry check',
        ];
    }

    $images = [];
    $imageRefs = [];

    foreach ($rows as $index => $row) {
        $image = pv_xlsx_local_image($row['photo_local_path'] ?? '');

        if (!$image) {
            continue;
        }

        $filename = 'image' . (count($images) + 1) . '.' . $image['extension'];
        $image['filename'] = $filename;
        $images[] = $image;
        $imageRefs[] = [
            'filename' => $filename,
            'row_zero_based' => $index + 1,
        ];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'validation-xlsx-');

    if ($tmp === false) {
        throw new RuntimeException('Could not create temporary export file.');
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not initialise XLSX export.');
    }

    $zip->addFromString('[Content_Types].xml', pv_xlsx_content_types($images));
    $zip->addFromString('_rels/.rels', pv_xlsx_root_rels());
    $zip->addFromString('docProps/app.xml', pv_xlsx_app_xml());
    $zip->addFromString('docProps/core.xml', pv_xlsx_core_xml());
    $zip->addFromString('xl/workbook.xml', pv_xlsx_workbook_xml());
    $zip->addFromString('xl/_rels/workbook.xml.rels', pv_xlsx_workbook_rels());
    $zip->addFromString('xl/styles.xml', pv_xlsx_styles_xml());
    $zip->addFromString('xl/theme/theme1.xml', pv_xlsx_theme_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', pv_xlsx_sheet_xml($headers, $rows, $imageRefs));
    $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', pv_xlsx_sheet_rels($imageRefs));

    if (!empty($imageRefs)) {
        $zip->addFromString('xl/drawings/drawing1.xml', pv_xlsx_drawing_xml($imageRefs));
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', pv_xlsx_drawing_rels($imageRefs));
    }

    foreach ($images as $image) {
        $zip->addFile($image['path'], 'xl/media/' . $image['filename']);
    }

    $zip->close();

    $filename = 'explorer-belt-validation-' . preg_replace('/[^0-9-]/', '', $schengenExitDate) . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    readfile($tmp);
    @unlink($tmp);
    exit;
}

function pv_status_badge(array $status): string
{
    $level = $status['level'] ?? 'info';
    $label = $status['label'] ?? '';
    $details = $status['details'] ?? '';

    return '<span class="validation-pill validation-pill-' . e($level) . '" title="' . e($details) . '">' . e($label) . '</span>';
}

function pv_cell_class_for_header(string $header, array $row): string
{
    if ($header === 'Attention' && !empty($row['flags'])) {
        return 'validation-attention-cell';
    }

    if (in_array($header, ['Medications', 'Allergies / dietary', 'Medical condition details', 'Physical condition / injury / incapacity', 'Medication allergy details', 'Additional welfare information'], true)) {
        return pv_has_text((string)($row['columns'][$header] ?? '')) ? 'validation-watch-cell' : '';
    }

    return '';
}

$schengenExitDate = pv_requested_schengen_exit_date();
$show = pv_clean_text($_GET['show'] ?? 'all');

if (!in_array($show, ['all', 'attention', 'complete'], true)) {
    $show = 'all';
}

$people = pv_fetch_people($pdo);
$submissions = pv_fetch_latest_submissions($pdo);
$allRows = pv_build_validation_rows($people, $submissions, $schengenExitDate);
$displayRows = pv_filter_rows($allRows, $show);

if (($_GET['download'] ?? '') === 'excel') {
    pv_export_xlsx($displayRows, $schengenExitDate);
}

$headers = !empty($allRows) ? array_keys($allRows[0]['columns']) : [];
$displayHeaders = array_values(array_filter($headers, static function (string $header): bool {
    return $header !== 'Attention';
}));
$totalCount = count($allRows);
$attentionCount = pv_count_attention($allRows);
$completeCount = $totalCount - $attentionCount;
$requiredPassportDate = pv_parse_date($schengenExitDate);

if ($requiredPassportDate) {
    $requiredPassportDate = (clone $requiredPassportDate)->modify('+3 months')->format('d/m/Y');
} else {
    $requiredPassportDate = 'invalid exit date';
}

$baseParams = [
    'schengen_exit_date' => $schengenExitDate,
    'show' => $show,
];

$downloadUrl = 'people_validation.php?' . http_build_query(array_merge($baseParams, ['download' => 'excel']));

include __DIR__ . '/header.php';
?>

<style>
    .validation-shell {
        max-width: 100%;
    }

    .validation-hero,
    .validation-hero h1,
    .validation-hero p {
        color: #ffffff !important;
    }

    .validation-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: end;
        margin-bottom: 1rem;
    }

    .validation-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem;
        margin-bottom: 1.25rem;
    }

    .validation-panel h2,
    .validation-panel h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .validation-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
    }

    @media (max-width: 900px) {
        .validation-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 540px) {
        .validation-summary-grid {
            grid-template-columns: 1fr;
        }
    }

    .validation-summary-box {
        border: 2px solid #d8d8d8;
        background: #f8f8f8;
        padding: 1rem;
    }

    .validation-summary-box strong {
        display: block;
        font-size: 2rem;
        line-height: 1;
        margin-bottom: 0.35rem;
    }

    .validation-note {
        border-left: 8px solid #1d70b8;
        background: #eef7ff;
        padding: 1rem;
        margin-bottom: 1.25rem;
    }

    .validation-scroll {
        width: 100%;
        overflow: auto;
        border: 2px solid #1d1d1d;
        background: #ffffff;
        max-height: 84vh;
    }

    .validation-table {
        border-collapse: separate;
        border-spacing: 0;
        min-width: 4200px;
        width: max-content;
        background: #ffffff;
    }

    .validation-table th,
    .validation-table td {
        border-right: 1px solid #d8d8d8;
        border-bottom: 1px solid #d8d8d8;
        padding: 0.22rem 0.35rem;
        vertical-align: middle;
        background: #ffffff;
        min-width: 140px;
        max-width: 260px;
        white-space: nowrap;
        overflow: visible;
        font-size: 0.82rem;
        line-height: 1.15;
    }

    .validation-table th {
        position: sticky;
        top: 0;
        z-index: 4;
        background: #7413dc;
        color: #ffffff;
        font-weight: 900;
        text-align: left;
    }

    .validation-table tbody tr:nth-child(even) td {
        background: #f8f8f8;
    }

    .validation-table tbody tr:hover td {
        background: #fff7bf;
    }

    .validation-table .sticky-photo {
        position: sticky;
        left: 0;
        z-index: 3;
        min-width: 54px;
        max-width: 54px;
        width: 54px;
        text-align: center;
        background: inherit;
    }

    .validation-table .sticky-name {
        position: sticky;
        left: 54px;
        z-index: 3;
        min-width: 180px;
        max-width: 180px;
        width: 180px;
        background: inherit;
        font-weight: 900;
    }

    .validation-table th.sticky-photo,
    .validation-table th.sticky-name {
        z-index: 6;
        background: #7413dc;
        color: #ffffff;
    }

    .validation-photo,
    .validation-photo-placeholder {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        border: 2px solid #1d1d1d;
        object-fit: cover;
        background: #7413dc;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        font-size: 0.8rem;
    }

    .validation-cell-text,
    .validation-small {
        display: block;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .validation-photo-wrap {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        outline: none;
        cursor: pointer;
    }

    .validation-photo-wrap:hover .validation-photo,
    .validation-photo-wrap:hover .validation-photo-placeholder {
        box-shadow: 0 0 0 3px #ffdd00;
    }

    .validation-photo-has-flags .validation-photo,
    .validation-photo-has-flags .validation-photo-placeholder {
        border-color: #d4351c;
        box-shadow: 0 0 0 2px #fff1f0;
    }

    .validation-photo-count {
        position: absolute;
        right: -4px;
        bottom: -3px;
        min-width: 18px;
        height: 18px;
        padding: 0 4px;
        border-radius: 999px;
        background: #d4351c;
        color: #ffffff;
        border: 2px solid #ffffff;
        font-size: 0.68rem;
        font-weight: 900;
        line-height: 14px;
        text-align: center;
    }

    .validation-face-tooltip {
        position: absolute;
        left: 48px;
        top: 50%;
        transform: translateY(-50%);
        z-index: 50;
        display: none;
        min-width: 280px;
        max-width: 420px;
        max-height: 360px;
        overflow: auto;
        padding: 0.75rem;
        border: 2px solid #1d1d1d;
        background: #ffffff;
        color: #1d1d1d;
        text-align: left;
        white-space: normal;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.22);
    }

    .validation-face-tooltip strong {
        display: block;
        margin-bottom: 0.4rem;
    }

    .validation-face-tooltip ul {
        padding-left: 1.1rem;
        margin: 0;
    }

    .validation-face-tooltip li {
        margin-bottom: 0.25rem;
    }

    .validation-photo-wrap:hover .validation-face-tooltip,
    .validation-photo-wrap:focus .validation-face-tooltip,
    .validation-photo-wrap:focus-within .validation-face-tooltip {
        display: none;
    }

    .validation-floating-tooltip {
        position: fixed;
        z-index: 99999;
        display: none;
        min-width: 280px;
        max-width: min(440px, calc(100vw - 32px));
        max-height: min(420px, calc(100vh - 32px));
        overflow: auto;
        padding: 0.75rem;
        border: 2px solid #1d1d1d;
        background: #ffffff;
        color: #1d1d1d;
        text-align: left;
        white-space: normal;
        box-shadow: 0 10px 28px rgba(0, 0, 0, 0.28);
        font-size: 0.9rem;
        line-height: 1.25;
    }

    .validation-floating-tooltip.is-visible {
        display: block;
    }

    .validation-floating-tooltip strong {
        display: block;
        margin-bottom: 0.45rem;
    }

    .validation-floating-tooltip ul {
        margin: 0;
        padding-left: 1.15rem;
    }

    .validation-floating-tooltip li {
        margin-bottom: 0.25rem;
    }

    .validation-pill {
        display: inline-block;
        border: 2px solid #1d1d1d;
        padding: 0.18rem 0.4rem;
        font-weight: 900;
        margin: 0.1rem 0.15rem 0.1rem 0;
        white-space: nowrap;
    }

    .validation-pill-ok {
        background: #00703c;
        border-color: #00703c;
        color: #ffffff;
    }

    .validation-pill-info {
        background: #eef7ff;
        border-color: #1d70b8;
        color: #1d1d1d;
    }

    .validation-pill-warn {
        background: #ffdd00;
        border-color: #ffdd00;
        color: #1d1d1d;
    }

    .validation-pill-bad {
        background: #d4351c;
        border-color: #d4351c;
        color: #ffffff;
    }

    .validation-attention-cell {
        background: #fff1f0 !important;
        color: #1d1d1d;
        font-weight: 800;
    }

    .validation-watch-cell {
        background: #fff7bf !important;
        font-weight: 800;
    }

    .validation-muted {
        color: #505a5f;
    }

    .validation-small {
        font-size: 0.78rem;
        margin-top: 0.15rem;
    }
</style>

<section class="page-hero validation-hero">
    <div class="container-fluid validation-shell px-4">
        <h1>People validation</h1>
        <p class="lead mb-0">
            Excel-style contingency view for passport, emergency contact and health checks.
        </p>
    </div>
</section>

<main id="main-content" class="container-fluid validation-shell my-4 px-4">
    <div class="validation-actions">
        <a class="btn btn-outline-primary" href="<?= e(url('people.php')) ?>">People</a>
        <a class="btn btn-outline-primary" href="<?= e(url('team_links.php')) ?>">Teams</a>
        <a class="btn btn-primary" href="<?= e(url($downloadUrl)) ?>">Download Excel contingency sheet</a>
    </div>

    <section class="validation-panel">
        <h2>Validation sheet</h2>

        <?php if (empty($displayRows)): ?>
            <p class="validation-muted mb-0">No participants match the current filter.</p>
        <?php else: ?>
            <div class="validation-scroll" role="region" aria-label="People validation table" tabindex="0">
                <table class="validation-table">
                    <thead>
                        <tr>
                            <?php foreach ($displayHeaders as $index => $header): ?>
                                <?php
                                $classes = [];

                                if ($header === 'Photo') {
                                    $classes[] = 'sticky-photo';
                                }

                                if ($header === 'Participant') {
                                    $classes[] = 'sticky-name';
                                }
                                ?>
                                <th class="<?= e(implode(' ', $classes)) ?>"><?= e($header) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($displayRows as $row): ?>
                            <?php $person = $row['person']; ?>
                            <tr>
                                <?php foreach ($displayHeaders as $header): ?>
                                    <?php
                                    $classes = [];

                                    if ($header === 'Photo') {
                                        $classes[] = 'sticky-photo';
                                    }

                                    if ($header === 'Participant') {
                                        $classes[] = 'sticky-name';
                                    }

                                    $extraClass = pv_cell_class_for_header($header, $row);

                                    if ($extraClass !== '') {
                                        $classes[] = $extraClass;
                                    }
                                    ?>
                                    <td class="<?= e(implode(' ', $classes)) ?>">
                                        <?php if ($header === 'Photo'): ?>
                                            <?php
                                            $flagCount = count($row['flags']);
                                            $flagTooltip = $flagCount === 0 ? 'No attention flags' : implode("\n", $row['flags']);
                                            ?>
                                            <span
                                                class="validation-photo-wrap <?= $flagCount > 0 ? 'validation-photo-has-flags' : 'validation-photo-ok' ?>"
                                                tabindex="0"
                                                data-participant-name="<?= e($person['name'] ?? 'Participant') ?>"
                                                data-flags="<?= e($flagTooltip) ?>"
                                                aria-label="<?= e(($person['name'] ?? 'Participant') . ': ' . $flagTooltip) ?>"
                                            >
                                                <?php if (pv_photo_src($person) !== ''): ?>
                                                    <img class="validation-photo" src="<?= e(pv_photo_src($person)) ?>" alt="Photo of <?= e($person['name'] ?? 'participant') ?>">
                                                <?php else: ?>
                                                    <span class="validation-photo-placeholder" aria-hidden="true"><?= e(pv_initials($person['name'] ?? '')) ?></span>
                                                <?php endif; ?>

                                                <?php if ($flagCount > 0): ?>
                                                    <span class="validation-photo-count" aria-hidden="true"><?= (int)$flagCount ?></span>
                                                <?php endif; ?>

                                            </span>
                                        <?php elseif ($header === 'Participant'): ?>
                                            <a href="<?= e(url('people.php?person_id=' . (int)$row['person_id'])) ?>">
                                                <?= e($row['columns'][$header] ?? '') ?>
                                            </a>
                                        <?php elseif ($header === 'Passport nationality check'): ?>
                                            <?= pv_status_badge($row['status']['nationality']) ?>
                                            <div class="validation-small"><?= e($row['status']['nationality']['details'] ?? '') ?></div>
                                        <?php elseif ($header === 'Passport expiry check'): ?>
                                            <?= pv_status_badge($row['status']['passport_expiry']) ?>
                                            <div class="validation-small"><?= e($row['status']['passport_expiry']['details'] ?? '') ?></div>
                                        <?php elseif ($header === 'EHIC/GHIC check'): ?>
                                            <?= pv_status_badge($row['status']['ehic']) ?>
                                            <div class="validation-small"><?= e($row['status']['ehic']['details'] ?? '') ?></div>
                                        <?php else: ?>
                                            <?php
                                            $cellValue = (string)($row['columns'][$header] ?? '');
                                            $compactCellValue = preg_replace('/\s*\n+\s*/', ' · ', $cellValue);
                                            ?>
                                            <span class="validation-cell-text" title="<?= e($cellValue) ?>">
                                                <?= e((string)$compactCellValue) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<div class="validation-floating-tooltip" id="validationFloatingTooltip" role="tooltip" aria-hidden="true"></div>

<script>
    (function () {
        var tooltip = document.getElementById('validationFloatingTooltip');

        if (!tooltip) {
            return;
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function buildTooltipHtml(trigger) {
            var participantName = trigger.getAttribute('data-participant-name') || 'Participant';
            var flagsText = trigger.getAttribute('data-flags') || 'No attention flags';
            var flags = flagsText.split(/\n+/).map(function (flag) {
                return flag.trim();
            }).filter(Boolean);

            if (!flags.length || flagsText === 'No attention flags') {
                return '<strong>' + escapeHtml(participantName) + '</strong><span class="validation-muted">No validation issues found.</span>';
            }

            return '<strong>' + escapeHtml(participantName) + ' — ' + flags.length + ' attention ' + (flags.length === 1 ? 'flag' : 'flags') + '</strong>' +
                '<ul>' + flags.map(function (flag) {
                    return '<li>' + escapeHtml(flag) + '</li>';
                }).join('') + '</ul>';
        }

        function positionTooltip(trigger) {
            var rect = trigger.getBoundingClientRect();
            var spacing = 10;

            tooltip.style.left = '0px';
            tooltip.style.top = '0px';
            tooltip.classList.add('is-visible');

            var tooltipRect = tooltip.getBoundingClientRect();
            var left = rect.right + spacing;
            var top = rect.top + (rect.height / 2) - (tooltipRect.height / 2);

            if (left + tooltipRect.width + spacing > window.innerWidth) {
                left = rect.left - tooltipRect.width - spacing;
            }

            if (left < spacing) {
                left = spacing;
            }

            if (top + tooltipRect.height + spacing > window.innerHeight) {
                top = window.innerHeight - tooltipRect.height - spacing;
            }

            if (top < spacing) {
                top = spacing;
            }

            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';
        }

        function showTooltip(trigger) {
            tooltip.innerHTML = buildTooltipHtml(trigger);
            tooltip.setAttribute('aria-hidden', 'false');
            positionTooltip(trigger);
        }

        function hideTooltip() {
            if (pinnedTrigger) return;
            tooltip.classList.remove('is-visible');
            tooltip.setAttribute('aria-hidden', 'true');
            tooltip.innerHTML = '';
            activeTrigger = null;
        }

        function forceHideTooltip() {
            pinnedTrigger = null;
            activeTrigger = null;
            tooltip.classList.remove('is-visible');
            tooltip.setAttribute('aria-hidden', 'true');
            tooltip.innerHTML = '';
        }

        var activeTrigger = null;
        var pinnedTrigger = null;

        document.querySelectorAll('.validation-photo-wrap').forEach(function (trigger) {
            trigger.addEventListener('mouseenter', function () {
                if (pinnedTrigger && pinnedTrigger !== trigger) return;
                activeTrigger = trigger;
                showTooltip(trigger);
            });

            trigger.addEventListener('mousemove', function () {
                if (pinnedTrigger && pinnedTrigger !== trigger) return;
                if (activeTrigger === trigger) positionTooltip(trigger);
            });

            trigger.addEventListener('mouseleave', function () {
                if (pinnedTrigger === trigger) return;
                if (activeTrigger === trigger) {
                    activeTrigger = null;
                    tooltip.classList.remove('is-visible');
                    tooltip.setAttribute('aria-hidden', 'true');
                    tooltip.innerHTML = '';
                }
            });

            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                if (pinnedTrigger === trigger) {
                    forceHideTooltip();
                    return;
                }

                pinnedTrigger = trigger;
                activeTrigger = trigger;
                showTooltip(trigger);
            });

            trigger.addEventListener('focus', function () {
                if (!pinnedTrigger) {
                    activeTrigger = trigger;
                    showTooltip(trigger);
                }
            });

            trigger.addEventListener('blur', function () {
                if (pinnedTrigger !== trigger) {
                    activeTrigger = null;
                    tooltip.classList.remove('is-visible');
                    tooltip.setAttribute('aria-hidden', 'true');
                    tooltip.innerHTML = '';
                }
            });
        });

        document.addEventListener('click', function (e) {
            if (!pinnedTrigger) return;
            if (tooltip.contains(e.target)) return;
            if (e.target.closest('.validation-photo-wrap')) return;
            forceHideTooltip();
        });

        window.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') forceHideTooltip();
        });

        var scrollContainer = document.querySelector('.validation-scroll');
        if (scrollContainer) {
            scrollContainer.addEventListener('scroll', function () {
                if (pinnedTrigger) {
                    positionTooltip(pinnedTrigger);
                } else if (activeTrigger) {
                    positionTooltip(activeTrigger);
                }
            });
        }

        window.addEventListener('resize', forceHideTooltip);
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>
