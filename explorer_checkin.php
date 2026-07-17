<?php
$loadLeaflet = true;
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();

$error = '';
$success = '';
$token = trim($_GET['token'] ?? $_SESSION['explorer_portal_token'] ?? '');

if (empty($_SESSION['explorer_checkin_csrf'])) {
    $_SESSION['explorer_checkin_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['explorer_checkin_csrf'];

function explorer_csrf_valid(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['explorer_checkin_csrf'])
        && hash_equals((string)$_SESSION['explorer_checkin_csrf'], (string)$_POST['csrf_token']);
}

// explorer_contact_phone() is now defined in config.php for shared use across all explorer pages.
// explorer_fetch_team() is now defined in config.php for shared use across all explorer pages.

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

// explorer_queue_email() is now defined in config.php for shared use across all explorer pages.

/**
 * Fetch emails of leaders who are currently on-duty AND in-country.
 * Uses leader_duty_roster for on-duty check and leader_schedules for in-country check.
 * Falls back gracefully if tables don't exist.
 */
function explorer_fetch_on_duty_in_country_leader_emails(PDO $pdo): array
{
    $emails = [];

    try {
        // Get the current duty date (before 9am = yesterday's duty)
        $tz = new DateTimeZone(defined('DEFAULT_TIMEZONE') ? DEFAULT_TIMEZONE : 'Europe/London');
        $now = new DateTime('now', $tz);
        $currentHour = (int)$now->format('G');
        $dutyDate = ($currentHour < 9)
            ? (clone $now)->modify('-1 day')->format('Y-m-d')
            : $now->format('Y-m-d');

        // Leaders who are on-duty today AND have an active in_country schedule
        $stmt = $pdo->prepare(
            'SELECT DISTINCT l.email
             FROM leader_duty_roster r
             JOIN leaders l ON l.id = r.leader_id
             JOIN leader_schedules ls ON ls.leader_id = l.id
             WHERE r.duty_date = ?
               AND r.status = "on_duty"
               AND ls.status = "in_country"
               AND NOW() BETWEEN ls.schedule_start AND ls.schedule_end
               AND l.email IS NOT NULL
               AND l.email != ""'
        );
        $stmt->execute([$dutyDate]);

        foreach ($stmt->fetchAll() as $row) {
            $email = trim((string)$row['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[strtolower($email)] = $email;
            }
        }

        // If no one matched (maybe no schedule entries), fall back to on-duty leaders with is_in_country flag
        if (empty($emails)) {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT l.email
                 FROM leader_duty_roster r
                 JOIN leaders l ON l.id = r.leader_id
                 WHERE r.duty_date = ?
                   AND r.status = "on_duty"
                   AND (l.is_in_country = 1)
                   AND l.email IS NOT NULL
                   AND l.email != ""'
            );
            $stmt->execute([$dutyDate]);

            foreach ($stmt->fetchAll() as $row) {
                $email = trim((string)$row['email']);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[strtolower($email)] = $email;
                }
            }
        }
    } catch (Throwable $e) {
        // Tables may not exist — fall back to all leader emails
        return explorer_fetch_leader_emails($pdo);
    }

    // If still empty (no one on duty), fall back to all leaders as safety net
    if (empty($emails)) {
        return explorer_fetch_leader_emails($pdo);
    }

    return array_values($emails);
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
    include __DIR__ . '/explorer_error.php';
}

// Store token in session for cross-page navigation
$_SESSION['explorer_portal_token'] = $token;

$teamMembers = explorer_fetch_team_members($pdo, (int)$team['id']);

// Fetch outstanding (unacknowledged) announcements for this team
$outstandingAnnouncements = [];
try {
    ensure_announcements_tables($pdo);
    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.content, a.created_at, l.name AS sender_name
         FROM announcements a
         LEFT JOIN leaders l ON l.id = a.sender_leader_id
         LEFT JOIN announcement_acknowledgements ack ON ack.announcement_id = a.id AND ack.team_id = ?
         WHERE (a.team_id IS NULL OR a.team_id = ?)
           AND ack.id IS NULL
         ORDER BY a.created_at DESC'
    );
    $stmt->execute([(int)$team['id'], (int)$team['id']]);
    $outstandingAnnouncements = $stmt->fetchAll();
} catch (Throwable $e) {
    $outstandingAnnouncements = [];
}

