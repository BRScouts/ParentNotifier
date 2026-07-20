<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

/**
 * Parent Engagement Analytics Dashboard
 *
 * Shows leaders how parents are interacting with the portal:
 * - Total visits over time (daily chart)
 * - Unique visitors per day (by IP hash)
 * - Per-team breakdown
 * - Time from check-in submission to first parent portal view
 * - Peak viewing times
 */

const ENGAGEMENT_DEFAULT_DAYS = 14;

// --- Date range ---
$daysBack = max(1, min(90, (int)($_GET['days'] ?? ENGAGEMENT_DEFAULT_DAYS)));
$tz = new DateTimeZone(defined('DEFAULT_TIMEZONE') ? DEFAULT_TIMEZONE : 'Europe/London');
$now = new DateTime('now', $tz);
$startDate = (clone $now)->modify("-{$daysBack} days")->format('Y-m-d 00:00:00');
$endDate = $now->format('Y-m-d 23:59:59');

// --- Check if the table exists ---
function engagement_table_exists(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = "parent_portal_visits"'
        );
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$tableExists = engagement_table_exists($pdo);

// --- Aggregate data ---
$totalVisits = 0;
$uniqueVisitors = 0;
$dailyVisits = [];
$teamBreakdown = [];
$peakHours = [];
$checkinToViewTimes = [];

if ($tableExists) {
    // Total visits in range
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM parent_portal_visits
             WHERE visited_at BETWEEN ? AND ?'
        );
        $stmt->execute([$startDate, $endDate]);
        $totalVisits = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $totalVisits = 0;
    }

    // Unique visitors (distinct ip_hash per day)
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT ip_hash) FROM parent_portal_visits
             WHERE visited_at BETWEEN ? AND ?
               AND ip_hash IS NOT NULL'
        );
        $stmt->execute([$startDate, $endDate]);
        $uniqueVisitors = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $uniqueVisitors = 0;
    }

    // Daily visit counts
    try {
        $stmt = $pdo->prepare(
            'SELECT DATE(visited_at) AS visit_date,
                    COUNT(*) AS total_views,
                    COUNT(DISTINCT ip_hash) AS unique_visitors
             FROM parent_portal_visits
             WHERE visited_at BETWEEN ? AND ?
             GROUP BY DATE(visited_at)
             ORDER BY visit_date ASC'
        );
        $stmt->execute([$startDate, $endDate]);
        $dailyVisits = $stmt->fetchAll();
    } catch (Throwable $e) {
        $dailyVisits = [];
    }

    // Per-team breakdown
    try {
        $stmt = $pdo->prepare(
            'SELECT t.name AS team_name,
                    ppv.team_id,
                    COUNT(*) AS total_views,
                    COUNT(DISTINCT ppv.ip_hash) AS unique_visitors,
                    MAX(ppv.visited_at) AS last_visit
             FROM parent_portal_visits ppv
             INNER JOIN teams t ON t.id = ppv.team_id
             WHERE ppv.visited_at BETWEEN ? AND ?
             GROUP BY ppv.team_id, t.name
             ORDER BY total_views DESC'
        );
        $stmt->execute([$startDate, $endDate]);
        $teamBreakdown = $stmt->fetchAll();
    } catch (Throwable $e) {
        $teamBreakdown = [];
    }

    // Peak hours (hour of day distribution)
    try {
        $stmt = $pdo->prepare(
            'SELECT HOUR(visited_at) AS visit_hour,
                    COUNT(*) AS visit_count
             FROM parent_portal_visits
             WHERE visited_at BETWEEN ? AND ?
             GROUP BY HOUR(visited_at)
             ORDER BY visit_hour ASC'
        );
        $stmt->execute([$startDate, $endDate]);
        $peakHours = $stmt->fetchAll();
    } catch (Throwable $e) {
        $peakHours = [];
    }

    // Average time from check-in submission to first parent view
    try {
        $stmt = $pdo->prepare(
            'SELECT ec.team_id,
                    t.name AS team_name,
                    ec.submitted_at,
                    MIN(ppv.visited_at) AS first_parent_view,
                    TIMESTAMPDIFF(MINUTE, ec.submitted_at, MIN(ppv.visited_at)) AS minutes_to_view
             FROM explorer_checkins ec
             INNER JOIN teams t ON t.id = ec.team_id
             INNER JOIN parent_portal_visits ppv
                ON ppv.team_id = ec.team_id
                AND ppv.visited_at > ec.submitted_at
                AND ppv.visited_at < DATE_ADD(ec.submitted_at, INTERVAL 24 HOUR)
             WHERE ec.submitted_at BETWEEN ? AND ?
             GROUP BY ec.id, ec.team_id, t.name, ec.submitted_at
             ORDER BY ec.submitted_at DESC
             LIMIT 50'
        );
        $stmt->execute([$startDate, $endDate]);
        $checkinToViewTimes = $stmt->fetchAll();
    } catch (Throwable $e) {
        $checkinToViewTimes = [];
    }
}

