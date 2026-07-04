<?php
require_once __DIR__ . '/auth.php';

$pdo = db();
$user = current_user();
$parentTeam = parent_access_team();

if (!$user && !$parentTeam) {
    redirect('403.php');
}

$isLeader = (bool)$user;
$error = '';
$success = '';

/**
 * CSRF helper
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['schedule_csrf'])) {
    $_SESSION['schedule_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['schedule_csrf'];

function schedule_csrf_valid(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['schedule_csrf'])
        && hash_equals((string)$_SESSION['schedule_csrf'], (string)$_POST['csrf_token']);
}

/**
 * Ensure the activity_schedule table exists
 */
function ensure_schedule_table(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );
        $stmt->execute(['activity_schedule']);

        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }

        $pdo->exec('
            CREATE TABLE activity_schedule (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                activity_date DATE NOT NULL,
                start_time TIME NULL,
                end_time TIME NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                internal_note TEXT NULL,
                is_leaders_only TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_schedule_date (activity_date),
                INDEX idx_schedule_leaders_only (is_leaders_only)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function schedule_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function schedule_media_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    return url($path);
}

function schedule_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    return $initials ?: '?';
}

ensure_schedule_table($pdo);

/**
 * POST actions (leaders only)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLeader) {
    if (is_readonly()) {
        $error = 'Your account has read-only access and cannot modify the schedule.';
    } else {
    $action = $_POST['action'] ?? '';

    try {
        if (!schedule_csrf_valid()) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        if ($action === 'add_activity') {
            $activityDate = trim($_POST['activity_date'] ?? '');
            $startTime = trim($_POST['start_time'] ?? '') ?: null;
            $endTime = trim($_POST['end_time'] ?? '') ?: null;
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $internalNote = trim($_POST['internal_note'] ?? '');
            $isLeadersOnly = isset($_POST['is_leaders_only']) ? 1 : 0;
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if ($title === '') {
                throw new RuntimeException('Activity title is required.');
            }

            if ($activityDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $activityDate)) {
                throw new RuntimeException('A valid date is required.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO activity_schedule
                    (activity_date, start_time, end_time, title, description, internal_note, is_leaders_only, sort_order, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $activityDate,
                $startTime,
                $endTime,
                $title,
                $description,
                $internalNote,
                $isLeadersOnly,
                $sortOrder,
                $user['id'] ?? null,
            ]);

            $success = 'Activity added.';
            redirect('schedule.php' . ($parentTeam ? '?token=' . $parentTeam['parent_token'] : ''));
        }

        if ($action === 'edit_activity') {
            $activityId = (int)($_POST['activity_id'] ?? 0);
            $activityDate = trim($_POST['activity_date'] ?? '');
            $startTime = trim($_POST['start_time'] ?? '') ?: null;
            $endTime = trim($_POST['end_time'] ?? '') ?: null;
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $internalNote = trim($_POST['internal_note'] ?? '');
            $isLeadersOnly = isset($_POST['is_leaders_only']) ? 1 : 0;
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if ($activityId <= 0) {
                throw new RuntimeException('Invalid activity.');
            }

            if ($title === '') {
                throw new RuntimeException('Activity title is required.');
            }

            $stmt = $pdo->prepare(
                'UPDATE activity_schedule
                 SET activity_date = ?, start_time = ?, end_time = ?, title = ?, description = ?,
                     internal_note = ?, is_leaders_only = ?, sort_order = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $activityDate,
                $startTime,
                $endTime,
                $title,
                $description,
                $internalNote,
                $isLeadersOnly,
                $sortOrder,
                $activityId,
            ]);

            $success = 'Activity updated.';
            redirect('schedule.php' . ($parentTeam ? '?token=' . $parentTeam['parent_token'] : ''));
        }

        if ($action === 'delete_activity') {
            $activityId = (int)($_POST['activity_id'] ?? 0);

            if ($activityId <= 0) {
                throw new RuntimeException('Invalid activity.');
            }

            $stmt = $pdo->prepare('DELETE FROM activity_schedule WHERE id = ?');
            $stmt->execute([$activityId]);

            $success = 'Activity deleted.';
            redirect('schedule.php' . ($parentTeam ? '?token=' . $parentTeam['parent_token'] : ''));
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
    } // end else (not readonly)
}

/**
 * Fetch all upcoming activities (today onwards), grouped by date
 */
