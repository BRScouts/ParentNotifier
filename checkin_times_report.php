<?php
/**
 * Check-in Times Report
 *
 * Shows explorer check-in times vs the 7pm (19:00) deadline each day,
 * whether they were on time or late, minutes missed, and per-team summary.
 */
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

const CHECKIN_DEADLINE_HOUR = 19; // 7pm
const CHECKIN_TIMEZONE = 'Europe/Helsinki';

/**
 * Fetch all reviewed/approved check-ins with team info.
 * We use submitted_at as the check-in time (when the explorer actually checked in).
 */
$checkins = [];
$teams = [];
$teamSummary = [];

try {
    // Get all teams
    $teams = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC')->fetchAll();

    // Get all check-ins that have been reviewed (status = reviewed or approved)
    $stmt = $pdo->query(
        'SELECT
            ec.id,
            ec.team_id,
            ec.submitted_at,
            ec.submitted_by,
            ec.location_name,
            ec.status,
            ec.miles_covered,
            t.name AS team_name
         FROM explorer_checkins ec
         JOIN teams t ON t.id = ec.team_id
         WHERE ec.status IN ("reviewed", "approved", "pending")
         ORDER BY ec.submitted_at ASC'
    );
    $checkins = $stmt->fetchAll();
} catch (Throwable $e) {
    $checkins = [];
    $teams = [];
}

$tz = new DateTimeZone(CHECKIN_TIMEZONE);

/**
 * Process each check-in to determine on-time/late status.
 */
$processedCheckins = [];
$teamStats = []; // team_id => [on_time, late, total_late_minutes, checkins_count, dates]

foreach ($checkins as $checkin) {
    $teamId = (int)$checkin['team_id'];

    if (!isset($teamStats[$teamId])) {
        $teamStats[$teamId] = [
            'team_name' => $checkin['team_name'],
            'on_time' => 0,
            'late' => 0,
            'total_late_minutes' => 0,
            'checkins_count' => 0,
            'dates' => [],
        ];
    }

    // Parse submitted_at in the event timezone
    $submittedDt = new DateTime($checkin['submitted_at'], new DateTimeZone('UTC'));
    $submittedDt->setTimezone($tz);

    $checkinDate = $submittedDt->format('Y-m-d');

    // Skip if we already have a check-in for this team on this date (use the first one)
    if (in_array($checkinDate, $teamStats[$teamId]['dates'], true)) {
        continue;
    }
    $teamStats[$teamId]['dates'][] = $checkinDate;
    $teamStats[$teamId]['checkins_count']++;

    // The deadline is 19:00 on the same day in Finland time
    $deadlineDt = new DateTime($checkinDate . ' ' . CHECKIN_DEADLINE_HOUR . ':00:00', $tz);

    // Calculate difference in minutes
    $diffSeconds = $submittedDt->getTimestamp() - $deadlineDt->getTimestamp();
    $diffMinutes = (int)round($diffSeconds / 60);

    $isOnTime = $diffMinutes <= 0;
    $minutesLate = $isOnTime ? 0 : $diffMinutes;

    if ($isOnTime) {
        $teamStats[$teamId]['on_time']++;
    } else {
        $teamStats[$teamId]['late']++;
        $teamStats[$teamId]['total_late_minutes'] += $minutesLate;
    }

    $processedCheckins[] = [
        'id' => $checkin['id'],
        'team_id' => $teamId,
        'team_name' => $checkin['team_name'],
        'submitted_by' => $checkin['submitted_by'],
        'location_name' => $checkin['location_name'],
        'submitted_at' => $submittedDt->format('d M Y, H:i'),
        'date' => $submittedDt->format('D d M'),
        'time' => $submittedDt->format('H:i'),
        'is_on_time' => $isOnTime,
        'minutes_late' => $minutesLate,
        'minutes_early' => $isOnTime ? abs($diffMinutes) : 0,
        'status' => $checkin['status'],
    ];
}

// Sort processed check-ins by date descending
usort($processedCheckins, function ($a, $b) {
    return strcmp($b['submitted_at'], $a['submitted_at']);
});

// Sort team stats by on-time percentage descending
uasort($teamStats, function ($a, $b) {
    $pctA = $a['checkins_count'] > 0 ? ($a['on_time'] / $a['checkins_count']) : 0;
    $pctB = $b['checkins_count'] > 0 ? ($b['on_time'] / $b['checkins_count']) : 0;
    return $pctB <=> $pctA;
});

// Filter by team if requested
$filterTeamId = isset($_GET['team_id']) && $_GET['team_id'] !== '' ? (int)$_GET['team_id'] : null;