// Calculate average response time
$avgMinutesToView = 0;
if (!empty($checkinToViewTimes)) {
    $totalMinutes = array_sum(array_column($checkinToViewTimes, 'minutes_to_view'));
    $avgMinutesToView = (int)round($totalMinutes / count($checkinToViewTimes));
}

// Prepare chart data
$chartLabels = [];
$chartViews = [];
$chartUnique = [];

// Fill in all days (including zero-visit days)
$fillDate = new DateTime($startDate, $tz);
$endDateObj = new DateTime($endDate, $tz);
$dailyMap = [];
foreach ($dailyVisits as $row) {
    $dailyMap[$row['visit_date']] = $row;
}

while ($fillDate <= $endDateObj) {
    $dateStr = $fillDate->format('Y-m-d');
    $chartLabels[] = $fillDate->format('d M');
    $chartViews[] = (int)($dailyMap[$dateStr]['total_views'] ?? 0);
    $chartUnique[] = (int)($dailyMap[$dateStr]['unique_visitors'] ?? 0);
    $fillDate->modify('+1 day');
}

// Peak hours chart (fill all 24 hours)
$hourLabels = [];
$hourCounts = [];
$peakMap = [];
foreach ($peakHours as $row) {
    $peakMap[(int)$row['visit_hour']] = (int)$row['visit_count'];
}
for ($h = 0; $h < 24; $h++) {
    $hourLabels[] = str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00';
    $hourCounts[] = $peakMap[$h] ?? 0;
}

