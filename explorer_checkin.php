<?php
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();

$error = '';
$success = '';
$token = trim($_GET['token'] ?? '');

if (empty($_SESSION['explorer_checkin_csrf'])) {
    $_SESSION['explorer_checkin_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['explorer_checkin_csrf'];

function explorer_csrf_valid(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['explorer_checkin_csrf'])
        && hash_equals((string)$_SESSION['explorer_checkin_csrf'], (string)$_POST['csrf_token']);
}

function explorer_contact_phone(): string
{
    if (defined('EXPLORER_EMERGENCY_PHONE')) {
        return (string)EXPLORER_EMERGENCY_PHONE;
    }

    if (defined('CONTACT_PHONE')) {
        return (string)CONTACT_PHONE;
    }

    return 'the emergency phone number provided by the leadership team';
}

function explorer_fetch_team(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM teams
         WHERE explorer_token = ?
         LIMIT 1'
    );

    $stmt->execute([$token]);
    $team = $stmt->fetch();

    return $team ?: null;
}

function explorer_fetch_team_members(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, photo_url
         FROM young_people
         WHERE team_id = ?
           AND is_active = 1
         ORDER BY name ASC'
    );

    $stmt->execute([$teamId]);

    return $stmt->fetchAll();
}

function explorer_media_url(?string $path): string
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

function explorer_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials ?: '?';
}

function explorer_bool_from_post(string $name): int
{
    return ($_POST[$name] ?? '') === 'yes' ? 1 : 0;
}

function explorer_clean_text(?string $value, int $maxLength = 5000): string
{
    $value = trim((string)$value);
    $value = strip_tags($value);

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function explorer_valid_lat_lng($lat, $lng): bool
{
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return false;
    }

    $lat = (float)$lat;
    $lng = (float)$lng;

    return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
}

function explorer_decode_json_list(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function explorer_queue_email(PDO $pdo, string $toEmail, string $subject, string $content, ?int $teamId = null): void
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

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
        $teamId,
    ]);
}

function explorer_fetch_leader_emails(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            'SELECT email
             FROM leaders
             WHERE email IS NOT NULL
               AND email <> ""
               AND (
                    is_active = 1
                    OR is_active IS NULL
               )
             ORDER BY name ASC'
        );
    } catch (Throwable $exception) {
        $stmt = $pdo->query(
            'SELECT email
             FROM leaders
             WHERE email IS NOT NULL
               AND email <> ""
             ORDER BY name ASC'
        );
    }

    $emails = [];

    foreach ($stmt->fetchAll() as $row) {
        $email = trim((string)$row['email']);

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[strtolower($email)] = $email;
        }
    }

    return array_values($emails);
}

function explorer_add_person_log(
    PDO $pdo,
    int $personId,
    string $title,
    string $body,
    string $occurredAt
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO person_logs
                (person_id, leader_id, log_type, title, body, occurred_at)
             VALUES
                (?, NULL, "first_aid", ?, ?, ?)'
        );

        $stmt->execute([
            $personId,
            $title,
            $body,
            $occurredAt,
        ]);
    } catch (Throwable $exception) {
        /**
         * Fallback for installations where the enum does not include first_aid.
         */
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO person_logs
                    (person_id, leader_id, log_type, title, body, occurred_at)
                 VALUES
                    (?, NULL, "general", ?, ?, ?)'
            );

            $stmt->execute([
                $personId,
                $title,
                $body,
                $occurredAt,
            ]);
        } catch (Throwable $ignored) {
            /**
             * Do not block the check-in if person_logs is not available.
             */
        }
    }
}

function explorer_build_member_reports(array $teamMembers): array
{
    $reports = [];

    $issueFlags = $_POST['member_issue'] ?? [];
    $injuryDescriptions = $_POST['injury_description'] ?? [];
    $medicationDetails = $_POST['medication_detail'] ?? [];
    $firstAidGiven = $_POST['first_aid_given'] ?? [];

    foreach ($teamMembers as $member) {
        $personId = (int)$member['id'];

        $hasIssue = isset($issueFlags[$personId]) && $issueFlags[$personId] === 'yes';

        if (!$hasIssue) {
            continue;
        }

        $injury = explorer_clean_text($injuryDescriptions[$personId] ?? '', 3000);
        $medication = explorer_clean_text($medicationDetails[$personId] ?? '', 3000);
        $firstAid = explorer_clean_text($firstAidGiven[$personId] ?? '', 3000);

        if ($injury === '' && $medication === '' && $firstAid === '') {
            continue;
        }

        $reports[] = [
            'person_id' => $personId,
            'name' => $member['name'],
            'injury_description' => $injury,
            'medication_detail' => $medication,
            'first_aid_given' => $firstAid,
        ];
    }

    return $reports;
}

