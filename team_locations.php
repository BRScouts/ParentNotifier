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

    .locations-page-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 420px;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 1100px) {
        .locations-page-layout {
            grid-template-columns: 1fr;
        }
    }

    .locations-map-panel,
    .locations-side-panel,
    .locations-table-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .locations-map-panel h2,
    .locations-side-panel h2,
    .locations-table-panel h2 {
        margin-top: 0;
        font-weight: 900;
    }

    #team-progress-map {
        height: 720px;
        border: 2px solid #1d1d1d;
        background: #f3f2f1;
    }

    @media (max-width: 700px) {
        #team-progress-map {
            height: 480px;
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

    .team-progress-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        margin-bottom: 1rem;
    }

    .team-progress-card-header {
        padding: 1rem;
        border-bottom: 2px solid #d8d8d8;
        background: #f8f8f8;
    }

    .team-progress-title-row {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
    }

    .team-progress-title-row h3 {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 900;
    }

    .team-colour-key {
        width: 22px;
        height: 22px;
        border: 2px solid #1d1d1d;
        flex: 0 0 auto;
    }

    .team-progress-meta {
        margin-top: 0.5rem;
        color: #505a5f;
    }

    .team-progress-body {
        padding: 1rem;
    }

    .face-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.3rem;
        margin-bottom: 0.75rem;
    }

    .mini-face {
        width: 30px !important;
        height: 30px !important;
        min-width: 30px !important;
        min-height: 30px !important;
        max-width: 30px !important;
        max-height: 30px !important;
        border: 2px solid #1d1d1d;
        object-fit: cover;
        background: #7413dc;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 900;
        line-height: 1;
        text-decoration: none;
        overflow: hidden;
    }

    .mini-face img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
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

    .route-stops {
        list-style: none;
        padding: 0;
        margin: 0.75rem 0 0;
    }

    .route-stop {
        display: grid;
        grid-template-columns: 16px minmax(0, 1fr);
        gap: 0.5rem;
        border-top: 1px solid #d8d8d8;
        padding: 0.6rem 0;
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
    }

    .team-empty {
        color: #505a5f;
        margin-bottom: 0;
    }

    .distance-value {
        font-size: 1.1rem;
        font-weight: 900;
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
</style>

<section class="page-hero">
    <div class="container">
        <h1>Locations &amp; progress</h1>
        <p class="lead">
            Leader-only overview of team check-ins, approximate routes and progress across the trip.
        </p>
    </div>
</section>

<main id="main-content" class="container-fluid my-5 px-4">

    <div class="locations-page-layout">

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

        <aside class="locations-side-panel">
            <h2>Teams</h2>

            <?php if (empty($teamData)): ?>
                <p>No teams have been added yet.</p>
            <?php endif; ?>

            <?php foreach ($teamData as $teamId => $data): ?>
                <?php
                $team = $data['team'];
                $latest = $data['latest_location'];
                $checkedToday = $data['checked_in_today'];
                ?>

                <article class="team-progress-card" id="team-card-<?= (int)$teamId ?>">
                    <div class="team-progress-card-header">
                        <div class="team-progress-title-row">
                            <div>
                                <h3><?= e($team['name']) ?></h3>

                                <div class="team-progress-meta">
                                    <span class="status-pill <?= e(status_class($team['status'])) ?>">
                                        <?= e(status_label($team['status'])) ?>
                                    </span>
                                </div>
                            </div>

                            <span
                                class="team-colour-key"
                                style="background: <?= e($data['colour']) ?>;"
                                title="Map route colour"
                            ></span>
                        </div>
                    </div>

                    <div class="team-progress-body">

                        <?php if (!empty($data['people'])): ?>
                            <div class="face-row" aria-label="Team members">
                                <?php foreach ($data['people'] as $person): ?>
                                    <?php $personName = person_display_name($person); ?>

                                    <span class="mini-face" title="<?= e($personName) ?>">
                                        <?php if (!empty($person['photo_url'])): ?>
                                            <img src="<?= e($person['photo_url']) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(person_initials($personName)) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <p>
                            <span class="distance-value">
                                <?= e(number_format($data['total_miles'], 1)) ?> miles
                            </span>
                            <br>
                            <span class="muted">
                                Approximate distance between check-ins
                            </span>
                        </p>

                        <p>
                            <?php if ($checkedToday): ?>
                                <span class="checkin-state checkin-state-good">
                                    Checked in today
                                </span>
                            <?php else: ?>
                                <span class="checkin-state checkin-state-waiting">
                                    No check-in today
                                </span>
                            <?php endif; ?>
                        </p>

                        <?php if ($latest): ?>
                            <p class="mb-2">
                                <strong>Latest:</strong>
                                <?= e($latest['location_name']) ?>
                                <br>
                                <span class="muted">
                                    <?= e(format_datetime($latest['checked_in_at'])) ?>
                                </span>
                            </p>
                        <?php else: ?>
                            <p class="team-empty">
                                No check-ins have been added for this team yet.
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($data['locations'])): ?>
                            <h4 class="h6 mt-3">Stops</h4>

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
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>

                    </div>
                </article>
            <?php endforeach; ?>
        </aside>

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
        if (typeof L === 'undefined') {
            return;
        }

        var mapTeams = <?= $mapJson ?: '[]' ?>;

        var map = L.map('team-progress-map', {
            scrollWheelZoom: true
        }).setView([54.5, -3.2], 6);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        var bounds = [];

        mapTeams.forEach(function (team) {
            var latLngs = [];

            team.points.forEach(function (point, index) {
                var latLng = [point.lat, point.lng];
                latLngs.push(latLng);
                bounds.push(latLng);

                var marker = L.circleMarker(latLng, {
                    radius: index === team.points.length - 1 ? 8 : 6,
                    color: '#1d1d1d',
                    weight: 2,
                    fillColor: team.colour,
                    fillOpacity: 1
                }).addTo(map);

                var popupHtml =
                    '<strong>' + escapeHtml(team.name) + '</strong><br>' +
                    escapeHtml(point.location_name) + '<br>' +
                    '<span>' + escapeHtml(point.checked_in_at) + '</span><br>' +
                    '<span>Leader: ' + escapeHtml(point.leader_name) + '</span>';

                if (point.public_note) {
                    popupHtml += '<br><br>' + escapeHtml(point.public_note);
                }

                marker.bindPopup(popupHtml);
            });

            if (latLngs.length >= 2) {
                L.polyline(latLngs, {
                    color: team.colour,
                    weight: 4,
                    opacity: 0.85,
                    dashArray: '8, 8'
                }).addTo(map);
            }
        });

        if (bounds.length > 0) {
            map.fitBounds(bounds, {
                padding: [30, 30]
            });
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>