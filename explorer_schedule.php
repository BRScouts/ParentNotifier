<?php
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();
$token = trim($_GET['token'] ?? $_SESSION['explorer_portal_token'] ?? '');

$team = explorer_fetch_team($pdo, $token);

if (!$team) {
    include __DIR__ . '/explorer_error.php';
}

$_SESSION['explorer_portal_token'] = $token;

/**
 * Ensure the activity_schedule table exists
 */
function explorer_schedule_table_exists(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );
        $stmt->execute(['activity_schedule']);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$hasTable = explorer_schedule_table_exists($pdo);
$activitiesByDate = [];
$today = date('Y-m-d');

if ($hasTable) {
    $stmt = $pdo->prepare(
        'SELECT activity_date, start_time, end_time, title, description
         FROM activity_schedule
         WHERE activity_date >= ?
           AND is_leaders_only = 0
         ORDER BY activity_date ASC, sort_order ASC, start_time ASC, id ASC'
    );
    $stmt->execute([$today]);

    foreach ($stmt->fetchAll() as $row) {
        $activitiesByDate[$row['activity_date']][] = $row;
    }
}

include __DIR__ . '/explorer_header.php';

$tokenParam = urlencode($token);
?>

<style>
    .schedule-page {
        padding-top: 1rem;
        padding-bottom: 2rem;
    }
    .schedule-page-header {
        margin-bottom: 1.25rem;
    }
    .schedule-page-header h1 {
        font-weight: 900;
        font-size: 1.3rem;
        margin: 0 0 0.2rem;
    }
    .schedule-page-header p {
        color: #505a5f;
        font-size: 0.92rem;
        margin: 0;
    }

    .schedule-day {
        margin-bottom: 1.5rem;
    }
    .schedule-day-heading {
        font-weight: 900;
        font-size: 1rem;
        border-bottom: 3px solid #7413dc;
        padding-bottom: 0.4rem;
        margin-bottom: 0.6rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .schedule-today-tag {
        background: #00703c;
        color: #fff;
        font-size: 0.68rem;
        font-weight: 900;
        padding: 0.12rem 0.4rem;
        text-transform: uppercase;
    }

    .schedule-item {
        background: #fff;
        border: 2px solid #d8d8d8;
        padding: 0.85rem 1rem;
        margin-bottom: 0.5rem;
    }
    .schedule-item-time {
        font-weight: 900;
        font-size: 0.85rem;
        color: #505a5f;
        margin-bottom: 0.1rem;
    }
    .schedule-item-title {
        font-weight: 900;
        font-size: 1rem;
        margin: 0 0 0.2rem;
        color: #1d1d1d;
    }
    .schedule-item-desc {
        font-size: 0.9rem;
        color: #1d1d1d;
        line-height: 1.5;
        margin: 0;
    }

    .schedule-empty {
        background: #f8f8f8;
        border: 2px dashed #b1b4b6;
        padding: 2rem 1.5rem;
        text-align: center;
        color: #505a5f;
        font-weight: 700;
    }

    @media (max-width: 575.98px) {
        .schedule-page-header h1 { font-size: 1.15rem; }
        .schedule-item { padding: 0.75rem; }
        .schedule-item-title { font-size: 0.92rem; }
        .schedule-item-desc { font-size: 0.85rem; }
        .schedule-empty { padding: 1.25rem 1rem; font-size: 0.9rem; }
    }
</style>

<div class="container schedule-page">

    <div class="schedule-page-header">
        <h1>Schedule</h1>
        <p>Upcoming activities and plans for the expedition.</p>
    </div>

    <?php if (empty($activitiesByDate)): ?>
        <div class="schedule-empty">
            No upcoming activities scheduled yet. Check back later.
        </div>
    <?php else: ?>
        <?php foreach ($activitiesByDate as $date => $activities): ?>
            <?php $isToday = ($date === $today); ?>
            <section class="schedule-day">
                <h2 class="schedule-day-heading">
                    <?= e(date('l j M', strtotime($date))) ?>
                    <?php if ($isToday): ?>
                        <span class="schedule-today-tag">Today</span>
                    <?php endif; ?>
                </h2>

                <?php foreach ($activities as $activity): ?>
                    <div class="schedule-item">
                        <?php if ($activity['start_time']): ?>
                            <p class="schedule-item-time">
                                <?= e(date('H:i', strtotime($activity['start_time']))) ?>
                                <?php if ($activity['end_time']): ?>
                                    &ndash; <?= e(date('H:i', strtotime($activity['end_time']))) ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>

                        <h3 class="schedule-item-title"><?= e($activity['title']) ?></h3>

                        <?php if (!empty($activity['description'])): ?>
                            <p class="schedule-item-desc"><?= nl2br(e($activity['description'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/explorer_footer.php'; ?>
