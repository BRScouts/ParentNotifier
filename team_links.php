<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

const TEAM_CHECKIN_START_DATE = '2026-07-29';
const TEAM_CHECKIN_DAYS = 10;
const FINLAND_TIMEZONE = 'Europe/Helsinki';
const CHECKIN_OVERDUE_HOUR_FINLAND = 19;

$error = '';

/**
 * Helpers
 */

function generate_parent_token(): string
{
    return bin2hex(random_bytes(32));
}

function generate_explorer_token(): string
{
    return bin2hex(random_bytes(32));
}

function slugify_team_name(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string)$slug, '-');

    return $slug !== '' ? $slug : 'team-' . time();
}

function ensure_unique_team_slug(PDO $pdo, string $baseSlug, ?int $ignoreTeamId = null): string
{
    $slug = $baseSlug;
    $counter = 2;

    while (true) {
        if ($ignoreTeamId) {
            $stmt = $pdo->prepare('SELECT id FROM teams WHERE slug = ? AND id != ? LIMIT 1');
            $stmt->execute([$slug, $ignoreTeamId]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM teams WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        }

        if (!$stmt->fetch()) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

function safe_float($value): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }

    return (float)$value;
}

function distance_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusKm = 6371.0;

    $lat1Rad = deg2rad($lat1);
    $lon1Rad = deg2rad($lon1);
    $lat2Rad = deg2rad($lat2);
    $lon2Rad = deg2rad($lon2);

    $deltaLat = $lat2Rad - $lat1Rad;
    $deltaLon = $lon2Rad - $lon1Rad;

    $a = sin($deltaLat / 2) ** 2
        + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadiusKm * $c;
}

function miles_from_km(float $km): float
{
    return $km * 0.621371;
}

function person_display_name(array $person): string
{
    if (!empty($person['name'])) {
        return $person['name'];
    }

    $firstName = $person['first_name'] ?? '';
    $lastName = $person['last_name'] ?? '';

    $name = trim($firstName . ' ' . $lastName);

    return $name !== '' ? $name : 'Young person';
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

function json_items(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, static function ($item) {
        if (is_string($item)) {
            return trim($item) !== '';
        }

        if (is_array($item)) {
            return !empty(array_filter($item));
        }

        return false;
    }));
}

function person_has_allergies(array $person): bool
{
    return count(json_items($person['allergies_json'] ?? null)) > 0;
}

function checkin_dates(): array
{
    $dates = [];
    $start = new DateTime(TEAM_CHECKIN_START_DATE);

    for ($i = 0; $i < TEAM_CHECKIN_DAYS; $i++) {
        $date = clone $start;
        $date->modify('+' . $i . ' days');

        $dates[] = [
            'date' => $date->format('Y-m-d'),
            'label' => $date->format('j M'),
            'short' => $date->format('j'),
        ];
    }

    return $dates;
}

function fetch_team(PDO $pdo, int $teamId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM teams WHERE id = ? LIMIT 1');
    $stmt->execute([$teamId]);
    $team = $stmt->fetch();

    return $team ?: null;
}

function finland_today(): string
{
    return (new DateTime('now', new DateTimeZone(FINLAND_TIMEZONE)))->format('Y-m-d');
}

function finland_hour(): int
{
    return (int)(new DateTime('now', new DateTimeZone(FINLAND_TIMEZONE)))->format('G');
}

function date_in_finland(?string $datetime): ?string
{
    if (!$datetime) {
        return null;
    }

    try {
        $dt = new DateTime($datetime);
        $dt->setTimezone(new DateTimeZone(FINLAND_TIMEZONE));
        return $dt->format('Y-m-d');
    } catch (Throwable $exception) {
        return date('Y-m-d', strtotime($datetime));
    }
}

function checked_in_today_finland(?string $datetime): bool
{
    return date_in_finland($datetime) === finland_today();
}

function member_reports_summary(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, static function ($report) {
        return is_array($report) && !empty($report);
    }));
}

function checkin_status_class(string $status): string
{
    if ($status === 'reviewed') {
        return 'status-good';
    }

    if ($status === 'rejected') {
        return 'status-danger';
    }

    return 'status-warning';
}

function checkin_status_label(string $status): string
{
    if ($status === 'reviewed') {
        return 'Reviewed / posted';
    }

    if ($status === 'rejected') {
        return 'Rejected';
    }

    return 'Pending review';
}

function team_parent_link(array $team): string
{
    return url('team.php?token=' . $team['parent_token']);
}

function team_explorer_link(array $team): string
{
    return url('explorer_checkin.php?token=' . $team['explorer_token']);
}

function build_parent_checkin_body(string $teamName, string $locationName, string $publicNote, string $accommodationType): string
{
    if ($publicNote !== '') {
        return $publicNote;
    }

    $body = $teamName . ' has checked in for the evening.';

    if ($locationName !== '') {
        $body .= "\n\nApproximate location: " . $locationName . '.';
    }

    if ($accommodationType !== '') {
        $body .= "\n\nThey are staying: " . $accommodationType . '.';
    }

    $body .= "\n\nLocations are manually reviewed by leaders before being shared. No news is not bad news.";

    return $body;
}

function queue_parent_checkin_emails(PDO $pdo, int $teamId, string $teamName, string $subject, string $body, string $parentLink): int
{
    $stmt = $pdo->prepare(
        'SELECT parent_emails_json
         FROM young_people
         WHERE team_id = ?
           AND is_active = 1'
    );

    $stmt->execute([$teamId]);

    $emails = [];

    foreach ($stmt->fetchAll() as $row) {
        foreach (json_items($row['parent_emails_json'] ?? null) as $email) {
            $email = trim((string)$email);

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[strtolower($email)] = $email;
            }
        }
    }

    $content = nl2br(e($body));
    $content .= '<hr>';
    $content .= '<p><strong>View the team portal:</strong><br>';
    $content .= '<a href="' . e($parentLink) . '">' . e($parentLink) . '</a></p>';

    $insert = $pdo->prepare(
        'INSERT INTO email_queue
            (to_email, subject, content, related_team_id)
         VALUES
            (?, ?, ?, ?)'
    );

    $count = 0;

    foreach ($emails as $email) {
        $insert->execute([
            $email,
            $subject,
            $content,
            $teamId,
        ]);

        $count++;
    }

    return $count;
}

