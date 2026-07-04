<?php
declare(strict_types=1);

/**
 * Cron: Team Status Cycle
 * 
 * Run every minute via cPanel cron.
 * All times are evaluated in Europe/Helsinki timezone.
 *
 * Logic:
 * 1. Only active between 29 July 2026 and 7 August 2026 (inclusive, Helsinki time).
 * 2. If a team's latest check-in has been reviewed by a leader AND the team status
 *    is currently "checked_in", set the team status to "resting".
 * 3. At 7:00 AM Helsinki time, if a team is "resting", set their status to "on_route"
 *    so they know to submit a new check-in for the day.
 * 4. If a check-in is still "pending" (awaiting leader review), do NOT touch the
 *    team status — parents can see the status and it should remain accurate.
 *
 * cPanel entry (every minute):
 *   * * * * * /usr/local/bin/php /path/to/cron_team_status_cycle.php >> /dev/null 2>&1
 */

require_once __DIR__ . '/config.php';

// --- Configuration ---
const CYCLE_TIMEZONE       = 'Europe/Helsinki';
const CYCLE_START_DATE     = '2026-07-29';
const CYCLE_END_DATE       = '2026-08-07';
const CYCLE_MORNING_HOUR   = 7; // 7:00 AM Helsinki time to flip resting → on_route

// --- Timezone setup ---
$tz  = new DateTimeZone(CYCLE_TIMEZONE);
$now = new DateTime('now', $tz);

$startDate = new DateTime(CYCLE_START_DATE . ' 00:00:00', $tz);
$endDate   = new DateTime(CYCLE_END_DATE . ' 23:59:59', $tz);

// Only run within the active date range
if ($now < $startDate || $now > $endDate) {
    exit(0);
}

$pdo = db();

$currentHour   = (int)$now->format('G');
$currentMinute = (int)$now->format('i');

// --- Step 1: Teams with a reviewed check-in whose status is still "checked_in" → "resting" ---
// This handles the transition after a leader reviews a submission.
// The leader review sets the team to "checked_in" and the explorer_checkins row to "reviewed".
// We then move them to "resting" so parents see they're settled for the night.

$stmt = $pdo->prepare(
    'UPDATE teams t
     INNER JOIN (
         SELECT ec.team_id, MAX(ec.reviewed_at) AS last_reviewed_at
         FROM explorer_checkins ec
         WHERE ec.status = "reviewed"
         GROUP BY ec.team_id
     ) latest ON latest.team_id = t.id
     SET t.status = "resting"
     WHERE t.status = "checked_in"'
);
$stmt->execute();

$restedCount = $stmt->rowCount();

// --- Step 2: At 7:00 AM Helsinki, flip "resting" teams to "on_route" ---
// This only triggers during the 07:00 minute window.
// Since the cron runs every minute, checking hour=7 and minute=0 ensures it fires once.

if ($currentHour === CYCLE_MORNING_HOUR && $currentMinute === 0) {
    $stmt = $pdo->prepare(
        'UPDATE teams
         SET status = "on_route"
         WHERE status = "resting"'
    );
    $stmt->execute();

    $onRouteCount = $stmt->rowCount();
} else {
    $onRouteCount = 0;
}

// --- Logging (optional, useful for debugging in cPanel) ---
if ($restedCount > 0 || $onRouteCount > 0) {
    $logLine = sprintf(
        "[%s] Cycle ran: %d team(s) → resting, %d team(s) → on_route\n",
        $now->format('Y-m-d H:i:s T'),
        $restedCount,
        $onRouteCount
    );
    @file_put_contents(__DIR__ . '/cron_team_status_cycle.log', $logLine, FILE_APPEND);
}
