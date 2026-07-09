<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

const ANALYTICS_EMAILS_PER_PAGE = 25;

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

function analytics_page_label(?string $path, ?string $pageKey = null): string
{
    $key = strtolower(trim((string)$pageKey));

    if ($key === '') {
        $key = strtolower(basename((string)$path));
    }

    $labels = [
        'dashboard.php' => 'Dashboard',
        'leaders.php' => 'Leaders',
        'contact.php' => 'Contact',
    ];

    return $labels[$key] ?? analytics_clean_path((string)$path);
}

function analytics_query_date(string $key, string $fallback): string
{
    $value = trim($_GET[$key] ?? '');

    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    return $fallback;
}

function analytics_url_with_params(array $overrides): string
{
    $params = $_GET;

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return url('analytics.php' . (!empty($params) ? '?' . http_build_query($params) : ''));
}

function analytics_page_filter_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';

    return '
        AND (
            ' . $prefix . 'page_key IN ("dashboard.php", "leaders.php", "contact.php")
            OR ' . $prefix . 'request_path IN ("/dashboard.php", "/leaders.php", "/contact.php")
            OR ' . $prefix . 'request_path LIKE "%/dashboard.php"
            OR ' . $prefix . 'request_path LIKE "%/leaders.php"
            OR ' . $prefix . 'request_path LIKE "%/contact.php"
        )
    ';
}

function analytics_normalise_email(?string $email): string
{
    return strtolower(trim((string)$email));
}

