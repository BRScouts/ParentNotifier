<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();
$error = '';

$teams = $pdo->query('SELECT * FROM teams ORDER BY name ASC')->fetchAll();

/**
 * Email queue helpers.
 *
 * The queue now stores plain text only.
 * Your cron job should wrap this content in the final HTML email template.
 */

function decode_json_items(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, static function ($item) {
        return is_string($item) && trim($item) !== '';
    }));
}

function get_team_parent_emails(PDO $pdo, int $teamId): array
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
        foreach (decode_json_items($row['parent_emails_json'] ?? null) as $email) {
            $email = trim((string)$email);

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[strtolower($email)] = $email;
            }
        }
    }

    return array_values($emails);
}

function build_location_email_content(
    string $teamName,
    string $locationName,
    string $feedBody,
    string $parentUrl
): string {
    return
        $teamName . " has a new location check-in.\n\n" .
        "Location: " . $locationName . "\n\n" .
        $feedBody . "\n\n" .
        "View the update here:\n" .
        $parentUrl . "\n\n" .
        "Please note: that the exact location of participants on the map is not directly shown but Leaders have confirmed their location for the evening. " .
        "For more information, please click the link below to see the update.";
}

function queue_email(
    PDO $pdo,
    string $toEmail,
    string $subject,
    string $content,
    ?int $teamId = null,
    ?int $postId = null,
    ?int $locationId = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO email_queue
            (to_email, subject, content, related_team_id, related_post_id, related_location_id)
         VALUES
            (?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $toEmail,
        $subject,
        $content,
        $teamId,
        $postId,
        $locationId,
    ]);
}