$today = date('Y-m-d');
$tokenParam = $parentTeam ? '&token=' . $parentTeam['parent_token'] : '';
$tokenParamFirst = $parentTeam ? '?token=' . $parentTeam['parent_token'] : '';

$whereClause = 'WHERE activity_date >= ?';
$params = [$today];

if (!$isLeader) {
    $whereClause .= ' AND is_leaders_only = 0';
}

$stmt = $pdo->prepare(
    'SELECT * FROM activity_schedule ' . $whereClause . ' ORDER BY activity_date ASC, sort_order ASC, start_time ASC, id ASC'
);
$stmt->execute($params);
$allActivities = $stmt->fetchAll();

// Group by date
$activitiesByDate = [];
foreach ($allActivities as $activity) {
    $activitiesByDate[$activity['activity_date']][] = $activity;
}

/**
 * Fetch on-duty leaders per date (from leader_duty_roster)
 */
$dutyByDate = []; // [date => [leader rows]]
$allLeadersById = [];

if (schedule_table_exists($pdo, 'leader_duty_roster')) {
    // Get all dates we're showing
    $scheduleDates = array_keys($activitiesByDate);
    // Also include today even if no activities
    if (!in_array($today, $scheduleDates, true)) {
        $scheduleDates[] = $today;
    }
    sort($scheduleDates);

    if (!empty($scheduleDates)) {
        $startDate = $scheduleDates[0];
        $endDate = end($scheduleDates);

        $stmt = $pdo->prepare(
            'SELECT r.*, l.name AS leader_name, l.photo_url AS leader_photo
             FROM leader_duty_roster r
             JOIN leaders l ON l.id = r.leader_id
             WHERE r.duty_date BETWEEN ? AND ? AND r.status = "on_duty"
             ORDER BY r.duty_date ASC, l.name ASC'
        );
        $stmt->execute([$startDate, $endDate]);

        foreach ($stmt->fetchAll() as $row) {
            $dutyByDate[$row['duty_date']][] = $row;
        }
    }
}

/**
 * Editing mode
 */
$editingId = $isLeader ? (int)($_GET['edit'] ?? 0) : 0;
$editingActivity = null;

if ($editingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM activity_schedule WHERE id = ? LIMIT 1');
    $stmt->execute([$editingId]);
    $editingActivity = $stmt->fetch() ?: null;
}

include __DIR__ . '/header.php';
?>

