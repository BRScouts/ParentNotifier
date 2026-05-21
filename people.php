<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

$error = '';

const PEOPLE_UPLOAD_DIR = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/people/';
const PEOPLE_UPLOAD_PUBLIC_PATH = 'assets/people/';

/**
 * Helpers
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

function emergency_contacts_from_post(): ?string
{
    $names = $_POST['contact_name'] ?? [];
    $relationships = $_POST['contact_relationship'] ?? [];
    $phones = $_POST['contact_phone'] ?? [];
    $emails = $_POST['contact_email'] ?? [];

    $contacts = [];

    foreach ($names as $index => $name) {
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

    return empty($contacts) ? null : json_encode($contacts, JSON_UNESCAPED_UNICODE);
}

function person_has_allergies(array $person): bool
{
    return count(json_items($person['allergies_json'] ?? null)) > 0;
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
        return date('Y-m-d\TH:i');
    }

    return date('Y-m-d\TH:i', strtotime($datetime));
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

function render_person_photo(array $person, string $className): void
{
    $name = $person['name'] ?? 'Young person';

    if (!empty($person['photo_url'])) {
        ?>
        <img class="<?= e($className) ?>" src="<?= e(url($person['photo_url'])) ?>" alt="Photo of <?= e($name) ?>">
        <?php
    } else {
        ?>
        <div class="<?= e($className) ?>" aria-hidden="true">
            <?= e(person_initials($name)) ?>
        </div>
        <?php
    }
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
        $notes = trim($_POST['notes'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $error = 'Name is required.';
        } else {
            try {
                $photoPath = handle_profile_upload('profile_image', null);

                $stmt = $pdo->prepare(
                    'INSERT INTO young_people
                        (
                            team_id,
                            name,
                            dob,
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
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $teamId > 0 ? $teamId : null,
                    $name,
                    $dob !== '' ? $dob : null,
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
        $notes = trim($_POST['notes'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $existing = $personId > 0 ? fetch_person($pdo, $personId) : null;

        if (!$existing) {
            $error = 'Person not found.';
        } elseif ($name === '') {
            $error = 'Name is required.';
        } else {
            try {
                $photoPath = handle_profile_upload('profile_image', $existing['photo_url'] ?? null);

                $stmt = $pdo->prepare(
                    'UPDATE young_people
                     SET team_id = ?,
                         name = ?,
                         dob = ?,
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
                : date('Y-m-d H:i:s');

            $stmt = $pdo->prepare(
                'INSERT INTO person_logs
                    (person_id, leader_id, log_type, title, body, occurred_at)
                 VALUES
                    (?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $personId,
                $user['id'] ?? null,
                $logType,
                $title,
                $body,
                $occurredAtForDb,
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

if ($view === 'print' && $currentPerson) {
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
            <h2>Medical and welfare information</h2>

            <p><strong>Allergies:</strong> <?= e(implode(', ', array_map('strval', json_items($currentPerson['allergies_json'] ?? null))) ?: 'None recorded') ?></p>
            <p><strong>Medications:</strong> <?= e(implode(', ', array_map('strval', json_items($currentPerson['medications_json'] ?? null))) ?: 'None recorded') ?></p>
            <p><strong>Notes:</strong><br><?= nl2br(e($currentPerson['notes'] ?? '')) ?></p>
        </div>

        <div class="section">
            <h2>Emergency contacts</h2>

            <?php $contacts = json_items($currentPerson['emergency_contacts_json'] ?? null); ?>

            <?php if (empty($contacts)): ?>
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
                        <?php foreach ($contacts as $contact): ?>
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
        border: 2px solid #1d1d1d;
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
        border: 2px solid #1d1d1d;
        background: #7413dc;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        font-size: 2.5rem;
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

        <?php if ($currentPerson): ?>
            <a class="btn btn-outline-primary" href="<?= e(url('people.php?view=edit&person_id=' . (int)$currentPerson['id'])) ?>">Edit details</a>
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
            <?php include __DIR__ . '/people_form_partial.php'; ?>
        </section>

    <?php elseif ($view === 'edit' && $currentPerson): ?>

        <section class="people-panel">
            <h2>Edit <?= e($currentPerson['name']) ?></h2>
            <?php $formPerson = $currentPerson; include __DIR__ . '/people_form_partial.php'; ?>
        </section>

    <?php elseif ($currentPerson): ?>

        <?php
        $allergies = json_items($currentPerson['allergies_json'] ?? null);
        $medications = json_items($currentPerson['medications_json'] ?? null);
        $phones = json_items($currentPerson['phones_json'] ?? null);
        $parentEmails = json_items($currentPerson['parent_emails_json'] ?? null);
        $emergencyContacts = json_items($currentPerson['emergency_contacts_json'] ?? null);
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
                    <h3>Emergency contacts</h3>

                    <?php if (empty($emergencyContacts)): ?>
                        <p class="muted mb-0">No emergency contacts recorded.</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($emergencyContacts as $contact): ?>
                                <li>
                                    <strong><?= e($contact['name'] ?? '') ?></strong>
                                    <?php if (!empty($contact['relationship'])): ?>
                                        - <?= e($contact['relationship']) ?>
                                    <?php endif; ?>

                                    <?php if (!empty($contact['phone'])): ?>
                                        <br>Phone: <?= e($contact['phone']) ?>
                                    <?php endif; ?>

                                    <?php if (!empty($contact['email'])): ?>
                                        <br>Email:
                                        <a href="mailto:<?= e($contact['email']) ?>"><?= e($contact['email']) ?></a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="record-box">
                    <h3>Parent emails</h3>
                    <?php if (empty($parentEmails)): ?>
                        <p class="muted mb-0">No parent emails recorded.</p>
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
                        <p class="muted mb-0">No phone numbers recorded.</p>
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
            </div>

            <?php if (!empty($currentPerson['notes'])): ?>
                <hr>
                <h3>Notes</h3>
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
                        <input disabled
                            class="form-control"
                            type="datetime-local"
                            name="occurred_at"
                            value="<?= e(datetime_local_value(date('Y-m-d H:i:s'))) ?>"
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

                        <form method="post" onsubmit="return confirm('Delete this log entry?');">
                            <input type="hidden" name="action" value="delete_log">
                            <input type="hidden" name="person_id" value="<?= (int)$currentPerson['id'] ?>">
                            <input type="hidden" name="log_id" value="<?= (int)$log['id'] ?>">
                            <button class="btn btn-outline-danger btn-sm">Delete log</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

    <?php else: ?>

        <section class="people-panel">
            <h2>Young people</h2>

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
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($people as $person): ?>
                            <?php
                            $profileUrl = url('people.php?person_id=' . (int)$person['id']);
                            ?>

                            <tr>
                                <td>
                                    <a class="person-face" href="<?= e($profileUrl) ?>">
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

                                <td>
                                    <?php if ((int)$person['is_active'] === 1): ?>
                                        <span class="status-pill status-checked-in">Active</span>
                                    <?php else: ?>
                                        <span class="status-pill status-delayed">Inactive</span>
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

<?php include __DIR__ . '/footer.php'; ?>