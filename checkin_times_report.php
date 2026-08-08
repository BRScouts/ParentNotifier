<?php
/**
 * Check-in Times Report
 *
 * Shows explorer check-in times vs the 7pm (19:00) deadline each day,
 * whether they were on time or late, minutes missed, and per-team leaderboard.
 *
 * Cut-off: check-ins up to 03:00 the next day count towards the previous day's deadline.
 */
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

const CHECKIN_DEADLINE_HOUR = 19; // 7pm
const CHECKIN_CUTOFF_HOUR = 3;   // 3am next day — anything before this still counts for previous day
const CHECKIN_TIMEZONE = 'Europe/Helsinki';

/**
 * Fetch all check-ins with team info.
 */
$checkins = [];
$teams = [];

try {
    $teams = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC')->fetchAll();

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
 * Determine the "check-in day" for a given datetime.
 * If the time is between midnight and 03:00, it belongs to the previous calendar day.
 */
function checkin_day(DateTime $dt): string
{
    $hour = (int)$dt->format('G');
    if ($hour < CHECKIN_CUTOFF_HOUR) {
        // Before 3am — belongs to previous day
        $day = clone $dt;
        $day->modify('-1 day');
        return $day->format('Y-m-d');
    }
    return $dt->format('Y-m-d');
}

/**
 * Process each check-in to determine on-time/late status.
 */
$processedCheckins = [];
$teamStats = []; // team_id => [on_time, late, total_late_minutes, total_diff_minutes, checkins_count, dates]

foreach ($checkins as $checkin) {
    $teamId = (int)$checkin['team_id'];

    if (!isset($teamStats[$teamId])) {
        $teamStats[$teamId] = [
            'team_name' => $checkin['team_name'],
            'on_time' => 0,
            'late' => 0,
            'total_late_minutes' => 0,
            'total_diff_minutes' => 0, // signed: negative = early, positive = late
            'checkins_count' => 0,
            'dates' => [],
        ];
    }

    // Parse submitted_at in the event timezone
    $submittedDt = new DateTime($checkin['submitted_at'], new DateTimeZone('UTC'));
    $submittedDt->setTimezone($tz);

    $checkinDate = checkin_day($submittedDt);

    // Skip duplicate check-ins for same team on same day (keep first)
    if (in_array($checkinDate, $teamStats[$teamId]['dates'], true)) {
        continue;
    }
    $teamStats[$teamId]['dates'][] = $checkinDate;
    $teamStats[$teamId]['checkins_count']++;

    // The deadline is 19:00 on the check-in day
    $deadlineDt = new DateTime($checkinDate . ' ' . CHECKIN_DEADLINE_HOUR . ':00:00', $tz);

    // Calculate difference in minutes (positive = late, negative = early)
    $diffSeconds = $submittedDt->getTimestamp() - $deadlineDt->getTimestamp();
    $diffMinutes = (int)round($diffSeconds / 60);

    $isOnTime = $diffMinutes <= 0;
    $minutesLate = $isOnTime ? 0 : $diffMinutes;

    $teamStats[$teamId]['total_diff_minutes'] += $diffMinutes;

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
        'checkin_day' => $checkinDate,
        'time' => $submittedDt->format('H:i'),
        'is_on_time' => $isOnTime,
        'minutes_late' => $minutesLate,
        'minutes_early' => $isOnTime ? abs($diffMinutes) : 0,
        'diff_minutes' => $diffMinutes,
        'status' => $checkin['status'],
    ];
}

// Sort processed check-ins by date descending
usort($processedCheckins, function ($a, $b) {
    return strcmp($b['checkin_day'], $a['checkin_day']) ?: strcmp($a['team_name'], $b['team_name']);
});

/**
 * Build the leaderboard — sorted by average minutes from deadline (lower = more punctual).
 * Average is signed: negative means on average they checked in early.
 */