$filteredCheckins = $processedCheckins;
if ($filterTeamId !== null) {
    $filteredCheckins = array_filter($processedCheckins, fn($c) => $c['team_id'] === $filterTeamId);
    $filteredCheckins = array_values($filteredCheckins);
}

// Overall stats
$totalCheckins = array_sum(array_map(fn($t) => $t['checkins_count'], $teamStats));
$totalOnTime = array_sum(array_map(fn($t) => $t['on_time'], $teamStats));
$totalLate = array_sum(array_map(fn($t) => $t['late'], $teamStats));
$totalLateMinutes = array_sum(array_map(fn($t) => $t['total_late_minutes'], $teamStats));
$overallOnTimePct = $totalCheckins > 0 ? round(($totalOnTime / $totalCheckins) * 100, 1) : 0;

include __DIR__ . '/header.php';
?>

<style>
    .report-shell { max-width: 1280px; }

    .report-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
        margin-bottom: 2rem;
    }
    @media (max-width: 900px) {
        .report-summary-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 500px) {
        .report-summary-grid { grid-template-columns: 1fr; }
    }

    .report-stat-card {
        border: 2px solid #d8d8d8;
        background: #fff;
        padding: 1.25rem;
    }
    .report-stat-card h3 {
        font-size: 0.85rem;
        color: #505a5f;
        font-weight: 900;
        margin: 0 0 0.4rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .report-stat-value {
        font-size: 2rem;
        font-weight: 900;
        line-height: 1.1;
        margin: 0;
    }
    .report-stat-sub {
        color: #505a5f;
        font-size: 0.9rem;
        margin: 0.3rem 0 0;
    }

    .report-panel {
        border: 2px solid #d8d8d8;
        background: #fff;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }
    .report-panel h2 {
        margin-top: 0;
        font-weight: 900;
        font-size: 1.2rem;
    }

    .report-table-wrap { overflow-x: auto; }
    .report-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
    }
    .report-table th,
    .report-table td {
        padding: 0.6rem 0.75rem;
        border-bottom: 1px solid #e8e8e8;
        text-align: left;
        white-space: nowrap;
    }
    .report-table th {
        font-weight: 900;
        background: #f8f8f8;
        border-bottom: 2px solid #d8d8d8;
    }
    .report-table tbody tr:hover { background: #f9f9f9; }

    .badge-ontime {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        font-size: 0.78rem;
        font-weight: 800;
        border-radius: 3px;
        background: #00703c;
        color: #fff;
    }
    .badge-late {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        font-size: 0.78rem;
        font-weight: 800;
        border-radius: 3px;
        background: #d4351c;
        color: #fff;
    }
    .badge-early {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        font-size: 0.78rem;
        font-weight: 700;
        border-radius: 3px;
        background: #e8f5e9;
        color: #00703c;
    }
    .badge-late-mins {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        font-size: 0.78rem;
        font-weight: 700;
        border-radius: 3px;
        background: #fde8e4;
        color: #d4351c;
    }

    .pct-bar {
        width: 100%;
        height: 8px;
        background: #eee;
        border-radius: 4px;
        overflow: hidden;
        margin-top: 0.3rem;
    }
    .pct-bar-fill {
        height: 100%;
        border-radius: 4px;
        transition: width 0.3s ease;
    }
    .pct-bar-good { background: #00703c; }
    .pct-bar-warn { background: #f47738; }
    .pct-bar-bad { background: #d4351c; }

    .filter-form {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }
    .filter-form label { font-weight: 800; margin: 0; }
    .filter-form select {
        border: 2px solid #d8d8d8;
        padding: 0.4rem 0.75rem;
        font-size: 0.9rem;
        font-weight: 600;
    }
</style>

<div class="page-hero">
    <div class="container">
        <h1>Check-in Times Report</h1>
        <p class="lead">Explorer check-in punctuality vs the 19:00 (<?= e(CHECKIN_TIMEZONE) ?>) deadline</p>
    </div>
</div>

<div class="container report-shell">

    <!-- Summary Cards -->
    <div class="report-summary-grid">
        <div class="report-stat-card">
            <h3>Total Check-ins</h3>
            <p class="report-stat-value"><?= $totalCheckins ?></p>
            <p class="report-stat-sub">Across all teams</p>
        </div>
        <div class="report-stat-card">
            <h3>On Time</h3>
            <p class="report-stat-value" style="color: #00703c;"><?= $totalOnTime ?></p>
            <p class="report-stat-sub"><?= $overallOnTimePct ?>% on time</p>
        </div>
        <div class="report-stat-card">
            <h3>Late</h3>
            <p class="report-stat-value" style="color: #d4351c;"><?= $totalLate ?></p>
            <p class="report-stat-sub"><?= $totalCheckins > 0 ? round(($totalLate / $totalCheckins) * 100, 1) : 0 ?>% late</p>
        </div>
        <div class="report-stat-card">
            <h3>Total Minutes Late</h3>
            <p class="report-stat-value"><?= number_format($totalLateMinutes) ?></p>
            <p class="report-stat-sub">Avg <?= $totalLate > 0 ? round($totalLateMinutes / $totalLate) : 0 ?> min per late check-in</p>
        </div>
    </div>

    <!-- Team Summary Table -->
    <div class="report-panel">
        <h2>Team Summary</h2>
        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Team</th>
                        <th>Check-ins</th>
                        <th>On Time</th>
                        <th>Late</th>
                        <th>% On Time</th>
                        <th>Total Mins Late</th>
                        <th>Avg Mins Late</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($teamStats)): ?>
                        <tr><td colspan="7" style="text-align:center; color:#505a5f; padding:2rem;">No check-in data available yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($teamStats as $tId => $stats): ?>
                            <?php
                                $pct = $stats['checkins_count'] > 0 ? round(($stats['on_time'] / $stats['checkins_count']) * 100, 1) : 0;
                                $avgLate = $stats['late'] > 0 ? round($stats['total_late_minutes'] / $stats['late']) : 0;
                                $barClass = $pct >= 80 ? 'pct-bar-good' : ($pct >= 50 ? 'pct-bar-warn' : 'pct-bar-bad');
                            ?>
                            <tr>
                                <td>
                                    <a href="?team_id=<?= $tId ?>" style="font-weight:700; color:#1d70b8; text-decoration:none;">
                                        <?= e($stats['team_name']) ?>
                                    </a>
                                </td>
                                <td><?= $stats['checkins_count'] ?></td>
                                <td style="color:#00703c; font-weight:700;"><?= $stats['on_time'] ?></td>
                                <td style="color:#d4351c; font-weight:700;"><?= $stats['late'] ?></td>
                                <td>
                                    <strong><?= $pct ?>%</strong>
                                    <div class="pct-bar"><div class="pct-bar-fill <?= $barClass ?>" style="width:<?= $pct ?>%"></div></div>
                                </td>
                                <td><?= $stats['total_late_minutes'] > 0 ? number_format($stats['total_late_minutes']) . ' min' : '—' ?></td>
                                <td><?= $avgLate > 0 ? $avgLate . ' min' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Filter -->
    <form class="filter-form" method="get" action="">
        <label for="team_filter">Filter by team:</label>
        <select name="team_id" id="team_filter" onchange="this.form.submit()">
            <option value="">All Teams</option>
            <?php foreach ($teams as $team): ?>
                <option value="<?= (int)$team['id'] ?>" <?= $filterTeamId === (int)$team['id'] ? 'selected' : '' ?>>
                    <?= e($team['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($filterTeamId !== null): ?>
            <a href="<?= e(url('checkin_times_report.php')) ?>" style="font-size:0.85rem; font-weight:700;">Clear filter</a>
        <?php endif; ?>
    </form>

    <!-- Detailed Check-ins Table -->
    <div class="report-panel">
        <h2>
            Detailed Check-ins
            <?php if ($filterTeamId !== null && isset($teamStats[$filterTeamId])): ?>
                — <?= e($teamStats[$filterTeamId]['team_name']) ?>
            <?php endif; ?>
        </h2>
        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Team</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Submitted By</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Difference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filteredCheckins)): ?>
                        <tr><td colspan="7" style="text-align:center; color:#505a5f; padding:2rem;">No check-ins found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($filteredCheckins as $ci): ?>
                            <tr>
                                <td style="font-weight:700;"><?= e($ci['team_name']) ?></td>
                                <td><?= e($ci['date']) ?></td>
                                <td style="font-weight:700;"><?= e($ci['time']) ?></td>
                                <td><?= e($ci['submitted_by'] ?: '—') ?></td>
                                <td><?= e($ci['location_name'] ?: '—') ?></td>
                                <td>
                                    <?php if ($ci['is_on_time']): ?>
                                        <span class="badge-ontime">On Time</span>
                                    <?php else: ?>
                                        <span class="badge-late">Late</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($ci['is_on_time']): ?>
                                        <?php if ($ci['minutes_early'] > 0): ?>
                                            <span class="badge-early"><?= $ci['minutes_early'] ?> min early</span>
                                        <?php else: ?>
                                            <span class="badge-early">Exactly on time</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge-late-mins">+<?= $ci['minutes_late'] ?> min late</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php include __DIR__ . '/footer.php'; ?>