function queue_team_emails(
    PDO $pdo,
    int $teamId,
    string $subject,
    string $content,
    ?int $postId = null,
    ?int $locationId = null
): int {
    $emails = get_team_parent_emails($pdo, $teamId);
    $count = 0;

    foreach ($emails as $email) {
        queue_email(
            $pdo,
            $email,
            $subject,
            $content,
            $teamId,
            $postId,
            $locationId
        );

        $count++;
    }

    return $count;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teamId = (int)($_POST['team_id'] ?? 0);
    $locationName = trim($_POST['location_name'] ?? '');
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $publicNote = trim($_POST['public_note'] ?? '');
    $internalNote = trim($_POST['internal_note'] ?? '');
    $status = $_POST['status'] ?? 'checked_in';

    $allowedStatuses = [
        'not_started',
        'on_route',
        'checked_in',
        'resting',
        'delayed',
        'needs_follow_up',
        'completed',
    ];

    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'checked_in';
    }

    if ($teamId <= 0 || $locationName === '' || $latitude === '' || $longitude === '') {
        $error = 'Team, location name, latitude and longitude are required.';
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
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

            $locationId = (int)$pdo->lastInsertId();

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

            $stmt = $pdo->prepare('SELECT name, parent_token FROM teams WHERE id = ? LIMIT 1');
            $stmt->execute([$teamId]);
            $team = $stmt->fetch();

            $teamName = $team['name'] ?? 'Team';
            $parentToken = $team['parent_token'] ?? '';

            $feedBody = $publicNote !== ''
                ? $publicNote
                : $teamName . ' has checked in at ' . $locationName . '.';

            $stmt = $pdo->prepare(
                'INSERT INTO posts
                    (team_id, leader_id, title, body, post_type, visibility, is_pinned, is_published, published_at)
                 VALUES
                    (?, ?, ?, ?, ?, ?, 0, 1, NOW())'
            );

            $stmt->execute([
                $teamId,
                $user['id'],
                $teamName . ' checked in',
                $feedBody,
                'check_in',
                'team',
            ]);

            $postId = (int)$pdo->lastInsertId();

            $parentUrl = $parentToken !== ''
                ? url('dashboard.php?token=' . $parentToken . '#post-' . $postId)
                : url('dashboard.php#post-' . $postId);

            $emailSubject = $teamName . ' check-in update';

            $emailContent = build_location_email_content(
                $teamName,
                $locationName,
                $feedBody,
                $parentUrl
            );

            queue_team_emails(
                $pdo,
                $teamId,
                $emailSubject,
                $emailContent,
                $postId,
                $locationId
            );

            $pdo->commit();

            redirect('dashboard.php#post-' . $postId);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            $error = 'Could not save the location or queue the emails.';
        }
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

    .location-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 360px;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 980px) {
        .location-layout {
            grid-template-columns: 1fr;
        }
    }

    .location-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .location-panel h2 {
        margin-top: 0;
        font-weight: 900;
    }

    .location-panel label {
        font-weight: 800;
    }

    .map-search-row {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    @media (max-width: 700px) {
        .map-search-row {
            display: block;
        }

        .map-search-row button {
            margin-top: 0.5rem;
        }
    }

    #location-map {
        height: 520px;
        border: 2px solid #1d1d1d;
        background: #f3f2f1;
    }

    .search-results {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        margin-bottom: 1rem;
        display: none;
    }

    .search-result-button {
        display: block;
        width: 100%;
        border: 0;
        border-bottom: 1px solid #d8d8d8;
        background: #ffffff;
        padding: 0.75rem;
        text-align: left;
        cursor: pointer;
    }

    .search-result-button:hover,
    .search-result-button:focus {
        background: #f3f2f1;
        text-decoration: underline;
    }

    .location-note {
        border-left: 6px solid #1d70b8;
        background: #eef7ff;
        padding: 0.75rem;
        margin-bottom: 1rem;
        font-size: 0.95rem;
    }

    .muted {
        color: #505a5f;
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>Add team location</h1>
        <p class="lead">
            Search for a place in Finland or click the map to set the team’s approximate location.
        </p>
    </div>
</section>

<main id="main-content" class="container my-5">

    <p>
        <a href="<?= e(url('dashboard.php')) ?>">
            Back to dashboard
        </a>
    </p>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="location-note">
        <p class="mb-0">
            Use a parent-safe approximate location where appropriate. Saving this check-in will add a post to the team feed
            and queue a plain-text email notification for the parent contacts. The cron job will apply the final email template.
        </p>
    </div>

    <div class="location-layout">

        <div class="location-panel">
            <h2>Choose location</h2>

            <div class="map-search-row">
                <input
                    class="form-control"
                    id="map-search"
                    type="search"
                    placeholder="Search Finland, for example Helsinki, Tampere, Turku or a campsite"
                >
                <button class="btn btn-primary" type="button" id="map-search-button">
                    Search map
                </button>
            </div>

            <div id="search-results" class="search-results"></div>

            <div id="location-map"></div>
        </div>

        <aside class="location-panel">
            <h2>Save location</h2>

            <form method="post">
                <div class="form-group">
                    <label for="team_id">Team</label>
                    <select class="form-control" id="team_id" name="team_id" required>
                        <option value="">Choose team</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= (int)$team['id'] ?>">
                                <?= e($team['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="location_name">Location name</label>
                    <input
                        class="form-control"
                        id="location_name"
                        name="location_name"
                        placeholder="Example: Near Helsinki"
                        required
                    >
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="latitude">Latitude</label>
                        <input class="form-control" id="latitude" name="latitude" required>
                    </div>

                    <div class="form-group col-md-6">
                        <label for="longitude">Longitude</label>
                        <input class="form-control" id="longitude" name="longitude" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="status">Team status</label>
                    <select class="form-control" id="status" name="status">
                        <option value="checked_in">Checked in</option>
                        <option value="on_route">On route</option>
                        <option value="resting">Resting</option>
                        <option value="delayed">Delayed</option>
                        <option value="needs_follow_up">Needs leader follow-up</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="public_note">Feed note</label>
                    <textarea
                        class="form-control"
                        id="public_note"
                        name="public_note"
                        rows="4"
                        placeholder="Optional. If blank, a simple check-in post will still be added."
                    ></textarea>
                    <small class="form-text text-muted">
                        This is visible to parents for that team and will be included as plain text in the queued email.
                    </small>
                </div>

                <div class="form-group">
                    <label for="internal_note">Internal note</label>
                    <textarea class="form-control" id="internal_note" name="internal_note" rows="4"></textarea>
                    <small class="form-text text-muted">
                        Internal leader-only note. This is not emailed to parents.
                    </small>
                </div>

                <button class="btn btn-primary" type="submit">
                    Save location and queue email
                </button>
            </form>
        </aside>

    </div>

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

        /*
         * Finland default view.
         * Centre is approximately central Finland.
         */
        var finlandCentre = [64.9631, 25.5947];

        var map = L.map('location-map').setView(finlandCentre, 5);
        var marker = null;

        var locationNameInput = document.getElementById('location_name');
        var latitudeInput = document.getElementById('latitude');
        var longitudeInput = document.getElementById('longitude');
        var searchInput = document.getElementById('map-search');
        var searchButton = document.getElementById('map-search-button');
        var searchResults = document.getElementById('search-results');

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        function setPin(lat, lng, label) {
            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.marker([lat, lng], {
                    draggable: true
                }).addTo(map);

                marker.on('dragend', function () {
                    var pos = marker.getLatLng();
                    latitudeInput.value = pos.lat.toFixed(7);
                    longitudeInput.value = pos.lng.toFixed(7);
                });
            }

            marker.bindPopup(label || 'Selected location').openPopup();

            latitudeInput.value = Number(lat).toFixed(7);
            longitudeInput.value = Number(lng).toFixed(7);

            if (label && locationNameInput.value.trim() === '') {
                locationNameInput.value = label;
            }
        }

        map.on('click', function (event) {
            setPin(
                event.latlng.lat,
                event.latlng.lng,
                locationNameInput.value || 'Selected location'
            );
        });

        function renderSearchResults(results) {
            searchResults.innerHTML = '';

            if (!results.length) {
                searchResults.style.display = 'block';
                searchResults.innerHTML = '<div class="p-3">No places found in Finland.</div>';
                return;
            }

            results.slice(0, 8).forEach(function (result) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'search-result-button';
                button.textContent = result.display_name;

                button.addEventListener('click', function () {
                    var lat = parseFloat(result.lat);
                    var lng = parseFloat(result.lon);

                    locationNameInput.value = result.display_name;
                    setPin(lat, lng, result.display_name);
                    map.setView([lat, lng], 13);

                    searchResults.style.display = 'none';
                    searchResults.innerHTML = '';
                });

                searchResults.appendChild(button);
            });

            searchResults.style.display = 'block';
        }

        function searchMap() {
            var query = searchInput.value.trim();

            if (query === '') {
                return;
            }

            searchButton.disabled = true;
            searchButton.textContent = 'Searching...';

            /*
             * Restrict Nominatim search to Finland.
             */
            var url =
                'https://nominatim.openstreetmap.org/search' +
                '?format=json' +
                '&limit=8' +
                '&countrycodes=fi' +
                '&q=' + encodeURIComponent(query);

            fetch(url, {
                headers: {
                    'Accept': 'application/json'
                }
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (results) {
                    renderSearchResults(results);
                })
                .catch(function () {
                    searchResults.style.display = 'block';
                    searchResults.innerHTML = '<div class="p-3">Search failed. Try again or click the map manually.</div>';
                })
                .finally(function () {
                    searchButton.disabled = false;
                    searchButton.textContent = 'Search map';
                });
        }

        searchButton.addEventListener('click', searchMap);

        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchMap();
            }
        });
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>