include __DIR__ . '/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>Parent Engagement Analytics</h1>
        <p class="text-muted">How parents are interacting with the portal over the last <?= $daysBack ?> days.</p>
    </div>

    <!-- Date range filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="form-inline" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                <label for="days"><strong>Time range:</strong></label>
                <select name="days" id="days" class="form-control" style="width: auto;" onchange="this.form.submit()">
                    <option value="7" <?= $daysBack === 7 ? 'selected' : '' ?>>Last 7 days</option>
                    <option value="14" <?= $daysBack === 14 ? 'selected' : '' ?>>Last 14 days</option>
                    <option value="30" <?= $daysBack === 30 ? 'selected' : '' ?>>Last 30 days</option>
                    <option value="60" <?= $daysBack === 60 ? 'selected' : '' ?>>Last 60 days</option>
                    <option value="90" <?= $daysBack === 90 ? 'selected' : '' ?>>Last 90 days</option>
                </select>
            </form>
        </div>
    </div>

    <?php if (!$tableExists): ?>
        <div class="alert alert-warning">
            <strong>No data yet.</strong> The parent portal visits table has not been created. 
            Run the <code>database_migration_push_analytics.sql</code> migration or wait for the first parent portal visit.
        </div>
    <?php else: ?>

    <!-- Summary cards -->
    <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size: 2.5rem; font-weight: 900; color: #7413dc;"><?= number_format($totalVisits) ?></div>
                <div class="text-muted">Total Page Views</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size: 2.5rem; font-weight: 900; color: #7413dc;"><?= number_format($uniqueVisitors) ?></div>
                <div class="text-muted">Unique Visitors</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size: 2.5rem; font-weight: 900; color: #7413dc;"><?= count($teamBreakdown) ?></div>
                <div class="text-muted">Teams with Views</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size: 2.5rem; font-weight: 900; color: #7413dc;">
                    <?php if ($avgMinutesToView > 0): ?>
                        <?= $avgMinutesToView < 60 ? $avgMinutesToView . ' min' : round($avgMinutesToView / 60, 1) . ' hr' ?>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </div>
                <div class="text-muted">Avg. Time to View After Check-in</div>
            </div>
        </div>
    </div>

    <!-- Daily visits chart -->
    <div class="card mb-4">
        <div class="card-body">
            <h3 style="margin-top: 0;">Daily Portal Views</h3>
            <canvas id="dailyChart" height="80"></canvas>
        </div>
    </div>

    <!-- Peak hours chart -->
    <div class="card mb-4">
        <div class="card-body">
            <h3 style="margin-top: 0;">Viewing Times (Hour of Day)</h3>
            <canvas id="hourChart" height="60"></canvas>
        </div>
    </div>

    <!-- Per-team breakdown -->
    <div class="card mb-4">
        <div class="card-body">
            <h3 style="margin-top: 0;">Engagement by Team</h3>
            <?php if (empty($teamBreakdown)): ?>
                <p class="text-muted">No team visits recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th>Total Views</th>
                                <th>Unique Visitors</th>
                                <th>Last Visit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teamBreakdown as $row): ?>
                                <tr>
                                    <td><strong><?= e($row['team_name']) ?></strong></td>
                                    <td><?= number_format((int)$row['total_views']) ?></td>
                                    <td><?= number_format((int)$row['unique_visitors']) ?></td>
                                    <td><?= format_datetime($row['last_visit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Check-in to view response times -->
    <div class="card mb-4">
        <div class="card-body">
            <h3 style="margin-top: 0;">Check-in to Parent View (Response Times)</h3>
            <p class="text-muted">How quickly parents view the portal after a team submits a check-in.</p>
            <?php if (empty($checkinToViewTimes)): ?>
                <p class="text-muted">No matched data yet. This requires both check-ins and portal visits.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th>Check-in Time</th>
                                <th>First Parent View</th>
                                <th>Response Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checkinToViewTimes as $row): ?>
                                <tr>
                                    <td><?= e($row['team_name']) ?></td>
                                    <td><?= format_datetime($row['submitted_at']) ?></td>
                                    <td><?= format_datetime($row['first_parent_view']) ?></td>
                                    <td>
                                        <?php
                                        $mins = (int)$row['minutes_to_view'];
                                        if ($mins < 60) {
                                            echo $mins . ' min';
                                        } else {
                                            echo round($mins / 60, 1) . ' hr';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
</div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
(function () {
    var chartLabels = <?= json_encode($chartLabels) ?>;
    var chartViews = <?= json_encode($chartViews) ?>;
    var chartUnique = <?= json_encode($chartUnique) ?>;
    var hourLabels = <?= json_encode($hourLabels) ?>;
    var hourCounts = <?= json_encode($hourCounts) ?>;

    // Daily visits line chart
    var dailyCtx = document.getElementById('dailyChart');
    if (dailyCtx) {
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [
                    {
                        label: 'Total Views',
                        data: chartViews,
                        borderColor: '#7413dc',
                        backgroundColor: 'rgba(116, 19, 220, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                    },
                    {
                        label: 'Unique Visitors',
                        data: chartUnique,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }

    // Peak hours bar chart
    var hourCtx = document.getElementById('hourChart');
    if (hourCtx) {
        new Chart(hourCtx, {
            type: 'bar',
            data: {
                labels: hourLabels,
                datasets: [{
                    label: 'Views',
                    data: hourCounts,
                    backgroundColor: 'rgba(116, 19, 220, 0.6)',
                    borderColor: '#7413dc',
                    borderWidth: 1,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