function analytics_json_items(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function analytics_emails_from_json_list(?string $json): array
{
    $items = analytics_json_items($json);
    $emails = [];

    foreach ($items as $item) {
        if (!is_string($item)) {
            continue;
        }

        $email = trim($item);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return $emails;
}

function analytics_emails_from_emergency_contacts(?string $json): array
{
    $items = analytics_json_items($json);
    $emails = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $email = trim((string)($item['email'] ?? ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return $emails;
}

function analytics_build_leader_email_lookup(PDO $pdo): array
{
    $lookup = [];

    if (!analytics_page_table_exists($pdo, 'leaders')) {
        return $lookup;
    }

    try {
        $rows = $pdo->query(
            'SELECT id, name, email
             FROM leaders
             WHERE email IS NOT NULL
               AND email <> ""'
        )->fetchAll();

        foreach ($rows as $row) {
            $email = analytics_normalise_email($row['email'] ?? '');

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $lookup[$email] = [
                    'leader_id' => (int)$row['id'],
                    'leader_name' => $row['name'] ?? 'Leader',
                ];
            }
        }
    } catch (Throwable $exception) {
        return [];
    }

    return $lookup;
}

function analytics_build_email_team_lookup(PDO $pdo): array
{
    $lookup = [];

    if (!analytics_page_table_exists($pdo, 'young_people')) {
        return $lookup;
    }

    $hasTeams = analytics_page_table_exists($pdo, 'teams');

    $teamSelect = $hasTeams ? 't.name AS team_name' : 'NULL AS team_name';
    $teamJoin = $hasTeams ? 'LEFT JOIN teams t ON t.id = yp.team_id' : '';

    try {
        $stmt = $pdo->query(
            'SELECT
                yp.id,
                yp.name,
                yp.team_id,
                yp.participant_email,
                yp.parent_emails_json,
                yp.emergency_contacts_json,
                ' . $teamSelect . '
             FROM young_people yp
             ' . $teamJoin . '
             WHERE yp.is_active = 1'
        );

        foreach ($stmt->fetchAll() as $person) {
            $teamId = !empty($person['team_id']) ? (int)$person['team_id'] : null;

            if (!$teamId) {
                continue;
            }

            $teamName = $person['team_name'] ?: 'Team #' . $teamId;

            $emails = [];

            if (!empty($person['participant_email'])) {
                $emails[] = $person['participant_email'];
            }

            foreach (analytics_emails_from_json_list($person['parent_emails_json'] ?? null) as $email) {
                $emails[] = $email;
            }

            foreach (analytics_emails_from_emergency_contacts($person['emergency_contacts_json'] ?? null) as $email) {
                $emails[] = $email;
            }

            foreach ($emails as $email) {
                $normalised = analytics_normalise_email($email);

                if ($normalised === '' || !filter_var($normalised, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                if (!isset($lookup[$normalised])) {
                    $lookup[$normalised] = [
                        'team_id' => $teamId,
                        'team_name' => $teamName,
                        'people' => [],
                    ];
                }

                $lookup[$normalised]['people'][(int)$person['id']] = $person['name'];
            }
        }
    } catch (Throwable $exception) {
        return [];
    }

    return $lookup;
}

function analytics_resolve_email_attribution(?string $email, array $teamLookup, array $leaderLookup): array
{
    $normalised = analytics_normalise_email($email);

    if ($normalised === '' || !filter_var($normalised, FILTER_VALIDATE_EMAIL)) {
        return [
            'type' => 'unattributed',
            'team_id' => null,
            'team_name' => null,
            'label' => 'Not attributed',
        ];
    }

    if (isset($leaderLookup[$normalised])) {
        return [
            'type' => 'leader',
            'team_id' => null,
            'team_name' => null,
            'label' => 'Leader',
        ];
    }

    if (isset($teamLookup[$normalised])) {
        return [
            'type' => 'team',
            'team_id' => $teamLookup[$normalised]['team_id'],
            'team_name' => $teamLookup[$normalised]['team_name'],
            'label' => $teamLookup[$normalised]['team_name'],
        ];
    }

    return [
        'type' => 'unattributed',
        'team_id' => null,
        'team_name' => null,
        'label' => 'Not attributed',
    ];
}

function analytics_resolve_row_attribution(array $row, array $teamLookup, array $leaderLookup): array
{
    if (!empty($row['related_team_id'])) {
        return [
            'type' => 'team',
            'team_id' => (int)$row['related_team_id'],
            'team_name' => $row['team_name'] ?? ('Team #' . (int)$row['related_team_id']),
            'label' => $row['team_name'] ?? ('Team #' . (int)$row['related_team_id']),
        ];
    }

    return analytics_resolve_email_attribution($row['recipient_email'] ?? ($row['to_email'] ?? null), $teamLookup, $leaderLookup);
}

function analytics_increment_group(array &$groups, string $key, array $base, int $visits = 1): void
{
    if (!isset($groups[$key])) {
        $groups[$key] = $base;
    }

    $groups[$key]['visits'] += $visits;
}

$today = new DateTime('today');
$defaultFrom = (clone $today)->modify('-30 days')->format('Y-m-d');
$defaultTo = $today->format('Y-m-d');

$dateFrom = analytics_query_date('from', $defaultFrom);
$dateTo = analytics_query_date('to', $defaultTo);

$emailPage = max(1, (int)($_GET['email_page'] ?? 1));
$emailOffset = ($emailPage - 1) * ANALYTICS_EMAILS_PER_PAGE;

$fromDateTime = $dateFrom . ' 00:00:00';
$toDateTime = $dateTo . ' 23:59:59';

$hasEmailQueue = analytics_page_table_exists($pdo, 'email_queue');
$hasTokens = analytics_page_table_exists($pdo, 'email_tracking_tokens');
$hasEvents = analytics_page_table_exists($pdo, 'email_tracking_events');
$hasPageVisits = analytics_page_table_exists($pdo, 'page_visits');
$hasTeams = analytics_page_table_exists($pdo, 'teams');

$teamEmailLookup = analytics_build_email_team_lookup($pdo);
$leaderEmailLookup = analytics_build_leader_email_lookup($pdo);

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
$emailTotalRows = 0;
$emailTotalPages = 1;
$pageRows = [];
$teamRows = [];
$clickerRows = [];
$recentClickRows = [];
$zeroClickRows = [];
$yearRows = [];
$failedEmailRows = [];
$quickestClick = null;
$leaderVisitCount = 0;
$excludedAdminVisitCount = 0;
$unattributedVisitCount = 0;
$ignoredUnattributedForTeamCount = 0;
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

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM email_queue eq
             WHERE eq.status = "sent"
               AND eq.sent_at BETWEEN ? AND ?'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $emailTotalRows = (int)$stmt->fetchColumn();
        $emailTotalPages = max(1, (int)ceil($emailTotalRows / ANALYTICS_EMAILS_PER_PAGE));

        if ($emailPage > $emailTotalPages) {
            redirect(analytics_url_with_params(['email_page' => $emailTotalPages]));
        }

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
                ett.recipient_email,
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
             LIMIT ' . ANALYTICS_EMAILS_PER_PAGE . ' OFFSET ' . $emailOffset
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $emailRows = $stmt->fetchAll();

        foreach ($emailRows as &$emailRow) {
            $attribution = analytics_resolve_row_attribution($emailRow, $teamEmailLookup, $leaderEmailLookup);
            $emailRow['analytics_attribution'] = $attribution;
        }
        unset($emailRow);

        $stmt = $pdo->prepare(
            'SELECT
                eq.id,
                eq.to_email,
                eq.subject,
                eq.sent_at,
                eq.related_team_id,
                ' . $teamNameSelect . '
                ett.recipient_email,
                ett.open_count,
                ett.click_count
             FROM email_queue eq
             LEFT JOIN email_tracking_tokens ett ON ett.email_queue_id = eq.id
             ' . $teamJoin . '
             WHERE eq.status = "sent"
               AND eq.sent_at BETWEEN ? AND ?
               AND COALESCE(ett.click_count, 0) = 0
             ORDER BY eq.sent_at DESC
             LIMIT 50'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $zeroClickRows = $stmt->fetchAll();

        foreach ($zeroClickRows as &$zeroRow) {
            $zeroRow['analytics_attribution'] = analytics_resolve_row_attribution($zeroRow, $teamEmailLookup, $leaderEmailLookup);
        }
        unset($zeroRow);

        $stmt = $pdo->prepare(
            'SELECT
                eq.id,
                eq.to_email,
                eq.subject,
                eq.sent_at,
                eq.related_team_id,
                ' . $teamNameSelect . '
                ett.recipient_email,
                ett.first_clicked_at,
                ett.click_count,
                TIMESTAMPDIFF(SECOND, eq.sent_at, ett.first_clicked_at) AS seconds_to_first_click
             FROM email_queue eq
             INNER JOIN email_tracking_tokens ett ON ett.email_queue_id = eq.id
             ' . $teamJoin . '
             WHERE eq.status = "sent"
               AND eq.sent_at BETWEEN ? AND ?
               AND ett.first_clicked_at IS NOT NULL
             ORDER BY seconds_to_first_click ASC
             LIMIT 1'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $quickestClick = $stmt->fetch() ?: null;

        if ($quickestClick) {
            $quickestClick['analytics_attribution'] = analytics_resolve_row_attribution($quickestClick, $teamEmailLookup, $leaderEmailLookup);
        }

        // Fetch failed/errored emails
        $stmt = $pdo->prepare(
            'SELECT
                eq.id,
                eq.to_email,
                eq.subject,
                eq.status,
                eq.attempts,
                eq.last_error,
                eq.queued_at,
                eq.updated_at
             FROM email_queue eq
             WHERE eq.status IN ("failed", "pending")
               AND eq.last_error IS NOT NULL
               AND eq.last_error <> ""
               AND eq.queued_at BETWEEN ? AND ?
             ORDER BY eq.updated_at DESC
             LIMIT 50'
        );
        $stmt->execute([$fromDateTime, $toDateTime]);
        $failedEmailRows = $stmt->fetchAll();
    }

    if ($hasPageVisits) {
        $pageFilter = analytics_page_filter_sql();

        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS page_visits,
                COUNT(DISTINCT session_id) AS unique_sessions,
                SUM(CASE WHEN leader_id IS NULL THEN 1 ELSE 0 END) AS parent_page_visits,
                COUNT(DISTINCT CASE WHEN leader_id IS NULL THEN session_id ELSE NULL END) AS parent_unique_sessions,
                SUM(CASE WHEN leader_id IS NOT NULL THEN 1 ELSE 0 END) AS leader_page_visits,
                SUM(CASE WHEN email_queue_id IS NULL THEN 1 ELSE 0 END) AS unattributed_visits
             FROM page_visits
             WHERE visited_at BETWEEN ? AND ?
             ' . $pageFilter
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
            'SELECT COUNT(*)
             FROM page_visits
             WHERE visited_at BETWEEN ? AND ?
               AND leader_id IS NULL
               AND NOT (
                    page_key IN ("dashboard.php", "leaders.php", "contact.php")
                    OR request_path IN ("/dashboard.php", "/leaders.php", "/contact.php")
                    OR request_path LIKE "%/dashboard.php"
                    OR request_path LIKE "%/leaders.php"
                    OR request_path LIKE "%/contact.php"
               )'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $excludedAdminVisitCount = (int)$stmt->fetchColumn();

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
               ' . analytics_page_filter_sql() . '
             GROUP BY request_path, page_key
             ORDER BY visits DESC, unique_sessions DESC
             LIMIT 30'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $pageRows = $stmt->fetchAll();

        /**
         * Fetch parent-facing page visits and do team/clicker attribution in PHP.
         * This lets us infer a team from the people table without adding a table.
         */
        $teamVisitGroups = [];
        $clickerGroups = [];

        $stmt = $pdo->prepare(
            'SELECT
                pv.session_id,
                pv.email_queue_id,
                pv.recipient_email,
                pv.related_team_id,
                pv.related_post_id,
                pv.request_path,
                pv.page_key,
                pv.visited_at,
                t.name AS team_name
             FROM page_visits pv
             LEFT JOIN teams t ON t.id = pv.related_team_id
             WHERE pv.visited_at BETWEEN ? AND ?
               AND pv.leader_id IS NULL
               ' . analytics_page_filter_sql('pv') . '
             ORDER BY pv.visited_at ASC
             LIMIT 5000'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);

        foreach ($stmt->fetchAll() as $visit) {
            $attribution = analytics_resolve_row_attribution($visit, $teamEmailLookup, $leaderEmailLookup);

            if ($attribution['type'] === 'leader') {
                continue;
            }

            if ($attribution['type'] !== 'team' || empty($attribution['team_id'])) {
                $ignoredUnattributedForTeamCount++;
                continue;
            }

            $teamKey = (string)$attribution['team_id'];

            if (!isset($teamVisitGroups[$teamKey])) {
                $teamVisitGroups[$teamKey] = [
                    'team_id' => $attribution['team_id'],
                    'team_name' => $attribution['team_name'],
                    'visits' => 0,
                    'sessions' => [],
                    'emails' => [],
                ];
            }

            $teamVisitGroups[$teamKey]['visits']++;
            $teamVisitGroups[$teamKey]['sessions'][$visit['session_id']] = true;

            if (!empty($visit['email_queue_id'])) {
                $teamVisitGroups[$teamKey]['emails'][(int)$visit['email_queue_id']] = true;
            }

            $recipientEmail = $visit['recipient_email'] ?: 'Unknown / unattributed';
            $clickerKey = analytics_normalise_email($recipientEmail) . '|' . $teamKey;

            if (!isset($clickerGroups[$clickerKey])) {
                $clickerGroups[$clickerKey] = [
                    'recipient_email' => $recipientEmail,
                    'team_id' => $attribution['team_id'],
                    'team_name' => $attribution['team_name'],
                    'visits' => 0,
                    'sessions' => [],
                    'pages' => [],
                    'first_visit_at' => $visit['visited_at'],
                    'last_visit_at' => $visit['visited_at'],
                ];
            }

            $clickerGroups[$clickerKey]['visits']++;
            $clickerGroups[$clickerKey]['sessions'][$visit['session_id']] = true;
            $clickerGroups[$clickerKey]['pages'][$visit['request_path']] = true;

            if (strtotime($visit['visited_at']) < strtotime($clickerGroups[$clickerKey]['first_visit_at'])) {
                $clickerGroups[$clickerKey]['first_visit_at'] = $visit['visited_at'];
            }

            if (strtotime($visit['visited_at']) > strtotime($clickerGroups[$clickerKey]['last_visit_at'])) {
                $clickerGroups[$clickerKey]['last_visit_at'] = $visit['visited_at'];
            }
        }

        foreach ($teamVisitGroups as $teamGroup) {
            $teamRows[] = [
                'team_id' => $teamGroup['team_id'],
                'team_name' => $teamGroup['team_name'],
                'visits' => $teamGroup['visits'],
                'unique_sessions' => count($teamGroup['sessions']),
                'attributed_emails' => count($teamGroup['emails']),
            ];
        }

        usort($teamRows, static function ($a, $b) {
            return $b['visits'] <=> $a['visits'];
        });

        $teamRows = array_slice($teamRows, 0, 30);

        foreach ($clickerGroups as $clickerGroup) {
            $clickerRows[] = [
                'recipient_email' => $clickerGroup['recipient_email'],
                'team_id' => $clickerGroup['team_id'],
                'team_name' => $clickerGroup['team_name'],
                'visits' => $clickerGroup['visits'],
                'sessions' => count($clickerGroup['sessions']),
                'pages_visited' => count($clickerGroup['pages']),
                'first_visit_at' => $clickerGroup['first_visit_at'],
                'last_visit_at' => $clickerGroup['last_visit_at'],
            ];
        }

        usort($clickerRows, static function ($a, $b) {
            return $b['visits'] <=> $a['visits'];
        });

        $clickerRows = array_slice($clickerRows, 0, 30);

        $stmt = $pdo->query(
            'SELECT
                YEAR(visited_at) AS visit_year,
                request_path,
                page_key,
                COUNT(*) AS visits,
                COUNT(DISTINCT session_id) AS unique_sessions
             FROM page_visits
             WHERE leader_id IS NULL
               ' . analytics_page_filter_sql() . '
             GROUP BY YEAR(visited_at), request_path, page_key
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
                ete.related_team_id,
                t.name AS team_name,
                eq.subject,
                eq.sent_at,
                CASE
                    WHEN ete.created_at IS NOT NULL AND eq.sent_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, eq.sent_at, ete.created_at)
                    ELSE NULL
                END AS seconds_after_sent
             FROM email_tracking_events ete
             LEFT JOIN email_queue eq ON eq.id = ete.email_queue_id
             LEFT JOIN teams t ON t.id = ete.related_team_id
             WHERE ete.created_at BETWEEN ? AND ?
               AND ete.event_type = "click"
               AND (
                    ete.request_path IS NULL
                    OR ete.request_path = ""
                    OR ete.request_path LIKE "%dashboard.php%"
                    OR ete.request_path LIKE "%leaders.php%"
                    OR ete.request_path LIKE "%contact.php%"
               )
             ORDER BY ete.created_at DESC
             LIMIT 50'
        );

        $stmt->execute([$fromDateTime, $toDateTime]);
        $recentClickRows = $stmt->fetchAll();

        foreach ($recentClickRows as &$clickRow) {
            $clickRow['analytics_attribution'] = analytics_resolve_row_attribution($clickRow, $teamEmailLookup, $leaderEmailLookup);
        }
        unset($clickRow);
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
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    @media (max-width: 1200px) {
        .analytics-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 780px) {
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

    .analytics-panel-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
        flex-wrap: wrap;
        margin-bottom: 1rem;
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

    .email-mobile-list {
        display: none;
    }

    .email-mobile-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
        margin-bottom: 0.75rem;
    }

    .email-mobile-card summary {
        cursor: pointer;
        font-weight: 900;
    }

    .email-mobile-card dl {
        margin: 0.75rem 0 0;
        display: grid;
        grid-template-columns: 120px minmax(0, 1fr);
        gap: 0.35rem 0.75rem;
    }

    .email-mobile-card dt {
        font-weight: 900;
        color: #505a5f;
    }

    .email-mobile-card dd {
        margin: 0;
        word-break: break-word;
    }

    @media (max-width: 820px) {
        .email-table-desktop {
            display: none;
        }

        .email-mobile-list {
            display: block;
        }
    }

    .pagination-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        margin-top: 1rem;
        align-items: center;
    }

    .pagination-link,
    .pagination-current {
        display: inline-block;
        border: 2px solid #1d70b8;
        padding: 0.45rem 0.7rem;
        font-weight: 900;
        text-decoration: none;
    }

    .pagination-current {
        background: #1d70b8;
        color: #ffffff;
    }

    .pagination-link:hover,
    .pagination-link:focus {
        background: #eef7ff;
        text-decoration: none;
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
            Parent-facing pages only (Dashboard, Leaders, Contact). Leader visits excluded. Team attribution inferred from people records.
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
                <?= (int)$summary['emails_opened'] ?> of <?= (int)$summary['emails_sent'] ?> opened
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
            <h2>Quickest click</h2>
            <p class="metric-value">
                <?= $quickestClick ? e(analytics_seconds_to_human((int)$quickestClick['seconds_to_first_click'])) : 'None' ?>
            </p>
            <p class="metric-sub">
                <?php if ($quickestClick): ?>
                    <?= e($quickestClick['to_email']) ?>
                    <br>
                    <?= e($quickestClick['analytics_attribution']['label'] ?? 'Not attributed') ?>
                <?php else: ?>
                    No click recorded in this period
                <?php endif; ?>
            </p>
        </div>

        <div class="metric-card">
            <h2>Parent page visits</h2>
            <p class="metric-value"><?= (int)$summary['parent_page_visits'] ?></p>
            <p class="metric-sub">
                <?= (int)$summary['parent_unique_sessions'] ?> parent sessions
            </p>
        </div>

        <div class="metric-card">
            <h2>Failed emails</h2>
            <p class="metric-value" <?= count($failedEmailRows) > 0 ? 'style="color:#d4351c;"' : '' ?>>
                <?= count($failedEmailRows) ?>
            </p>
            <p class="metric-sub">
                <?= count($failedEmailRows) > 0 ? 'Needs attention' : 'All clear' ?>
            </p>
        </div>
    </section>

    <section class="analytics-panel">
        <div class="analytics-panel-header">
            <div>
                <h2>Emails sent</h2>
                <p class="muted mb-0">
                    Showing <?= $emailTotalRows === 0 ? 0 : ($emailOffset + 1) ?>
                    to <?= min($emailOffset + ANALYTICS_EMAILS_PER_PAGE, $emailTotalRows) ?>
                    of <?= (int)$emailTotalRows ?> sent emails.
                </p>
            </div>

            <p class="muted mb-0">
                Page <?= (int)$emailPage ?> of <?= (int)$emailTotalPages ?>
            </p>
        </div>

        <?php if (empty($emailRows)): ?>
            <div class="empty-box">
                No sent email tracking data found for this date range.
            </div>
        <?php else: ?>
            <div class="analytics-table-wrap email-table-desktop">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Sent</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Attribution</th>
                            <th>Opens</th>
                            <th>Clicks</th>
                            <th>Time to click</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($emailRows as $row): ?>
                            <?php
                            $openCount = (int)($row['open_count'] ?? 0);
                            $clickCount = (int)($row['click_count'] ?? 0);
                            $attribution = $row['analytics_attribution'] ?? ['label' => 'Not attributed', 'type' => 'unattributed'];
                            ?>
                            <tr>
                                <td><?= e(analytics_safe_datetime($row['sent_at'] ?? null)) ?></td>
                                <td><?= e($row['to_email'] ?? '') ?></td>
                                <td><?= e($row['subject'] ?? '') ?></td>
                                <td><?= e($attribution['label'] ?? 'Not attributed') ?></td>
                                <td>
                                    <?php if ($openCount > 0): ?>
                                        <span class="rate-pill rate-good"><?= $openCount ?></span>
                                    <?php else: ?>
                                        <span class="rate-pill rate-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($clickCount > 0): ?>
                                        <span class="rate-pill rate-good"><?= $clickCount ?></span>
                                    <?php else: ?>
                                        <span class="rate-pill rate-warning">0</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(analytics_seconds_to_human($row['seconds_to_first_click'] !== null ? (int)$row['seconds_to_first_click'] : null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="email-mobile-list">
                <?php foreach ($emailRows as $row): ?>
                    <?php
                    $openCount = (int)($row['open_count'] ?? 0);
                    $clickCount = (int)($row['click_count'] ?? 0);
                    $attribution = $row['analytics_attribution'] ?? ['label' => 'Not attributed', 'type' => 'unattributed'];
                    ?>
                    <details class="email-mobile-card">
                        <summary>
                            <?= e($row['subject'] ?? 'Email') ?>
                            <br>
                            <span class="muted"><?= e($row['to_email'] ?? '') ?></span>
                        </summary>

                        <dl>
                            <dt>Sent</dt>
                            <dd><?= e(analytics_safe_datetime($row['sent_at'] ?? null)) ?></dd>

                            <dt>Attribution</dt>
                            <dd><?= e($attribution['label'] ?? 'Not attributed') ?></dd>

                            <dt>Opens</dt>
                            <dd><?= $openCount ?></dd>

                            <dt>Clicks</dt>
                            <dd><?= $clickCount ?></dd>

                            <dt>Status</dt>
                            <dd>
                                <?php if ($clickCount > 0): ?>
                                    <span class="rate-pill rate-good">Clicked</span>
                                <?php elseif ($openCount > 0): ?>
                                    <span class="rate-pill rate-warning">Opened only</span>
                                <?php else: ?>
                                    <span class="rate-pill rate-muted">No open/click</span>
                                <?php endif; ?>
                            </dd>

                            <dt>First click</dt>
                            <dd><?= e(analytics_seconds_to_human($row['seconds_to_first_click'] !== null ? (int)$row['seconds_to_first_click'] : null)) ?></dd>
                        </dl>
                    </details>
                <?php endforeach; ?>
            </div>

            <?php if ($emailTotalPages > 1): ?>
                <nav class="pagination-wrap" aria-label="Email pagination">
                    <?php if ($emailPage > 1): ?>
                        <a class="pagination-link" href="<?= e(analytics_url_with_params(['email_page' => $emailPage - 1])) ?>">
                            Previous
                        </a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $emailPage - 2);
                    $endPage = min($emailTotalPages, $emailPage + 2);
                    ?>

                    <?php if ($startPage > 1): ?>
                        <a class="pagination-link" href="<?= e(analytics_url_with_params(['email_page' => 1])) ?>">1</a>
                        <?php if ($startPage > 2): ?>
                            <span class="muted">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <?php if ($i === $emailPage): ?>
                            <span class="pagination-current"><?= (int)$i ?></span>
                        <?php else: ?>
                            <a class="pagination-link" href="<?= e(analytics_url_with_params(['email_page' => $i])) ?>">
                                <?= (int)$i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($endPage < $emailTotalPages): ?>
                        <?php if ($endPage < $emailTotalPages - 1): ?>
                            <span class="muted">...</span>
                        <?php endif; ?>
                        <a class="pagination-link" href="<?= e(analytics_url_with_params(['email_page' => $emailTotalPages])) ?>">
                            <?= (int)$emailTotalPages ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($emailPage < $emailTotalPages): ?>
                        <a class="pagination-link" href="<?= e(analytics_url_with_params(['email_page' => $emailPage + 1])) ?>">
                            Next
                        </a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="analytics-panel">
        <h2>Failed emails</h2>

        <?php if (empty($failedEmailRows)): ?>
            <div class="empty-box" style="border-color:#00703c;">
                No failed emails in this date range.
            </div>
        <?php else: ?>
            <div class="warning-box">
                <strong><?= count($failedEmailRows) ?> email(s) with errors.</strong>
                These emails failed to send or are retrying.
            </div>
            <div class="analytics-table-wrap">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Queued</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Attempts</th>
                            <th>Error</th>
                            <th>Last attempt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($failedEmailRows as $row): ?>
                            <tr>
                                <td><?= e(analytics_safe_datetime($row['queued_at'] ?? null)) ?></td>
                                <td><?= e($row['to_email'] ?? '') ?></td>
                                <td><?= e($row['subject'] ?? '') ?></td>
                                <td>
                                    <?php if ($row['status'] === 'failed'): ?>
                                        <span class="rate-pill" style="background:#d4351c;color:#fff;border-color:#d4351c;">Failed</span>
                                    <?php else: ?>
                                        <span class="rate-pill rate-warning">Retrying</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)($row['attempts'] ?? 0) ?> / 5</td>
                                <td style="max-width:300px;word-break:break-word;font-size:0.85rem;"><?= e($row['last_error'] ?? '') ?></td>
                                <td><?= e(analytics_safe_datetime($row['updated_at'] ?? null)) ?></td>
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
                No parent page visits found for Dashboard, Leaders or Contact in this date range.
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
                                    <strong><?= e(analytics_page_label($row['request_path'] ?? '', $row['page_key'] ?? '')) ?></strong>
                                    <br><span class="muted"><?= e(analytics_clean_path($row['request_path'] ?? '')) ?></span>
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
                No team-attributed parent/clicker activity found for Dashboard, Leaders or Contact in this date range.
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
                                <td><?= e($row['team_name'] ?: 'Team #' . $row['team_id']) ?></td>
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
                No team-attributed parent visits found for Dashboard, Leaders or Contact in this date range.
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
                                <td><?= e($row['team_name'] ?: 'Team #' . $row['team_id']) ?></td>
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
                            <th>Attribution</th>
                            <th>Subject</th>
                            <th>URL / page</th>
                            <th>After sent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentClickRows as $row): ?>
                            <?php $attribution = $row['analytics_attribution'] ?? ['label' => 'Not attributed']; ?>
                            <tr>
                                <td><?= e(analytics_safe_datetime($row['created_at'] ?? null)) ?></td>
                                <td><?= e($row['recipient_email'] ?? 'Unknown') ?></td>
                                <td><?= e($attribution['label'] ?? 'Not attributed') ?></td>
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
        <details>
            <summary><h2 style="display:inline;">Emails with no clicks</h2></summary>

            <?php if (empty($zeroClickRows)): ?>
                <div class="empty-box" style="margin-top:1rem;">
                    All tracked sent emails in this period have at least one click.
                </div>
            <?php else: ?>
                <div class="analytics-table-wrap" style="margin-top:1rem;">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Sent</th>
                                <th>Recipient</th>
                                <th>Attribution</th>
                                <th>Subject</th>
                                <th>Opens</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($zeroClickRows as $row): ?>
                                <?php $attribution = $row['analytics_attribution'] ?? ['label' => 'Not attributed']; ?>
                                <tr>
                                    <td><?= e(analytics_safe_datetime($row['sent_at'] ?? null)) ?></td>
                                    <td><?= e($row['to_email'] ?? '') ?></td>
                                    <td><?= e($attribution['label'] ?? 'Not attributed') ?></td>
                                    <td><?= e($row['subject'] ?? '') ?></td>
                                    <td>
                                        <?php if ((int)($row['open_count'] ?? 0) > 0): ?>
                                            <span class="rate-pill rate-good">Opened</span>
                                        <?php else: ?>
                                            <span class="rate-pill rate-muted">Not opened</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </details>
    </section>

    <section class="analytics-panel">
        <details>
            <summary><h2 style="display:inline;">Yearly parent page usage</h2></summary>

            <?php if (empty($yearRows)): ?>
                <div class="empty-box" style="margin-top:1rem;">
                    No yearly parent page usage found yet.
                </div>
            <?php else: ?>
                <div class="analytics-table-wrap" style="margin-top:1rem;">
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
                                    <td><?= e(analytics_page_label($row['request_path'] ?? '', $row['page_key'] ?? '')) ?></td>
                                    <td><?= (int)$row['visits'] ?></td>
                                    <td><?= (int)$row['unique_sessions'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </details>
    </section>

    <section class="analytics-panel">
        <details>
            <summary><h2 style="display:inline;">Internal activity note</h2></summary>

            <div style="margin-top:1rem;">
                <p>
                    Leader visits excluded: <strong><?= (int)$leaderVisitCount ?></strong> ·
                    Non-parent pages excluded: <strong><?= (int)$excludedAdminVisitCount ?></strong> ·
                    Unattributed visits: <strong><?= (int)$unattributedVisitCount ?></strong> ·
                    Ignored for team attribution: <strong><?= (int)$ignoredUnattributedForTeamCount ?></strong>
                </p>
                <p class="muted mb-0">
                    Team attribution is inferred from existing people records. Unmatched emails remain unattributed.
                </p>
            </div>
        </details>
    </section>

</main>

<?php include __DIR__ . '/footer.php'; ?>