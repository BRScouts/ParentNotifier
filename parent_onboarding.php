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
 * General helpers
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

/**
 * Form extraction helpers
 */

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

function medications_from_post(): array
{
    $names = $_POST['medication_name'] ?? [];
    $types = $_POST['medication_type'] ?? [];
    $dosages = $_POST['medication_dosage'] ?? [];
    $frequencies = $_POST['medication_frequency'] ?? [];
    $frequencyOther = $_POST['medication_frequency_other'] ?? [];
    $notes = $_POST['medication_notes'] ?? [];

    $items = [];

    foreach ($names as $index => $name) {
        $name = trim((string)$name);
        $type = trim((string)($types[$index] ?? ''));
        $dosage = trim((string)($dosages[$index] ?? ''));
        $frequency = trim((string)($frequencies[$index] ?? ''));
        $other = trim((string)($frequencyOther[$index] ?? ''));
        $note = trim((string)($notes[$index] ?? ''));

        if ($name === '' && $type === '' && $dosage === '' && $frequency === '' && $other === '' && $note === '') {
            continue;
        }

        if ($frequency === 'Other' && $other !== '') {
            $frequency = 'Other - ' . $other;
        }

        $items[] = trim(
            'Medication: ' . $name .
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

    foreach ($details as $index => $detail) {
        $type = trim((string)($types[$index] ?? ''));
        $detail = trim((string)$detail);
        $severity = trim((string)($severities[$index] ?? ''));
        $note = trim((string)($notes[$index] ?? ''));

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

/**
 * Data helpers
 */

function fetch_person(PDO $pdo, int $personId): ?array
{
    $completionSelect = '';

    if (column_exists($pdo, 'young_people', 'parent_form_completed_at')) {
        $completionSelect .= ', yp.parent_form_completed_at';
    }

    if (column_exists($pdo, 'young_people', 'parent_onboarding_completed_at')) {
        $completionSelect .= ', yp.parent_onboarding_completed_at';
    }

    $stmt = $pdo->prepare(
        'SELECT 
            yp.*,
            t.name AS team_name,
            t.parent_token
            ' . $completionSelect . '
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
 * Email queue
 */

function queue_onboarding_confirmation_email(
    PDO $pdo,
    string $toEmail,
    array $person,
    array $allUpdateEmails
): void {
    $teamName = $person['team_name'] ?? 'your participant’s team';
    $privateTeamLink = team_parent_link($person);

    $subject = 'Explorer Belt onboarding completed';

    $content =
        '<p>Thank you. The Explorer Belt onboarding details for <strong>' . e($person['name'] ?? 'your participant') . '</strong> have been submitted.</p>' .
        '<p>Your private teams update page is:</p>' .
        '<p><a href="' . e($privateTeamLink) . '">' . e($privateTeamLink) . '</a></p>' .
        '<p>This is where updates, photos and evening check-in locations will be provided during the event for ' . e($teamName) . '.</p>' .
        '<p>We will include the above link with every update you recieve via these emails so do not worry if you loose it. You will also receive an email when a new photo, update or evening location has been confirmed in the system.</p>' .
        '<p><strong>No news is not bad news.</strong> Due to signal, time to process updates, and the need to ensure all teams have checked in, updates may not appear immediately.</p>' .
        '<p>During the event, please contact the Home Contact shown on the contact page rather than contacting the team in Finland directly.</p>' .
        '<hr>' .
        '<p><strong>Email addresses that will receive updates during the trip:</strong></p>' .
        '<ul>';

    foreach ($allUpdateEmails as $email) {
        $content .= '<li>' . e($email) . '</li>';
    }

    $content .= '</ul>' .
        '<p>Please look out for emails from <strong>' . e(ONBOARDING_CONFIRMATION_FROM_EMAIL) . '</strong>. This will send emails upto and prior to the event, consider adding this email address to your safe senders list.</p>';

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
             * Do not block the form if the email queue is temporarily unavailable.
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
         * Do not block the form if person_logs is unavailable.
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

    if (empty($contacts)) {
        $errors[] = 'Please provide at least one emergency contact.';
    }

    foreach ($contacts as $contact) {
        $name = trim((string)($contact['name'] ?? ''));
        $phone = trim((string)($contact['phone'] ?? ''));
        $email = trim((string)($contact['email'] ?? ''));

        if ($name === '') {
            $errors[] = 'Each emergency contact must have a name.';
        }

        if ($phone === '' && $email === '') {
            $errors[] = 'Each emergency contact must have at least a phone number or email address.';
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

    if (empty($_POST['privacy_acknowledgement'])) {
        $errors[] = 'Please confirm that you have read the privacy notice.';
    }

    return array_values(array_unique($errors));
}

/**
 * Leader preview
 */

function fetch_demo_leaders(PDO $pdo): array
{
    $bioSelect = 'NULL AS bio';

    if (column_exists($pdo, 'leaders', 'bio')) {
        $bioSelect = 'l.bio AS bio';
    } elseif (column_exists($pdo, 'leaders', 'blurb')) {
        $bioSelect = 'l.blurb AS bio';
    } elseif (column_exists($pdo, 'leaders', 'profile')) {
        $bioSelect = 'l.profile AS bio';
    }

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
                'SELECT DISTINCT
                    l.id,
                    l.name,
                    l.photo_url,
                    ' . $bioSelect . '
                 FROM leaders l
                 INNER JOIN leader_schedules ls ON ls.leader_id = l.id
                 WHERE (l.is_active = 1 OR l.is_active IS NULL)
                   AND (
                        ls.schedule_type = "in_country"
                        OR ls.schedule_type = "home_contact"
                        OR ls.schedule_type IS NULL
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
            'SELECT
                l.id,
                l.name,
                l.photo_url,
                ' . $bioSelect . '
             FROM leaders l
             WHERE l.is_active = 1
             ORDER BY l.name ASC'
        );

        return $stmt->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
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
        $formErrors = validate_parent_form();

        if (!empty($formErrors)) {
            $error = implode(' ', $formErrors);
        } else {
            try {
                $contactsArray = emergency_contacts_from_post();
                $contactsJson = empty($contactsArray) ? null : json_encode($contactsArray, JSON_UNESCAPED_UNICODE);

                $additionalEmails = additional_update_emails_from_post();
                $mergedParentEmails = merged_parent_update_emails($contactsArray, $additionalEmails);

                $medicationsJson = json_list_from_array(medications_from_post());
                $allergiesJson = json_list_from_array(allergies_from_post());

                $additionalInformation = trim($_POST['additional_information'] ?? '');
                $participantEmail = trim($_POST['participant_email'] ?? '');
                $participantPhone = trim($_POST['participant_phone'] ?? '');
                $homeAddress = trim($_POST['home_address'] ?? '');
                $gender = trim($_POST['gender'] ?? '');

                $photoPath = handle_parent_profile_upload('profile_image');
                $photoUpdated = true;

                $pdo->beginTransaction();

                $completionColumn = completion_column($pdo);
                $completionSql = $completionColumn ? ', ' . $completionColumn . ' = NOW()' : '';

                $stmt = $pdo->prepare(
                    'UPDATE young_people
                     SET participant_email = ?,
                         participant_phone = ?,
                         home_address = ?,
                         gender = ?,
                         photo_url = ?,
                         emergency_contacts_json = ?,
                         parent_emails_json = ?,
                         medications_json = ?,
                         allergies_json = ?
                         ' . $completionSql . '
                     WHERE id = ?'
                );

                $stmt->execute([
                    $participantEmail !== '' ? $participantEmail : null,
                    $participantPhone !== '' ? $participantPhone : null,
                    $homeAddress !== '' ? $homeAddress : null,
                    $gender !== '' ? $gender : null,
                    $photoPath,
                    $contactsJson,
                    json_list_from_array($mergedParentEmails),
                    $medicationsJson,
                    $allergiesJson,
                    $personId,
                ]);

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
    $medications = ensure_rows(json_items($matchedPerson['medications_json'] ?? null), 1, '');
    $allergies = ensure_rows(json_items($matchedPerson['allergies_json'] ?? null), 1, '');
}

$demoLeaders = $submittedPerson ? fetch_demo_leaders($pdo) : [];
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

        .onboarding-panel h2,
        .onboarding-panel h3,
        .onboarding-panel h4 {
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

        .locked-box {
            border-left: 8px solid #d4351c;
            background: #fff1f0;
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

        .dynamic-row-grid.medication {
            grid-template-columns: repeat(5, minmax(0, 1fr)) auto;
        }

        .dynamic-row-grid.allergy {
            grid-template-columns: repeat(4, minmax(0, 1fr)) auto;
        }

        @media (max-width: 1100px) {
            .dynamic-row-grid,
            .dynamic-row-grid.simple,
            .dynamic-row-grid.medication,
            .dynamic-row-grid.allergy {
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

        .private-link-box {
            border: 2px solid #00703c;
            background: #e9f8ef;
            padding: 1rem;
            word-break: break-all;
            margin: 1rem 0;
        }

        .leaders-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        @media (max-width: 800px) {
            .leaders-grid {
                grid-template-columns: 1fr;
            }
        }

        .leader-card {
            display: grid;
            grid-template-columns: 96px minmax(0, 1fr);
            gap: 1rem;
            border: 2px solid #d8d8d8;
            background: #ffffff;
            padding: 1rem;
            align-items: start;
        }

        @media (max-width: 520px) {
            .leader-card {
                grid-template-columns: 1fr;
            }
        }

        .leader-photo,
        .leader-placeholder {
            width: 96px;
            height: 96px;
            border: 2px solid #1d1d1d;
            background: #7413dc;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 2rem;
            object-fit: cover;
        }

        .leader-card h3 {
            margin: 0 0 0.35rem;
            font-size: 1.2rem;
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
        This form is used to safely run Explorer Belt 2026. Information submitted here will be available to authorised leaders supporting the trip.
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($isLocked && $lockedPerson): ?>

        <section class="locked-box">
            <h2>Form already completed</h2>

            <p>
                The onboarding form for <strong><?= e($lockedPerson['name']) ?></strong> has already been submitted.
            </p>

            <p class="mb-0">
                For security and data protection reasons, this form cannot be viewed again once submitted.
                If you need to change anything, please email
                <a href="mailto:<?= e(DATA_PROTECTION_EMAIL) ?>"><?= e(DATA_PROTECTION_EMAIL) ?></a>
                and ask for the form to be unlocked.
            </p>
        </section>

        <p>
            <a class="btn btn-outline-primary" href="<?= e(url('parent_onboarding.php?reset=1')) ?>">
                Start again
            </a>
        </p>

    <?php elseif ($success && $submittedPerson): ?>

        <section class="success-box">
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
        </section>

        <section class="onboarding-panel">
            <h2>Your private team page</h2>

            <p>
                Please save this link. This is where updates, photos and evening location check-ins will be provided during the event.
            </p>

            <div class="private-link-box">
                <strong><?= e($submittedPerson['team_name'] ?: 'Team page') ?></strong><br>
                <a href="<?= e($privateTeamLink) ?>"><?= e($privateTeamLink) ?></a>
            </div>

            <p>
                You will receive an email when a new photo, update or evening location has been confirmed.
            </p>

            <div class="warning-box">
                <strong>No news is not bad news.</strong>
                <p class="mb-0">
                    Due to signal, time to process updates, and the need to ensure all teams have checked in safely,
                    updates may not happen immediately. During the event, please contact the Home Contact listed on
                    the contact page, not the team in Finland.
                </p>
            </div>
        </section>

        <section class="onboarding-panel">
            <h2>Leadership team</h2>

            <p class="muted">
                These are leaders involved in supporting the event. The team page may show leader details and updates during the trip.
            </p>

            <?php if (!empty($demoLeaders)): ?>
                <div class="leaders-grid">
                    <?php foreach ($demoLeaders as $leader): ?>
                        <?php $leaderPhoto = media_url($leader['photo_url'] ?? ''); ?>

                        <article class="leader-card">
                            <div>
                                <?php if ($leaderPhoto !== ''): ?>
                                    <img class="leader-photo" src="<?= e($leaderPhoto) ?>" alt="Photo of <?= e($leader['name']) ?>">
                                <?php else: ?>
                                    <div class="leader-placeholder" aria-hidden="true">
                                        <?= e(initials((string)$leader['name'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <h3><?= e($leader['name']) ?></h3>

                                <?php if (!empty($leader['bio'])): ?>
                                    <p><?= nl2br(e($leader['bio'])) ?></p>
                                <?php else: ?>
                                    <p class="muted mb-0">
                                        Supporting Explorer Belt 2026.
                                    </p>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted mb-0">
                    Leadership team details will appear once leaders are scheduled.
                </p>
            <?php endif; ?>
        </section>

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
                <h3>Participant contact details</h3>

                <div class="form-group">
                    <label for="gender">Gender</label>
                    <select class="form-control" id="gender" name="gender">
                        <?php
                        $genderValue = $matchedPerson['gender'] ?? '';
                        $genderOptions = [
                            '' => 'Prefer not to say / not recorded',
                            'Female' => 'Female',
                            'Male' => 'Male',
                            'Non-binary' => 'Non-binary',
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

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="participant_email">Participant contact email</label>
                        <input
                            class="form-control"
                            id="participant_email"
                            name="participant_email"
                            type="email"
                            value="<?= e($matchedPerson['participant_email'] ?? '') ?>"
                        >
                    </div>

                    <div class="form-group col-md-6">
                        <label for="participant_phone">Participant phone number</label>
                        <input
                            class="form-control"
                            id="participant_phone"
                            name="participant_phone"
                            value="<?= e($matchedPerson['participant_phone'] ?? '') ?>"
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
                    ><?= e($matchedPerson['home_address'] ?? '') ?></textarea>
                </div>
            </section>

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

                <p>
                    Anyone listed for Explorer update emails will receive emails about the participant’s team progress, photos and confirmed evening check-ins.
                </p>

                <div class="warning-box">
                    <strong>Important:</strong>
                    Please make sure you have permission from each person before adding their email address for updates.
                </div>

                <h4>Automatically included from emergency contacts</h4>
                <div id="autoEmailRows"></div>

                <hr>

                <h4>Additional update emails</h4>

                <p class="muted">
                    You can add up to 5 additional email addresses.
                </p>

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
                <h3>Medication</h3>

                <p class="muted">
                    Add any prescribed or non-prescribed medication. Include dosage and how often it is taken.
                </p>

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
                <h3>Allergies, intolerances and dietary needs</h3>

                <p class="muted">
                    Add allergies, intolerances and dietary needs. These will be stored against the participant’s allergy record so leaders can see them clearly.
                </p>

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

                                <button type="button" class="btn btn-outline-danger" data-remove-row>
                                    Remove
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="dynamic-actions">
                    <button type="button" class="btn btn-outline-primary" id="addAllergyRow">
                        Add allergy / intolerance / dietary need
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

            <section class="dynamic-section">
                <h3>Privacy acknowledgement</h3>

                <p>
                    Please read the privacy notice before submitting this form.
                    It explains how personal information is processed for Explorer Belt 2026.
                </p>

                <p>
                    <a href="<?= e(url('privacy.php')) ?>" target="_blank" rel="noopener">
                        Open privacy notice in a new tab
                    </a>
                </p>

                <div class="form-check">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="privacy_acknowledgement"
                        name="privacy_acknowledgement"
                        value="1"
                        required
                    >
                    <label class="form-check-label" for="privacy_acknowledgement">
                        I confirm that I have read the privacy notice.
                    </label>
                </div>
            </section>

            <section class="warning-box">
                <strong>Before submitting:</strong>
                Please check the information carefully. Once submitted, this form cannot be viewed again unless the trip team unlocks it.
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
    <div class="dynamic-row medication-row">
        <div class="dynamic-row-grid medication">
            <div class="form-group mb-0">
                <label>Medication name</label>
                <input class="form-control" name="medication_name[]">
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

            <button type="button" class="btn btn-outline-danger" data-remove-row>
                Remove
            </button>
        </div>
    </div>
</template>

<template id="allergyRowTemplate">
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
                <input class="form-control" name="allergy_detail[]">
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