<style>
    .schedule-hero {
        padding: 1.25rem 0 !important;
        margin-bottom: 1rem !important;
    }

    .schedule-hero h1 {
        margin-bottom: 0.25rem;
    }

    .schedule-day-group {
        margin-bottom: 2rem;
    }

    .schedule-day-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        border-bottom: 3px solid #7413dc;
        padding-bottom: 0.5rem;
        margin-bottom: 0.75rem;
        flex-wrap: wrap;
    }

    .schedule-day-header h2 {
        font-size: 1.15rem;
        font-weight: 900;
        margin: 0;
    }

    .schedule-day-today-badge {
        display: inline-block;
        background: #00703c;
        color: #ffffff;
        font-size: 0.7rem;
        font-weight: 900;
        padding: 0.15rem 0.5rem;
        text-transform: uppercase;
    }

    .schedule-duty-leaders {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        margin-left: auto;
    }

    .schedule-duty-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: #505a5f;
        text-transform: uppercase;
        margin-right: 0.2rem;
    }

    .schedule-duty-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #00703c;
        background: #f3f2f1;
    }

    .schedule-duty-avatar-placeholder {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        border: 2px solid #00703c;
        background: #7413dc;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 900;
    }

    .schedule-activity {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 0.85rem 1rem;
        margin-bottom: 0.6rem;
        position: relative;
    }

    .schedule-activity-leaders-only {
        border-left: 6px solid #7413dc;
        background: #faf5ff;
    }

    .schedule-activity-time {
        font-weight: 900;
        font-size: 0.9rem;
        color: #1d1d1d;
        margin-bottom: 0.15rem;
    }

    .schedule-activity-title {
        font-weight: 900;
        font-size: 1.05rem;
        margin: 0 0 0.25rem;
    }

    .schedule-activity-desc {
        margin-bottom: 0.35rem;
        color: #1d1d1d;
        font-size: 0.95rem;
    }

    .schedule-internal-note {
        border-left: 4px solid #1d70b8;
        background: #eef7ff;
        padding: 0.4rem 0.65rem;
        margin-top: 0.4rem;
        font-size: 0.85rem;
    }

    .schedule-internal-note strong {
        display: block;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #505a5f;
        margin-bottom: 0.1rem;
    }

    .schedule-leaders-badge {
        display: inline-block;
        background: #7413dc;
        color: #ffffff;
        font-size: 0.7rem;
        font-weight: 900;
        padding: 0.15rem 0.4rem;
        margin-left: 0.4rem;
        vertical-align: middle;
    }

    .schedule-activity-actions {
        position: absolute;
        top: 0.6rem;
        right: 0.6rem;
        display: flex;
        gap: 0.4rem;
    }

    .schedule-form-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .schedule-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }

    @media (max-width: 760px) {
        .schedule-form-grid {
            grid-template-columns: 1fr;
        }

        .schedule-day-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.4rem;
        }

        .schedule-duty-leaders {
            margin-left: 0;
        }
    }

    .schedule-empty {
        border: 2px dashed #b1b4b6;
        background: #f8f8f8;
        padding: 2rem;
        text-align: center;
        font-weight: 700;
        color: #505a5f;
    }
</style>

<section class="page-hero schedule-hero">
    <div class="container">
        <h1>Schedule</h1>
        <p class="lead mb-0">
            Upcoming activities and plans for the trip.
        </p>
    </div>
</section>

