<?php
require_once __DIR__ . '/auth.php';

$pdo = db();
$user = current_user();
$parentTeam = parent_access_team();

if (!$user && !$parentTeam) {
    redirect('403.php');
}

$isAdmin = (bool)$user;
$error = '';
$success = '';

/**
 * General helpers
 */

function eb_leaders_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );

        $stmt->execute([$table]);

        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }

        /*
         * Fallback: try selecting no rows from the table.
         * This confirms the table is usable even if information_schema is restricted.
         */
        $safeTable = str_replace('`', '', $table);
        $pdo->query('SELECT 1 FROM `' . $safeTable . '` LIMIT 1');

        return true;
    } catch (Throwable $exception) {
        return false;
    }
}

function eb_leaders_schedule_table(PDO $pdo): ?string
{
    /*
     * Your table name.
     */
    if (eb_leaders_table_exists($pdo, 'leader_schedules')) {
        return 'leader_schedules';
    }

    /*
     * Backwards-compatible fallbacks.
     */
    foreach (['leader_schedule', 'leader_availability'] as $table) {
        if (eb_leaders_table_exists($pdo, $table)) {
            return $table;
        }
    }

    return null;
}

function eb_leaders_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query('DESCRIBE `' . str_replace('`', '', $table) . '`');
        $columns = [];

        foreach ($stmt->fetchAll() as $row) {
            $columns[] = $row['Field'];
        }

        return $columns;
    } catch (Throwable $exception) {
        return [];
    }
}

function eb_leaders_has_col(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function eb_leaders_first_col(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function eb_leaders_val(array $row, array $keys, $default = '')
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
    }

    return $default;
}

function eb_leaders_media_url(?string $path): string
{
    $path = trim((string)$path);

    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return url($path);
}

function eb_leaders_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials ?: '?';
}

function eb_leaders_format_datetime(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return '';
    }

    return date('d M Y', $timestamp);
}

/**
 * Schedule helpers
 */


function eb_leaders_get_schedule_config(PDO $pdo): array
{
    $table = eb_leaders_schedule_table($pdo);

    if (!$table) {
        return [
            'table' => null,
            'columns' => [],
            'id_col' => null,
            'leader_col' => null,
            'start_col' => null,
            'end_col' => null,
            'type_col' => null,
            'note_col' => null,
        ];
    }

    $columns = eb_leaders_columns($pdo, $table);

    return [
        'table' => $table,
        'columns' => $columns,
        'id_col' => eb_leaders_first_col($columns, ['id', 'schedule_id']),
        'leader_col' => eb_leaders_first_col($columns, ['leader_id']),
        'start_col' => eb_leaders_first_col($columns, [
            'schedule_start',
            'starts_at',
            'start_at',
            'start_datetime',
            'start_date',
            'from_date',
            'date_from',
        ]),
        'end_col' => eb_leaders_first_col($columns, [
            'schedule_end',
            'ends_at',
            'end_at',
            'end_datetime',
            'end_date',
            'to_date',
            'date_to',
        ]),
        'type_col' => eb_leaders_first_col($columns, [
            'schedule_type',
            'type',
            'status',
            'leader_status',
            'role_type',
        ]),
        'note_col' => eb_leaders_first_col($columns, [
            'note',
            'notes',
            'description',
        ]),
    ];
}

function eb_leaders_schedule_start(array $schedule): ?int
{
    $raw = eb_leaders_val($schedule, [
        'schedule_start',
        'starts_at',
        'start_at',
        'start_datetime',
        'start_date',
        'from_date',
        'date_from',
    ], '');

    $timestamp = strtotime((string)$raw);

    return $timestamp ?: null;
}

function eb_leaders_schedule_end(array $schedule): ?int
{
    $raw = eb_leaders_val($schedule, [
        'schedule_end',
        'ends_at',
        'end_at',
        'end_datetime',
        'end_date',
        'to_date',
        'date_to',
    ], '');

    $timestamp = strtotime((string)$raw);

    if ($timestamp) {
        return $timestamp;
    }

    $start = eb_leaders_schedule_start($schedule);

    if ($start) {
        return strtotime('+1 day', $start) ?: null;
    }

    return null;
}

function eb_leaders_schedule_status(array $schedule): string
{
    $raw = strtolower((string)eb_leaders_val($schedule, [
        'schedule_type',
        'type',
        'status',
        'leader_status',
        'role_type',
    ], ''));

    if (str_contains($raw, 'home')) {
        return 'home_contact';
    }

    if (
        str_contains($raw, 'country')
        || str_contains($raw, 'finland')
        || str_contains($raw, 'in_country')
    ) {
        return 'in_country';
    }

    if (!empty($schedule['is_home_contact'])) {
        return 'home_contact';
    }

    return 'in_country';
}

function eb_leaders_current_schedule_for_leader(array $schedules): ?array
{
    $now = time();

    foreach ($schedules as $schedule) {
        $start = eb_leaders_schedule_start($schedule);
        $end = eb_leaders_schedule_end($schedule);

        if (!$start || !$end) {
            continue;
        }

        if ($now >= $start && $now <= $end) {
            return $schedule;
        }
    }

    return null;
}

function eb_leaders_get_next_schedule(array $schedules): ?array
{
    $now = time();
    $next = null;
    $nextStart = PHP_INT_MAX;

    foreach ($schedules as $schedule) {
        $start = eb_leaders_schedule_start($schedule);

        if (!$start || $start <= $now) {
            continue;
        }

        if ($start < $nextStart) {
            $nextStart = $start;
            $next = $schedule;
        }
    }

    return $next;
}

function eb_leaders_schedule_label(?array $schedule): string
{
    if (!$schedule) {
        return '';
    }

    $startRaw = eb_leaders_val($schedule, [
        'schedule_start',
        'starts_at',
        'start_at',
        'start_datetime',
        'start_date',
        'from_date',
        'date_from',
    ], '');

    $endRaw = eb_leaders_val($schedule, [
        'schedule_end',
        'ends_at',
        'end_at',
        'end_datetime',
        'end_date',
        'to_date',
        'date_to',
    ], '');

    $parts = [];

    if ($startRaw) {
        $parts[] = 'From ' . eb_leaders_format_datetime((string)$startRaw);
    }

    if ($endRaw) {
        $parts[] = 'until ' . eb_leaders_format_datetime((string)$endRaw);
    }

    return implode(' ', $parts);
}

function eb_leaders_status_from_leader(array $leader): string
{
    $rawStatus = strtolower((string)eb_leaders_val($leader, [
        'current_status',
        'status',
        'leader_status',
    ], ''));

    if (str_contains($rawStatus, 'home')) {
        return 'home_contact';
    }

    if (
        str_contains($rawStatus, 'country')
        || str_contains($rawStatus, 'finland')
        || str_contains($rawStatus, 'in_country')
    ) {
        return 'in_country';
    }

    if (!empty($leader['is_home_contact']) || !empty($leader['home_contact'])) {
        return 'home_contact';
    }

    if (!empty($leader['is_in_country']) || !empty($leader['in_country'])) {
        return 'in_country';
    }

    return 'not_scheduled';
}

/**
 * Parent-facing status labels
 */

function eb_leaders_parent_status(array $leader): array
{
    $currentSchedule = $leader['_current_schedule'] ?? null;
    $schedules = $leader['_schedules'] ?? [];

    if ($currentSchedule) {
        $currentType = eb_leaders_schedule_status($currentSchedule);

        if ($currentType === 'home_contact') {
            return [
                'status' => 'current_home_contact',
                'label' => 'Current Home Contact',
                'class' => 'status-home-contact',
                'detail' => eb_leaders_schedule_label($currentSchedule),
            ];
        }

        return [
            'status' => 'supporting_finland',
            'label' => 'Supporting in Finland',
            'class' => 'status-in-country',
            'detail' => eb_leaders_schedule_label($currentSchedule),
        ];
    }

    $nextSchedule = eb_leaders_get_next_schedule($schedules);

    if ($nextSchedule) {
        $nextType = eb_leaders_schedule_status($nextSchedule);

        if ($nextType === 'home_contact') {
            return [
                'status' => 'preparing_home_contact',
                'label' => 'Preparing to be Home Contact',
                'class' => 'status-preparing-home-contact',
                'detail' => eb_leaders_schedule_label($nextSchedule),
            ];
        }

        return [
            'status' => 'due_finland',
            'label' => 'Due to arrive in Finland',
            'class' => 'status-due-finland',
            'detail' => eb_leaders_schedule_label($nextSchedule),
        ];
    }

    $fallbackStatus = eb_leaders_status_from_leader($leader);

    if ($fallbackStatus === 'home_contact') {
        return [
            'status' => 'current_home_contact',
            'label' => 'Current Home Contact',
            'class' => 'status-home-contact',
            'detail' => '',
        ];
    }

    if ($fallbackStatus === 'in_country') {
        return [
            'status' => 'supporting_finland',
            'label' => 'Supporting in Finland',
            'class' => 'status-in-country',
            'detail' => '',
        ];
    }

    return [
        'status' => 'supporting_remotely',
        'label' => 'Supporting remotely',
        'class' => 'status-supporting-remotely',
        'detail' => '',
    ];
}