function create_reviewed_location_and_post(
    PDO $pdo,
    array $user,
    int $teamId,
    ?int $checkinId,
    string $locationName,
    string $latitude,
    string $longitude,
    string $publicNote,
    string $internalNote,
    string $status,
    string $accommodationType,
    string $reviewNotes
): void {
    $team = fetch_team($pdo, $teamId);

    if (!$team) {
        throw new RuntimeException('Team not found.');
    }

    $teamName = $team['name'] ?? 'Team';

    $stmt = $pdo->prepare(
        'INSERT INTO team_locations
            (team_id, leader_id, location_name, latitude, longitude, public_note, internal_note)
         VALUES
            (?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $teamId,
        $user['id'],
        $locationName,
        $latitude,
        $longitude,
        $publicNote,
        $internalNote,
    ]);

    $stmt = $pdo->prepare(
        'UPDATE teams
         SET status = ?,
             current_location_name = ?,
             current_latitude = ?,
             current_longitude = ?,
             last_check_in_at = NOW()
         WHERE id = ?'
    );

    $stmt->execute([
        $status,
        $locationName,
        $latitude,
        $longitude,
        $teamId,
    ]);

    $feedBody = build_parent_checkin_body($teamName, $locationName, $publicNote, $accommodationType);

    $stmt = $pdo->prepare(
        'INSERT INTO posts
            (team_id, leader_id, title, body, post_type, visibility, is_pinned, is_published, published_at)
         VALUES
            (?, ?, ?, ?, "check_in", "team", 0, 1, NOW())'
    );

    $stmt->execute([
        $teamId,
        $user['id'],
        $teamName . ' checked in',
        $feedBody,
    ]);

    $parentLink = team_parent_link($team);

    queue_parent_checkin_emails(
        $pdo,
        $teamId,
        $teamName,
        $teamName . ' check-in update',
        $feedBody,
        $parentLink
    );

    if ($checkinId !== null) {
        $stmt = $pdo->prepare(
            'UPDATE explorer_checkins
             SET status = "reviewed",
                 reviewed_by = ?,
                 reviewed_at = NOW(),
                 review_notes = ?
             WHERE id = ?'
        );

        $stmt->execute([
            $user['id'],
            $reviewNotes,
            $checkinId,
        ]);
    }
}

/**
 * POST actions
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $allowedStatuses = [
        'not_started',
        'on_route',
        'checked_in',
        'resting',
        'delayed',
        'needs_follow_up',
        'completed',
    ];

    if ($action === 'add_team') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'not_started';
        $isPublic = isset($_POST['is_public']) ? 1 : 0;

        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'not_started';
        }

        if ($name === '') {
            $error = 'Team name is required.';
        } else {
            $slug = ensure_unique_team_slug($pdo, slugify_team_name($name));

            $stmt = $pdo->prepare(
                'INSERT INTO teams
                    (name, slug, parent_token, explorer_token, description, status, is_public)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $name,
                $slug,
                generate_parent_token(),
                generate_explorer_token(),
                $description,
                $status,
                $isPublic,
            ]);

            $newTeamId = (int)$pdo->lastInsertId();

            redirect('team_links.php?view=team&team_id=' . $newTeamId);
        }
    }

    if ($action === 'update_team') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'not_started';
        $isPublic = isset($_POST['is_public']) ? 1 : 0;

        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'not_started';
        }

        if ($teamId <= 0 || $name === '') {
            $error = 'Team name is required.';
        } else {
            $slug = ensure_unique_team_slug($pdo, slugify_team_name($name), $teamId);

            $stmt = $pdo->prepare(
                'UPDATE teams
                 SET name = ?,
                     slug = ?,
                     description = ?,
                     status = ?,
                     is_public = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $name,
                $slug,
                $description,
                $status,
                $isPublic,
                $teamId,
            ]);

            redirect('team_links.php?view=team&team_id=' . $teamId . '&tab=edit');
        }
    }

    if ($action === 'regenerate_team_token') {
        $teamId = (int)($_POST['team_id'] ?? 0);

        if ($teamId > 0) {
            $stmt = $pdo->prepare('UPDATE teams SET parent_token = ? WHERE id = ?');
            $stmt->execute([
                generate_parent_token(),
                $teamId,
            ]);
        }

        redirect('team_links.php?view=team&team_id=' . $teamId);
    }

    if ($action === 'regenerate_explorer_token') {
        $teamId = (int)($_POST['team_id'] ?? 0);

        if ($teamId > 0) {
            $stmt = $pdo->prepare('UPDATE teams SET explorer_token = ? WHERE id = ?');
            $stmt->execute([
                generate_explorer_token(),
                $teamId,
            ]);
        }

        redirect('team_links.php?view=team&team_id=' . $teamId);
    }

    if ($action === 'add_post') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $postType = $_POST['post_type'] ?? 'team_update';
        $photoUrl = trim($_POST['photo_url'] ?? '');
        $isPinned = isset($_POST['is_pinned']) ? 1 : 0;

        if (!in_array($postType, ['general', 'team_update', 'check_in', 'photo', 'important'], true)) {
            $postType = 'team_update';
        }

        if ($teamId <= 0) {
            $error = 'Team is required.';
        } elseif ($title === '' || $body === '') {
            $error = 'Post title and update are required.';
        } else {
            if ($isPinned === 1) {
                $pdo->exec('UPDATE posts SET is_pinned = 0');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO posts
                    (team_id, leader_id, title, body, post_type, visibility, photo_url, is_pinned, is_published, published_at)
                 VALUES
                    (?, ?, ?, ?, ?, "team", ?, ?, 1, NOW())'
            );

            $stmt->execute([
                $teamId,
                $user['id'],
                $title,
                $body,
                $postType,
                $photoUrl,
                $isPinned,
            ]);

            redirect('team_links.php?view=team&team_id=' . $teamId . '&tab=posts');
        }
    }

    if ($action === 'approve_explorer_checkin' || $action === 'manual_checkin') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $checkinId = $action === 'approve_explorer_checkin' ? (int)($_POST['checkin_id'] ?? 0) : null;

        $locationName = trim($_POST['location_name'] ?? '');
        $latitude = trim($_POST['latitude'] ?? '');
        $longitude = trim($_POST['longitude'] ?? '');
        $publicNote = trim($_POST['public_note'] ?? '');
        $internalNote = trim($_POST['internal_note'] ?? '');
        $reviewNotes = trim($_POST['review_notes'] ?? '');
        $status = $_POST['status'] ?? 'checked_in';
        $accommodationType = trim($_POST['accommodation_type'] ?? '');

        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'checked_in';
        }

        if ($teamId <= 0 || $locationName === '' || $latitude === '' || $longitude === '') {
            $error = 'Team, location name, latitude and longitude are required.';
        } else {
            try {
                $pdo->beginTransaction();

                create_reviewed_location_and_post(
                    $pdo,
                    $user,
                    $teamId,
                    $checkinId,
                    $locationName,
                    $latitude,
                    $longitude,
                    $publicNote,
                    $internalNote,
                    $status,
                    $accommodationType,
                    $reviewNotes
                );

                $pdo->commit();

                redirect('team_links.php?view=team&team_id=' . $teamId . '&tab=check-ins');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $exception->getMessage();
            }
        }
    }

    if ($action === 'reject_explorer_checkin') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $checkinId = (int)($_POST['checkin_id'] ?? 0);
        $reviewNotes = trim($_POST['review_notes'] ?? '');

        if ($teamId > 0 && $checkinId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE explorer_checkins
                 SET status = "rejected",
                     reviewed_by = ?,
                     reviewed_at = NOW(),
                     review_notes = ?
                 WHERE id = ?
                   AND team_id = ?'
            );

            $stmt->execute([
                $user['id'],
                $reviewNotes,
                $checkinId,
                $teamId,
            ]);
        }

        redirect('team_links.php?view=team&team_id=' . $teamId . '&tab=check-ins');
    }
}

/**
 * Fetch main data
 */