// Handle announcement acknowledgement POST (separate from check-in submit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acknowledge_announcement' && $team) {
    try {
        if (!explorer_csrf_valid()) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        $announcementId = (int)($_POST['announcement_id'] ?? 0);
        $acknowledgedByName = explorer_clean_text($_POST['ack_acknowledged_by'] ?? '', 150);

        if ($announcementId <= 0) {
            throw new RuntimeException('Invalid announcement.');
        }
        if ($acknowledgedByName === '') {
            throw new RuntimeException('Please select who is acknowledging this announcement.');
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO announcement_acknowledgements (announcement_id, team_id, acknowledged_by_name)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$announcementId, (int)$team['id'], $acknowledgedByName]);

        // Send notification to on-duty in-country leaders
        if ($stmt->rowCount() > 0) {
            $annStmt = $pdo->prepare('SELECT title FROM announcements WHERE id = ?');
            $annStmt->execute([$announcementId]);
            $annRow = $annStmt->fetch();

            if ($annRow) {
                $emailSubject = e($team['name']) . ' acknowledged: ' . $annRow['title'];
                $emailContent = '<p><strong>' . e($team['name']) . '</strong> has acknowledged the announcement: <strong>' . e($annRow['title']) . '</strong></p>';
                $emailContent .= '<p>Acknowledged by: ' . e($acknowledgedByName) . '</p>';
                $emailContent .= '<p>Time: ' . e(date('d M Y, H:i')) . '</p>';

                $dutyEmails = explorer_fetch_on_duty_in_country_leader_emails($pdo);
                foreach ($dutyEmails as $email) {
                    explorer_queue_email($pdo, $email, $emailSubject, $emailContent, (int)$team['id']);
                }
            }
        }

        redirect('explorer_checkin.php?token=' . urlencode($token));
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'acknowledge_announcement' && $team) {
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

if ($locationName === '') {
    throw new RuntimeException('Please enter your nearest place or location name.');
}

if ($accommodationType === '') {
    throw new RuntimeException('Please tell us where you are staying tonight.');
}

if ($submittedBy === '') {
    throw new RuntimeException('Please enter the name of the person completing this form.');
}

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

        // Also acknowledge any announcements submitted with the check-in
        $ackIds = $_POST['checkin_ack_announcement'] ?? [];
        $ackNames = $_POST['checkin_ack_name'] ?? [];
        if (is_array($ackIds)) {
            foreach ($ackIds as $ackAnnId) {
                $ackAnnId = (int)$ackAnnId;
                $ackName = explorer_clean_text($ackNames[$ackAnnId] ?? '', 150);
                if ($ackAnnId > 0 && $ackName !== '') {
                    try {
                        $ackStmt = $pdo->prepare(
                            'INSERT IGNORE INTO announcement_acknowledgements (announcement_id, team_id, acknowledged_by_name)
                             VALUES (?, ?, ?)'
                        );
                        $ackStmt->execute([$ackAnnId, (int)$team['id'], $ackName]);
                    } catch (Throwable $e) {
                        // Don't block check-in if ack fails
                    }
                }
            }
        }

        $leaderEmails = explorer_fetch_on_duty_in_country_leader_emails($pdo);

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

$submittedSuccess = ($_GET['submitted'] ?? '') === '1' && !empty($_SESSION['explorer_checkin_success']);
$successData = $_SESSION['explorer_checkin_success'] ?? null;

if ($submittedSuccess) {
    unset($_SESSION['explorer_checkin_success']);
}

// --- Check if team has already submitted a check-in today ---
$alreadyCheckedInToday = false;
$todayCheckinSubmittedAt = null;
try {
    $tz = new DateTimeZone(defined('DEFAULT_TIMEZONE') ? DEFAULT_TIMEZONE : 'Europe/London');
    $todayDate = (new DateTime('now', $tz))->format('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT submitted_at FROM explorer_checkins
         WHERE team_id = ? AND DATE(submitted_at) = ?
         ORDER BY submitted_at DESC LIMIT 1'
    );
    $stmt->execute([(int)$team['id'], $todayDate]);
    $todayRow = $stmt->fetch();
    if ($todayRow) {
        $alreadyCheckedInToday = true;
        $todayCheckinSubmittedAt = $todayRow['submitted_at'];
    }
} catch (Throwable $e) {
    // Graceful fallback
}

include __DIR__ . '/explorer_header.php';
?>

<style>
    body {
        background: #f3f2f1;
        color: #1d1d1d;
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

    .member-selector {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 0.75rem;
    }

    .member-select-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.4rem;
        padding: 0.75rem;
        border: 3px solid #d8d8d8;
        border-radius: 12px;
        background: #ffffff;
        cursor: pointer;
        transition: border-color 0.15s, background 0.15s, transform 0.1s;
        width: 100px;
        text-align: center;
    }

    .member-select-btn:hover {
        border-color: #1d70b8;
        background: #f0f7ff;
    }

    .member-select-btn:active {
        transform: scale(0.95);
    }

    .member-select-btn.selected {
        border-color: #00703c;
        background: #e9f8ef;
        box-shadow: 0 0 0 2px #00703c;
    }

    .member-select-photo {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        border: 2px solid #1d1d1d;
        object-fit: cover;
    }

    .member-select-placeholder {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        border: 2px solid #1d1d1d;
        background: #7413dc;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        font-size: 1.1rem;
    }

    .member-select-btn.selected .member-select-photo,
    .member-select-btn.selected .member-select-placeholder {
        border-color: #00703c;
    }

    .member-select-name {
        font-size: 0.85rem;
        font-weight: 700;
        color: #1d1d1d;
        line-height: 1.2;
        word-break: break-word;
    }
</style>

<div class="container mb-5">

    <?php if ($submittedSuccess): ?>
        <section class="success-box">
            <h2>Check-in submitted</h2>

            <p>
                Thank you. The check-in for <strong><?= e($successData['team_name'] ?? $team['name']) ?></strong>
                has been submitted to the leadership team. - Please only submit one check-in per day.
            </p>

            <p>
                This response may not be reviewed straight away.
                If you need help, have an urgent welfare issue, or need immediate support, contact the leadership team by phone:
                <strong><?= e(explorer_contact_phone()) ?></strong>.
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

        <?php if ($alreadyCheckedInToday): ?>
        <section class="warning-box" id="duplicateCheckinWarning">
            <strong>⚠️ You have already checked in today</strong>
            <p style="margin: 0.5rem 0;">
                Your team submitted a check-in at <strong><?= e(format_datetime($todayCheckinSubmittedAt)) ?></strong>.
                You normally only need to check in once per day. Are you sure you want to submit another one?
            </p>
            <button type="button" class="btn btn-primary btn-sm" id="confirmDuplicateCheckin" style="margin-top: 0.5rem;">
                Yes, I want to submit another check-in
            </button>
        </section>
        <?php endif; ?>

        <form method="post" id="checkinForm" <?php if ($alreadyCheckedInToday): ?>style="display:none;"<?php endif; ?>>
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

                <div id="checkinMapWrapper">
                    <div id="checkinMapPlaceholder" class="map-box" style="display:flex;align-items:center;justify-content:center;flex-direction:column;cursor:pointer;">
                        <p style="font-weight:800;font-size:1.1rem;margin:0 0 0.5rem;">Loading map...</p>
                        <p style="margin:0;color:#505a5f;font-size:0.9rem;">Tap here if the map doesn't load</p>
                    </div>
                    <div id="checkinMap" class="map-box" style="display:none;"></div>
                </div>

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
    required
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
                    <label>Who is completing this form?</label>
                    <p class="muted" style="margin-bottom:0.75rem;">Tap your name to select.</p>

                    <div class="member-selector" id="submittedBySelector">
                        <?php foreach ($teamMembers as $member): ?>
                            <?php $memberPhoto = explorer_media_url($member['photo_url'] ?? ''); ?>
                            <button
                                type="button"
                                class="member-select-btn"
                                data-name="<?= e($member['name']) ?>"
                            >
                                <?php if ($memberPhoto !== ''): ?>
                                    <img class="member-select-photo" src="<?= e($memberPhoto) ?>" alt="">
                                <?php else: ?>
                                    <span class="member-select-placeholder" aria-hidden="true">
                                        <?= e(explorer_initials($member['name'])) ?>
                                    </span>
                                <?php endif; ?>
                                <span class="member-select-name"><?= e($member['name']) ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <input
                        type="hidden"
                        id="submitted_by"
                        name="submitted_by"
                        required
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

            <?php if (!empty($outstandingAnnouncements)): ?>
            <section class="checkin-panel">
                <h2>4. Outstanding announcements</h2>

                <div class="info-box">
                    The following announcements have not been acknowledged by your team yet.
                    Please read each one and select who is acknowledging it.
                </div>

                <?php foreach ($outstandingAnnouncements as $ann): ?>
                    <div class="announcement-inline" style="border: 2px solid #7413dc; padding: 1rem; margin-bottom: 1rem; background: #faf5ff;">
                        <h3 style="margin-top: 0; font-size: 1.1rem;"><?= e($ann['title']) ?></h3>
                        <?php if ($ann['sender_name']): ?>
                            <p class="muted" style="margin-bottom: 0.5rem; font-size: 0.9rem;">
                                From <?= e($ann['sender_name']) ?> &middot; <?= e(format_datetime($ann['created_at'])) ?>
                            </p>
                        <?php endif; ?>
                        <div style="margin-bottom: 0.75rem; line-height: 1.6;">
                            <?= nl2br(e($ann['content'])) ?>
                        </div>

                        <label style="font-weight: 800; display: block; margin-bottom: 0.5rem;">Who is acknowledging this?</label>
                        <p class="muted" style="margin-bottom: 0.5rem;">Tap to select.</p>

                        <div class="member-selector ack-member-selector" data-ack-id="<?= (int)$ann['id'] ?>">
                            <?php foreach ($teamMembers as $member): ?>
                                <?php $memberPhoto = explorer_media_url($member['photo_url'] ?? ''); ?>
                                <button
                                    type="button"
                                    class="member-select-btn ack-select-btn"
                                    data-name="<?= e($member['name']) ?>"
                                    data-ack-id="<?= (int)$ann['id'] ?>"
                                >
                                    <?php if ($memberPhoto !== ''): ?>
                                        <img class="member-select-photo" src="<?= e($memberPhoto) ?>" alt="">
                                    <?php else: ?>
                                        <span class="member-select-placeholder" aria-hidden="true">
                                            <?= e(explorer_initials($member['name'])) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="member-select-name"><?= e($member['name']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <input type="hidden" name="checkin_ack_name[<?= (int)$ann['id'] ?>]" class="ack-name-input" data-ack-id="<?= (int)$ann['id'] ?>" value="">
                        <input type="hidden" name="checkin_ack_announcement[]" class="ack-checkbox-input" data-ack-id="<?= (int)$ann['id'] ?>" value="" disabled>
                    </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

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

<script>
    window.addEventListener('load', function () {
        // Duplicate check-in confirmation
        var duplicateWarning = document.getElementById('duplicateCheckinWarning');
        var confirmBtn = document.getElementById('confirmDuplicateCheckin');
        var checkinForm = document.getElementById('checkinForm');

        if (confirmBtn && duplicateWarning && checkinForm) {
            confirmBtn.addEventListener('click', function () {
                duplicateWarning.style.display = 'none';
                checkinForm.style.display = 'block';
            });
        }

        var mapElement = document.getElementById('checkinMap');
        var mapPlaceholder = document.getElementById('checkinMapPlaceholder');
        var mapLoaded = false;

        function initCheckinMap() {
            if (mapLoaded) return;
            if (!mapElement || typeof L === 'undefined') return;

            mapLoaded = true;

            // Show the real map, hide placeholder
            mapElement.style.display = 'block';
            if (mapPlaceholder) mapPlaceholder.style.display = 'none';

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

        // Lazy-load: try to init map immediately if Leaflet is ready
        if (typeof L !== 'undefined') {
            initCheckinMap();
        }

        // Fallback: if Leaflet loads late (deferred), poll briefly
        if (!mapLoaded) {
            var leafletCheckInterval = setInterval(function () {
                if (typeof L !== 'undefined') {
                    clearInterval(leafletCheckInterval);
                    initCheckinMap();
                }
            }, 200);

            // Stop polling after 15 seconds
            setTimeout(function () {
                clearInterval(leafletCheckInterval);
                if (!mapLoaded && mapPlaceholder) {
                    mapPlaceholder.querySelector('p').textContent = 'Map could not load. Tap to retry.';
                }
            }, 15000);
        }

        // Allow tap on placeholder to trigger load
        if (mapPlaceholder) {
            mapPlaceholder.addEventListener('click', function () {
                if (typeof L !== 'undefined') {
                    initCheckinMap();
                } else {
                    mapPlaceholder.querySelector('p').textContent = 'Map library not available. Check your connection.';
                }
            });
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

                var submittedBy = document.getElementById('submitted_by');
                if (!submittedBy || submittedBy.value === '') {
                    event.preventDefault();
                    alert('Please select who is completing this form.');
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

        // Submitted-by member selector
        var submittedBySelector = document.getElementById('submittedBySelector');
        var submittedByInput = document.getElementById('submitted_by');

        if (submittedBySelector) {
            var selectorButtons = submittedBySelector.querySelectorAll('.member-select-btn');

            selectorButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    selectorButtons.forEach(function (b) {
                        b.classList.remove('selected');
                    });

                    btn.classList.add('selected');
                    submittedByInput.value = btn.getAttribute('data-name');
                });
            });
        }

        // Acknowledgement face selectors
        document.querySelectorAll('.ack-select-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var ackId = btn.getAttribute('data-ack-id');

                // Deselect siblings in same group
                var container = btn.closest('.ack-member-selector');
                if (container) {
                    container.querySelectorAll('.ack-select-btn').forEach(function (b) {
                        b.classList.remove('selected');
                    });
                }

                btn.classList.add('selected');

                // Set the hidden name input
                var nameInput = document.querySelector('.ack-name-input[data-ack-id="' + ackId + '"]');
                if (nameInput) {
                    nameInput.value = btn.getAttribute('data-name');
                }

                // Enable the hidden checkbox input so it submits
                var checkboxInput = document.querySelector('.ack-checkbox-input[data-ack-id="' + ackId + '"]');
                if (checkboxInput) {
                    checkboxInput.value = ackId;
                    checkboxInput.disabled = false;
                }
            });
        });
    });
</script>

<?php include __DIR__ . '/explorer_footer.php'; ?>