$leaderboard = [];
foreach ($teamStats as $tId => $stats) {
    $avgDiff = $stats['checkins_count'] > 0 ? round($stats['total_diff_minutes'] / $stats['checkins_count'], 1) : 0;
    $pct = $stats['checkins_count'] > 0 ? round(($stats['on_time'] / $stats['checkins_count']) * 100, 1) : 0;
    $avgLate = $stats['late'] > 0 ? round($stats['total_late_minutes'] / $stats['late']) : 0;

    $leaderboard[] = [
        'team_id' => $tId,
        'team_name' => $stats['team_name'],
        'checkins_count' => $stats['checkins_count'],
        'on_time' => $stats['on_time'],
        'late' => $stats['late'],
        'pct_on_time' => $pct,
        'total_late_minutes' => $stats['total_late_minutes'],
        'avg_late_minutes' => $avgLate,
        'avg_diff_minutes' => $avgDiff, // negative = early on average
    ];
}

// Sort leaderboard: best average (most negative / least positive) first
usort($leaderboard, function ($a, $b) {
    return $a['avg_diff_minutes'] <=> $b['avg_diff_minutes'];
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

    .leaderboard-rank {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        font-weight: 900;
        font-size: 0.85rem;
        border-radius: 50%;
        color: #fff;
    }
    .rank-1 { background: #d4af37; }
    .rank-2 { background: #a0a0a0; }
    .rank-3 { background: #cd7f32; }
    .rank-default { background: #505a5f; }

    .avg-badge {
        display: inline-block;
        padding: 0.25rem 0.65rem;
        font-size: 0.82rem;
        font-weight: 800;
        border-radius: 3px;
    }
    .avg-early { background: #e8f5e9; color: #00703c; }
    .avg-late { background: #fde8e4; color: #d4351c; }

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

    <!-- Leaderboard -->
    <div class="report-panel">
        <h2>Punctuality Leaderboard</h2>
        <p style="color:#505a5f; font-size:0.9rem; margin-bottom:1rem;">Ranked by average check-in time relative to the 19:00 deadline. Negative = early on average.</p>
        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Team</th>
                        <th>Check-ins</th>
                        <th>On Time</th>
                        <th>Late</th>
                        <th>% On Time</th>
                        <th>Avg from Deadline</th>
                        <th>Total Mins Late</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaderboard)): ?>
                        <tr><td colspan="8" style="text-align:center; color:#505a5f; padding:2rem;">No check-in data available yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($leaderboard as $rank => $row): ?>
                            <?php
                                $position = $rank + 1;
                                $rankClass = match($position) { 1 => 'rank-1', 2 => 'rank-2', 3 => 'rank-3', default => 'rank-default' };
                                $barClass = $row['pct_on_time'] >= 80 ? 'pct-bar-good' : ($row['pct_on_time'] >= 50 ? 'pct-bar-warn' : 'pct-bar-bad');
                                $avgClass = $row['avg_diff_minutes'] <= 0 ? 'avg-early' : 'avg-late';
                                $avgLabel = $row['avg_diff_minutes'] <= 0
                                    ? abs($row['avg_diff_minutes']) . ' min early'
                                    : '+' . $row['avg_diff_minutes'] . ' min late';
                            ?>
                            <tr>
                                <td><span class="leaderboard-rank <?= $rankClass ?>"><?= $position ?></span></td>
                                <td>
                                    <a href="?team_id=<?= $row['team_id'] ?>" style="font-weight:700; color:#1d70b8; text-decoration:none;">
                                        <?= e($row['team_name']) ?>
                                    </a>
                                </td>
                                <td><?= $row['checkins_count'] ?></td>
                                <td style="color:#00703c; font-weight:700;"><?= $row['on_time'] ?></td>
                                <td style="color:#d4351c; font-weight:700;"><?= $row['late'] ?></td>
                                <td>
                                    <strong><?= $row['pct_on_time'] ?>%</strong>
                                    <div class="pct-bar"><div class="pct-bar-fill <?= $barClass ?>" style="width:<?= $row['pct_on_time'] ?>%"></div></div>
                                </td>
                                <td><span class="avg-badge <?= $avgClass ?>"><?= $avgLabel ?></span></td>
                                <td><?= $row['total_late_minutes'] > 0 ? number_format($row['total_late_minutes']) . ' min' : '—' ?></td>
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
            Daily Check-ins
            <?php if ($filterTeamId !== null): ?>
                <?php
                    $filterTeamName = '';
                    foreach ($leaderboard as $lb) {
                        if ($lb['team_id'] === $filterTeamId) { $filterTeamName = $lb['team_name']; break; }
                    }
                ?>
                — <?= e($filterTeamName) ?>
            <?php endif; ?>
        </h2>
        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Team</th>
                        <th>Day</th>
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