$teams = $pdo
    ->query('SELECT * FROM teams ORDER BY name ASC')
    ->fetchAll();

$locations = $pdo
    ->query(
        'SELECT
            tl.*,
            t.name AS team_name,
            l.name AS leader_name
         FROM team_locations tl
         JOIN teams t ON t.id = tl.team_id
         LEFT JOIN leaders l ON l.id = tl.leader_id
         ORDER BY tl.team_id ASC, tl.checked_in_at ASC'
    )
    ->fetchAll();

$people = [];

try {
    $people = $pdo
        ->query(
            'SELECT *
             FROM young_people
             WHERE is_active = 1
             ORDER BY team_id ASC, name ASC'
        )
        ->fetchAll();
} catch (PDOException $exception) {
    $people = [];
}

$explorerCheckins = [];

try {
    $explorerCheckins = $pdo
        ->query(
            'SELECT
                ec.*,
                t.name AS team_name,
                l.name AS reviewed_by_name
             FROM explorer_checkins ec
             JOIN teams t ON t.id = ec.team_id
             LEFT JOIN leaders l ON l.id = ec.reviewed_by
             ORDER BY ec.submitted_at DESC'
        )
        ->fetchAll();
} catch (Throwable $exception) {
    $explorerCheckins = [];
}

$locationsByTeam = [];
$peopleByTeam = [];
$explorerCheckinsByTeam = [];

foreach ($locations as $location) {
    $teamId = (int)$location['team_id'];
    $locationsByTeam[$teamId][] = $location;
}

foreach ($people as $person) {
    if (!empty($person['team_id'])) {
        $teamId = (int)$person['team_id'];
        $peopleByTeam[$teamId][] = $person;
    }
}

foreach ($explorerCheckins as $checkin) {
    $teamId = (int)$checkin['team_id'];
    $explorerCheckinsByTeam[$teamId][] = $checkin;
}

$checkinDates = checkin_dates();

$teamSummaries = [];

foreach ($teams as $team) {
    $teamId = (int)$team['id'];
    $teamLocations = $locationsByTeam[$teamId] ?? [];
    $teamPeople = $peopleByTeam[$teamId] ?? [];
    $teamExplorerCheckins = $explorerCheckinsByTeam[$teamId] ?? [];

    $checkedDates = [];
    $totalKm = 0.0;
    $previous = null;
    $latestLocation = null;
    $approvedToday = false;
    $pendingToday = false;

    foreach ($teamLocations as $location) {
        $date = date_in_finland($location['checked_in_at']);
        $checkedDates[$date] = true;

        if (checked_in_today_finland($location['checked_in_at'])) {
            $approvedToday = true;
        }

        $lat = safe_float($location['latitude']);
        $lng = safe_float($location['longitude']);

        if ($lat !== null && $lng !== null) {
            if ($previous) {
                $prevLat = safe_float($previous['latitude']);
                $prevLng = safe_float($previous['longitude']);

                if ($prevLat !== null && $prevLng !== null) {
                    $totalKm += distance_km($prevLat, $prevLng, $lat, $lng);
                }
            }

            $previous = $location;
        }

        $latestLocation = $location;
    }

    foreach ($teamExplorerCheckins as $checkin) {
        if ($checkin['status'] === 'pending' && date_in_finland($checkin['submitted_at']) === finland_today()) {
            $pendingToday = true;
        }
    }

    $isOverdue = !$approvedToday && !$pendingToday && finland_hour() >= CHECKIN_OVERDUE_HOUR_FINLAND;

    if ($approvedToday) {
        $ragStatus = 'approved';
        $ragLabel = 'Parents notified';
    } elseif ($pendingToday) {
        $ragStatus = 'pending';
        $ragLabel = 'Submitted, pending review';
    } elseif ($isOverdue) {
        $ragStatus = 'overdue';
        $ragLabel = 'No check-in after 19:00 Finland';
    } else {
        $ragStatus = 'normal';
        $ragLabel = 'Normal';
    }

    $allergyPeople = array_values(array_filter($teamPeople, 'person_has_allergies'));

    $teamSummaries[$teamId] = [
        'team' => $team,
        'people' => $teamPeople,
        'locations' => $teamLocations,
        'explorer_checkins' => $teamExplorerCheckins,
        'latest_location' => $latestLocation,
        'checked_dates' => $checkedDates,
        'distance_miles' => miles_from_km($totalKm),
        'checked_in_today' => $approvedToday,
        'pending_today' => $pendingToday,
        'rag_status' => $ragStatus,
        'rag_label' => $ragLabel,
        'allergy_people' => $allergyPeople,
    ];
}

/**
 * Current view.
 */

$view = $_GET['view'] ?? 'overview';
$currentTeamId = (int)($_GET['team_id'] ?? 0);
$currentTab = $_GET['tab'] ?? 'overview';

$allowedTabs = ['overview', 'progress', 'posts', 'add-update', 'check-ins', 'edit'];

if (!in_array($currentTab, $allowedTabs, true)) {
    $currentTab = 'overview';
}

$currentTeam = null;
$currentTeamSummary = null;
$currentTeamPosts = [];