<main id="main-content" class="container my-4">

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($isLeader && $editingActivity && !is_readonly()): ?>
        <section class="schedule-form-panel">
            <h2>Edit activity</h2>

            <form method="post">
                <input type="hidden" name="action" value="edit_activity">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="activity_id" value="<?= (int)$editingActivity['id'] ?>">

                <div class="schedule-form-grid">
                    <div class="form-group">
                        <label>Title</label>
                        <input class="form-control" name="title" value="<?= e($editingActivity['title']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Date</label>
                        <input class="form-control" type="date" name="activity_date" value="<?= e($editingActivity['activity_date']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Start time</label>
                        <input class="form-control" type="time" name="start_time" value="<?= e($editingActivity['start_time'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>End time</label>
                        <input class="form-control" type="time" name="end_time" value="<?= e($editingActivity['end_time'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Sort order</label>
                        <input class="form-control" type="number" name="sort_order" value="<?= (int)$editingActivity['sort_order'] ?>">
                        <small class="form-text text-muted">Lower numbers appear first.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label>Description (visible to parents)</label>
                    <textarea class="form-control" name="description" rows="3"><?= e($editingActivity['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label>Internal note (leaders only)</label>
                    <textarea class="form-control" name="internal_note" rows="3"><?= e($editingActivity['internal_note'] ?? '') ?></textarea>
                    <small class="form-text text-muted">Only visible to logged-in leaders.</small>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_leaders_only" id="edit_leaders_only"
                        <?= (int)$editingActivity['is_leaders_only'] === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="edit_leaders_only">
                        Leaders only (hide from parents entirely)
                    </label>
                </div>

                <button class="btn btn-primary">Save changes</button>
                <a href="<?= e(url('schedule.php' . $tokenParamFirst)) ?>" class="btn btn-outline-primary ml-2">Cancel</a>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($isLeader && !$editingActivity && !is_readonly()): ?>
        <details class="schedule-form-panel">
            <summary style="cursor:pointer; font-weight:900;">+ Add activity</summary>

            <form method="post" class="mt-3">
                <input type="hidden" name="action" value="add_activity">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <div class="schedule-form-grid">
                    <div class="form-group">
                        <label>Title</label>
                        <input class="form-control" name="title" required>
                    </div>

                    <div class="form-group">
                        <label>Date</label>
                        <input class="form-control" type="date" name="activity_date" value="<?= e($today) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Start time</label>
                        <input class="form-control" type="time" name="start_time">
                    </div>

                    <div class="form-group">
                        <label>End time</label>
                        <input class="form-control" type="time" name="end_time">
                    </div>

                    <div class="form-group">
                        <label>Sort order</label>
                        <input class="form-control" type="number" name="sort_order" value="0">
                        <small class="form-text text-muted">Lower numbers appear first.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label>Description (visible to parents)</label>
                    <textarea class="form-control" name="description" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label>Internal note (leaders only)</label>
                    <textarea class="form-control" name="internal_note" rows="3"></textarea>
                    <small class="form-text text-muted">Only visible to logged-in leaders.</small>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_leaders_only" id="add_leaders_only">
                    <label class="form-check-label" for="add_leaders_only">
                        Leaders only (hide from parents entirely)
                    </label>
                    <small class="form-text text-muted d-block">Use for reminders, prep tasks, or internal events.</small>
                </div>

                <button class="btn btn-primary">Add activity</button>
            </form>
        </details>
    <?php endif; ?>

    <?php if (empty($activitiesByDate)): ?>
        <div class="schedule-empty">
            No upcoming activities scheduled<?= $isLeader ? '. Use the form above to add one.' : '.' ?>
        </div>
    <?php else: ?>
        <?php foreach ($activitiesByDate as $date => $dayActivities): ?>
            <?php
            $isToday = ($date === $today);
            $onDutyLeaders = $dutyByDate[$date] ?? [];
            ?>
            <section class="schedule-day-group">
                <div class="schedule-day-header">
                    <h2><?= e(date('l j M', strtotime($date))) ?></h2>
                    <?php if ($isToday): ?>
                        <span class="schedule-day-today-badge">Today</span>
                    <?php endif; ?>

                    <?php if (!empty($onDutyLeaders) && $isLeader): ?>
                        <div class="schedule-duty-leaders">
                            <span class="schedule-duty-label">On duty:</span>
                            <?php foreach ($onDutyLeaders as $dl): ?>
                                <?php
                                $photo = schedule_media_url($dl['leader_photo'] ?? '');
                                $name = $dl['leader_name'] ?? 'Leader';
                                ?>
                                <?php if ($photo !== ''): ?>
                                    <img class="schedule-duty-avatar" src="<?= e($photo) ?>" alt="<?= e($name) ?>" title="<?= e($name) ?>">
                                <?php else: ?>
                                    <span class="schedule-duty-avatar-placeholder" title="<?= e($name) ?>"><?= e(schedule_initials($name)) ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php foreach ($dayActivities as $activity): ?>
                    <article class="schedule-activity <?= (int)$activity['is_leaders_only'] === 1 ? 'schedule-activity-leaders-only' : '' ?>">

                        <?php if ($isLeader && !is_readonly()): ?>
                            <div class="schedule-activity-actions">
                                <a href="<?= e(url('schedule.php?edit=' . (int)$activity['id'] . $tokenParam)) ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this activity?');">
                                    <input type="hidden" name="action" value="delete_activity">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="activity_id" value="<?= (int)$activity['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <?php if ($activity['start_time']): ?>
                            <p class="schedule-activity-time">
                                <?= e(date('H:i', strtotime($activity['start_time']))) ?>
                                <?php if ($activity['end_time']): ?>
                                    &ndash; <?= e(date('H:i', strtotime($activity['end_time']))) ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>

                        <h3 class="schedule-activity-title">
                            <?= e($activity['title']) ?>
                            <?php if ((int)$activity['is_leaders_only'] === 1): ?>
                                <span class="schedule-leaders-badge">Leaders only</span>
                            <?php endif; ?>
                        </h3>

                        <?php if (!empty($activity['description'])): ?>
                            <div class="schedule-activity-desc">
                                <?= nl2br(e($activity['description'])) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($isLeader && !empty($activity['internal_note'])): ?>
                            <div class="schedule-internal-note">
                                <strong>Internal note</strong>
                                <?= nl2br(e($activity['internal_note'])) ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

</main>

<?php include __DIR__ . '/footer.php'; ?>