function eb_leaders_admin_status_label(string $status): string
{
    if ($status === 'home_contact') {
        return 'Home contact';
    }

    if ($status === 'in_country') {
        return 'In country';
    }

    return 'Not scheduled';
}

/**
 * Data fetchers
 */

function eb_leaders_fetch_all(PDO $pdo, array $leaderColumns): array
{
    $where = '';

    if (eb_leaders_has_col($leaderColumns, 'is_active')) {
        $where = 'WHERE is_active = 1';
    }

    $stmt = $pdo->query(
        'SELECT *
         FROM leaders
         ' . $where
    );

    $leaders = $stmt->fetchAll();

    shuffle($leaders);

    return $leaders;
}

function eb_leaders_fetch_schedules(PDO $pdo, array $scheduleConfig, array $leaders): array
{
    if (!$scheduleConfig['table'] || !$scheduleConfig['leader_col'] || empty($leaders)) {
        return [];
    }

    $leaderIds = array_map(static function ($leader) {
        return (int)$leader['id'];
    }, $leaders);

    $leaderIds = array_values(array_filter($leaderIds));

    if (empty($leaderIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($leaderIds), '?'));
    $orderCol = $scheduleConfig['start_col'] ?: $scheduleConfig['id_col'];

    $sql = 'SELECT * FROM `' . $scheduleConfig['table'] . '`
            WHERE `' . $scheduleConfig['leader_col'] . '` IN (' . $placeholders . ')';

    if ($orderCol) {
        $sql .= ' ORDER BY `' . $orderCol . '` ASC';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($leaderIds);

    $grouped = [];

    foreach ($stmt->fetchAll() as $schedule) {
        $leaderId = (int)$schedule[$scheduleConfig['leader_col']];

        if (!isset($grouped[$leaderId])) {
            $grouped[$leaderId] = [];
        }

        $grouped[$leaderId][] = $schedule;
    }

    return $grouped;
}

/**
 * Admin save helpers
 */

function eb_leaders_save_leader(PDO $pdo, array $leaderColumns): void
{
    $leaderId = (int)($_POST['leader_id'] ?? 0);

    $input = [
        'name' => trim($_POST['name'] ?? ''),
        'role' => trim($_POST['role'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'bio' => trim($_POST['bio'] ?? ''),
        'photo_url' => trim($_POST['photo_url'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'is_home_contact' => isset($_POST['is_home_contact']) ? 1 : 0,
        'is_in_country' => isset($_POST['is_in_country']) ? 1 : 0,
        'hide_from_schedule' => isset($_POST['hide_from_schedule']) ? 1 : 0,
    ];

    // Only trip_admin can change the role field
    if (!is_trip_admin()) {
        unset($input['role']);
    }

    if ($input['name'] === '') {
        throw new RuntimeException('Leader name is required.');
    }

    $allowed = [];

    foreach ($input as $key => $value) {
        if (eb_leaders_has_col($leaderColumns, $key)) {
            $allowed[$key] = $value;
        }
    }

    if ($leaderId > 0) {
        $sets = [];
        $values = [];

        foreach ($allowed as $column => $value) {
            $sets[] = '`' . $column . '` = ?';
            $values[] = $value;
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $leaderId;

        $stmt = $pdo->prepare(
            'UPDATE leaders SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );

        $stmt->execute($values);
    } else {
        $columns = array_keys($allowed);
        $values = array_values($allowed);

        if (empty($columns)) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO leaders (`' . implode('`, `', $columns) . '`)
             VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')'
        );

        $stmt->execute($values);
    }
}

function eb_leaders_add_schedule(PDO $pdo, array $scheduleConfig): void
{
    if (!$scheduleConfig['table'] || !$scheduleConfig['leader_col'] || !$scheduleConfig['start_col']) {
        throw new RuntimeException('Leader schedule table is not configured.');
    }

    $leaderId = (int)($_POST['leader_id'] ?? 0);
    $scheduleType = $_POST['schedule_type'] ?? 'in_country';
    $start = trim($_POST['schedule_start'] ?? '');
    $end = trim($_POST['schedule_end'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($leaderId <= 0) {
        throw new RuntimeException('Choose a leader.');
    }

    if ($start === '') {
        throw new RuntimeException('Schedule start is required.');
    }

    $columns = [];
    $values = [];

    $columns[] = $scheduleConfig['leader_col'];
    $values[] = $leaderId;

    $columns[] = $scheduleConfig['start_col'];
    $values[] = str_replace('T', ' ', $start);

    if ($scheduleConfig['end_col']) {
        $columns[] = $scheduleConfig['end_col'];
        $values[] = $end !== '' ? str_replace('T', ' ', $end) : null;
    }

    if ($scheduleConfig['type_col']) {
        $columns[] = $scheduleConfig['type_col'];
        $values[] = $scheduleType;
    }

    if ($scheduleConfig['note_col']) {
        $columns[] = $scheduleConfig['note_col'];
        $values[] = $note;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO `' . $scheduleConfig['table'] . '`
            (`' . implode('`, `', $columns) . '`)
         VALUES
            (' . implode(',', array_fill(0, count($columns), '?')) . ')'
    );

    $stmt->execute($values);
}

function eb_leaders_delete_schedule(PDO $pdo, array $scheduleConfig): void
{
    if (!$scheduleConfig['table'] || !$scheduleConfig['id_col']) {
        throw new RuntimeException('Schedule deletion is not configured.');
    }

    $scheduleId = (int)($_POST['schedule_id'] ?? 0);

    if ($scheduleId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'DELETE FROM `' . $scheduleConfig['table'] . '`
         WHERE `' . $scheduleConfig['id_col'] . '` = ?'
    );

    $stmt->execute([$scheduleId]);
}

function eb_leaders_edit_schedule(PDO $pdo, array $scheduleConfig): void
{
    if (!$scheduleConfig['table'] || !$scheduleConfig['id_col'] || !$scheduleConfig['start_col']) {
        throw new RuntimeException('Schedule editing is not configured.');
    }

    $scheduleId = (int)($_POST['schedule_id'] ?? 0);
    $scheduleType = $_POST['schedule_type'] ?? 'in_country';
    $start = trim($_POST['schedule_start'] ?? '');
    $end = trim($_POST['schedule_end'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($scheduleId <= 0) {
        throw new RuntimeException('Invalid schedule entry.');
    }

    if ($start === '') {
        throw new RuntimeException('Schedule start is required.');
    }

    $sets = [];
    $values = [];

    $sets[] = '`' . $scheduleConfig['start_col'] . '` = ?';
    $values[] = str_replace('T', ' ', $start);

    if ($scheduleConfig['end_col']) {
        $sets[] = '`' . $scheduleConfig['end_col'] . '` = ?';
        $values[] = $end !== '' ? str_replace('T', ' ', $end) : null;
    }

    if ($scheduleConfig['type_col']) {
        $sets[] = '`' . $scheduleConfig['type_col'] . '` = ?';
        $values[] = $scheduleType;
    }

    if ($scheduleConfig['note_col']) {
        $sets[] = '`' . $scheduleConfig['note_col'] . '` = ?';
        $values[] = $note;
    }

    if (empty($sets)) {
        return;
    }

    $values[] = $scheduleId;

    $stmt = $pdo->prepare(
        'UPDATE `' . $scheduleConfig['table'] . '`
         SET ' . implode(', ', $sets) . '
         WHERE `' . $scheduleConfig['id_col'] . '` = ?'
    );

    $stmt->execute($values);
}

/**
 * Duty roster helpers
 */

function eb_leaders_ensure_duty_table(PDO $pdo): bool
{
    if (eb_leaders_table_exists($pdo, 'leader_duty_roster')) {
        return true;
    }

    try {
        $pdo->exec('
            CREATE TABLE leader_duty_roster (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                leader_id INT UNSIGNED NOT NULL,
                duty_date DATE NOT NULL,
                status ENUM("on_duty","off_duty","day_off") NOT NULL DEFAULT "on_duty",
                note TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_leader_duty_date (leader_id, duty_date),
                INDEX idx_duty_date (duty_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function eb_leaders_fetch_duty_roster(PDO $pdo, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM leader_duty_roster
         WHERE duty_date BETWEEN ? AND ?
         ORDER BY duty_date ASC, leader_id ASC'
    );
    $stmt->execute([$startDate, $endDate]);

    $roster = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = (int)$row['leader_id'] . '_' . $row['duty_date'];
        $roster[$key] = $row;
    }

    return $roster;
}

function eb_leaders_save_duty(PDO $pdo): void
{
    $leaderId = (int)($_POST['leader_id'] ?? 0);
    $dutyDate = trim($_POST['duty_date'] ?? '');
    $status = trim($_POST['duty_status'] ?? 'on_duty');
    $note = trim($_POST['duty_note'] ?? '');

    if ($leaderId <= 0) {
        throw new RuntimeException('Choose a leader.');
    }

    if ($dutyDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dutyDate)) {
        throw new RuntimeException('A valid date is required.');
    }

    $allowedStatuses = ['on_duty', 'off_duty', 'day_off'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'on_duty';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO leader_duty_roster (leader_id, duty_date, status, note)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note)'
    );

    $stmt->execute([$leaderId, $dutyDate, $status, $note]);
}

function eb_leaders_save_duty_bulk(PDO $pdo): void
{
    $entries = $_POST['duty'] ?? [];

    if (!is_array($entries)) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO leader_duty_roster (leader_id, duty_date, status, note)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note)'
    );

    foreach ($entries as $leaderId => $dates) {
        $leaderId = (int)$leaderId;
        if ($leaderId <= 0) continue;

        foreach ($dates as $date => $status) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

            $allowedStatuses = ['on_duty', 'off_duty', 'day_off'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'on_duty';
            }

            $stmt->execute([$leaderId, $date, $status, '']);
        }
    }
}

/**
 * Setup
 */

$leaderColumns = eb_leaders_columns($pdo, 'leaders');
$scheduleConfig = eb_leaders_get_schedule_config($pdo);

/**
 * Admin POST actions
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    if (is_readonly()) {
        $error = 'Your account has read-only access and cannot manage leaders.';
    } else {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_leader') {
            eb_leaders_save_leader($pdo, $leaderColumns);
            redirect('leaders.php?tab=manage');
        }

        if ($action === 'add_schedule') {
            eb_leaders_add_schedule($pdo, $scheduleConfig);
            redirect('leaders.php?tab=schedule');
        }

        if ($action === 'delete_schedule') {
            eb_leaders_delete_schedule($pdo, $scheduleConfig);
            redirect('leaders.php?tab=schedule');
        }

        if ($action === 'edit_schedule') {
            eb_leaders_edit_schedule($pdo, $scheduleConfig);
            redirect('leaders.php?tab=schedule');
        }

        if ($action === 'save_duty') {
            eb_leaders_ensure_duty_table($pdo);
            eb_leaders_save_duty($pdo);
            redirect('leaders.php?tab=duty');
        }

        if ($action === 'save_duty_bulk') {
            eb_leaders_ensure_duty_table($pdo);
            eb_leaders_save_duty_bulk($pdo);
            redirect('leaders.php?tab=duty');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
    } // end else (not readonly)
}

/**
 * Fetch data
 */

$leaders = eb_leaders_fetch_all($pdo, $leaderColumns);
$schedulesByLeader = eb_leaders_fetch_schedules($pdo, $scheduleConfig, $leaders);

$decoratedLeaders = [];

foreach ($leaders as $leader) {
    $leaderId = (int)$leader['id'];
    $leaderSchedules = $schedulesByLeader[$leaderId] ?? [];
    $currentSchedule = eb_leaders_current_schedule_for_leader($leaderSchedules);
    $parentStatus = null;

    $leader['_schedules'] = $leaderSchedules;
    $leader['_current_schedule'] = $currentSchedule;
    $leader['_parent_status'] = eb_leaders_parent_status($leader);

    $decoratedLeaders[] = $leader;
}

/**
 * Group for parent-facing display
 */

$homeContacts = [];
$inCountryLeaders = [];
$upcomingLeaders = [];
$remoteLeaders = [];

foreach ($decoratedLeaders as $leader) {
    $parentStatus = $leader['_parent_status'];

    // Leaders marked as hidden from schedule are not shown on the parent view
    if (!empty($leader['hide_from_schedule'])) {
        continue;
    }

    if ($parentStatus['status'] === 'current_home_contact') {
        $homeContacts[] = $leader;
    } elseif ($parentStatus['status'] === 'supporting_finland') {
        $inCountryLeaders[] = $leader;
    } elseif (
        $parentStatus['status'] === 'due_finland'
        || $parentStatus['status'] === 'preparing_home_contact'
    ) {
        $upcomingLeaders[] = $leader;
    } else {
        $remoteLeaders[] = $leader;
    }
}

$tab = $_GET['tab'] ?? 'view';

if (!$isAdmin) {
    $tab = 'view';
}

/**
 * Render parent-facing card
 */

function eb_leaders_render_card(array $leader): void
{
    $name = (string)eb_leaders_val($leader, ['name', 'full_name'], 'Leader');
    $bio = (string)eb_leaders_val($leader, ['bio', 'description', 'profile'], '');
    $photo = eb_leaders_media_url((string)eb_leaders_val($leader, ['photo_url', 'image_url', 'avatar_url'], ''));

    $parentStatus = $leader['_parent_status'] ?? eb_leaders_parent_status($leader);

    $status = $parentStatus['status'];
    $statusLabel = $parentStatus['label'];
    $statusClass = $parentStatus['class'];
    $scheduleLabel = $parentStatus['detail'];

    $isHomeContact = $status === 'current_home_contact';
    ?>
    <article class="leader-card leader-profile-card">
        <div class="leader-photo-wrap">
            <?php if ($photo !== ''): ?>
                <img
                    class="leader-photo"
                    src="<?= e($photo) ?>"
                    alt="Photo of <?= e($name) ?>"
                >
            <?php else: ?>
                <div class="leader-photo leader-photo-placeholder" aria-hidden="true">
                    <?= e(eb_leaders_initials($name)) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="leader-card-body">
            <div class="leader-card-heading">
                <div>
                    <h2><?= e($name) ?></h2>
                </div>
            </div>

            <p class="leader-status-line">
                <span class="leader-status <?= e($statusClass) ?>">
                    <?= e($statusLabel) ?>
                </span>
            </p>

            <?php if ($scheduleLabel !== ''): ?>
                <p class="leader-schedule">
                    <?= e($scheduleLabel) ?>
                </p>
            <?php endif; ?>

            <?php if ($bio !== ''): ?>
                <div class="leader-bio-wrapper">
                    <div class="leader-bio leader-bio-clamped">
                        <?= nl2br(e($bio)) ?>
                    </div>

                    <button type="button" class="leader-read-more" data-read-more>
                        Read more
                    </button>
                </div>
            <?php endif; ?>

            <?php if ($isHomeContact): ?>
                <div class="leader-contact-details">
                    <h3>Contact details</h3>

                    <?php if (!empty($leader['phone'])): ?>
                        <p class="mb-1">
                            <strong>Phone:</strong>
                            <a href="tel:<?= e(preg_replace('/\s+/', '', (string)$leader['phone'])) ?>">
                                <?= e($leader['phone']) ?>
                            </a>
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($leader['email'])): ?>
                        <p class="mb-0">
                            <strong>Email:</strong>
                            <a href="mailto:<?= e($leader['email']) ?>">
                                <?= e($leader['email']) ?>
                            </a>
                        </p>
                    <?php endif; ?>

                    <?php if (empty($leader['phone']) && empty($leader['email'])): ?>
                        <p class="muted mb-0">
                            Contact details have not been added yet.
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

include __DIR__ . '/header.php';
?>

<style>
    .compact-leaders-hero {
        padding: 1.25rem 0 !important;
        margin-bottom: 1rem !important;
    }

    .compact-leaders-hero h1 {
        margin-bottom: 0.25rem;
    }

    .compact-leaders-hero .lead {
        font-size: 1.05rem;
    }

    .leader-contact-warning {
        border-left: 8px solid #ffdd00;
        background: #fff7bf;
        color: #1d1d1d;
        padding: 0.9rem 1rem;
        margin-bottom: 1.5rem;
    }

    .leader-contact-warning strong {
        display: block;
        font-weight: 900;
        margin-bottom: 0.2rem;
    }

    .leader-section {
        margin-bottom: 2.25rem;
    }

    .leader-section-heading {
        border-bottom: 4px solid #7413dc;
        padding-bottom: 0.75rem;
        margin-bottom: 1rem;
    }

    .leader-section-heading h2 {
        margin-bottom: 0.2rem;
        font-weight: 900;
    }

    .leader-section-heading p {
        margin-bottom: 0;
    }

    .leader-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    @media (min-width: 768px) {
        .leader-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .leader-profile-card {
        display: grid;
        grid-template-columns: 104px minmax(0, 1fr);
        gap: 1rem;
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
    }

    @media (max-width: 560px) {
        .leader-profile-card {
            grid-template-columns: 1fr;
        }
    }

    .leader-photo-wrap {
        width: 104px;
    }

    .leader-photo {
        width: 104px !important;
        height: 104px !important;
        max-width: 104px !important;
        max-height: 104px !important;
        object-fit: cover;
        display: block;
        border: 2px solid #1d1d1d;
        background: #f3f2f1;
    }

    .leader-photo-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        background: #7413dc;
        color: #ffffff;
        font-size: 2.4rem;
        font-weight: 900;
    }

    .leader-card-body h2 {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 900;
    }

    .leader-card-heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
    }

    .leader-status-line {
        margin: 0.45rem 0;
    }

    .leader-status {
        display: inline-block;
        padding: 0.3rem 0.5rem;
        border: 2px solid #1d1d1d;
        font-weight: 900;
        line-height: 1.2;
    }

    .status-in-country {
        background: #00703c;
        color: #ffffff;
    }

    .status-home-contact {
        background: #1d70b8;
        color: #ffffff;
    }

    .status-due-finland {
        background: #ffdd00;
        color: #1d1d1d;
    }

    .status-preparing-home-contact {
        background: #e7f1fb;
        color: #1d1d1d;
    }

    .status-supporting-remotely {
        background: #f3f2f1;
        color: #1d1d1d;
    }

    .leader-schedule {
        color: #505a5f;
        margin-bottom: 0.6rem;
        font-size: 0.95rem;
    }

    .leader-bio-wrapper {
        margin-top: 0.5rem;
    }

    .leader-bio {
        margin-bottom: 0;
    }

    .leader-bio-clamped {
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .leader-bio-expanded {
        display: block;
        overflow: visible;
    }

    .leader-read-more {
        margin-top: 0.35rem;
        padding: 0;
        border: 0;
        background: transparent;
        color: #1d70b8;
        font-weight: 900;
        text-decoration: underline;
        cursor: pointer;
    }

    .leader-read-more:hover,
    .leader-read-more:focus {
        color: #003078;
    }

    .leader-contact-details {
        border-left: 6px solid #1d70b8;
        background: #eef7ff;
        padding: 0.75rem;
        margin-top: 0.85rem;
    }

    .leader-contact-details h3 {
        font-size: 1rem;
        margin: 0 0 0.5rem;
        font-weight: 900;
    }

    .leader-contact-details a {
        font-weight: 800;
    }

    .admin-leader-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }

    @media (max-width: 760px) {
        .admin-leader-form-grid {
            grid-template-columns: 1fr;
        }
    }

    .admin-leader-row {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .admin-leader-row summary {
        cursor: pointer;
        font-weight: 900;
    }

    .schedule-table-wrap {
        overflow-x: auto;
    }

    .schedule-table {
        width: 100%;
        border-collapse: collapse;
        background: #ffffff;
    }

    .schedule-table th,
    .schedule-table td {
        border: 1px solid #d8d8d8;
        padding: 0.55rem;
        vertical-align: top;
    }

    .schedule-table th {
        background: #f3f2f1;
        font-weight: 900;
    }

    /* Duty roster */
    .duty-roster-wrap {
        overflow-x: auto;
        margin-bottom: 1rem;
    }

    .duty-roster-table {
        width: 100%;
        border-collapse: collapse;
        background: #ffffff;
        min-width: 900px;
    }

    .duty-roster-table th,
    .duty-roster-table td {
        border: 1px solid #d8d8d8;
        padding: 0.4rem 0.35rem;
        text-align: center;
        vertical-align: middle;
        font-size: 0.85rem;
    }

    .duty-roster-table th {
        background: #f3f2f1;
        font-weight: 900;
    }

    .duty-name-col {
        text-align: left !important;
        white-space: nowrap;
        min-width: 120px;
        padding-left: 0.75rem !important;
    }

    .duty-date-col {
        min-width: 70px;
    }

    .duty-day-name {
        display: block;
        font-size: 0.75rem;
        text-transform: uppercase;
    }

    .duty-day-num {
        display: block;
        font-size: 0.8rem;
    }

    .duty-today {
        border-left: 3px solid #00703c !important;
        border-right: 3px solid #00703c !important;
        background: #f0faf4 !important;
    }

    .duty-roster-table thead .duty-today {
        border-top: 3px solid #00703c !important;
    }

    .duty-roster-table tbody tr:last-child .duty-today {
        border-bottom: 3px solid #00703c !important;
    }

    .duty-cell-on {
        background: #cce5d6 !important;
    }

    .duty-cell-off {
        background: #fde0da !important;
    }

    .duty-cell-dayoff {
        background: #e7f1fb !important;
    }

    .duty-select {
        width: 100%;
        border: 1px solid #b1b4b6;
        background: transparent;
        font-size: 0.8rem;
        padding: 0.2rem;
        cursor: pointer;
    }

    .duty-legend-item {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        margin-right: 1rem;
        font-size: 0.9rem;
        font-weight: 700;
    }

    .duty-swatch {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 1px solid #1d1d1d;
    }

    .duty-swatch-on { background: #cce5d6; }
    .duty-swatch-off { background: #fde0da; }
    .duty-swatch-dayoff { background: #e7f1fb; }

    .duty-cell-disabled {
        background: #f3f2f1 !important;
    }

    .duty-not-here {
        color: #b1b4b6;
        font-size: 0.8rem;
    }

    /* Duty view mode (per-day rows, all leaders on one line) */
    .duty-view-list {
        border: 2px solid #d8d8d8;
        background: #ffffff;
    }

    .duty-view-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.6rem 0.75rem;
        border-bottom: 1px solid #e8e8e8;
    }

    .duty-view-row:last-child {
        border-bottom: none;
    }

    .duty-view-row-today {
        border: 3px solid #00703c;
        border-radius: 0;
        background: #f0faf4;
        margin: -1px;
        position: relative;
        z-index: 1;
    }

    .duty-view-row-date {
        min-width: 90px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }

    .duty-view-row-dayname {
        font-weight: 900;
        font-size: 0.9rem;
    }

    .duty-view-row-daynum {
        font-size: 0.85rem;
        color: #505a5f;
    }

    .duty-view-today-badge {
        display: inline-block;
        background: #00703c;
        color: #ffffff;
        font-size: 0.7rem;
        font-weight: 900;
        padding: 0.1rem 0.4rem;
        text-transform: uppercase;
    }

    .duty-view-row-leaders {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
    }

    .duty-pill {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
        font-weight: 700;
        border: 1px solid #d8d8d8;
    }

    .duty-pill-on {
        background: #cce5d6;
        border-color: #00703c;
        color: #00703c;
    }

    .duty-pill-off {
        background: #fde0da;
        border-color: #d4351c;
        color: #d4351c;
    }

    .duty-pill-dayoff {
        background: #e7f1fb;
        border-color: #1d70b8;
        color: #1d70b8;
    }

    .duty-pill-unset {
        background: #f3f2f1;
        border-color: #b1b4b6;
        color: #505a5f;
    }

    @media (max-width: 600px) {
        .duty-view-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.35rem;
        }

        .duty-view-row-date {
            min-width: auto;
        }
    }

    /* Duty faces view - shared */
    .duty-faces-nav-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        font-size: 1.4rem;
        font-weight: 900;
        background: #ffffff;
        border: 2px solid #1d1d1d;
        color: #1d1d1d;
        text-decoration: none;
        cursor: pointer;
        flex-shrink: 0;
    }

    .duty-faces-nav-btn:hover {
        background: #1d1d1d;
        color: #ffffff;
    }

    .duty-faces-nav-disabled {
        opacity: 0.3;
        cursor: default;
        pointer-events: none;
    }

    .duty-faces-dot {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 50%;
    }

    .duty-dot-on { background: #00703c; }
    .duty-dot-off { background: #d4351c; }

    /* Week/trip navigation bar */
    .duty-week-nav {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-bottom: 1.25rem;
        padding: 0.75rem;
        background: #f3f2f1;
        border: 2px solid #d8d8d8;
    }

    .duty-week-nav-toggle {
        display: flex;
        gap: 0;
    }

    .duty-view-toggle-btn {
        padding: 0.4rem 0.85rem;
        font-size: 0.85rem;
        font-weight: 900;
        border: 2px solid #1d1d1d;
        background: #ffffff;
        color: #1d1d1d;
        text-decoration: none;
        cursor: pointer;
    }

    .duty-view-toggle-btn:first-child {
        border-right: none;
    }

    .duty-view-toggle-active {
        background: #1d1d1d;
        color: #ffffff;
    }

    .duty-week-nav-arrows {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .duty-week-nav-label {
        font-weight: 900;
        font-size: 0.95rem;
        white-space: nowrap;
    }

    /* Day cards list */
    .duty-week-list {
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
    }

    .duty-day-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 0.75rem 1rem;
    }

    .duty-day-card-today {
        border: 3px solid #00703c;
        background: #f0faf4;
    }

    .duty-day-card-header {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.5rem;
        flex-wrap: wrap;
    }

    .duty-day-card-dayname {
        font-weight: 900;
        font-size: 1rem;
    }

    .duty-day-card-datenum {
        font-size: 0.9rem;
        color: #505a5f;
    }

    .duty-day-card-body {
        display: flex;
        gap: 1.5rem;
        flex-wrap: wrap;
    }

    .duty-day-card-section {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .duty-day-card-section-label {
        display: flex;
        align-items: center;
        gap: 0.3rem;
        font-size: 0.8rem;
        font-weight: 900;
        text-transform: uppercase;
        color: #505a5f;
        white-space: nowrap;
    }

    .duty-day-card-faces {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
    }

    .duty-day-face {
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    .duty-day-face-photo {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #00703c;
        background: #f3f2f1;
    }

    .duty-day-face-photo-off {
        border-color: #d4351c;
        opacity: 0.7;
    }

    .duty-day-face-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        background: #7413dc;
        color: #ffffff;
        font-size: 0.75rem;
        font-weight: 900;
    }

    .duty-day-face-name {
        font-size: 0.8rem;
        font-weight: 700;
    }

    .duty-day-card-none {
        font-size: 0.85rem;
        color: #b1b4b6;
    }

    @media (max-width: 600px) {
        .duty-week-nav {
            flex-direction: column;
            align-items: stretch;
        }

        .duty-week-nav-arrows {
            justify-content: space-between;
        }

        .duty-week-nav-toggle {
            justify-content: center;
        }

        .duty-day-card-body {
            flex-direction: column;
            gap: 0.6rem;
        }

        .duty-day-card-section {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.35rem;
        }
    }
</style>

<section class="page-hero compact-leaders-hero">
    <div class="container">
        <h1>Leadership team</h1>
        <p class="lead mb-0">
            Meet the leaders supporting the Explorer Belt trip.
        </p>
    </div>
</section>

<main id="main-content" class="container my-4">

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <div class="leader-contact-warning">
        <strong>Please do not contact leaders in Finland directly.</strong>
        <span>
            If you need to contact the trip team, please contact the current home contact shown below.
        </span>
    </div>

    <?php if ($isAdmin): ?>
        <nav class="admin-tabs">
            <a class="admin-tab <?= $tab === 'view' ? 'active' : '' ?>" href="<?= e(url('leaders.php?tab=view')) ?>">
                Parent view
            </a>
            <a class="admin-tab <?= $tab === 'duty' ? 'active' : '' ?>" href="<?= e(url('leaders.php?tab=duty')) ?>">
                Duty roster
            </a>
            <a class="admin-tab <?= $tab === 'manage' ? 'active' : '' ?>" href="<?= e(url('leaders.php?tab=manage')) ?>">
                Manage leaders
            </a>
            <a class="admin-tab <?= $tab === 'schedule' ? 'active' : '' ?>" href="<?= e(url('leaders.php?tab=schedule')) ?>">
                Manage schedule
            </a>
        </nav>
    <?php endif; ?>

    <?php if ($tab === 'manage' && $isAdmin): ?>

        <section class="admin-panel mb-4">
            <h2>Add leader</h2>

            <form method="post">
                <input type="hidden" name="action" value="save_leader">

                <div class="admin-leader-form-grid">
                    <div class="form-group">
                        <label>Name</label>
                        <input class="form-control" name="name" required>
                    </div>

                    <div class="form-group">
                        <label>Internal role</label>
                        <?php if (is_trip_admin()): ?>
                            <select class="form-control" name="role">
                                <option value="">Leader</option>
                                <option value="trip_admin">Trip admin</option>
                                <option value="readonly">Read-only</option>
                            </select>
                        <?php else: ?>
                            <input class="form-control" name="role" disabled value="">
                        <?php endif; ?>
                        <small class="form-text text-muted">
                            Internal only. This is not shown to parents.<?php if (!is_trip_admin()): ?> Only trip admins can change roles.<?php endif; ?>
                        </small>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input class="form-control" type="email" name="email">
                    </div>

                    <div class="form-group">
                        <label>Phone</label>
                        <input class="form-control" name="phone">
                    </div>

                    <div class="form-group">
                        <label>Photo URL</label>
                        <input class="form-control" type="url" name="photo_url">
                    </div>

                    <div class="form-group">
                        <label>Status fallback</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_in_country" id="add_is_in_country">
                            <label class="form-check-label" for="add_is_in_country">Supporting in Finland</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_home_contact" id="add_is_home_contact">
                            <label class="form-check-label" for="add_is_home_contact">Current Home Contact</label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Bio</label>
                    <textarea class="form-control" name="bio" rows="4"></textarea>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_active" id="add_is_active" checked>
                    <label class="form-check-label" for="add_is_active">Active</label>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="hide_from_schedule" id="add_hide_from_schedule">
                    <label class="form-check-label" for="add_hide_from_schedule">Hide from parent schedule</label>
                    <small class="form-text text-muted d-block">
                        Leader can still log in but will not appear on the parent-facing schedule.
                    </small>
                </div>

                <button class="btn btn-primary"<?php if (is_readonly()): ?> disabled<?php endif; ?>>Add leader</button>
            </form>
        </section>

        <section class="admin-panel">
            <h2>Edit leaders</h2>

            <?php foreach ($decoratedLeaders as $leader): ?>
                <?php
                $leaderId = (int)$leader['id'];
                $name = (string)eb_leaders_val($leader, ['name', 'full_name'], 'Leader');
                ?>
                <details class="admin-leader-row">
                    <summary><?= e($name) ?></summary>

                    <form method="post" class="mt-3">
                        <input type="hidden" name="action" value="save_leader">
                        <input type="hidden" name="leader_id" value="<?= $leaderId ?>">

                        <div class="admin-leader-form-grid">
                            <div class="form-group">
                                <label>Name</label>
                                <input class="form-control" name="name" value="<?= e($name) ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Internal role</label>
                                <?php
                                    $currentRole = (string)eb_leaders_val($leader, ['role'], '');
                                ?>
                                <?php if (is_trip_admin()): ?>
                                    <select class="form-control" name="role">
                                        <option value=""<?= $currentRole === '' ? ' selected' : '' ?>>Leader</option>
                                        <option value="trip_admin"<?= $currentRole === 'trip_admin' ? ' selected' : '' ?>>Trip admin</option>
                                        <option value="readonly"<?= $currentRole === 'readonly' ? ' selected' : '' ?>>Read-only</option>
                                    </select>
                                <?php else: ?>
                                    <input class="form-control" name="role" value="<?= e($currentRole) ?>" disabled>
                                <?php endif; ?>
                                <small class="form-text text-muted">
                                    Internal only. This is not shown to parents.<?php if (!is_trip_admin()): ?> Only trip admins can change roles.<?php endif; ?>
                                </small>
                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <input class="form-control" type="email" name="email" value="<?= e(eb_leaders_val($leader, ['email'], '')) ?>">
                            </div>

                            <div class="form-group">
                                <label>Phone</label>
                                <input class="form-control" name="phone" value="<?= e(eb_leaders_val($leader, ['phone'], '')) ?>">
                            </div>

                            <div class="form-group">
                                <label>Photo URL</label>
                                <input class="form-control" type="url" name="photo_url" value="<?= e(eb_leaders_val($leader, ['photo_url', 'image_url', 'avatar_url'], '')) ?>">
                            </div>

                            <div class="form-group">
                                <label>Status fallback</label>

                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="is_in_country"
                                        id="is_in_country_<?= $leaderId ?>"
                                        <?= !empty($leader['is_in_country']) || !empty($leader['in_country']) ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label" for="is_in_country_<?= $leaderId ?>">
                                        Supporting in Finland
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="is_home_contact"
                                        id="is_home_contact_<?= $leaderId ?>"
                                        <?= !empty($leader['is_home_contact']) || !empty($leader['home_contact']) ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label" for="is_home_contact_<?= $leaderId ?>">
                                        Current Home Contact
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Bio</label>
                            <textarea class="form-control" name="bio" rows="4"><?= e(eb_leaders_val($leader, ['bio', 'description', 'profile'], '')) ?></textarea>
                        </div>

                        <div class="form-check mb-3">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="is_active"
                                id="is_active_<?= $leaderId ?>"
                                <?= !isset($leader['is_active']) || (int)$leader['is_active'] === 1 ? 'checked' : '' ?>
                            >
                            <label class="form-check-label" for="is_active_<?= $leaderId ?>">Active</label>
                        </div>

                        <div class="form-check mb-3">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="hide_from_schedule"
                                id="hide_from_schedule_<?= $leaderId ?>"
                                <?= !empty($leader['hide_from_schedule']) ? 'checked' : '' ?>
                            >
                            <label class="form-check-label" for="hide_from_schedule_<?= $leaderId ?>">Hide from parent schedule</label>
                            <small class="form-text text-muted d-block">
                                Leader can still log in but will not appear on the parent-facing schedule.
                            </small>
                        </div>

                        <button class="btn btn-primary"<?php if (is_readonly()): ?> disabled<?php endif; ?>>Save leader</button>
                    </form>
                </details>
            <?php endforeach; ?>
        </section>

    <?php elseif ($tab === 'schedule' && $isAdmin): ?>

        <section class="admin-panel mb-4">
            <h2>Add schedule entry</h2>

            <?php if (!$scheduleConfig['table']): ?>
                <div class="alert alert-warning">
                    No leader schedule table was found. Create a table named <strong>leader_schedules</strong>.
                </div>

                <pre><code>CREATE TABLE leader_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    leader_id INT UNSIGNED NOT NULL,
    schedule_type ENUM('in_country','home_contact') NOT NULL DEFAULT 'in_country',
    schedule_start DATETIME NOT NULL,
    schedule_end DATETIME NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_leader_schedules_leader_id (leader_id)
);</code></pre>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="add_schedule">

                    <div class="admin-leader-form-grid">
                        <div class="form-group">
                            <label>Leader</label>
                            <select class="form-control" name="leader_id" required>
                                <option value="">Choose leader</option>
                                <?php foreach ($decoratedLeaders as $leader): ?>
                                    <option value="<?= (int)$leader['id'] ?>">
                                        <?= e(eb_leaders_val($leader, ['name', 'full_name'], 'Leader')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Schedule type</label>
                            <select class="form-control" name="schedule_type">
                                <option value="in_country">Supporting in Finland</option>
                                <option value="home_contact">Home Contact</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Start</label>
                            <input class="form-control" type="datetime-local" name="schedule_start" required>
                        </div>

                        <div class="form-group">
                            <label>End</label>
                            <input class="form-control" type="datetime-local" name="schedule_end">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Note</label>
                        <textarea class="form-control" name="note" rows="3"></textarea>
                    </div>

                    <button class="btn btn-primary"<?php if (is_readonly()): ?> disabled<?php endif; ?>>Add schedule</button>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($scheduleConfig['table']): ?>
            <section class="admin-panel">
                <h2>Existing schedule entries</h2>

                <div class="schedule-table-wrap">
                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th>Leader</th>
                                <th>Type</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Note</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($decoratedLeaders as $leader): ?>
                                <?php foreach (($leader['_schedules'] ?? []) as $schedule): ?>
                                    <?php
                                    $scheduleId = $scheduleConfig['id_col'] ? (int)$schedule[$scheduleConfig['id_col']] : 0;
                                    $type = eb_leaders_schedule_status($schedule);
                                    $schedStartRaw = (string)eb_leaders_val($schedule, ['schedule_start', 'starts_at', 'start_at', 'start_datetime', 'start_date', 'from_date', 'date_from'], '');
                                    $schedEndRaw = (string)eb_leaders_val($schedule, ['schedule_end', 'ends_at', 'end_at', 'end_datetime', 'end_date', 'to_date', 'date_to'], '');
                                    $schedNote = (string)eb_leaders_val($schedule, ['note', 'notes', 'description'], '');
                                    $schedStartLocal = $schedStartRaw ? date('Y-m-d\TH:i', strtotime($schedStartRaw)) : '';
                                    $schedEndLocal = $schedEndRaw ? date('Y-m-d\TH:i', strtotime($schedEndRaw)) : '';
                                    $editingThis = isset($_GET['edit_schedule']) && (int)$_GET['edit_schedule'] === $scheduleId;
                                    ?>
                                    <?php if ($editingThis && $scheduleId > 0 && !is_readonly()): ?>
                                        <tr style="background:#eef7ff;">
                                            <td><?= e(eb_leaders_val($leader, ['name', 'full_name'], 'Leader')) ?></td>
                                            <td colspan="5">
                                                <form method="post" class="d-flex flex-wrap align-items-end" style="gap:0.5rem;">
                                                    <input type="hidden" name="action" value="edit_schedule">
                                                    <input type="hidden" name="schedule_id" value="<?= $scheduleId ?>">

                                                    <div class="form-group mb-0" style="min-width:140px;">
                                                        <label class="small mb-0">Type</label>
                                                        <select class="form-control form-control-sm" name="schedule_type">
                                                            <option value="in_country"<?= $type !== 'home_contact' ? ' selected' : '' ?>>In Finland</option>
                                                            <option value="home_contact"<?= $type === 'home_contact' ? ' selected' : '' ?>>Home Contact</option>
                                                        </select>
                                                    </div>

                                                    <div class="form-group mb-0" style="min-width:160px;">
                                                        <label class="small mb-0">Start</label>
                                                        <input class="form-control form-control-sm" type="datetime-local" name="schedule_start" value="<?= e($schedStartLocal) ?>" required>
                                                    </div>

                                                    <div class="form-group mb-0" style="min-width:160px;">
                                                        <label class="small mb-0">End</label>
                                                        <input class="form-control form-control-sm" type="datetime-local" name="schedule_end" value="<?= e($schedEndLocal) ?>">
                                                    </div>

                                                    <div class="form-group mb-0" style="min-width:120px;">
                                                        <label class="small mb-0">Note</label>
                                                        <input class="form-control form-control-sm" name="note" value="<?= e($schedNote) ?>">
                                                    </div>

                                                    <div class="form-group mb-0">
                                                        <button class="btn btn-primary btn-sm">Save</button>
                                                        <a href="<?= e(url('leaders.php?tab=schedule')) ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td><?= e(eb_leaders_val($leader, ['name', 'full_name'], 'Leader')) ?></td>
                                            <td><?= e($type === 'home_contact' ? 'Home Contact' : 'Supporting in Finland') ?></td>
                                            <td><?= e(eb_leaders_format_datetime($schedStartRaw)) ?></td>
                                            <td><?= e(eb_leaders_format_datetime($schedEndRaw)) ?></td>
                                            <td><?= nl2br(e($schedNote)) ?></td>
                                            <td>
                                                <?php if ($scheduleId > 0): ?>
                                                    <div class="d-flex" style="gap:0.25rem;">
                                                        <?php if (!is_readonly()): ?>
                                                            <a href="<?= e(url('leaders.php?tab=schedule&edit_schedule=' . $scheduleId)) ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                                                        <?php endif; ?>
                                                        <form method="post" onsubmit="return confirm('Delete this schedule entry?');">
                                                            <input type="hidden" name="action" value="delete_schedule">
                                                            <input type="hidden" name="schedule_id" value="<?= $scheduleId ?>">
                                                            <button class="btn btn-outline-danger btn-sm"<?php if (is_readonly()): ?> disabled<?php endif; ?>>Delete</button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

    <?php elseif ($tab === 'duty' && $isAdmin): ?>

        <?php
        eb_leaders_ensure_duty_table($pdo);

        $dutyEditMode = isset($_GET['edit_roster']);

        // Build a per-leader date range from their in_country schedule entries
        // Only show days where a leader is scheduled as in_country
        $dutyLeaderRanges = []; // [leader_id => ['start' => date, 'end' => date, 'dates' => [...]]]
        $globalStart = null;
        $globalEnd = null;

        foreach ($decoratedLeaders as $leader) {
            if (!empty($leader['hide_from_schedule'])) {
                continue;
            }

            $leaderSchedules = $leader['_schedules'] ?? [];
            $inCountryDates = [];

            foreach ($leaderSchedules as $schedule) {
                $type = eb_leaders_schedule_status($schedule);
                if ($type !== 'in_country') {
                    continue;
                }

                $start = eb_leaders_schedule_start($schedule);
                $end = eb_leaders_schedule_end($schedule);

                if (!$start || !$end) {
                    continue;
                }

                $d = new DateTime(date('Y-m-d', $start));
                $dEnd = new DateTime(date('Y-m-d', $end));

                while ($d <= $dEnd) {
                    $inCountryDates[] = $d->format('Y-m-d');
                    $d->modify('+1 day');
                }
            }

            // Also include if flagged as in_country without schedule
            if (empty($inCountryDates) && (!empty($leader['is_in_country']) || !empty($leader['in_country']))) {
                // Fallback: show today +/- 3 days
                $d = new DateTime(date('Y-m-d', strtotime('-3 days')));
                $dEnd = new DateTime(date('Y-m-d', strtotime('+7 days')));
                while ($d <= $dEnd) {
                    $inCountryDates[] = $d->format('Y-m-d');
                    $d->modify('+1 day');
                }
            }

            if (empty($inCountryDates)) {
                continue;
            }

            sort($inCountryDates);
            $inCountryDates = array_unique($inCountryDates);

            $leaderStart = $inCountryDates[0];
            $leaderEnd = end($inCountryDates);

            $dutyLeaderRanges[(int)$leader['id']] = [
                'leader' => $leader,
                'start' => $leaderStart,
                'end' => $leaderEnd,
                'dates' => $inCountryDates,
            ];

            if ($globalStart === null || $leaderStart < $globalStart) {
                $globalStart = $leaderStart;
            }
            if ($globalEnd === null || $leaderEnd > $globalEnd) {
                $globalEnd = $leaderEnd;
            }
        }

        // Build the full date range
        $dutyDates = [];
        if ($globalStart && $globalEnd) {
            $dCurrent = new DateTime($globalStart);
            $dEnd = new DateTime($globalEnd);
            while ($dCurrent <= $dEnd) {
                $dutyDates[] = $dCurrent->format('Y-m-d');
                $dCurrent->modify('+1 day');
            }
        }

        // Fetch roster
        $dutyRoster = [];
        if (!empty($dutyDates)) {
            $dutyRoster = eb_leaders_fetch_duty_roster($pdo, $dutyDates[0], end($dutyDates));
        }
        ?>

        <section class="admin-panel mb-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap mb-3" style="gap:0.5rem;">
                <div>
                    <h2 class="mb-1">Duty roster</h2>
                    <p class="muted mb-0">
                        Shows which leaders are on/off duty while they're in country.
                    </p>
                    <p class="muted mb-0" style="font-size: 0.85rem; margin-top: 0.25rem;">
                        <strong>Note:</strong> Duty runs 09:00 to 09:00 the following day. Handover is at 09:00 each morning.
                    </p>
                </div>
                <?php if (!empty($dutyDates)): ?>
                    <?php if ($dutyEditMode): ?>
                        <a href="<?= e(url('leaders.php?tab=duty')) ?>" class="btn btn-outline-primary">View mode</a>
                    <?php elseif (!is_readonly()): ?>
                        <a href="<?= e(url('leaders.php?tab=duty&edit_roster=1')) ?>" class="btn btn-primary">Edit roster</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if (empty($dutyLeaderRanges)): ?>
                <div class="empty-panel">
                    No leaders have in-country schedule entries. Add schedule entries on the "Manage schedule" tab first.
                </div>
            <?php elseif ($dutyEditMode): ?>

                <!-- EDIT MODE: full grid with dropdowns -->
                <form method="post">
                    <input type="hidden" name="action" value="save_duty_bulk">
                    <input type="hidden" name="week_start" value="<?= e($dutyDates[0] ?? '') ?>">

                    <div class="duty-roster-wrap">
                        <table class="duty-roster-table">
                            <thead>
                                <tr>
                                    <th class="duty-name-col">Leader</th>
                                    <?php foreach ($dutyDates as $dd): ?>
                                        <th class="duty-date-col <?= $dd === date('Y-m-d') ? 'duty-today' : '' ?>">
                                            <span class="duty-day-name"><?= e(date('D', strtotime($dd))) ?></span>
                                            <span class="duty-day-num"><?= e(date('j/n', strtotime($dd))) ?></span>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dutyLeaderRanges as $lid => $rangeData): ?>
                                    <tr>
                                        <td class="duty-name-col">
                                            <strong><?= e(eb_leaders_val($rangeData['leader'], ['name', 'full_name'], 'Leader')) ?></strong>
                                        </td>
                                        <?php foreach ($dutyDates as $dd): ?>
                                            <?php
                                            $isInCountryDay = in_array($dd, $rangeData['dates'], true);
                                            $key = $lid . '_' . $dd;
                                            $currentStatus = $dutyRoster[$key]['status'] ?? '';
                                            $cellClass = '';
                                            if ($currentStatus === 'on_duty') $cellClass = 'duty-cell-on';
                                            elseif ($currentStatus === 'off_duty') $cellClass = 'duty-cell-off';
                                            elseif ($currentStatus === 'day_off') $cellClass = 'duty-cell-dayoff';
                                            ?>
                                            <td class="duty-cell <?= $cellClass ?> <?= $dd === date('Y-m-d') ? 'duty-today' : '' ?> <?= !$isInCountryDay ? 'duty-cell-disabled' : '' ?>">
                                                <?php if ($isInCountryDay): ?>
                                                    <select name="duty[<?= $lid ?>][<?= e($dd) ?>]" class="duty-select">
                                                        <option value="" <?= $currentStatus === '' ? 'selected' : '' ?>>—</option>
                                                        <option value="on_duty" <?= $currentStatus === 'on_duty' ? 'selected' : '' ?>>On</option>
                                                        <option value="off_duty" <?= $currentStatus === 'off_duty' ? 'selected' : '' ?>>Off</option>
                                                        <option value="day_off" <?= $currentStatus === 'day_off' ? 'selected' : '' ?>>Day off</option>
                                                    </select>
                                                <?php else: ?>
                                                    <span class="duty-not-here" title="Not in country">—</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">
                        <button class="btn btn-primary"<?php if (is_readonly()): ?> disabled<?php endif; ?>>Save roster</button>
                        <a href="<?= e(url('leaders.php?tab=duty')) ?>" class="btn btn-outline-primary ml-2">Cancel</a>
                    </div>
                </form>

            <?php else: ?>

                <!-- VIEW MODE: week or full trip view -->
                <?php
                $today = date('Y-m-d');
                $dutyViewMode = $_GET['duty_view'] ?? 'trip'; // 'week' or 'trip'

                // Week mode: paginate by week
                $weekOffset = (int)($_GET['week'] ?? 0);

                // Find which week today falls in (or start at week 0 if trip hasn't started)
                $todayIdx = array_search($today, $dutyDates);
                if ($todayIdx === false) {
                    // Today is outside the trip — start at 0
                    $defaultWeekStart = 0;
                } else {
                    $defaultWeekStart = (int)floor($todayIdx / 7) * 7;
                }

                $weekStartIdx = $defaultWeekStart + ($weekOffset * 7);
                if ($weekStartIdx < 0) $weekStartIdx = 0;
                if ($weekStartIdx >= count($dutyDates)) $weekStartIdx = max(0, count($dutyDates) - 7);

                $weekDates = $dutyViewMode === 'week'
                    ? array_slice($dutyDates, $weekStartIdx, 7)
                    : $dutyDates;

                $hasPrevWeek = $weekStartIdx > 0;
                $hasNextWeek = ($weekStartIdx + 7) < count($dutyDates);

                // Build per-day data for the visible dates
                $dayDataList = [];
                foreach ($weekDates as $dd) {
                    $onDuty = [];
                    $offDuty = [];

                    foreach ($dutyLeaderRanges as $lid => $rangeData) {
                        if (!in_array($dd, $rangeData['dates'], true)) {
                            continue;
                        }
                        $key = $lid . '_' . $dd;
                        $st = $dutyRoster[$key]['status'] ?? '';
                        $leaderInfo = [
                            'name' => eb_leaders_val($rangeData['leader'], ['name', 'full_name'], 'Leader'),
                            'first_name' => explode(' ', trim((string)eb_leaders_val($rangeData['leader'], ['name', 'full_name'], 'Leader')))[0],
                            'photo' => eb_leaders_media_url((string)eb_leaders_val($rangeData['leader'], ['photo_url', 'image_url', 'avatar_url'], '')),
                            'initials' => eb_leaders_initials((string)eb_leaders_val($rangeData['leader'], ['name', 'full_name'], 'Leader')),
                        ];

                        if ($st === 'on_duty') {
                            $onDuty[] = $leaderInfo;
                        } else {
                            $offDuty[] = $leaderInfo;
                        }
                    }

                    $dayDataList[] = [
                        'date' => $dd,
                        'is_today' => ($dd === $today),
                        'on_duty' => $onDuty,
                        'off_duty' => $offDuty,
                    ];
                }
                ?>

                <!-- View toggle and week navigation -->
                <div class="duty-week-nav">
                    <div class="duty-week-nav-toggle">
                        <a href="<?= e(url('leaders.php?tab=duty&duty_view=week&week=' . $weekOffset)) ?>"
                           class="duty-view-toggle-btn <?= $dutyViewMode === 'week' ? 'duty-view-toggle-active' : '' ?>">
                            Week
                        </a>
                        <a href="<?= e(url('leaders.php?tab=duty&duty_view=trip')) ?>"
                           class="duty-view-toggle-btn <?= $dutyViewMode === 'trip' ? 'duty-view-toggle-active' : '' ?>">
                            Full trip
                        </a>
                    </div>

                    <?php if ($dutyViewMode === 'week'): ?>
                        <div class="duty-week-nav-arrows">
                            <?php if ($hasPrevWeek): ?>
                                <a href="<?= e(url('leaders.php?tab=duty&duty_view=week&week=' . ($weekOffset - 1))) ?>" class="duty-faces-nav-btn" aria-label="Previous week">&larr;</a>
                            <?php else: ?>
                                <span class="duty-faces-nav-btn duty-faces-nav-disabled">&larr;</span>
                            <?php endif; ?>

                            <span class="duty-week-nav-label">
                                <?= e(date('j M', strtotime($weekDates[0]))) ?> – <?= e(date('j M', strtotime(end($weekDates)))) ?>
                            </span>

                            <?php if ($hasNextWeek): ?>
                                <a href="<?= e(url('leaders.php?tab=duty&duty_view=week&week=' . ($weekOffset + 1))) ?>" class="duty-faces-nav-btn" aria-label="Next week">&rarr;</a>
                            <?php else: ?>
                                <span class="duty-faces-nav-btn duty-faces-nav-disabled">&rarr;</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="duty-week-nav-label">
                            <?= e(date('j M', strtotime($dutyDates[0]))) ?> – <?= e(date('j M', strtotime(end($dutyDates)))) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Roster cards per day -->
                <div class="duty-week-list">
                    <?php foreach ($dayDataList as $dayData): ?>
                        <div class="duty-day-card <?= $dayData['is_today'] ? 'duty-day-card-today' : '' ?>">
                            <div class="duty-day-card-header">
                                <span class="duty-day-card-dayname"><?= e(date('l', strtotime($dayData['date']))) ?></span>
                                <span class="duty-day-card-datenum"><?= e(date('j M', strtotime($dayData['date']))) ?></span>
                                <?php if ($dayData['is_today']): ?>
                                    <span class="duty-view-today-badge">Today</span>
                                <?php endif; ?>
                            </div>

                            <div class="duty-day-card-body">
                                <div class="duty-day-card-section duty-day-card-section-on">
                                    <span class="duty-day-card-section-label"><span class="duty-faces-dot duty-dot-on"></span> On duty</span>
                                    <?php if (empty($dayData['on_duty'])): ?>
                                        <span class="duty-day-card-none">None assigned</span>
                                    <?php else: ?>
                                        <div class="duty-day-card-faces">
                                            <?php foreach ($dayData['on_duty'] as $dl): ?>
                                                <div class="duty-day-face" title="<?= e($dl['name']) ?>">
                                                    <?php if ($dl['photo'] !== ''): ?>
                                                        <img class="duty-day-face-photo" src="<?= e($dl['photo']) ?>" alt="<?= e($dl['name']) ?>">
                                                    <?php else: ?>
                                                        <span class="duty-day-face-photo duty-day-face-placeholder"><?= e($dl['initials']) ?></span>
                                                    <?php endif; ?>
                                                    <span class="duty-day-face-name"><?= e($dl['first_name']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="duty-day-card-section duty-day-card-section-off">
                                    <span class="duty-day-card-section-label"><span class="duty-faces-dot duty-dot-off"></span> Off</span>
                                    <?php if (empty($dayData['off_duty'])): ?>
                                        <span class="duty-day-card-none">—</span>
                                    <?php else: ?>
                                        <div class="duty-day-card-faces">
                                            <?php foreach ($dayData['off_duty'] as $dl): ?>
                                                <div class="duty-day-face duty-day-face-off" title="<?= e($dl['name']) ?>">
                                                    <?php if ($dl['photo'] !== ''): ?>
                                                        <img class="duty-day-face-photo duty-day-face-photo-off" src="<?= e($dl['photo']) ?>" alt="<?= e($dl['name']) ?>">
                                                    <?php else: ?>
                                                        <span class="duty-day-face-photo duty-day-face-placeholder duty-day-face-photo-off"><?= e($dl['initials']) ?></span>
                                                    <?php endif; ?>
                                                    <span class="duty-day-face-name"><?= e($dl['first_name']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

            <div class="mt-3">
                <span class="duty-legend-item"><span class="duty-swatch duty-swatch-on"></span> On duty</span>
                <span class="duty-legend-item"><span class="duty-swatch duty-swatch-off"></span> Off duty (in country)</span>
                <span class="duty-legend-item"><span class="duty-swatch duty-swatch-dayoff"></span> Day off</span>
            </div>
        </section>

    <?php else: ?>

        <?php if (!empty($homeContacts)): ?>
            <section class="leader-section">
                <div class="leader-section-heading">
                    <h2>Current Home Contact</h2>
                    <p class="muted">
                        Please use this contact if you need to reach the trip team.
                    </p>
                </div>

                <div class="leader-grid">
                    <?php foreach ($homeContacts as $leader): ?>
                        <?php eb_leaders_render_card($leader); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($inCountryLeaders)): ?>
            <section class="leader-section">
                <div class="leader-section-heading">
                    <h2>Supporting in Finland</h2>
                    <p class="muted">
                        These leaders are currently supporting the trip in Finland. Please do not contact them directly.
                    </p>
                </div>

                <div class="leader-grid">
                    <?php foreach ($inCountryLeaders as $leader): ?>
                        <?php eb_leaders_render_card($leader); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($upcomingLeaders)): ?>
            <section class="leader-section">
                <div class="leader-section-heading">
                    <h2>Upcoming support</h2>
                    <p class="muted">
                        These leaders are scheduled to support the trip soon.
                    </p>
                </div>

                <div class="leader-grid">
                    <?php foreach ($upcomingLeaders as $leader): ?>
                        <?php eb_leaders_render_card($leader); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($remoteLeaders)): ?>
            <section class="leader-section">
                <div class="leader-section-heading">
                    <h2>Supporting remotely</h2>
                    <p class="muted">
                        These leaders are supporting the trip remotely or are not currently scheduled in Finland.
                    </p>
                </div>

                <div class="leader-grid">
                    <?php foreach ($remoteLeaders as $leader): ?>
                        <?php eb_leaders_render_card($leader); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (
            empty($homeContacts)
            && empty($inCountryLeaders)
            && empty($upcomingLeaders)
            && empty($remoteLeaders)
        ): ?>
            <div class="empty-panel">
                No leaders are currently listed.
            </div>
        <?php endif; ?>

    <?php endif; ?>

</main>

<script>
    (function () {
        var buttons = document.querySelectorAll('[data-read-more]');

        buttons.forEach(function (button) {
            var wrapper = button.closest('.leader-bio-wrapper');

            if (!wrapper) {
                return;
            }

            var bio = wrapper.querySelector('.leader-bio');

            if (!bio) {
                return;
            }

            setTimeout(function () {
                if (bio.scrollHeight <= bio.clientHeight + 2) {
                    button.style.display = 'none';
                }
            }, 100);

            button.addEventListener('click', function () {
                var expanded = bio.classList.toggle('leader-bio-expanded');

                if (expanded) {
                    bio.classList.remove('leader-bio-clamped');
                    button.textContent = 'Show less';
                } else {
                    bio.classList.add('leader-bio-clamped');
                    button.textContent = 'Read more';
                }
            });
        });
    })();

    // Duty roster live cell colouring
    (function () {
        var selects = document.querySelectorAll('.duty-select');

        selects.forEach(function (select) {
            select.addEventListener('change', function () {
                var cell = select.closest('.duty-cell');
                if (!cell) return;

                cell.classList.remove('duty-cell-on', 'duty-cell-off', 'duty-cell-dayoff');

                if (select.value === 'on_duty') cell.classList.add('duty-cell-on');
                else if (select.value === 'off_duty') cell.classList.add('duty-cell-off');
                else if (select.value === 'day_off') cell.classList.add('duty-cell-dayoff');
            });
        });
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>