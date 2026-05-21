<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();

/**
 * Helpers
 */

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

function checked_in_today(?string $datetime): bool
{
    if (!$datetime) {
        return false;
    }

    return date('Y-m-d', strtotime($datetime)) === date('Y-m-d');
}

function safe_float($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    return (float)$value;
}

function team_colour_for_index(int $index): string
{
    $colours = [
        '#1d70b8', // blue
        '#00703c', // green
        '#d4351c', // red
        '#f47738', // orange
        '#4c2c92', // purple
        '#00847f', // teal
        '#b58840', // brown/gold
        '#6f72af', // muted purple
        '#2b8cc4', // light blue
        '#85994b', // olive
    ];

    return $colours[$index % count($colours)];
}

function person_display_name(array $person): string
{
    if (!empty($person['name'])) {
        return $person['name'];
    }

    $first = $person['first_name'] ?? '';
    $last = $person['last_name'] ?? '';
    $name = trim($first . ' ' . $last);

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

/**
 * Fetch teams.
 */
$teams = $pdo
    ->query('SELECT * FROM teams ORDER BY name ASC')
    ->fetchAll();

/**
 * Fetch locations, newest first for audit table.
 */
$locations = $pdo
    ->query(
        'SELECT 
            tl.*, 
            t.name AS team_name, 
            t.status AS team_status,
            l.name AS leader_name 
         FROM team_locations tl 
         JOIN teams t ON t.id = tl.team_id 
         LEFT JOIN leaders l ON l.id = tl.leader_id 
         ORDER BY tl.checked_in_at DESC'
    )
    ->fetchAll();

/**
 * Fetch locations in route order, oldest first.
 */
$routeLocations = $pdo
    ->query(
        'SELECT 
            tl.*, 
            t.name AS team_name, 
            t.status AS team_status,
            l.name AS leader_name 
         FROM team_locations tl 
         JOIN teams t ON t.id = tl.team_id 
         LEFT JOIN leaders l ON l.id = tl.leader_id 
         ORDER BY tl.team_id ASC, tl.checked_in_at ASC'
    )
    ->fetchAll();

/**
 * Fetch young people.
 *
 * This expects the expanded young_people structure, but still tolerates older
 * first_name / last_name columns if they are present.
 */
$youngPeople = [];

try {
    $youngPeople = $pdo
        ->query(
            'SELECT *
             FROM young_people
             WHERE is_active = 1
             ORDER BY team_id ASC, name ASC'
        )
        ->fetchAll();
} catch (PDOException $exception) {
    try {
        $youngPeople = $pdo
            ->query(
                'SELECT *
                 FROM young_people
                 WHERE is_active = 1
                 ORDER BY team_id ASC, last_name ASC, first_name ASC'
            )
            ->fetchAll();
    } catch (PDOException $exception) {
        $youngPeople = [];
    }
}

/**
 * Organise data by team.
 */
$teamData = [];
$teamIndex = 0;

foreach ($teams as $team) {
    $teamId = (int)$team['id'];

    $teamData[$teamId] = [
        'team' => $team,
        'colour' => team_colour_for_index($teamIndex),
        'people' => [],
        'locations' => [],
        'latest_location' => null,
        'total_km' => 0.0,
        'total_miles' => 0.0,
        'checked_in_today' => false,
    ];

    $teamIndex++;
}

foreach ($youngPeople as $person) {
    if (empty($person['team_id'])) {
        continue;
    }

    $teamId = (int)$person['team_id'];

    if (!isset($teamData[$teamId])) {
        continue;
    }

    $teamData[$teamId]['people'][] = $person;
}

foreach ($routeLocations as $location) {
    $teamId = (int)$location['team_id'];

    if (!isset($teamData[$teamId])) {
        continue;
    }

    $lat = safe_float($location['latitude']);
    $lng = safe_float($location['longitude']);

    if ($lat === null || $lng === null) {
        continue;
    }

    $teamData[$teamId]['locations'][] = $location;
}

/**
 * Calculate distance and latest location.
 */
foreach ($teamData as $teamId => &$data) {
    $previous = null;
    $totalKm = 0.0;

    foreach ($data['locations'] as $location) {
        $lat = safe_float($location['latitude']);
        $lng = safe_float($location['longitude']);

        if ($lat === null || $lng === null) {
            continue;
        }

        if ($previous) {
            $prevLat = safe_float($previous['latitude']);
            $prevLng = safe_float($previous['longitude']);

            if ($prevLat !== null && $prevLng !== null) {
                $totalKm += distance_km($prevLat, $prevLng, $lat, $lng);
            }
        }

        $previous = $location;
    }

    if (!empty($data['locations'])) {
        $data['latest_location'] = $data['locations'][count($data['locations']) - 1];
        $data['checked_in_today'] = checked_in_today($data['latest_location']['checked_in_at'] ?? null);
    }

    $data['total_km'] = $totalKm;
    $data['total_miles'] = miles_from_km($totalKm);
}
unset($data);

/**
 * Build map data.
 */
$mapTeams = [];

foreach ($teamData as $teamId => $data) {
    $points = [];

    foreach ($data['locations'] as $location) {
        $lat = safe_float($location['latitude']);
        $lng = safe_float($location['longitude']);

        if ($lat === null || $lng === null) {
            continue;
        }

        $points[] = [
            'lat' => $lat,
            'lng' => $lng,
            'location_name' => $location['location_name'],
            'checked_in_at' => format_datetime($location['checked_in_at']),
            'leader_name' => $location['leader_name'] ?: 'Unknown',
            'public_note' => $location['public_note'] ?? '',
            'internal_note' => $location['internal_note'] ?? '',
        ];
    }

    if (empty($points)) {
        continue;
    }

    $mapTeams[] = [
        'id' => $teamId,
        'name' => $data['team']['name'],
        'colour' => $data['colour'],
        'points' => $points,
        'total_miles' => round($data['total_miles'], 1),
    ];
}

$mapJson = json_encode($mapTeams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);


$selectedTeamId = 0;

if (!empty($teamData)) {
    $requestedTeamId = (int)($_GET['team_id'] ?? 0);

    if ($requestedTeamId > 0 && isset($teamData[$requestedTeamId])) {
        $selectedTeamId = $requestedTeamId;
    } else {
        $firstTeamId = array_key_first($teamData);
        $selectedTeamId = $firstTeamId !== null ? (int)$firstTeamId : 0;
    }
}

include __DIR__ . '/header.php';
?>

<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIINfQj7pP5aLQW/sA0VYCcuyWEmvZNS35M="
    crossorigin=""
>

<style>
    .page-hero,
    .page-hero h1,
    .page-hero h2,
    .page-hero h3,
    .page-hero p,
    .page-hero .lead {
        color: #ffffff !important;
    }

    .locations-shell {
        max-width: 1480px;
    }

    .locations-map-panel,
    .locations-team-browser,
    .locations-team-detail,
    .locations-table-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .locations-map-panel h2,
    .locations-team-browser h2,
    .locations-team-detail h2,
    .locations-table-panel h2,
    .locations-team-detail h3 {
        margin-top: 0;
        font-weight: 900;
    }

    #team-progress-map {
        height: 620px;
        border: 2px solid #1d1d1d;
        background: #f3f2f1;
    }

    @media (max-width: 700px) {
        #team-progress-map {
            height: 430px;
        }
    }

    .map-help {
        border-left: 6px solid #1d70b8;
        background: #eef7ff;
        padding: 0.85rem;
        margin-bottom: 1rem;
    }

    .map-help p {
        margin-bottom: 0;
    }

    .locations-browser-layout {
        display: grid;
        grid-template-columns: 360px minmax(0, 1fr);
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 1100px) {
        .locations-browser-layout {
            grid-template-columns: 1fr;
        }
    }

    .locations-team-browser {
        position: sticky;
        top: 1rem;
        max-height: calc(100vh - 2rem);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    @media (max-width: 1100px) {
        .locations-team-browser {
            position: static;
            max-height: none;
        }
    }

    .team-browser-header {
        flex: 0 0 auto;
        border-bottom: 2px solid #d8d8d8;
        padding-bottom: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .team-search {
        border: 2px solid #1d1d1d;
        border-radius: 0;
        min-height: 42px;
    }

    .compact-team-list {
        overflow-y: auto;
        padding-right: 0.25rem;
    }

    @media (max-width: 1100px) {
        .compact-team-list {
            max-height: 420px;
        }
    }

    .compact-team-card {
        width: 100%;
        border: 2px solid #d8d8d8;
        background: #ffffff;
        text-align: left;
        padding: 0.85rem;
        margin-bottom: 0.65rem;
        cursor: pointer;
        color: #1d1d1d;
        display: block;
    }

    .compact-team-card:hover,
    .compact-team-card:focus {
        border-color: #1d1d1d;
        box-shadow: 0 0 0 3px #ffdd00;
        outline: none;
    }

    .compact-team-card.is-active {
        border-color: #7413dc;
        box-shadow: inset 6px 0 0 #7413dc;
        background: #f8f8f8;
    }

    .compact-team-topline {
        display: grid;
        grid-template-columns: 20px minmax(0, 1fr) auto;
        gap: 0.55rem;
        align-items: start;
    }

    .team-colour-key {
        width: 18px;
        height: 18px;
        border: 2px solid #1d1d1d;
        flex: 0 0 auto;
        margin-top: 0.2rem;
    }

    .compact-team-name {
        font-weight: 900;
        line-height: 1.2;
        display: block;
    }

    .compact-team-meta {
        color: #505a5f;
        font-size: 0.9rem;
        line-height: 1.35;
        display: block;
        margin-top: 0.2rem;
    }

    .compact-mileage {
        font-weight: 900;
        white-space: nowrap;
    }

    .compact-latest {
        margin: 0.55rem 0 0;
        color: #505a5f;
        font-size: 0.9rem;
    }

    .face-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
        margin-top: 0.65rem;
    }

    .mini-face {
        width: 28px !important;
        height: 28px !important;
        min-width: 28px !important;
        min-height: 28px !important;
        max-width: 28px !important;
        max-height: 28px !important;
        border: 2px solid #1d1d1d;
        object-fit: cover;
        background: #7413dc;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 900;
        line-height: 1;
        text-decoration: none;
        overflow: hidden;
        border-radius: 50%;
    }

    .mini-face img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .mini-face-more {
        background: #f3f2f1;
        color: #1d1d1d;
    }

    .selected-team-panel {
        display: none;
    }

    .selected-team-panel.is-active {
        display: block;
    }

    .selected-team-header {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 1rem;
        align-items: start;
        border-bottom: 2px solid #d8d8d8;
        padding-bottom: 1rem;
        margin-bottom: 1rem;
    }

    @media (max-width: 760px) {
        .selected-team-header {
            grid-template-columns: 1fr;
        }
    }

    .selected-team-header h2 {
        margin-bottom: 0.35rem;
    }

    .team-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        justify-content: flex-end;
    }

    @media (max-width: 760px) {
        .team-actions {
            justify-content: flex-start;
        }
    }

    .selected-stats-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.25rem;
    }

    @media (max-width: 760px) {
        .selected-stats-grid {
            grid-template-columns: 1fr;
        }
    }

    .stat-box {
        border: 2px solid #d8d8d8;
        background: #f8f8f8;
        padding: 0.9rem;
    }

    .stat-label {
        display: block;
        color: #505a5f;
        font-size: 0.9rem;
        font-weight: 800;
        margin-bottom: 0.25rem;
    }

    .stat-value {
        display: block;
        font-size: 1.25rem;
        font-weight: 900;
        line-height: 1.2;
    }

    .checkin-state {
        display: inline-block;
        border: 2px solid #1d1d1d;
        padding: 0.25rem 0.45rem;
        font-weight: 900;
        font-size: 0.85rem;
    }

    .checkin-state-good {
        background: #00703c;
        color: #ffffff;
    }

    .checkin-state-waiting {
        background: #ffdd00;
        color: #1d1d1d;
    }

    .selected-content-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 320px;
        gap: 1rem;
        align-items: start;
    }

    @media (max-width: 900px) {
        .selected-content-grid {
            grid-template-columns: 1fr;
        }
    }

    .route-stops {
        list-style: none;
        padding: 0;
        margin: 0;
        border: 2px solid #d8d8d8;
        background: #ffffff;
    }

    .route-stop {
        display: grid;
        grid-template-columns: 16px minmax(0, 1fr);
        gap: 0.6rem;
        border-top: 1px solid #d8d8d8;
        padding: 0.7rem;
    }

    .route-stop:first-child {
        border-top: 0;
    }

    .route-stop-dot {
        width: 12px;
        height: 12px;
        border-radius: 999px;
        border: 2px solid #1d1d1d;
        margin-top: 0.25rem;
    }

    .route-stop strong {
        display: block;
    }

    .route-stop small {
        color: #505a5f;
        display: block;
    }

    .team-members-panel {
        border: 2px solid #d8d8d8;
        background: #f8f8f8;
        padding: 1rem;
    }

    .member-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.5rem;
    }

    @media (max-width: 500px) {
        .member-grid {
            grid-template-columns: 1fr;
        }
    }

    .member-chip {
        display: grid;
        grid-template-columns: 32px minmax(0, 1fr);
        gap: 0.5rem;
        align-items: center;
        color: #1d1d1d;
        text-decoration: none;
        background: #ffffff;
        border: 1px solid #d8d8d8;
        padding: 0.35rem;
    }

    .member-chip:hover,
    .member-chip:focus {
        color: #1d1d1d;
        text-decoration: underline;
        border-color: #1d1d1d;
    }

    .member-chip .mini-face {
        margin: 0;
    }

    .member-name {
        font-weight: 800;
        line-height: 1.2;
        overflow-wrap: anywhere;
    }

    .team-empty {
        color: #505a5f;
        margin-bottom: 0;
    }

    .locations-table-panel {
        overflow-x: auto;
    }

    .locations-table-panel table {
        min-width: 980px;
    }

    .muted {
        color: #505a5f;
    }

    .leaflet-popup-content strong {
        font-weight: 900;
    }

    .hidden-by-search {
        display: none !important;
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>Locations &amp; progress</h1>
        <p class="lead">
            Leader-only overview of team check-ins, approximate routes and progress across the trip.
        </p>
    </div>
</section>

<main id="main-content" class="container-fluid my-5 px-4 locations-shell">

    <section class="locations-map-panel">
        <h2>Team progress map</h2>

        <div class="map-help">
            <p>
                Routes are drawn as approximate straight lines between manually entered check-ins.
                They are not turn-by-turn walking routes and should be treated as a progress overview only.
            </p>
        </div>

        <div id="team-progress-map"></div>
    </section>

    <div class="locations-browser-layout">

        <aside class="locations-team-browser">
            <div class="team-browser-header">
                <h2>Teams</h2>

                <label class="sr-only" for="team-filter">Search teams</label>
                <input
                    class="form-control team-search"
                    id="team-filter"
                    type="search"
                    placeholder="Search teams..."
                    autocomplete="off"
                >

                <p class="muted mt-2 mb-0" id="team-filter-count">
                    <?= count($teamData) ?> team<?= count($teamData) === 1 ? '' : 's' ?>
                </p>
            </div>

            <div class="compact-team-list" id="compact-team-list">
                <?php if (empty($teamData)): ?>
                    <p>No teams have been added yet.</p>
                <?php endif; ?>

                <?php foreach ($teamData as $teamId => $data): ?>
                    <?php
                    $team = $data['team'];
                    $latest = $data['latest_location'];
                    $checkedToday = $data['checked_in_today'];
                    $teamName = (string)$team['name'];
                    $teamSearch = strtolower($teamName . ' ' . ($team['status'] ?? '') . ' ' . ($latest['location_name'] ?? ''));
                    ?>

                    <button
                        type="button"
                        class="compact-team-card js-team-selector <?= (int)$teamId === $selectedTeamId ? 'is-active' : '' ?>"
                        data-team-id="<?= (int)$teamId ?>"
                        data-team-search="<?= e($teamSearch) ?>"
                    >
                        <span class="compact-team-topline">
                            <span
                                class="team-colour-key"
                                style="background: <?= e($data['colour']) ?>;"
                                aria-hidden="true"
                            ></span>

                            <span>
                                <span class="compact-team-name"><?= e($teamName) ?></span>
                                <span class="compact-team-meta">
                                    <?= e(status_label($team['status'])) ?>
                                </span>
                            </span>

                            <span class="compact-mileage">
                                <?= e(number_format($data['total_miles'], 1)) ?> mi
                            </span>
                        </span>

                        <span class="compact-latest">
                            <?php if ($latest): ?>
                                Latest: <?= e($latest['location_name']) ?><br>
                                <?= e(format_datetime($latest['checked_in_at'])) ?>
                            <?php else: ?>
                                No check-ins yet
                            <?php endif; ?>
                        </span>

                        <span class="face-row" aria-label="Team members">
                            <?php foreach (array_slice($data['people'], 0, 8) as $person): ?>
                                <?php $personName = person_display_name($person); ?>

                                <span class="mini-face" title="<?= e($personName) ?>">
                                    <?php if (!empty($person['photo_url'])): ?>
                                        <img src="<?= e($person['photo_url']) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(person_initials($personName)) ?>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>

                            <?php if (count($data['people']) > 8): ?>
                                <span class="mini-face mini-face-more">
                                    +<?= count($data['people']) - 8 ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="locations-team-detail">
            <?php if (empty($teamData)): ?>
                <div class="team-empty">
                    No team records are available.
                </div>
            <?php endif; ?>

            <?php foreach ($teamData as $teamId => $data): ?>
                <?php
                $team = $data['team'];
                $latest = $data['latest_location'];
                $checkedToday = $data['checked_in_today'];
                ?>

                <article
                    class="selected-team-panel js-team-detail <?= (int)$teamId === $selectedTeamId ? 'is-active' : '' ?>"
                    data-team-id="<?= (int)$teamId ?>"
                >
                    <div class="selected-team-header">
                        <div>
                            <h2><?= e($team['name']) ?></h2>

                            <p class="mb-1">
                                <span class="status-pill <?= e(status_class($team['status'])) ?>">
                                    <?= e(status_label($team['status'])) ?>
                                </span>
                            </p>

                            <?php if (!empty($team['description'])): ?>
                                <p class="muted mb-0"><?= nl2br(e($team['description'])) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="team-actions">
                            <button
                                type="button"
                                class="btn btn-outline-primary btn-sm js-focus-team"
                                data-team-id="<?= (int)$teamId ?>"
                            >
                                Focus on map
                            </button>

                            <a
                                class="btn btn-outline-primary btn-sm"
                                href="<?= e(url('team_links.php?view=team&team_id=' . (int)$teamId)) ?>"
                            >
                                Open team record
                            </a>
                        </div>
                    </div>

                    <div class="selected-stats-grid">
                        <div class="stat-box">
                            <span class="stat-label">Distance covered</span>
                            <span class="stat-value"><?= e(number_format($data['total_miles'], 1)) ?> miles</span>
                        </div>

                        <div class="stat-box">
                            <span class="stat-label">Check-in state</span>
                            <span class="stat-value">
                                <?php if ($checkedToday): ?>
                                    <span class="checkin-state checkin-state-good">Checked in today</span>
                                <?php else: ?>
                                    <span class="checkin-state checkin-state-waiting">No check-in today</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="stat-box">
                            <span class="stat-label">Latest location</span>
                            <span class="stat-value">
                                <?php if ($latest): ?>
                                    <?= e($latest['location_name']) ?>
                                <?php else: ?>
                                    Not recorded
                                <?php endif; ?>
                            </span>

                            <?php if ($latest): ?>
                                <span class="muted"><?= e(format_datetime($latest['checked_in_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="selected-content-grid">
                        <div>
                            <h3>Route stops</h3>

                            <?php if (empty($data['locations'])): ?>
                                <p class="team-empty">
                                    No check-ins have been added for this team yet.
                                </p>
                            <?php else: ?>
                                <ol class="route-stops">
                                    <?php foreach (array_reverse($data['locations']) as $stop): ?>
                                        <li class="route-stop">
                                            <span
                                                class="route-stop-dot"
                                                style="background: <?= e($data['colour']) ?>;"
                                                aria-hidden="true"
                                            ></span>

                                            <span>
                                                <strong><?= e($stop['location_name']) ?></strong>

                                                <small>
                                                    <?= e(format_datetime($stop['checked_in_at'])) ?>
                                                    <?php if (!empty($stop['leader_name'])): ?>
                                                        by <?= e($stop['leader_name']) ?>
                                                    <?php endif; ?>
                                                </small>

                                                <?php if (!empty($stop['public_note'])): ?>
                                                    <small><?= e($stop['public_note']) ?></small>
                                                <?php endif; ?>

                                                <?php if (!empty($stop['latitude']) && !empty($stop['longitude'])): ?>
                                                    <small>
                                                        <?= e($stop['latitude']) ?>, <?= e($stop['longitude']) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </div>

                        <aside class="team-members-panel">
                            <h3>Team members</h3>

                            <?php if (empty($data['people'])): ?>
                                <p class="team-empty">
                                    No young people are assigned to this team.
                                </p>
                            <?php else: ?>
                                <div class="member-grid">
                                    <?php foreach ($data['people'] as $person): ?>
                                        <?php
                                        $personName = person_display_name($person);
                                        $personUrl = url('people.php?person_id=' . (int)$person['id']);
                                        ?>

                                        <a class="member-chip" href="<?= e($personUrl) ?>">
                                            <span class="mini-face" aria-hidden="true">
                                                <?php if (!empty($person['photo_url'])): ?>
                                                    <img src="<?= e($person['photo_url']) ?>" alt="">
                                                <?php else: ?>
                                                    <?= e(person_initials($personName)) ?>
                                                <?php endif; ?>
                                            </span>

                                            <span class="member-name">
                                                <?= e($personName) ?>
                                            </span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </aside>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

    </div>

    <section class="locations-table-panel">
        <h2>Full location history</h2>

        <?php if (empty($locations)): ?>
            <p>No locations have been recorded yet.</p>
        <?php else: ?>
            <table class="table table-bordered table-responsive-lg">
                <thead>
                    <tr>
                        <th>Team</th>
                        <th>Location</th>
                        <th>Coordinates</th>
                        <th>Checked in</th>
                        <th>Leader</th>
                        <th>Public note</th>
                        <th>Internal note</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($locations as $loc): ?>
                        <tr>
                            <td><?= e($loc['team_name']) ?></td>
                            <td><?= e($loc['location_name']) ?></td>
                            <td>
                                <?= e($loc['latitude']) ?>,
                                <?= e($loc['longitude']) ?>
                            </td>
                            <td><?= e(format_datetime($loc['checked_in_at'])) ?></td>
                            <td><?= e($loc['leader_name'] ?: 'Unknown') ?></td>
                            <td><?= nl2br(e($loc['public_note'])) ?></td>
                            <td><?= nl2br(e($loc['internal_note'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

</main>

<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin=""
></script>

<script>
    (function () {
        var map = null;
        var teamLayers = {};
        var teamBounds = {};
        var allBounds = [];
        var activeTeamId = <?= (int)$selectedTeamId ?>;

        var mapTeams = <?= $mapJson ?: '[]' ?>;

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function initialiseMap() {
            if (typeof L === 'undefined') {
                return;
            }

            map = L.map('team-progress-map', {
                scrollWheelZoom: true
            }).setView([62.2, 25.3], 6);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            mapTeams.forEach(function (team) {
                var latLngs = [];
                var layerGroup = L.layerGroup().addTo(map);

                team.points.forEach(function (point, index) {
                    var latLng = [point.lat, point.lng];
                    latLngs.push(latLng);
                    allBounds.push(latLng);

                    var marker = L.circleMarker(latLng, {
                        radius: index === team.points.length - 1 ? 8 : 6,
                        color: '#1d1d1d',
                        weight: 2,
                        fillColor: team.colour,
                        fillOpacity: 1
                    }).addTo(layerGroup);

                    var popupHtml =
                        '<strong>' + escapeHtml(team.name) + '</strong><br>' +
                        escapeHtml(point.location_name) + '<br>' +
                        '<span>' + escapeHtml(point.checked_in_at) + '</span><br>' +
                        '<span>Leader: ' + escapeHtml(point.leader_name) + '</span>';

                    if (point.public_note) {
                        popupHtml += '<br><br>' + escapeHtml(point.public_note);
                    }

                    marker.bindPopup(popupHtml);

                    marker.on('click', function () {
                        selectTeam(team.id, false);
                    });
                });

                if (latLngs.length >= 2) {
                    L.polyline(latLngs, {
                        color: team.colour,
                        weight: 4,
                        opacity: 0.85,
                        dashArray: '8, 8'
                    }).addTo(layerGroup);
                }

                teamLayers[team.id] = layerGroup;
                teamBounds[team.id] = latLngs;
            });

            if (allBounds.length > 0) {
                map.fitBounds(allBounds, {
                    padding: [30, 30]
                });
            }

            if (activeTeamId > 0) {
                setTimeout(function () {
                    focusTeamOnMap(activeTeamId);
                }, 250);
            }
        }

        function selectTeam(teamId, shouldFocusMap) {
            activeTeamId = parseInt(teamId, 10);

            document.querySelectorAll('.js-team-selector').forEach(function (button) {
                button.classList.toggle('is-active', parseInt(button.dataset.teamId, 10) === activeTeamId);
            });

            document.querySelectorAll('.js-team-detail').forEach(function (panel) {
                panel.classList.toggle('is-active', parseInt(panel.dataset.teamId, 10) === activeTeamId);
            });

            if (shouldFocusMap) {
                focusTeamOnMap(activeTeamId);
            }
        }

        function focusTeamOnMap(teamId) {
            if (!map || !teamBounds[teamId] || teamBounds[teamId].length === 0) {
                return;
            }

            if (teamBounds[teamId].length === 1) {
                map.setView(teamBounds[teamId][0], 11);
            } else {
                map.fitBounds(teamBounds[teamId], {
                    padding: [45, 45]
                });
            }
        }

        document.querySelectorAll('.js-team-selector').forEach(function (button) {
            button.addEventListener('click', function () {
                selectTeam(button.dataset.teamId, true);
            });
        });

        document.querySelectorAll('.js-focus-team').forEach(function (button) {
            button.addEventListener('click', function () {
                focusTeamOnMap(parseInt(button.dataset.teamId, 10));
            });
        });

        var filterInput = document.getElementById('team-filter');
        var filterCount = document.getElementById('team-filter-count');
        var teamButtons = Array.prototype.slice.call(document.querySelectorAll('.js-team-selector'));

        function applyTeamFilter() {
            if (!filterInput) {
                return;
            }

            var query = filterInput.value.toLowerCase().trim();
            var visibleCount = 0;
            var firstVisible = null;
            var activeStillVisible = false;

            teamButtons.forEach(function (button) {
                var haystack = String(button.dataset.teamSearch || '').toLowerCase();
                var matches = query === '' || haystack.indexOf(query) !== -1;

                button.classList.toggle('hidden-by-search', !matches);

                if (matches) {
                    visibleCount++;

                    if (!firstVisible) {
                        firstVisible = button;
                    }

                    if (parseInt(button.dataset.teamId, 10) === activeTeamId) {
                        activeStillVisible = true;
                    }
                }
            });

            if (filterCount) {
                filterCount.textContent = visibleCount + ' team' + (visibleCount === 1 ? '' : 's');
            }

            if (!activeStillVisible && firstVisible) {
                selectTeam(firstVisible.dataset.teamId, true);
            }
        }

        if (filterInput) {
            filterInput.addEventListener('input', applyTeamFilter);
        }

        initialiseMap();
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>