<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

function analytics_page_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );

        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function analytics_page_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?'
        );

        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function analytics_percent(int $value, int $total): string
{
    if ($total <= 0) {
        return '0%';
    }

    return number_format(($value / $total) * 100, 1) . '%';
}

function analytics_seconds_to_human(?int $seconds): string
{
    if ($seconds === null || $seconds < 0) {
        return 'Not clicked';
    }

    if ($seconds < 60) {
        return $seconds . ' sec';
    }

    if ($seconds < 3600) {
        return floor($seconds / 60) . ' min';
    }

    if ($seconds < 86400) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return $hours . ' hr' . ($hours === 1 ? '' : 's') . ($minutes > 0 ? ' ' . $minutes . ' min' : '');
    }

    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);

    return $days . ' day' . ($days === 1 ? '' : 's') . ($hours > 0 ? ' ' . $hours . ' hr' : '');
}

function analytics_safe_datetime(?string $datetime): string
{
    if (!$datetime) {
        return 'Not recorded';
    }

    if (function_exists('format_datetime')) {
        return format_datetime($datetime);
    }

    return date('d M Y, H:i', strtotime($datetime));
}

function analytics_clean_path(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '/';
    }

    return $path;
}

function analytics_query_date(string $key, string $fallback): string
{
    $value = trim($_GET[$key] ?? '');

    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    return $fallback;
}

$today = new DateTime('today');
$defaultFrom = (clone $today)->modify('-30 days')->format('Y-m-d');
$defaultTo = $today->format('Y-m-d');

$dateFrom = analytics_query_date('from', $defaultFrom);
$dateTo = analytics_query_date('to', $defaultTo);

$fromDateTime = $dateFrom . ' 00:00:00';
$toDateTime = $dateTo . ' 23:59:59';

$hasEmailQueue = analytics_page_table_exists($pdo, 'email_queue');
$hasTokens = analytics_page_table_exists($pdo, 'email_tracking_tokens');
$hasEvents = analytics_page_table_exists($pdo, 'email_tracking_events');
$hasPageVisits = analytics_page_table_exists($pdo, 'page_visits');
$hasTeams = analytics_page_table_exists($pdo, 'teams');

$summary = [
    'emails_sent' => 0,
    'emails_opened' => 0,
    'emails_clicked' => 0,
    'total_opens' => 0,
    'total_clicks' => 0,
    'page_visits' => 0,
    'unique_sessions' => 0,
    'parent_page_visits' => 0,
    'parent_unique_sessions' => 0,
];

$emailRows = [];
$pageRows = [];
$teamRows = [];
$clickerRows = [];
$recentClickRows = [];
$zeroClickRows = [];
$yearRows = [];
$leaderVisitCount = 0;
$unattributedVisitCount = 0;
$error = '';