if ($view === 'team' && $currentTeamId > 0) {
    $currentTeam = fetch_team($pdo, $currentTeamId);

    if (!$currentTeam) {
        redirect('team_links.php');
    }

    $currentTeamSummary = $teamSummaries[$currentTeamId] ?? null;

    $stmt = $pdo->prepare(
        'SELECT
            p.*,
            l.name AS leader_name
         FROM posts p
         LEFT JOIN leaders l ON l.id = p.leader_id
         WHERE p.team_id = ?
         ORDER BY p.is_pinned DESC, p.published_at DESC
         LIMIT 50'
    );

    $stmt->execute([$currentTeamId]);
    $currentTeamPosts = $stmt->fetchAll();
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

    .teams-shell {
        max-width: 1240px;
    }

    .teams-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
    }

    .teams-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .teams-panel h2,
    .teams-panel h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .teams-panel label {
        font-weight: 800;
    }

    .rag-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }

    @media (max-width: 1000px) {
        .rag-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 620px) {
        .rag-grid {
            grid-template-columns: 1fr;
        }
    }

    .rag-card {
        border: 4px solid #b1b4b6;
        background: #ffffff;
        padding: 0.85rem;
        text-decoration: none;
        color: #1d1d1d;
        display: block;
    }

    .rag-card:hover,
    .rag-card:focus {
        color: #1d1d1d;
        text-decoration: none;
        box-shadow: 0 0 0 3px #ffdd00;
    }

    .rag-approved {
        border-color: #00703c;
    }

    .rag-overdue {
        border-color: #ffdd00;
        background: #fff7bf;
    }

    .rag-pending {
        border: 4px solid transparent;
        background:
            linear-gradient(#ffffff, #ffffff) padding-box,
            repeating-linear-gradient(
                45deg,
                #00703c 0,
                #00703c 10px,
                #ffdd00 10px,
                #ffdd00 20px
            ) border-box;
    }

    .rag-normal {
        border-color: #b1b4b6;
        background: #f8f8f8;
    }

    .rag-team-name {
        font-weight: 900;
        font-size: 1.05rem;
        margin-bottom: 0.35rem;
    }

    .rag-label {
        font-weight: 800;
        margin-bottom: 0.25rem;
    }

    .rag-time {
        color: #505a5f;
        font-size: 0.9rem;
    }

    .teams-table {
        width: 100%;
        border-collapse: collapse;
        background: #ffffff;
        border: 2px solid #d8d8d8;
    }

    .teams-table th,
    .teams-table td {
        border-bottom: 1px solid #d8d8d8;
        padding: 1rem;
        vertical-align: top;
    }

    .teams-table th {
        background: #f3f2f1;
        font-weight: 900;
    }

    .team-row-link {
        color: #1d1d1d;
        text-decoration: none;
    }

    .team-row-link:hover,
    .team-row-link:focus {
        text-decoration: underline;
    }

    .team-name {
        font-size: 1.25rem;
        font-weight: 900;
        display: block;
        margin-bottom: 0.5rem;
    }

    .face-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
    }

    .tiny-face {
        width: 28px !important;
        height: 28px !important;
        min-width: 28px !important;
        min-height: 28px !important;
        max-width: 28px !important;
        max-height: 28px !important;
        border: 2px solid #1d1d1d;
        background: #7413dc;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 900;
        text-decoration: none;
        overflow: hidden;
        position: relative;
    }

    .tiny-face img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .tiny-face-alert {
        position: absolute;
        right: -5px;
        top: -6px;
        background: #d4351c;
        color: #ffffff;
        border: 1px solid #ffffff;
        font-size: 0.65rem;
        line-height: 1;
        padding: 0.05rem 0.2rem;
        font-weight: 900;
    }

    .distance-big {
        font-size: 1.3rem;
        font-weight: 900;
        display: block;
    }

    .checkin-strip {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
    }

    .checkin-box {
        width: 34px;
        height: 34px;
        border: 2px solid #1d1d1d;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        font-size: 0.8rem;
    }

    .checkin-good {
        background: #00703c;
        color: #ffffff;
    }

    .checkin-missing {
        background: #d4351c;
        color: #ffffff;
    }

    .checkin-future {
        background: #f3f2f1;
        color: #505a5f;
        border-color: #b1b4b6;
    }

    .team-detail-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 360px;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 980px) {
        .team-detail-layout {
            grid-template-columns: 1fr;
        }
    }

    .team-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        border: 2px solid #d8d8d8;
        background: #f3f2f1;
        padding: 0.5rem;
    }

    .team-tab {
        display: inline-block;
        padding: 0.65rem 0.9rem;
        background: #ffffff;
        color: #1d1d1d;
        border: 2px solid transparent;
        font-weight: 900;
        text-decoration: none;
    }

    .team-tab:hover,
    .team-tab:focus {
        border-color: #1d1d1d;
        color: #1d1d1d;
        text-decoration: underline;
    }

    .team-tab.active {
        background: #7413dc;
        color: #ffffff;
        border-color: #7413dc;
        text-decoration: none;
    }

    .allergy-panel {
        border-left: 8px solid #d4351c;
        background: #fff1f0;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .member-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .member-list li {
        display: grid;
        grid-template-columns: 36px minmax(0, 1fr);
        gap: 0.6rem;
        border-top: 1px solid #d8d8d8;
        padding: 0.65rem 0;
    }

    .member-list li:first-child {
        border-top: 0;
    }

    .member-name {
        font-weight: 900;
        color: #1d1d1d;
    }

    .allergy-warning {
        display: inline-block;
        background: #d4351c;
        color: #ffffff;
        font-weight: 900;
        padding: 0.1rem 0.3rem;
        margin-right: 0.25rem;
    }

    .post-card,
    .location-card,
    .checkin-review-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .post-card h3,
    .location-card h3,
    .checkin-review-card h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .muted {
        color: #505a5f;
    }

    .team-link-box {
        border: 2px solid #1d70b8;
        background: #eef7ff;
        padding: 0.75rem;
        word-break: break-all;
        margin-bottom: 1rem;
    }

    .explorer-link-box {
        border: 2px solid #00703c;
        background: #e9f8ef;
        padding: 0.75rem;
        word-break: break-all;
        margin-bottom: 1rem;
    }

    .empty-box {
        border: 2px dashed #b1b4b6;
        background: #f8f8f8;
        padding: 1rem;
        font-weight: 700;
    }

    .parent-preview {
        border-left: 8px solid #00703c;
        background: #e9f8ef;
        padding: 1rem;
        margin: 1rem 0;
    }

    .internal-warning {
        border-left: 8px solid #d4351c;
        background: #fff1f0;
        padding: 1rem;
        margin: 1rem 0;
    }

    .status-pill {
        display: inline-block;
        padding: 0.35rem 0.55rem;
        border: 2px solid #1d1d1d;
        font-weight: 800;
    }

    .status-warning {
        background: #ffdd00;
        color: #1d1d1d;
    }

    .status-good {
        background: #00703c;
        color: #ffffff;
    }

    .status-danger {
        background: #d4351c;
        color: #ffffff;
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>Teams</h1>
        <p class="lead">
            Manage team progress, check-ins, updates and parent links.
        </p>
    </div>
</section>

<main id="main-content" class="container my-5 teams-shell">

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="teams-actions">
        <a class="btn btn-outline-primary" href="<?= e(url('team_links.php')) ?>">
            All teams
        </a>

        <?php if ($currentTeam): ?>
            <a class="btn btn-outline-primary" href="<?= e(team_parent_link($currentTeam)) ?>">
                Open parent view
            </a>

            <?php if (!empty($currentTeam['explorer_token'])): ?>
                <a class="btn btn-outline-primary" href="<?= e(team_explorer_link($currentTeam)) ?>" target="_blank" rel="noopener">
                    Open Explorer check-in
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <a class="btn btn-outline-primary" href="<?= e(url('people.php')) ?>">
            People
        </a>
    </div>

    <?php if ($view === 'team' && $currentTeam && $currentTeamSummary): ?>

        <?php
        $teamPeople = $currentTeamSummary['people'];
        $teamLocations = $currentTeamSummary['locations'];
        $teamExplorerCheckins = $currentTeamSummary['explorer_checkins'];
        $allergyPeople = $currentTeamSummary['allergy_people'];
        $parentLink = team_parent_link($currentTeam);
        $explorerLink = !empty($currentTeam['explorer_token']) ? team_explorer_link($currentTeam) : '';
        ?>

        <section class="teams-panel">
            <h2><?= e($currentTeam['name']) ?></h2>

            <p>
                <span class="status-pill <?= e(status_class($currentTeam['status'])) ?>">
                    <?= e(status_label($currentTeam['status'])) ?>
                </span>
            </p>

            <?php if (!empty($currentTeam['description'])): ?>
                <p><?= nl2br(e($currentTeam['description'])) ?></p>
            <?php endif; ?>

            <div class="team-link-box">
                <strong>Private parent link</strong><br>
                <a href="<?= e($parentLink) ?>"><?= e($parentLink) ?></a>
            </div>

            <form
                method="post"
                class="mb-3"
                onsubmit="return confirm('Regenerate this parent link? The old parent link will stop working.');"
            >
                <input type="hidden" name="action" value="regenerate_team_token">
                <input type="hidden" name="team_id" value="<?= (int)$currentTeam['id'] ?>">
                <button class="btn btn-outline-danger btn-sm">Regenerate parent link</button>
            </form>

            <div class="explorer-link-box">
                <strong>Explorer check-in link</strong><br>

                <?php if ($explorerLink !== ''): ?>
                    <a href="<?= e($explorerLink) ?>" target="_blank" rel="noopener">
                        <?= e($explorerLink) ?>
                    </a>
                <?php else: ?>
                    <span class="muted">No Explorer check-in link has been generated yet.</span>
                <?php endif; ?>
            </div>

            <form
                method="post"
                onsubmit="return confirm('Regenerate this Explorer check-in link? The old Explorer link will stop working.');"
            >
                <input type="hidden" name="action" value="regenerate_explorer_token">
                <input type="hidden" name="team_id" value="<?= (int)$currentTeam['id'] ?>">
                <button class="btn btn-outline-danger btn-sm">Regenerate Explorer check-in link</button>
            </form>
        </section>

        <nav class="team-tabs" aria-label="Team management">
            <?php
            $tabs = [
                'overview' => 'Overview',
                'progress' => 'Progress',
                'check-ins' => 'Explorer check-ins',
                'posts' => 'Posts & notes',
                'add-update' => 'Add update',
                'edit' => 'Edit team',
            ];
            ?>

            <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                <a
                    class="team-tab <?= $currentTab === $tabKey ? 'active' : '' ?>"
                    href="<?= e(url('team_links.php?view=team&team_id=' . (int)$currentTeam['id'] . '&tab=' . $tabKey)) ?>"
                >
                    <?= e($tabLabel) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="team-detail-layout">

            <div>

                <?php if ($currentTab === 'overview'): ?>

                    <section class="teams-panel">
                        <h2>Today’s check-in status</h2>

                        <p>
                            <span class="status-pill">
                                <?= e($currentTeamSummary['rag_label']) ?>
                            </span>
                        </p>

                        <p class="muted mb-0">
                            Finland time is currently <?= e((new DateTime('now', new DateTimeZone(FINLAND_TIMEZONE)))->format('H:i')) ?>.
                        </p>
                    </section>

                    <section class="teams-panel">
                        <h2>Team progress</h2>

                        <p>
                            <span class="distance-big">
                                <?= e(number_format($currentTeamSummary['distance_miles'], 1)) ?> miles
                            </span>
                            <span class="muted">
                                Approximate distance between manually entered check-ins.
                            </span>
                        </p>

                        <h3>10 day check-in status</h3>

                        <div class="checkin-strip">
                            <?php foreach ($checkinDates as $dateInfo): ?>
                                <?php
                                $date = $dateInfo['date'];
                                $isFuture = $date > finland_today();
                                $hasCheckin = isset($currentTeamSummary['checked_dates'][$date]);

                                $class = $hasCheckin
                                    ? 'checkin-good'
                                    : ($isFuture ? 'checkin-future' : 'checkin-missing');
                                ?>

                                <span
                                    class="checkin-box <?= e($class) ?>"
                                    title="<?= e($dateInfo['label'] . ($hasCheckin ? ' - checked in' : ' - no check-in')) ?>"
                                >
                                    <?= e($dateInfo['short']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <?php if (!empty($allergyPeople)): ?>
                        <section class="allergy-panel">
                            <h2>Allergy alerts</h2>

                            <ul class="mb-0">
                                <?php foreach ($allergyPeople as $person): ?>
                                    <li>
                                        <strong><?= e(person_display_name($person)) ?>:</strong>
                                        <?= e(implode(', ', array_map('strval', json_items($person['allergies_json'] ?? null)))) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endif; ?>

                <?php elseif ($currentTab === 'check-ins'): ?>

                    <section class="teams-panel">
                        <h2>Manual check-in</h2>

                        <p class="muted">
                            Use this if the team cannot submit their own check-in, or if a leader needs to add one directly.
                        </p>

                        <form method="post">
                            <input type="hidden" name="action" value="manual_checkin">
                            <input type="hidden" name="team_id" value="<?= (int)$currentTeam['id'] ?>">

                            <div class="form-group">
                                <label>Location name</label>
                                <input class="form-control" name="location_name" required>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Latitude</label>
                                    <input class="form-control" name="latitude" required>
                                </div>

                                <div class="form-group col-md-6">
                                    <label>Longitude</label>
                                    <input class="form-control" name="longitude" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Where are they staying?</label>
                                <select class="form-control" name="accommodation_type">
                                    <option value="">Not specified</option>
                                    <option value="Lean-to">Lean-to</option>
                                    <option value="Tent">Tent</option>
                                    <option value="With a host">With a host</option>
                                    <option value="Scout hut / hall">Scout hut / hall</option>
                                    <option value="Campsite">Campsite</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Team status</label>
                                <select class="form-control" name="status">
                                    <option value="checked_in">Checked in</option>
                                    <option value="on_route">On route</option>
                                    <option value="resting">Resting</option>
                                    <option value="delayed">Delayed</option>
                                    <option value="needs_follow_up">Needs follow-up</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Parent-facing update</label>
                                <textarea class="form-control" name="public_note" rows="4" placeholder="Do not include injuries, medication, welfare issues or private notes."></textarea>
                            </div>

                            <div class="form-group">
                                <label>Internal leader note</label>
                                <textarea class="form-control" name="internal_note" rows="4"></textarea>
                            </div>

                            <div class="parent-preview">
                                <strong>Parent update preview:</strong>
                                <p class="mb-0">
                                    If the parent-facing update box is left empty, the system will post a simple check-in message with the approximate location and accommodation type.
                                </p>
                            </div>

                            <button class="btn btn-primary">
                                Save check-in and notify parents
                            </button>
                        </form>
                    </section>

                    <section class="teams-panel">
                        <h2>Explorer submissions</h2>

                        <?php if (empty($teamExplorerCheckins)): ?>
                            <div class="empty-box">No Explorer check-ins have been submitted for this team yet.</div>
                        <?php else: ?>
                            <?php foreach ($teamExplorerCheckins as $checkin): ?>
                                <?php
                                $reports = member_reports_summary($checkin['member_reports_json'] ?? null);
                                $suggestedBody = build_parent_checkin_body(
                                    $currentTeam['name'],
                                    $checkin['location_name'] ?? '',
                                    '',
                                    $checkin['accommodation_type'] ?? ''
                                );
                                ?>

                                <article class="checkin-review-card">
                                    <h3>
                                        <?= e($checkin['location_name'] ?: 'Explorer check-in') ?>
                                    </h3>

                                    <p>
                                        <span class="status-pill <?= e(checkin_status_class($checkin['status'])) ?>">
                                            <?= e(checkin_status_label($checkin['status'])) ?>
                                        </span>
                                    </p>

                                    <p class="muted">
                                        Submitted <?= e(format_datetime($checkin['submitted_at'])) ?>
                                        <?php if (!empty($checkin['submitted_by'])): ?>
                                            by <?= e($checkin['submitted_by']) ?>
                                        <?php endif; ?>
                                    </p>

                                    <p>
                                        <strong>Coordinates:</strong>
                                        <?= e($checkin['latitude']) ?>, <?= e($checkin['longitude']) ?><br>

                                        <strong>Staying:</strong>
                                        <?= e($checkin['accommodation_type']) ?>
                                    </p>

                                    <?php if (!empty($checkin['accommodation_notes'])): ?>
                                        <p>
                                            <strong>Accommodation notes:</strong><br>
                                            <?= nl2br(e($checkin['accommodation_notes'])) ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if (!empty($checkin['welfare_notes'])): ?>
                                        <div class="internal-warning">
                                            <strong>Internal welfare notes:</strong><br>
                                            <?= nl2br(e($checkin['welfare_notes'])) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ((int)$checkin['has_injuries'] === 1 || (int)$checkin['has_medication'] === 1 || !empty($reports)): ?>
                                        <div class="internal-warning">
                                            <strong>Private first aid / medication information:</strong>
                                            <p>
                                                This is for leaders only. It will not be included in the parent update.
                                            </p>

                                            <?php if (!empty($reports)): ?>
                                                <ul class="mb-0">
                                                    <?php foreach ($reports as $report): ?>
                                                        <li>
                                                            <strong><?= e($report['name'] ?? 'Participant') ?></strong>

                                                            <?php if (!empty($report['injury_description'])): ?>
                                                                <br>Injury/concern: <?= nl2br(e($report['injury_description'])) ?>
                                                            <?php endif; ?>

                                                            <?php if (!empty($report['medication_detail'])): ?>
                                                                <br>Medication: <?= nl2br(e($report['medication_detail'])) ?>
                                                            <?php endif; ?>

                                                            <?php if (!empty($report['first_aid_given'])): ?>
                                                                <br>First aid: <?= nl2br(e($report['first_aid_given'])) ?>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="parent-preview">
                                        <strong>What parents will see:</strong>
                                        <p><?= nl2br(e($suggestedBody)) ?></p>
                                    </div>

                                    <?php if ($checkin['status'] === 'pending'): ?>
                                        <form method="post" class="mb-3">
                                            <input type="hidden" name="action" value="approve_explorer_checkin">
                                            <input type="hidden" name="team_id" value="<?= (int)$currentTeam['id'] ?>">
                                            <input type="hidden" name="checkin_id" value="<?= (int)$checkin['id'] ?>">

                                            <div class="form-group">
                                                <label>Location name</label>
                                                <input class="form-control" name="location_name" value="<?= e($checkin['location_name']) ?>" required>
                                            </div>

                                            <div class="form-row">
                                                <div class="form-group col-md-6">
                                                    <label>Latitude</label>
                                                    <input class="form-control" name="latitude" value="<?= e($checkin['latitude']) ?>" required>
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>Longitude</label>
                                                    <input class="form-control" name="longitude" value="<?= e($checkin['longitude']) ?>" required>
                                                </div>
                                            </div>

                                            <input type="hidden" name="accommodation_type" value="<?= e($checkin['accommodation_type']) ?>">

                                            <div class="form-group">
                                                <label>Team status</label>
                                                <select class="form-control" name="status">
                                                    <option value="checked_in">Checked in</option>
                                                    <option value="resting">Resting</option>
                                                    <option value="delayed">Delayed</option>
                                                    <option value="needs_follow_up">Needs follow-up</option>
                                                    <option value="completed">Completed</option>
                                                </select>
                                            </div>

                                            <div class="form-group">
                                                <label>Parent-facing update</label>
                                                <textarea class="form-control" name="public_note" rows="4" placeholder="Leave blank to use the preview above."></textarea>
                                                <small class="form-text text-muted">
                                                    Do not include injuries, medication, welfare concerns or private notes.
                                                </small>
                                            </div>

                                            <div class="form-group">
                                                <label>Internal leader note</label>
                                                <textarea class="form-control" name="internal_note" rows="4"><?= e($checkin['welfare_notes'] ?? '') ?></textarea>
                                            </div>

                                            <div class="form-group">
                                                <label>Review notes</label>
                                                <textarea class="form-control" name="review_notes" rows="3"></textarea>
                                            </div>

                                            <button class="btn btn-primary">
                                                Approve, post check-in and notify parents
                                            </button>
                                        </form>

                                        <form
                                            method="post"
                                            onsubmit="return confirm('Reject this Explorer check-in? It will not be posted to parents.');"
                                        >
                                            <input type="hidden" name="action" value="reject_explorer_checkin">
                                            <input type="hidden" name="team_id" value="<?= (int)$currentTeam['id'] ?>">
                                            <input type="hidden" name="checkin_id" value="<?= (int)$checkin['id'] ?>">

                                            <div class="form-group">
                                                <label>Reject notes</label>
                                                <textarea class="form-control" name="review_notes" rows="3"></textarea>
                                            </div>

                                            <button class="btn btn-outline-danger">
                                                Reject check-in
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <?php if (!empty($checkin['reviewed_by_name']) || !empty($checkin['reviewed_at'])): ?>
                                            <p class="muted mb-0">
                                                Reviewed
                                                <?php if (!empty($checkin['reviewed_by_name'])): ?>
                                                    by <?= e($checkin['reviewed_by_name']) ?>
                                                <?php endif; ?>

                                                <?php if (!empty($checkin['reviewed_at'])): ?>
                                                    on <?= e(format_datetime($checkin['reviewed_at'])) ?>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if (!empty($checkin['review_notes'])): ?>
                                            <p class="muted">
                                                <strong>Review notes:</strong>
                                                <?= nl2br(e($checkin['review_notes'])) ?>
                                            </p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>

                <?php elseif ($currentTab === 'progress'): ?>

                    <section class="teams-panel">
                        <h2>Check-in progress</h2>

                        <p>
                            <span class="distance-big">
                                <?= e(number_format($currentTeamSummary['distance_miles'], 1)) ?> miles
                            </span>
                            <span class="muted">
                                Approximate distance between check-ins.
                            </span>
                        </p>

                        <div class="checkin-strip mb-4">
                            <?php foreach ($checkinDates as $dateInfo): ?>
                                <?php
                                $date = $dateInfo['date'];
                                $isFuture = $date > finland_today();
                                $hasCheckin = isset($currentTeamSummary['checked_dates'][$date]);

                                $class = $hasCheckin
                                    ? 'checkin-good'
                                    : ($isFuture ? 'checkin-future' : 'checkin-missing');
                                ?>

                                <span
                                    class="checkin-box <?= e($class) ?>"
                                    title="<?= e($dateInfo['label'] . ($hasCheckin ? ' - checked in' : ' - no check-in')) ?>"
                                >
                                    <?= e($dateInfo['short']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>

                        <h3>Location check-ins</h3>

                        <?php if (empty($teamLocations)): ?>
                            <div class="empty-box">No locations have been added for this team yet.</div>
                        <?php else: ?>
                            <?php foreach (array_reverse($teamLocations) as $location): ?>
                                <article class="location-card">
                                    <h3><?= e($location['location_name']) ?></h3>
                                    <p class="muted">
                                        <?= e(format_datetime($location['checked_in_at'])) ?>
                                        <?php if (!empty($location['leader_name'])): ?>
                                            | <?= e($location['leader_name']) ?>
                                        <?php endif; ?>
                                    </p>

                                    <?php if (!empty($location['public_note'])): ?>
                                        <p><?= nl2br(e($location['public_note'])) ?></p>
                                    <?php endif; ?>

                                    <?php if (!empty($location['internal_note'])): ?>
                                        <p class="muted">
                                            <strong>Internal:</strong>
                                            <?= nl2br(e($location['internal_note'])) ?>
                                        </p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>

                <?php elseif ($currentTab === 'posts'): ?>

                    <section class="teams-panel">
                        <h2>Posts & team notes</h2>

                        <?php if (!empty($currentTeam['description'])): ?>
                            <article class="post-card">
                                <h3>Team notes</h3>
                                <p><?= nl2br(e($currentTeam['description'])) ?></p>
                            </article>
                        <?php endif; ?>

                        <?php if (empty($currentTeamPosts)): ?>
                            <div class="empty-box">No posts have been added for this team yet.</div>
                        <?php else: ?>
                            <?php foreach ($currentTeamPosts as $post): ?>
                                <article class="post-card">
                                    <h3><?= e($post['title']) ?></h3>
                                    <p class="muted">
                                        <?= e(format_datetime($post['published_at'])) ?>
                                        <?php if (!empty($post['leader_name'])): ?>
                                            | <?= e($post['leader_name']) ?>
                                        <?php endif; ?>
                                    </p>
                                    <p><?= nl2br(e($post['body'])) ?></p>

                                    <?php if (!empty($post['photo_url'])): ?>
                                        <p>
                                            <a href="<?= e($post['photo_url']) ?>">View photo</a>
                                        </p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>

                <?php elseif ($currentTab === 'add-update'): ?>

                    <section class="teams-panel">
                        <h2>Add update for <?= e($currentTeam['name']) ?></h2>

                        <form method="post">
                            <input type="hidden" name="action" value="add_post">
                            <input type="hidden" name="team_id" value="<?= (int)$currentTeam['id'] ?>">

                            <div class="form-group">
                                <label>Title</label>
                                <input class="form-control" name="title" required>
                            </div>

                            <div class="form-group">
                                <label>Update</label>
                                <textarea class="form-control" name="body" rows="6" required></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Post type</label>
                                    <select class="form-control" name="post_type">
                                        <option value="team_update">Team update</option>
                                        <option value="general">General</option>
                                        <option value="photo">Photo</option>
                                        <option value="important">Important</option>
                                    </select>
                                </div>

                                <div class="form-group col-md-6">
                                    <label>Photo URL</label>
                                    <input class="form-control" type="url" name="photo_url">
                                </div>
                            </div>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="is_pinned">
                                <label class="form-check-label">Pin this update</label>
                            </div>

                            <button class="btn btn-primary">Publish team update</button>
                        </form>
                    </section>

                <?php elseif ($currentTab === 'edit'): ?>

                    <section class="teams-panel">
                        <h2>Edit team</h2>

                        <form method="post">
                            <input type="hidden" name="action" value="update_team">
                            <input type="hidden" name="team_id" value="<?= (int)$currentTeam['id'] ?>">

                            <div class="form-group">
                                <label>Team name</label>
                                <input class="form-control" name="name" value="<?= e($currentTeam['name']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Team notes</label>
                                <textarea class="form-control" name="description" rows="5"><?= e($currentTeam['description'] ?? '') ?></textarea>
                            </div>

                            <div class="form-group">
                                <label>Status</label>
                                <select class="form-control" name="status">
                                    <?php
                                    $statuses = [
                                        'not_started' => 'Not started',
                                        'on_route' => 'On route',
                                        'checked_in' => 'Checked in',
                                        'resting' => 'Resting',
                                        'delayed' => 'Delayed',
                                        'needs_follow_up' => 'Needs follow-up',
                                        'completed' => 'Completed',
                                    ];
                                    ?>

                                    <?php foreach ($statuses as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= $currentTeam['status'] === $value ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-check mb-3">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="is_public"
                                    <?= (int)$currentTeam['is_public'] === 1 ? 'checked' : '' ?>
                                >
                                <label class="form-check-label">Visible to parents</label>
                            </div>

                            <button class="btn btn-primary">Save team</button>
                        </form>
                    </section>

                <?php endif; ?>

            </div>

            <aside>
                <section class="teams-panel">
                    <h2>Team members</h2>

                    <?php if (empty($teamPeople)): ?>
                        <p class="muted mb-0">No young people are assigned to this team yet.</p>
                    <?php else: ?>
                        <ul class="member-list">
                            <?php foreach ($teamPeople as $person): ?>
                                <?php
                                $personName = person_display_name($person);
                                $profileUrl = url('people.php?person_id=' . (int)$person['id']);
                                ?>

                                <li>
                                    <a class="tiny-face" href="<?= e($profileUrl) ?>" title="<?= e($personName) ?>">
                                        <?php if (!empty($person['photo_url'])): ?>
                                            <img src="<?= e($person['photo_url']) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(person_initials($personName)) ?>
                                        <?php endif; ?>

                                        <?php if (person_has_allergies($person)): ?>
                                            <span class="tiny-face-alert">!</span>
                                        <?php endif; ?>
                                    </a>

                                    <div>
                                        <a class="member-name" href="<?= e($profileUrl) ?>">
                                            <?php if (person_has_allergies($person)): ?>
                                                <span class="allergy-warning">!</span>
                                            <?php endif; ?>

                                            <?= e($personName) ?>
                                        </a>

                                        <?php if (person_has_allergies($person)): ?>
                                            <div class="muted">
                                                Allergies:
                                                <?= e(implode(', ', array_map('strval', json_items($person['allergies_json'] ?? null)))) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="teams-panel">
                    <h2>Check-in summary</h2>

                    <p>
                        <span class="distance-big">
                            <?= e(number_format($currentTeamSummary['distance_miles'], 1)) ?> miles
                        </span>
                    </p>

                    <p>
                        <strong>Today:</strong>
                        <?= e($currentTeamSummary['rag_label']) ?>
                    </p>

                    <div class="checkin-strip">
                        <?php foreach ($checkinDates as $dateInfo): ?>
                            <?php
                            $date = $dateInfo['date'];
                            $isFuture = $date > finland_today();
                            $hasCheckin = isset($currentTeamSummary['checked_dates'][$date]);

                            $class = $hasCheckin
                                ? 'checkin-good'
                                : ($isFuture ? 'checkin-future' : 'checkin-missing');
                            ?>

                            <span
                                class="checkin-box <?= e($class) ?>"
                                title="<?= e($dateInfo['label'] . ($hasCheckin ? ' - checked in' : ' - no check-in')) ?>"
                            >
                                <?= e($dateInfo['short']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>

        </div>

    <?php else: ?>

        <section class="teams-panel">
            <h2>Today’s check-in status</h2>

            <p class="muted">
                Based on Finland time. Amber starts after <?= e(CHECKIN_OVERDUE_HOUR_FINLAND) ?>:00 if no check-in has been submitted or approved.
            </p>

            <div class="rag-grid">
                <?php foreach ($teams as $team): ?>
                    <?php
                    $teamId = (int)$team['id'];
                    $summary = $teamSummaries[$teamId];
                    ?>

                    <a
                        class="rag-card rag-<?= e($summary['rag_status']) ?>"
                        href="<?= e(url('team_links.php?view=team&team_id=' . $teamId . '&tab=check-ins')) ?>"
                    >
                        <div class="rag-team-name">
                            <?= e($team['name']) ?>
                        </div>

                        <div class="rag-label">
                            <?= e($summary['rag_label']) ?>
                        </div>

                        <div class="rag-time">
                            <?php if ($summary['latest_location']): ?>
                                Last approved:
                                <?= e(format_datetime($summary['latest_location']['checked_in_at'])) ?>
                            <?php elseif ($summary['pending_today']): ?>
                                Waiting for leader review
                            <?php else: ?>
                                No approved check-in today
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="teams-panel">
            <h2>Teams overview</h2>
            <p class="muted">
                Select a team to view its members, updates, check-in status and route progress.
            </p>

            <?php if (empty($teams)): ?>
                <div class="empty-box">No teams have been added yet.</div>
            <?php else: ?>
                <table class="teams-table">
                    <thead>
                        <tr>
                            <th>Team</th>
                            <th>Approx. distance</th>
                            <th>10 day check-ins</th>
                            <th>Latest status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($teams as $team): ?>
                            <?php
                            $teamId = (int)$team['id'];
                            $summary = $teamSummaries[$teamId];
                            ?>

                            <tr>
                                <td>
                                    <a
                                        class="team-row-link"
                                        href="<?= e(url('team_links.php?view=team&team_id=' . $teamId)) ?>"
                                    >
                                        <span class="team-name"><?= e($team['name']) ?></span>
                                    </a>

                                    <div class="face-row">
                                        <?php foreach ($summary['people'] as $person): ?>
                                            <?php
                                            $personName = person_display_name($person);
                                            $profileUrl = url('people.php?person_id=' . (int)$person['id']);
                                            ?>

                                            <a class="tiny-face" href="<?= e($profileUrl) ?>" title="<?= e($personName) ?>">
                                                <?php if (!empty($person['photo_url'])): ?>
                                                    <img src="<?= e($person['photo_url']) ?>" alt="">
                                                <?php else: ?>
                                                    <?= e(person_initials($personName)) ?>
                                                <?php endif; ?>

                                                <?php if (person_has_allergies($person)): ?>
                                                    <span class="tiny-face-alert">!</span>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="distance-big">
                                        <?= e(number_format($summary['distance_miles'], 1)) ?> miles
                                    </span>
                                    <span class="muted">Approximate</span>
                                </td>

                                <td>
                                    <div class="checkin-strip">
                                        <?php foreach ($checkinDates as $dateInfo): ?>
                                            <?php
                                            $date = $dateInfo['date'];
                                            $isFuture = $date > finland_today();
                                            $hasCheckin = isset($summary['checked_dates'][$date]);

                                            $class = $hasCheckin
                                                ? 'checkin-good'
                                                : ($isFuture ? 'checkin-future' : 'checkin-missing');
                                            ?>

                                            <span
                                                class="checkin-box <?= e($class) ?>"
                                                title="<?= e($dateInfo['label'] . ($hasCheckin ? ' - checked in' : ' - no check-in')) ?>"
                                            >
                                                <?= e($dateInfo['short']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>

                                <td>
                                    <p>
                                        <span class="status-pill <?= e(status_class($team['status'])) ?>">
                                            <?= e(status_label($team['status'])) ?>
                                        </span>
                                    </p>

                                    <p>
                                        <strong>Today:</strong>
                                        <?= e($summary['rag_label']) ?>
                                    </p>

                                    <?php if ($summary['latest_location']): ?>
                                        <p class="muted mb-0">
                                            <?= e($summary['latest_location']['location_name']) ?><br>
                                            <?= e(format_datetime($summary['latest_location']['checked_in_at'])) ?>
                                        </p>
                                    <?php else: ?>
                                        <p class="muted mb-0">No approved check-ins yet.</p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="teams-panel">
            <h2>Add team</h2>

            <form method="post">
                <input type="hidden" name="action" value="add_team">

                <div class="form-group">
                    <label>Team name</label>
                    <input class="form-control" name="name" required>
                </div>

                <div class="form-group">
                    <label>Team notes</label>
                    <textarea class="form-control" name="description" rows="4"></textarea>
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select class="form-control" name="status">
                        <option value="not_started">Not started</option>
                        <option value="on_route">On route</option>
                        <option value="checked_in">Checked in</option>
                        <option value="resting">Resting</option>
                        <option value="delayed">Delayed</option>
                        <option value="needs_follow_up">Needs follow-up</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_public" checked>
                    <label class="form-check-label">Visible to parents</label>
                </div>

                <button class="btn btn-primary">Add team</button>
            </form>
        </section>

    <?php endif; ?>

</main>

<?php include __DIR__ . '/footer.php'; ?>