function explorer_log_member_reports(PDO $pdo, array $reports, int $checkinId, string $submittedAt): void
{
    foreach ($reports as $report) {
        $body = 'Submitted by team check-in form.' . "\n\n";
        $body .= 'Explorer check-in ID: ' . $checkinId . "\n\n";

        if (!empty($report['injury_description'])) {
            $body .= "Injury / concern:\n" . $report['injury_description'] . "\n\n";
        }

        if (!empty($report['medication_detail'])) {
            $body .= "Medication details:\n" . $report['medication_detail'] . "\n\n";
        }

        if (!empty($report['first_aid_given'])) {
            $body .= "First aid given:\n" . $report['first_aid_given'] . "\n\n";
        }

        explorer_add_person_log(
            $pdo,
            (int)$report['person_id'],
            'Team check-in first aid / medication report',
            trim($body),
            $submittedAt
        );
    }
}

function explorer_build_leader_email_content(array $team, array $checkin, array $memberReports): string
{
    $reviewUrl = url('add_location.php?checkin_id=' . (int)$checkin['id']);

    $content = '<p>A new team check-in has been submitted and is waiting for leader review.</p>';

    $content .= '<p><strong>Team:</strong> ' . e($team['name']) . '<br>';
    $content .= '<strong>Submitted:</strong> ' . e(format_datetime($checkin['submitted_at'])) . '<br>';
    $content .= '<strong>Location:</strong> ' . e($checkin['location_name'] ?: 'Not named') . '<br>';
    $content .= '<strong>Coordinates:</strong> ' . e($checkin['latitude']) . ', ' . e($checkin['longitude']) . '<br>';
    $content .= '<strong>Staying:</strong> ' . e($checkin['accommodation_type']) . '</p>';

    if (!empty($checkin['accommodation_notes'])) {
        $content .= '<p><strong>Accommodation notes:</strong><br>' . nl2br(e($checkin['accommodation_notes'])) . '</p>';
    }

    if (!empty($checkin['welfare_notes'])) {
        $content .= '<p><strong>General notes:</strong><br>' . nl2br(e($checkin['welfare_notes'])) . '</p>';
    }

    $content .= '<p><strong>Injuries reported:</strong> ' . ((int)$checkin['has_injuries'] === 1 ? 'Yes' : 'No') . '<br>';
    $content .= '<strong>Medication reported:</strong> ' . ((int)$checkin['has_medication'] === 1 ? 'Yes' : 'No') . '</p>';

    if (!empty($memberReports)) {
        $content .= '<p><strong>Participant reports:</strong></p>';
        $content .= '<ul>';

        foreach ($memberReports as $report) {
            $summaryParts = [];

            if (!empty($report['injury_description'])) {
                $summaryParts[] = 'injury/concern added';
            }

            if (!empty($report['medication_detail'])) {
                $summaryParts[] = 'medication details added';
            }

            if (!empty($report['first_aid_given'])) {
                $summaryParts[] = 'first aid details added';
            }

            $content .= '<li><strong>' . e($report['name']) . ':</strong> ' . e(implode(', ', $summaryParts)) . '</li>';
        }

        $content .= '</ul>';
    }

    $content .= '<p><strong>Review this check-in:</strong><br>';
    $content .= '<a href="' . e($reviewUrl) . '">' . e($reviewUrl) . '</a></p>';

    return $content;
}

$team = explorer_fetch_team($pdo, $token);

if (!$team) {
    http_response_code(404);
}