try {
    if ($hasEmailQueue && $hasTokens) {
        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS emails_sent,
                SUM(CASE WHEN ett.open_count > 0 THEN 1 ELSE 0 END) AS emails_opened,
                SUM(CASE WHEN ett.click_count > 0 THEN 1 ELSE 0 END) AS emails_clicked,
                COALESCE(SUM(ett.open_count), 0) AS total_opens,
                COALESCE(SUM(ett.click_count), 0) AS total_clicks
             FROM email_queue eq
             LEFT JOIN email_tracking_tokens ett ON ett.email_queue_id = eq.id
             WHERE eq.status = "sent"
               AND eq.sent_at BETWEEN ? AND ?'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $row = $stmt->fetch() ?: [];

        $summary['emails_sent'] = (int)($row['emails_sent'] ?? 0);
        $summary['emails_opened'] = (int)($row['emails_opened'] ?? 0);
        $summary['emails_clicked'] = (int)($row['emails_clicked'] ?? 0);
        $summary['total_opens'] = (int)($row['total_opens'] ?? 0);
        $summary['total_clicks'] = (int)($row['total_clicks'] ?? 0);

        $teamNameSelect = $hasTeams ? 't.name AS team_name,' : 'NULL AS team_name,';
        $teamJoin = $hasTeams ? 'LEFT JOIN teams t ON t.id = eq.related_team_id' : '';

        $stmt = $pdo->prepare(
            'SELECT
                eq.id,
                eq.to_email,
                eq.subject,
                eq.status,
                eq.sent_at,
                eq.related_team_id,
                ' . $teamNameSelect . '
                ett.id AS tracking_token_id,
                ett.open_count,
                ett.click_count,
                ett.first_opened_at,
                ett.last_opened_at,
                ett.first_clicked_at,
                ett.last_clicked_at,
                CASE
                    WHEN ett.first_clicked_at IS NOT NULL AND eq.sent_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, eq.sent_at, ett.first_clicked_at)
                    ELSE NULL
                END AS seconds_to_first_click
             FROM email_queue eq
             LEFT JOIN email_tracking_tokens ett ON ett.email_queue_id = eq.id
             ' . $teamJoin . '
             WHERE eq.status = "sent"
               AND eq.sent_at BETWEEN ? AND ?
             ORDER BY eq.sent_at DESC, eq.id DESC
             LIMIT 200'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $emailRows = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT
                eq.id,
                eq.to_email,
                eq.subject,
                eq.sent_at,
                ett.open_count,
                ett.click_count
             FROM email_queue eq
             LEFT JOIN email_tracking_tokens ett ON ett.email_queue_id = eq.id
             WHERE eq.status = "sent"
               AND eq.sent_at BETWEEN ? AND ?
               AND COALESCE(ett.click_count, 0) = 0
             ORDER BY eq.sent_at DESC
             LIMIT 50'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $zeroClickRows = $stmt->fetchAll();
    }

    if ($hasPageVisits) {
        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS page_visits,
                COUNT(DISTINCT session_id) AS unique_sessions,
                SUM(CASE WHEN leader_id IS NULL THEN 1 ELSE 0 END) AS parent_page_visits,
                COUNT(DISTINCT CASE WHEN leader_id IS NULL THEN session_id ELSE NULL END) AS parent_unique_sessions,
                SUM(CASE WHEN leader_id IS NOT NULL THEN 1 ELSE 0 END) AS leader_page_visits,
                SUM(CASE WHEN email_queue_id IS NULL THEN 1 ELSE 0 END) AS unattributed_visits
             FROM page_visits
             WHERE visited_at BETWEEN ? AND ?'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $row = $stmt->fetch() ?: [];

        $summary['page_visits'] = (int)($row['page_visits'] ?? 0);
        $summary['unique_sessions'] = (int)($row['unique_sessions'] ?? 0);
        $summary['parent_page_visits'] = (int)($row['parent_page_visits'] ?? 0);
        $summary['parent_unique_sessions'] = (int)($row['parent_unique_sessions'] ?? 0);
        $leaderVisitCount = (int)($row['leader_page_visits'] ?? 0);
        $unattributedVisitCount = (int)($row['unattributed_visits'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT
                request_path,
                page_key,
                COUNT(*) AS visits,
                COUNT(DISTINCT session_id) AS unique_sessions,
                COUNT(DISTINCT email_queue_id) AS attributed_emails
             FROM page_visits
             WHERE visited_at BETWEEN ? AND ?
               AND leader_id IS NULL
             GROUP BY request_path, page_key
             ORDER BY visits DESC, unique_sessions DESC
             LIMIT 30'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $pageRows = $stmt->fetchAll();

        $teamNameSelect = $hasTeams ? 't.name AS team_name,' : 'NULL AS team_name,';
        $teamJoin = $hasTeams ? 'LEFT JOIN teams t ON t.id = pv.related_team_id' : '';

        $stmt = $pdo->prepare(
            'SELECT
                pv.related_team_id,
                ' . $teamNameSelect . '
                COUNT(*) AS visits,
                COUNT(DISTINCT pv.session_id) AS unique_sessions,
                COUNT(DISTINCT pv.email_queue_id) AS attributed_emails
             FROM page_visits pv
             ' . $teamJoin . '
             WHERE pv.visited_at BETWEEN ? AND ?
               AND pv.leader_id IS NULL
               AND pv.related_team_id IS NOT NULL
             GROUP BY pv.related_team_id, team_name
             ORDER BY visits DESC, unique_sessions DESC
             LIMIT 30'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $teamRows = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT
                COALESCE(recipient_email, "Unknown / unattributed") AS recipient_email,
                related_team_id,
                COUNT(*) AS visits,
                COUNT(DISTINCT session_id) AS sessions,
                COUNT(DISTINCT request_path) AS pages_visited,
                MIN(visited_at) AS first_visit_at,
                MAX(visited_at) AS last_visit_at
             FROM page_visits
             WHERE visited_at BETWEEN ? AND ?
               AND leader_id IS NULL
             GROUP BY recipient_email, related_team_id
             ORDER BY visits DESC, sessions DESC
             LIMIT 30'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $clickerRows = $stmt->fetchAll();

        $stmt = $pdo->query(
            'SELECT
                YEAR(visited_at) AS visit_year,
                request_path,
                COUNT(*) AS visits,
                COUNT(DISTINCT session_id) AS unique_sessions
             FROM page_visits
             WHERE leader_id IS NULL
             GROUP BY YEAR(visited_at), request_path
             ORDER BY visit_year DESC, visits DESC
             LIMIT 100'
        );

        $yearRows = $stmt->fetchAll();
    }

    if ($hasEvents && $hasTokens && $hasEmailQueue) {
        $stmt = $pdo->prepare(
            'SELECT
                ete.created_at,
                ete.event_type,
                ete.recipient_email,
                ete.tracked_url,
                ete.request_path,
                eq.subject,
                eq.sent_at,
                CASE
                    WHEN ete.created_at IS NOT NULL AND eq.sent_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, eq.sent_at, ete.created_at)
                    ELSE NULL
                END AS seconds_after_sent
             FROM email_tracking_events ete
             LEFT JOIN email_queue eq ON eq.id = ete.email_queue_id
             WHERE ete.created_at BETWEEN ? AND ?
               AND ete.event_type = "click"
             ORDER BY ete.created_at DESC
             LIMIT 50'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $recentClickRows = $stmt->fetchAll();
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

include __DIR__ . '/header.php';
?>

<style>
    .page-hero,
    .page-hero h1,
    .page-hero p,
    .page-hero .lead {
        color: #ffffff !important;
    }

    .analytics-shell {
        max-width: 1280px;
    }

    .analytics-filter {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .analytics-filter label {
        font-weight: 900;
    }

    .analytics-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    @media (max-width: 1000px) {
        .analytics-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 560px) {
        .analytics-grid {
            grid-template-columns: 1fr;
        }
    }

    .metric-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
    }

    .metric-card h2 {
        font-size: 0.95rem;
        color: #505a5f;
        margin: 0 0 0.4rem;
        font-weight: 900;
    }

    .metric-value {
        font-size: 2rem;
        font-weight: 900;
        line-height: 1.1;
        margin: 0;
    }

    .metric-sub {
        color: #505a5f;
        margin: 0.35rem 0 0;
        font-size: 0.95rem;
    }

    .analytics-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .analytics-panel h2 {
        margin-top: 0;
        font-weight: 900;
    }

    .analytics-table-wrap {
        overflow-x: auto;
    }

    .analytics-table {
        width: 100%;
        border-collapse: collapse;
        background: #ffffff;
    }

    .analytics-table th,
    .analytics-table td {
        border-bottom: 1px solid #d8d8d8;
        padding: 0.65rem;
        vertical-align: top;
    }

    .analytics-table th {
        background: #f3f2f1;
        font-weight: 900;
        white-space: nowrap;
    }

    .rate-pill {
        display: inline-block;
        border: 2px solid #1d1d1d;
        padding: 0.15rem 0.4rem;
        font-weight: 900;
        background: #f3f2f1;
    }

    .rate-good {
        background: #00703c;
        color: #ffffff;
        border-color: #00703c;
    }

    .rate-warning {
        background: #ffdd00;
        color: #1d1d1d;
        border-color: #1d1d1d;
    }

    .rate-muted {
        background: #f3f2f1;
        color: #505a5f;
        border-color: #b1b4b6;
    }

    .muted {
        color: #505a5f;
    }

    .empty-box {
        border: 2px dashed #b1b4b6;
        background: #f8f8f8;
        padding: 1rem;
        font-weight: 700;
    }

    .warning-box {
        border-left: 8px solid #ffdd00;
        background: #fff7bf;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .info-box {
        border-left: 8px solid #1d70b8;
        background: #eef7ff;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>Analytics</h1>
        <p class="lead">
            Email engagement and parent portal usage.
        </p>
    </div>
</section>

<main id="main-content" class="container my-5 analytics-shell">

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasEmailQueue || !$hasTokens || !$hasEvents || !$hasPageVisits): ?>
        <div class="warning-box">
            <strong>Analytics setup is incomplete.</strong>
            <p class="mb-0">
                Expected tables:
                <code>email_queue</code>,
                <code>email_tracking_tokens</code>,
                <code>email_tracking_events</code>,
                <code>page_visits</code>.
            </p>
        </div>
    <?php endif; ?>

    <form class="analytics-filter" method="get">
        <div class="form-row align-items-end">
            <div class="form-group col-md-3">
                <label for="from">From</label>
                <input class="form-control" id="from" name="from" type="date" value="<?= e($dateFrom) ?>">
            </div>

            <div class="form-group col-md-3">
                <label for="to">To</label>
                <input class="form-control" id="to" name="to" type="date" value="<?= e($dateTo) ?>">
            </div>

            <div class="form-group col-md-3">
                <button class="btn btn-primary" type="submit">
                    Update
                </button>

                <a class="btn btn-outline-secondary" href="<?= e(url('analytics.php')) ?>">
                    Reset
                </a>
            </div>
        </div>
    </form>

    <div class="info-box">
        <p class="mb-0">
            Parent/page usage figures exclude logged-in leader visits. Email open tracking is approximate because some mail apps block or proxy images. Link clicks are more reliable.
        </p>
    </div>

    <section class="analytics-grid">
        <div class="metric-card">
            <h2>Sent emails</h2>
            <p class="metric-value"><?= (int)$summary['emails_sent'] ?></p>
            <p class="metric-sub">
                <?= (int)$summary['emails_clicked'] ?> clicked
            </p>
        </div>

        <div class="metric-card">
            <h2>Open rate</h2>
            <p class="metric-value">
                <?= e(analytics_percent((int)$summary['emails_opened'], (int)$summary['emails_sent'])) ?>
            </p>
            <p class="metric-sub">
                <?= (int)$summary['total_opens'] ?> total opens
            </p>
        </div>

        <div class="metric-card">
            <h2>Click rate</h2>
            <p class="metric-value">
                <?= e(analytics_percent((int)$summary['emails_clicked'], (int)$summary['emails_sent'])) ?>
            </p>
            <p class="metric-sub">
                <?= (int)$summary['total_clicks'] ?> total clicks
            </p>
        </div>

        <div class="metric-card">
            <h2>Parent page visits</h2>
            <p class="metric-value"><?= (int)$summary['parent_page_visits'] ?></p>
            <p class="metric-sub">
                <?= (int)$summary['parent_unique_sessions'] ?> parent sessions
            </p>
        </div>
    </section>

    <section class="analytics-panel">
        <h2>Emails sent</h2>

        <?php if (empty($emailRows)): ?>
            <div class="empty-box">
                No sent email tracking data found for this date range.
            </div>
        <?php else: ?>
            <div class="analytics-table-wrap">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Sent</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Team</th>
                            <th>Opens</th>
                            <th>Clicks</th>
                            <th>Open rate</th>
                            <th>Clicked?</th>
                            <th>Time to first click</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($emailRows as $row): ?>
                            <?php
                            $openCount = (int)($row['open_count'] ?? 0);
                            $clickCount = (int)($row['click_count'] ?? 0);
                            ?>
                            <tr>
                                <td><?= e(analytics_safe_datetime($row['sent_at'] ?? null)) ?></td>
                                <td><?= e($row['to_email'] ?? '') ?></td>
                                <td><?= e($row['subject'] ?? '') ?></td>
                                <td><?= e($row['team_name'] ?: ($row['related_team_id'] ? 'Team #' . $row['related_team_id'] : 'Not team-specific')) ?></td>
                                <td>
                                    <strong><?= $openCount ?></strong>
                                    <?php if (!empty($row['first_opened_at'])): ?>
                                        <br><span class="muted">First: <?= e(analytics_safe_datetime($row['first_opened_at'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= $clickCount ?></strong>
                                    <?php if (!empty($row['first_clicked_at'])): ?>
                                        <br><span class="muted">First: <?= e(analytics_safe_datetime($row['first_clicked_at'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($openCount > 0): ?>
                                        <span class="rate-pill rate-good">Opened</span>
                                    <?php else: ?>
                                        <span class="rate-pill rate-muted">No open</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($clickCount > 0): ?>
                                        <span class="rate-pill rate-good">Clicked</span>
                                    <?php else: ?>
                                        <span class="rate-pill rate-warning">No click</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(analytics_seconds_to_human($row['seconds_to_first_click'] !== null ? (int)$row['seconds_to_first_click'] : null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="analytics-panel">
        <h2>Most visited parent pages</h2>

        <?php if (empty($pageRows)): ?>
            <div class="empty-box">
                No parent page visits found for this date range.
            </div>
        <?php else: ?>
            <div class="analytics-table-wrap">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th>Visits</th>
                            <th>Unique sessions</th>
                            <th>Attributed emails</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pageRows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= e(analytics_clean_path($row['request_path'] ?? '')) ?></strong>
                                    <?php if (!empty($row['page_key'])): ?>
                                        <br><span class="muted"><?= e($row['page_key']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)$row['visits'] ?></td>
                                <td><?= (int)$row['unique_sessions'] ?></td>
                                <td><?= (int)$row['attributed_emails'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="analytics-panel">
        <h2>Most active parent/clicker</h2>

        <?php if (empty($clickerRows)): ?>
            <div class="empty-box">
                No parent/clicker activity found for this date range.
            </div>
        <?php else: ?>
            <div class="analytics-table-wrap">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Recipient</th>
                            <th>Team</th>
                            <th>Visits</th>
                            <th>Sessions</th>
                            <th>Pages visited</th>
                            <th>First visit</th>
                            <th>Last visit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clickerRows as $row): ?>
                            <tr>
                                <td><?= e($row['recipient_email'] ?? 'Unknown') ?></td>
                                <td><?= e($row['related_team_id'] ? 'Team #' . $row['related_team_id'] : 'Not attributed') ?></td>
                                <td><?= (int)$row['visits'] ?></td>
                                <td><?= (int)$row['sessions'] ?></td>
                                <td><?= (int)$row['pages_visited'] ?></td>
                                <td><?= e(analytics_safe_datetime($row['first_visit_at'] ?? null)) ?></td>
                                <td><?= e(analytics_safe_datetime($row['last_visit_at'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="analytics-panel">
        <h2>Visits by team</h2>

        <?php if (empty($teamRows)): ?>
            <div class="empty-box">
                No team-attributed parent visits found for this date range.
            </div>
        <?php else: ?>
            <div class="analytics-table-wrap">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Team</th>
                            <th>Visits</th>
                            <th>Unique sessions</th>
                            <th>Attributed emails</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teamRows as $row): ?>
                            <tr>
                                <td><?= e($row['team_name'] ?: 'Team #' . $row['related_team_id']) ?></td>
                                <td><?= (int)$row['visits'] ?></td>
                                <td><?= (int)$row['unique_sessions'] ?></td>
                                <td><?= (int)$row['attributed_emails'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="analytics-panel">
        <h2>Recent email link clicks</h2>

        <?php if (empty($recentClickRows)): ?>
            <div class="empty-box">
                No recent click events found for this date range.
            </div>
        <?php else: ?>
            <div class="analytics-table-wrap">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Clicked</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>URL / page</th>
                            <th>After sent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentClickRows as $row): ?>
                            <tr>
                                <td><?= e(analytics_safe_datetime($row['created_at'] ?? null)) ?></td>
                                <td><?= e($row['recipient_email'] ?? 'Unknown') ?></td>
                                <td><?= e($row['subject'] ?? '') ?></td>
                                <td>
                                    <?= e($row['tracked_url'] ?: $row['request_path'] ?: '') ?>
                                </td>
                                <td><?= e(analytics_seconds_to_human($row['seconds_after_sent'] !== null ? (int)$row['seconds_after_sent'] : null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="analytics-panel">
        <h2>Emails with no clicks</h2>

        <?php if (empty($zeroClickRows)): ?>
            <div class="empty-box">
                All tracked sent emails in this period have at least one click, or no email data is available.
            </div>
        <?php else: ?>
            <div class="analytics-table-wrap">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Sent</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Opens</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($zeroClickRows as $row): ?>
                            <tr>
                                <td><?= e(analytics_safe_datetime($row['sent_at'] ?? null)) ?></td>
                                <td><?= e($row['to_email'] ?? '') ?></td>
                                <td><?= e($row['subject'] ?? '') ?></td>
                                <td><?= (int)($row['open_count'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="analytics-panel">
        <h2>Yearly page usage</h2>

        <?php if (empty($yearRows)): ?>
            <div class="empty-box">
                No yearly page usage found yet.
            </div>
        <?php else: ?>
            <div class="analytics-table-wrap">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Year</th>
                            <th>Page</th>
                            <th>Visits</th>
                            <th>Unique sessions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($yearRows as $row): ?>
                            <tr>
                                <td><?= e((string)$row['visit_year']) ?></td>
                                <td><?= e(analytics_clean_path($row['request_path'] ?? '')) ?></td>
                                <td><?= (int)$row['visits'] ?></td>
                                <td><?= (int)$row['unique_sessions'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="analytics-panel">
        <h2>Internal activity note</h2>

        <p>
            Logged-in leader page visits in this date range:
            <strong><?= (int)$leaderVisitCount ?></strong>.
        </p>

        <p>
            Unattributed page visits in this date range:
            <strong><?= (int)$unattributedVisitCount ?></strong>.
        </p>

        <p class="muted mb-0">
            The parent usage tables above exclude leader visits using <code>leader_id IS NULL</code>.
            If a parent uses a tracked email link, their later page visits in that session are attributed to that email token.
        </p>
    </section>

</main>

<?php include __DIR__ . '/footer.php'; ?>