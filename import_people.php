<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

$error = '';
$previewRows = [];
$validRows = [];
$rowErrors = [];
$imported = 0;
$updated = 0;

$expectedHeaders = [
    'Name',
    'DOB',
    'Team',
    'Photo URL',
    'Emergency Contact 1 Name',
    'Emergency Contact 1 Relationship',
    'Emergency Contact 1 Phone',
    'Emergency Contact 1 Email',
    'Emergency Contact 2 Name',
    'Emergency Contact 2 Relationship',
    'Emergency Contact 2 Phone',
    'Emergency Contact 2 Email',
    'Emergency Contact 3 Name',
    'Emergency Contact 3 Relationship',
    'Emergency Contact 3 Phone',
    'Emergency Contact 3 Email',
    'Parent Email 1',
    'Parent Email 2',
    'Parent Email 3',
    'Parent Email 4',
    'Phone 1',
    'Phone 2',
    'Phone 3',
    'Medication 1',
    'Medication 2',
    'Medication 3',
    'Allergy 1',
    'Allergy 2',
    'Allergy 3',
    'Notes',
    'Active',
];

function normalise_header(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\xEF\xBB\xBF/', '', $value);

    return strtolower(preg_replace('/\s+/', ' ', $value));
}

function parse_import_date(?string $value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $formats = [
        'Y-m-d',
        'd/m/Y',
        'd-m-Y',
        'd.m.Y',
        'm/d/Y',
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

function truthy_active(?string $value): int
{
    $value = strtolower(trim((string)$value));

    if ($value === '') {
        return 1;
    }

    return in_array($value, ['yes', 'y', 'true', '1', 'active'], true) ? 1 : 0;
}

function clean_text(?string $value): string
{
    return trim((string)$value);
}

function json_list(array $values): ?string
{
    $clean = [];

    foreach ($values as $value) {
        $value = trim((string)$value);

        if ($value !== '') {
            $clean[] = $value;
        }
    }

    return empty($clean) ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
}

function emergency_contacts_json(array $row): ?string
{
    $contacts = [];

    for ($i = 1; $i <= 3; $i++) {
        $name = clean_text($row["Emergency Contact {$i} Name"] ?? '');
        $relationship = clean_text($row["Emergency Contact {$i} Relationship"] ?? '');
        $phone = clean_text($row["Emergency Contact {$i} Phone"] ?? '');
        $email = clean_text($row["Emergency Contact {$i} Email"] ?? '');

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

function find_team_id(PDO $pdo, string $teamName): ?int
{
    $teamName = trim($teamName);

    if ($teamName === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM teams WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$teamName]);
    $team = $stmt->fetch();

    return $team ? (int)$team['id'] : null;
}

function find_existing_person_id(PDO $pdo, string $name, ?string $dob): ?int
{
    if ($dob) {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM young_people
             WHERE LOWER(name) = LOWER(?)
               AND dob = ?
             LIMIT 1'
        );

        $stmt->execute([$name, $dob]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM young_people
             WHERE LOWER(name) = LOWER(?)
               AND dob IS NULL
             LIMIT 1'
        );

        $stmt->execute([$name]);
    }

    $person = $stmt->fetch();

    return $person ? (int)$person['id'] : null;
}

function build_person_row(PDO $pdo, array $row, int $lineNumber): array
{
    $errors = [];

    $name = clean_text($row['Name'] ?? '');
    $dob = parse_import_date($row['DOB'] ?? '');
    $teamName = clean_text($row['Team'] ?? '');
    $photoUrl = clean_text($row['Photo URL'] ?? '');
    $notes = clean_text($row['Notes'] ?? '');
    $isActive = truthy_active($row['Active'] ?? '');

    if ($name === '') {
        $errors[] = 'Name is required.';
    }

    if (clean_text($row['DOB'] ?? '') !== '' && !$dob) {
        $errors[] = 'DOB could not be parsed. Use YYYY-MM-DD or DD/MM/YYYY.';
    }

    $teamId = null;

    if ($teamName !== '') {
        $teamId = find_team_id($pdo, $teamName);

        if (!$teamId) {
            $errors[] = 'Team not found: ' . $teamName;
        }
    }

    $parentEmails = [
        $row['Parent Email 1'] ?? '',
        $row['Parent Email 2'] ?? '',
        $row['Parent Email 3'] ?? '',
        $row['Parent Email 4'] ?? '',
    ];

    foreach ($parentEmails as $email) {
        $email = trim((string)$email);

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid parent email: ' . $email;
        }
    }

    for ($i = 1; $i <= 3; $i++) {
        $email = trim((string)($row["Emergency Contact {$i} Email"] ?? ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid emergency contact {$i} email: " . $email;
        }
    }

    return [
        'line' => $lineNumber,
        'errors' => $errors,
        'data' => [
            'team_id' => $teamId,
            'name' => $name,
            'dob' => $dob,
            'photo_url' => $photoUrl,
            'emergency_contacts_json' => emergency_contacts_json($row),
            'parent_emails_json' => json_list($parentEmails),
            'phones_json' => json_list([
                $row['Phone 1'] ?? '',
                $row['Phone 2'] ?? '',
                $row['Phone 3'] ?? '',
            ]),
            'medications_json' => json_list([
                $row['Medication 1'] ?? '',
                $row['Medication 2'] ?? '',
                $row['Medication 3'] ?? '',
            ]),
            'allergies_json' => json_list([
                $row['Allergy 1'] ?? '',
                $row['Allergy 2'] ?? '',
                $row['Allergy 3'] ?? '',
            ]),
            'notes' => $notes,
            'is_active' => $isActive,
            'team_name' => $teamName,
        ],
    ];
}

function parse_uploaded_csv(array $expectedHeaders, PDO $pdo): array
{
    if (empty($_FILES['people_csv']) || !is_array($_FILES['people_csv'])) {
        throw new RuntimeException('Please choose a CSV file to import.');
    }

    $file = $_FILES['people_csv'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Please try again.');
    }

    $tmpName = $file['tmp_name'] ?? '';

    if (!is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded file.');
    }

    $handle = fopen($tmpName, 'rb');

    if (!$handle) {
        throw new RuntimeException('Could not read uploaded file.');
    }

    $headerRow = fgetcsv($handle);

    if (!$headerRow) {
        fclose($handle);
        throw new RuntimeException('CSV file is empty.');
    }

    $normalisedExpected = array_map('normalise_header', $expectedHeaders);
    $normalisedActual = array_map('normalise_header', $headerRow);

    if ($normalisedExpected !== $normalisedActual) {
        fclose($handle);
        throw new RuntimeException('CSV headers do not match the template. Please use the provided template and do not rename columns.');
    }

    $parsedRows = [];
    $lineNumber = 1;

    while (($csvRow = fgetcsv($handle)) !== false) {
        $lineNumber++;

        $isBlank = true;

        foreach ($csvRow as $value) {
            if (trim((string)$value) !== '') {
                $isBlank = false;
                break;
            }
        }

        if ($isBlank) {
            continue;
        }

        $csvRow = array_pad($csvRow, count($expectedHeaders), '');
        $csvRow = array_slice($csvRow, 0, count($expectedHeaders));

        $row = array_combine($expectedHeaders, $csvRow);

        $parsedRows[] = build_person_row($pdo, $row, $lineNumber);
    }

    fclose($handle);

    return $parsedRows;
}

function import_person_row(PDO $pdo, array $data): string
{
    $existingId = find_existing_person_id($pdo, $data['name'], $data['dob']);

    if ($existingId) {
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
            $data['team_id'],
            $data['name'],
            $data['dob'],
            $data['photo_url'],
            $data['emergency_contacts_json'],
            $data['parent_emails_json'],
            $data['phones_json'],
            $data['medications_json'],
            $data['allergies_json'],
            $data['notes'],
            $data['is_active'],
            $existingId,
        ]);

        return 'updated';
    }

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
        $data['team_id'],
        $data['name'],
        $data['dob'],
        $data['photo_url'],
        $data['emergency_contacts_json'],
        $data['parent_emails_json'],
        $data['phones_json'],
        $data['medications_json'],
        $data['allergies_json'],
        $data['notes'],
        $data['is_active'],
    ]);

    return 'created';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'preview';

    try {
        $parsedRows = parse_uploaded_csv($expectedHeaders, $pdo);

        foreach ($parsedRows as $parsedRow) {
            $previewRows[] = $parsedRow;

            if (!empty($parsedRow['errors'])) {
                $rowErrors[] = $parsedRow;
            } else {
                $validRows[] = $parsedRow;
            }
        }

        if ($mode === 'import') {
            if (!empty($rowErrors)) {
                $error = 'Import stopped because there are validation errors.';
            } else {
                $pdo->beginTransaction();

                try {
                    foreach ($validRows as $validRow) {
                        $result = import_person_row($pdo, $validRow['data']);

                        if ($result === 'created') {
                            $imported++;
                        } else {
                            $updated++;
                        }
                    }

                    $pdo->commit();

                    redirect('people.php');
                } catch (Throwable $exception) {
                    $pdo->rollBack();
                    $error = 'Import failed. No rows were imported.';
                }
            }
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
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

    .import-shell {
        max-width: 1180px;
    }

    .import-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .import-panel h2,
    .import-panel h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .import-panel label {
        font-weight: 800;
    }

    .warning-box {
        border-left: 8px solid #ffdd00;
        background: #fff7bf;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .error-box {
        border-left: 8px solid #d4351c;
        background: #fff1f0;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .success-box {
        border-left: 8px solid #00703c;
        background: #e9f8ef;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .preview-table-wrap {
        overflow-x: auto;
    }

    .preview-table {
        width: 100%;
        min-width: 900px;
        border-collapse: collapse;
        background: #ffffff;
    }

    .preview-table th,
    .preview-table td {
        border: 1px solid #d8d8d8;
        padding: 0.55rem;
        vertical-align: top;
    }

    .preview-table th {
        background: #f3f2f1;
        font-weight: 900;
    }

    .row-valid {
        background: #e9f8ef;
    }

    .row-invalid {
        background: #fff1f0;
    }

    .muted {
        color: #505a5f;
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>Import people</h1>
        <p class="lead">
            Upload a CSV file to create or update young people records.
        </p>
    </div>
</section>

<main id="main-content" class="container my-5 import-shell">

    <p>
        <a href="<?= e(url('people.php')) ?>">Back to people</a>
    </p>

    <div class="warning-box">
        <strong>Before importing:</strong>
        Download the template, fill it in, then save/export it as CSV before uploading.
    </div>

    <?php if ($error): ?>
        <div class="error-box">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($imported || $updated): ?>
        <div class="success-box">
            Imported <?= (int)$imported ?> new records and updated <?= (int)$updated ?> existing records.
        </div>
    <?php endif; ?>

    <section class="import-panel">
        <h2>Upload CSV</h2>

        <p>
            <a class="btn btn-outline-primary" href="<?= e(url('assets/templates/people_import_template.xlsx')) ?>">
                Download template
            </a>
        </p>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="mode" value="preview">

            <div class="form-group">
                <label for="people_csv">CSV file</label>
                <input
                    class="form-control"
                    type="file"
                    id="people_csv"
                    name="people_csv"
                    accept=".csv,text/csv"
                    required
                >
            </div>

            <button class="btn btn-primary">
                Preview import
            </button>
        </form>
    </section>

    <?php if (!empty($previewRows)): ?>
        <section class="import-panel">
            <h2>Preview</h2>

            <p>
                <?= count($validRows) ?> valid row<?= count($validRows) === 1 ? '' : 's' ?>.
                <?= count($rowErrors) ?> row<?= count($rowErrors) === 1 ? '' : 's' ?> with errors.
            </p>

            <?php if (!empty($rowErrors)): ?>
                <div class="error-box">
                    Fix the errors below and upload the CSV again.
                </div>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="mode" value="import">

                    <div class="form-group">
                        <label for="people_csv_confirm">Re-select the same CSV file to confirm import</label>
                        <input
                            class="form-control"
                            type="file"
                            id="people_csv_confirm"
                            name="people_csv"
                            accept=".csv,text/csv"
                            required
                        >
                    </div>

                    <button class="btn btn-success">
                        Import valid rows
                    </button>
                </form>
            <?php endif; ?>

            <div class="preview-table-wrap mt-3">
                <table class="preview-table">
                    <thead>
                        <tr>
                            <th>Line</th>
                            <th>Status</th>
                            <th>Name</th>
                            <th>DOB</th>
                            <th>Team</th>
                            <th>Parent emails</th>
                            <th>Allergies</th>
                            <th>Errors</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($previewRows as $row): ?>
                            <?php
                            $data = $row['data'];
                            $hasErrors = !empty($row['errors']);
                            ?>

                            <tr class="<?= $hasErrors ? 'row-invalid' : 'row-valid' ?>">
                                <td><?= (int)$row['line'] ?></td>
                                <td><?= $hasErrors ? 'Error' : 'Valid' ?></td>
                                <td><?= e($data['name']) ?></td>
                                <td><?= e($data['dob'] ?? '') ?></td>
                                <td><?= e($data['team_name']) ?></td>
                                <td><?= e(implode(', ', json_decode($data['parent_emails_json'] ?? '[]', true) ?: [])) ?></td>
                                <td><?= e(implode(', ', json_decode($data['allergies_json'] ?? '[]', true) ?: [])) ?></td>
                                <td>
                                    <?php if ($hasErrors): ?>
                                        <ul class="mb-0">
                                            <?php foreach ($row['errors'] as $rowError): ?>
                                                <li><?= e($rowError) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="muted">None</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

</main>

<?php include __DIR__ . '/footer.php'; ?>