$teamMembers = $team ? explorer_fetch_team_members($pdo, (int)$team['id']) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $team) {
    try {
        if (!explorer_csrf_valid()) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        $lat = $_POST['latitude'] ?? '';
        $lng = $_POST['longitude'] ?? '';

        if (!explorer_valid_lat_lng($lat, $lng)) {
            throw new RuntimeException('Please select and confirm your location on the map.');
        }

        if (($_POST['confirm_location'] ?? '') !== 'yes') {
            throw new RuntimeException('Please confirm that the map location is correct.');
        }

        $locationName = explorer_clean_text($_POST['location_name'] ?? '', 255);
        $accommodationType = explorer_clean_text($_POST['accommodation_type'] ?? '', 100);
        $accommodationNotes = explorer_clean_text($_POST['accommodation_notes'] ?? '', 3000);
        $submittedBy = explorer_clean_text($_POST['submitted_by'] ?? '', 150);
        $welfareNotes = explorer_clean_text($_POST['welfare_notes'] ?? '', 5000);

        if ($accommodationType === '') {
            throw new RuntimeException('Please tell us where you are staying tonight.');
        }

        $hasInjuries = explorer_bool_from_post('has_injuries');
        $hasMedication = explorer_bool_from_post('has_medication');

        $memberReports = [];

        if ($hasInjuries === 1 || $hasMedication === 1) {
            $memberReports = explorer_build_member_reports($teamMembers);

            if (empty($memberReports)) {
                throw new RuntimeException('You selected yes for injuries or medication. Please add details for the relevant team member.');
            }
        }

        $submittedAt = date('Y-m-d H:i:s');

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO explorer_checkins
                (
                    team_id,
                    status,
                    location_name,
                    latitude,
                    longitude,
                    accommodation_type,
                    accommodation_notes,
                    has_injuries,
                    has_medication,
                    welfare_notes,
                    member_reports_json,
                    submitted_by,
                    submitted_at,
                    ip_address,
                    user_agent
                )
             VALUES
                (
                    ?,
                    "pending",
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )'
        );

        $stmt->execute([
            (int)$team['id'],
            $locationName,
            (float)$lat,
            (float)$lng,
            $accommodationType,
            $accommodationNotes,
            $hasInjuries,
            $hasMedication,
            $welfareNotes,
            json_encode($memberReports, JSON_UNESCAPED_UNICODE),
            $submittedBy,
            $submittedAt,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        $checkinId = (int)$pdo->lastInsertId();

        $checkin = [
            'id' => $checkinId,
            'location_name' => $locationName,
            'latitude' => (float)$lat,
            'longitude' => (float)$lng,
            'accommodation_type' => $accommodationType,
            'accommodation_notes' => $accommodationNotes,
            'has_injuries' => $hasInjuries,
            'has_medication' => $hasMedication,
            'welfare_notes' => $welfareNotes,
            'submitted_at' => $submittedAt,
        ];

        if (!empty($memberReports)) {
            explorer_log_member_reports($pdo, $memberReports, $checkinId, $submittedAt);
        }

        $leaderEmails = explorer_fetch_leader_emails($pdo);

        $subject = 'Explorer Belt check-in submitted: ' . $team['name'];
        $content = explorer_build_leader_email_content($team, $checkin, $memberReports);

        foreach ($leaderEmails as $email) {
            explorer_queue_email(
                $pdo,
                $email,
                $subject,
                $content,
                (int)$team['id']
            );
        }

        $pdo->commit();

        $_SESSION['explorer_checkin_success'] = [
            'team_name' => $team['name'],
            'submitted_at' => $submittedAt,
        ];

        redirect('explorer_checkin.php?token=' . urlencode($token) . '&submitted=1');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $exception->getMessage();
    }
}

$submittedSuccess = $_GET['submitted'] === '1' && !empty($_SESSION['explorer_checkin_success']);
$successData = $_SESSION['explorer_checkin_success'] ?? null;

if ($submittedSuccess) {
    unset($_SESSION['explorer_checkin_success']);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e(APP_NAME) ?> - Team check-in</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">

    <style>
        body {
            background: #f3f2f1;
            color: #1d1d1d;
        }

        .checkin-hero {
            background: #7413dc;
            color: #ffffff;
            padding: 1.5rem 0;
            margin-bottom: 1.5rem;
        }

        .checkin-hero h1,
        .checkin-hero p {
            color: #ffffff;
        }

        .checkin-panel {
            border: 2px solid #d8d8d8;
            background: #ffffff;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .checkin-panel h2,
        .checkin-panel h3 {
            font-weight: 900;
            margin-top: 0;
        }

        .checkin-panel label {
            font-weight: 800;
        }

        .map-box {
            height: 420px;
            border: 2px solid #1d1d1d;
            background: #f3f2f1;
            margin-bottom: 0.75rem;
        }

        .warning-box {
            border-left: 8px solid #ffdd00;
            background: #fff7bf;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .danger-box {
            border-left: 8px solid #d4351c;
            background: #fdecea;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .info-box {
            border-left: 8px solid #1d70b8;
            background: #eef7ff;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .success-box {
            border-left: 8px solid #00703c;
            background: #e9f8ef;
            padding: 1.25rem;
            margin-bottom: 1rem;
        }

        .member-report {
            border: 2px solid #d8d8d8;
            background: #f8f8f8;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .member-heading {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .member-photo,
        .member-placeholder {
            width: 52px;
            height: 52px;
            max-width: 52px;
            max-height: 52px;
            border-radius: 50%;
            border: 2px solid #1d1d1d;
            object-fit: cover;
            background: #f3f2f1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
        }

        .member-placeholder {
            background: #7413dc;
            color: #ffffff;
        }

        .member-fields {
            display: none;
            margin-top: 0.75rem;
        }

        .member-report.active .member-fields {
            display: block;
        }

        .muted {
            color: #505a5f;
        }

        .small-map-note {
            color: #505a5f;
            font-size: 0.95rem;
        }
    </style>
</head>

<body>

<header class="checkin-hero">
    <div class="container">
        <h1>Team check-in</h1>

        <?php if ($team): ?>
            <p class="lead mb-0">
                <?= e($team['name']) ?>
            </p>
        <?php else: ?>
            <p class="lead mb-0">
                Explorer Belt Live
            </p>
        <?php endif; ?>
    </div>
</header>

<main class="container mb-5">

    <?php if (!$team): ?>
        <section class="checkin-panel">
            <h2>Link not recognised</h2>
            <p>
                This check-in link is not valid. Please check the link or contact the leadership team.
            </p>
        </section>
    <?php elseif ($submittedSuccess): ?>
        <section class="success-box">
            <h2>Check-in submitted</h2>

            <p>
                Thank you. The check-in for <strong><?= e($successData['team_name'] ?? $team['name']) ?></strong>
                has been submitted to the leadership team.
            </p>

            <p>
                This response may not be reviewed straight away.
                If you need help, have an urgent welfare issue, or need immediate support, contact the leadership team by phone:
                <strong><?= e(explorer_contact_phone()) ?></strong>.
            </p>

            <p class="mb-0">
                Leaders will review the check-in before it is added to the public parent update page.
            </p>
        </section>

        <p>
            <a class="btn btn-primary" href="<?= e(url('explorer_checkin.php?token=' . urlencode($token))) ?>">
                Submit another check-in
            </a>
        </p>
    <?php else: ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <section class="danger-box">
            <strong>Important:</strong>
            This form is not monitored continuously. If you need urgent help, first aid support, or immediate contact with leaders,
            call <strong><?= e(explorer_contact_phone()) ?></strong>.
        </section>

        <form method="post" id="checkinForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" id="latitude" name="latitude">
            <input type="hidden" id="longitude" name="longitude">

            <section class="checkin-panel">
                <h2>1. Confirm your location</h2>

                <p>
                    Use your device location, or tap the map to move the point. Please check it is correct before submitting.
                </p>

                <div class="mb-2">
                    <button type="button" class="btn btn-primary" id="useCurrentLocation">
                        Use my current location
                    </button>
                </div>

                <div id="checkinMap" class="map-box"></div>

                <p class="small-map-note">
                    The map starts in Finland. If location permission is allowed, it should move to where you are.
                </p>

                <div class="form-group">
                    <label for="location_name">Nearest place / location name</label>
                    <input
                        class="form-control"
                        id="location_name"
                        name="location_name"
                        placeholder="Example: Helsinki centre, campsite name, village name"
                    >
                </div>

                <div class="form-check">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="confirm_location"
                        name="confirm_location"
                        value="yes"
                        required
                    >
                    <label class="form-check-label" for="confirm_location">
                        I confirm the map location is correct.
                    </label>
                </div>
            </section>

            <section class="checkin-panel">
                <h2>2. Where are you staying?</h2>

                <div class="form-group">
                    <label for="accommodation_type">Type of stay</label>
                    <select class="form-control" id="accommodation_type" name="accommodation_type" required>
                        <option value="">Choose one</option>
                        <option value="Lean-to">Lean-to</option>
                        <option value="Tent">Tent</option>
                        <option value="With a host">With a host</option>
                        <option value="Scout hut / hall">Scout hut / hall</option>
                        <option value="Campsite">Campsite</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="accommodation_notes">Accommodation notes</label>
                    <textarea
                        class="form-control"
                        id="accommodation_notes"
                        name="accommodation_notes"
                        rows="3"
                        placeholder="Optional. Add any useful detail about where you are staying."
                    ></textarea>
                </div>

                <div class="form-group">
                    <label for="submitted_by">Submitted by</label>
                    <input
                        class="form-control"
                        id="submitted_by"
                        name="submitted_by"
                        placeholder="Name of person completing this form"
                    >
                </div>
            </section>

            <section class="checkin-panel">
                <h2>3. Welfare and first aid</h2>

                <div class="form-group">
                    <label>Has anyone had any injuries, illness, pain, blisters, or other first aid concerns today?</label>

                    <div class="form-check">
                        <input class="form-check-input welfare-toggle" type="radio" name="has_injuries" id="has_injuries_no" value="no" checked>
                        <label class="form-check-label" for="has_injuries_no">No</label>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input welfare-toggle" type="radio" name="has_injuries" id="has_injuries_yes" value="yes">
                        <label class="form-check-label" for="has_injuries_yes">Yes</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Has anyone taken medication today?</label>

                    <div class="form-check">
                        <input class="form-check-input welfare-toggle" type="radio" name="has_medication" id="has_medication_no" value="no" checked>
                        <label class="form-check-label" for="has_medication_no">No</label>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input welfare-toggle" type="radio" name="has_medication" id="has_medication_yes" value="yes">
                        <label class="form-check-label" for="has_medication_yes">Yes</label>
                    </div>
                </div>

                <div id="memberReportsPanel" style="display:none;">
                    <div class="info-box">
                        Select each person who had an injury, illness, first aid issue, or took medication. Add as much detail as possible.
                    </div>

                    <?php foreach ($teamMembers as $member): ?>
                        <?php
                        $memberId = (int)$member['id'];
                        $memberPhoto = explorer_media_url($member['photo_url'] ?? '');
                        ?>
                        <div class="member-report" data-member-report>
                            <div class="member-heading">
                                <?php if ($memberPhoto !== ''): ?>
                                    <img class="member-photo" src="<?= e($memberPhoto) ?>" alt="">
                                <?php else: ?>
                                    <div class="member-placeholder" aria-hidden="true">
                                        <?= e(explorer_initials($member['name'])) ?>
                                    </div>
                                <?php endif; ?>

                                <div>
                                    <strong><?= e($member['name']) ?></strong>

                                    <div class="form-check mt-1">
                                        <input
                                            class="form-check-input member-issue-toggle"
                                            type="checkbox"
                                            id="member_issue_<?= $memberId ?>"
                                            name="member_issue[<?= $memberId ?>]"
                                            value="yes"
                                        >
                                        <label class="form-check-label" for="member_issue_<?= $memberId ?>">
                                            Add details for this person
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="member-fields">
                                <div class="form-group">
                                    <label for="injury_description_<?= $memberId ?>">
                                        Injury / illness / concern
                                    </label>
                                    <textarea
                                        class="form-control"
                                        id="injury_description_<?= $memberId ?>"
                                        name="injury_description[<?= $memberId ?>]"
                                        rows="3"
                                        placeholder="Describe what happened, symptoms, location of injury, severity, and time if known."
                                    ></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="medication_detail_<?= $memberId ?>">
                                        Medication taken
                                    </label>
                                    <textarea
                                        class="form-control"
                                        id="medication_detail_<?= $memberId ?>"
                                        name="medication_detail[<?= $memberId ?>]"
                                        rows="3"
                                        placeholder="Medication name, dose, time taken, and why it was taken."
                                    ></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="first_aid_given_<?= $memberId ?>">
                                        First aid given
                                    </label>
                                    <textarea
                                        class="form-control"
                                        id="first_aid_given_<?= $memberId ?>"
                                        name="first_aid_given[<?= $memberId ?>]"
                                        rows="3"
                                        placeholder="What first aid was given and by whom?"
                                    ></textarea>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-group">
                    <label for="welfare_notes">Any other notes for leaders?</label>
                    <textarea
                        class="form-control"
                        id="welfare_notes"
                        name="welfare_notes"
                        rows="4"
                        placeholder="Optional. Anything else the leadership team should know."
                    ></textarea>
                </div>
            </section>

            <section class="warning-box">
                <strong>Before you submit:</strong>
                This response goes to a holding area for leaders to review. It may not be reviewed straight away.
                If you need help now, call <strong><?= e(explorer_contact_phone()) ?></strong>.
            </section>

            <button class="btn btn-primary btn-lg" type="submit">
                Submit check-in
            </button>
        </form>

    <?php endif; ?>

</main>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    (function () {
        var mapElement = document.getElementById('checkinMap');

        if (mapElement && typeof L !== 'undefined') {
            var latInput = document.getElementById('latitude');
            var lngInput = document.getElementById('longitude');
            var useLocationButton = document.getElementById('useCurrentLocation');

            var defaultLat = 61.9241;
            var defaultLng = 25.7482;

            var map = L.map(mapElement).setView([defaultLat, defaultLng], 6);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            var marker = L.marker([defaultLat, defaultLng], {
                draggable: true
            }).addTo(map);

            function setPosition(lat, lng, zoom) {
                latInput.value = Number(lat).toFixed(7);
                lngInput.value = Number(lng).toFixed(7);

                marker.setLatLng([lat, lng]);
                map.setView([lat, lng], zoom || map.getZoom());
            }

            marker.on('dragend', function () {
                var position = marker.getLatLng();
                setPosition(position.lat, position.lng);
            });

            map.on('click', function (event) {
                setPosition(event.latlng.lat, event.latlng.lng);
            });

            setPosition(defaultLat, defaultLng, 6);

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function (position) {
                    setPosition(position.coords.latitude, position.coords.longitude, 14);
                });
            }

            if (useLocationButton) {
                useLocationButton.addEventListener('click', function () {
                    if (!navigator.geolocation) {
                        alert('Location is not available on this device.');
                        return;
                    }

                    navigator.geolocation.getCurrentPosition(function (position) {
                        setPosition(position.coords.latitude, position.coords.longitude, 14);
                    }, function () {
                        alert('Could not get your current location. You can still tap the map to set it manually.');
                    }, {
                        enableHighAccuracy: true,
                        timeout: 12000,
                        maximumAge: 0
                    });
                });
            }

            setTimeout(function () {
                map.invalidateSize();
            }, 250);
        }

        var welfareToggles = document.querySelectorAll('.welfare-toggle');
        var memberReportsPanel = document.getElementById('memberReportsPanel');

        function updateWelfarePanel() {
            if (!memberReportsPanel) {
                return;
            }

            var hasInjuries = document.querySelector('input[name="has_injuries"]:checked');
            var hasMedication = document.querySelector('input[name="has_medication"]:checked');

            var show = (hasInjuries && hasInjuries.value === 'yes')
                || (hasMedication && hasMedication.value === 'yes');

            memberReportsPanel.style.display = show ? 'block' : 'none';
        }

        welfareToggles.forEach(function (input) {
            input.addEventListener('change', updateWelfarePanel);
        });

        updateWelfarePanel();

        document.querySelectorAll('.member-issue-toggle').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                var report = checkbox.closest('[data-member-report]');

                if (!report) {
                    return;
                }

                if (checkbox.checked) {
                    report.classList.add('active');
                } else {
                    report.classList.remove('active');
                }
            });
        });

        var form = document.getElementById('checkinForm');

        if (form) {
            form.addEventListener('submit', function (event) {
                var lat = document.getElementById('latitude');
                var lng = document.getElementById('longitude');
                var confirmLocation = document.getElementById('confirm_location');

                if (!lat || !lng || lat.value === '' || lng.value === '') {
                    event.preventDefault();
                    alert('Please select your location on the map.');
                    return;
                }

                if (!confirmLocation || !confirmLocation.checked) {
                    event.preventDefault();
                    alert('Please confirm the map location is correct.');
                    return;
                }

                var hasInjuries = document.querySelector('input[name="has_injuries"]:checked');
                var hasMedication = document.querySelector('input[name="has_medication"]:checked');

                var needsMemberDetails = (hasInjuries && hasInjuries.value === 'yes')
                    || (hasMedication && hasMedication.value === 'yes');

                if (needsMemberDetails) {
                    var checkedMembers = document.querySelectorAll('.member-issue-toggle:checked');

                    if (checkedMembers.length === 0) {
                        event.preventDefault();
                        alert('Please select the team member or members who need first aid, welfare or medication notes.');
                    }
                }
            });
        }
    })();
</script>

</body>
</html>