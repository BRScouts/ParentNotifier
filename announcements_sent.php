<?php
require_once __DIR__ . '/auth.php';
require_login();

$pdo = db();
$user = current_user();
ensure_announcements_tables($pdo);

// Ensure announcement_reads table exists (graceful)
try {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = "announcement_reads"'
    );
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec('
            CREATE TABLE announcement_reads (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                announcement_id INT UNSIGNED NOT NULL,
                team_id INT UNSIGNED NOT NULL,
                reader_name VARCHAR(150) NOT NULL,
                reader_email VARCHAR(255) NULL,
                read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_read_announcement_team_person (announcement_id, team_id, reader_name),
                INDEX idx_reads_announcement (announcement_id),
                INDEX idx_reads_team (team_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }
} catch (Throwable $e) {
    // Table may already exist or permissions issue — continue
}

// Determine if viewing a specific announcement
$viewAnnouncementId = isset($_GET['id']) ? (int)$_GET['id'] : null;

// --- Fetch all announcements ---
$announcements = [];
try {
    $hasPinnedCol = false;
    try {
        $colCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "announcements" AND COLUMN_NAME = "is_pinned"'
        );
        $colCheck->execute();
        $hasPinnedCol = (int)$colCheck->fetchColumn() > 0;
    } catch (Throwable $e) {}

    $pinnedOrder = $hasPinnedCol ? 'a.is_pinned DESC,' : '';

    $announcements = $pdo->query(
        'SELECT 
            a.id,
            a.team_id,
            a.title,
            a.content,
            a.created_at,
            l.name AS sender_name,
            CASE WHEN a.team_id IS NULL THEN \'All Teams\' ELSE t.name END AS target_name
         FROM announcements a
         LEFT JOIN leaders l ON l.id = a.sender_leader_id
         LEFT JOIN teams t ON t.id = a.team_id
         ORDER BY ' . $pinnedOrder . ' a.created_at DESC'
    )->fetchAll();
} catch (Throwable $e) {
    $announcements = [];
}

// --- If viewing a specific announcement, fetch detailed read/ack data ---
$detailAnnouncement = null;
$acknowledgements = [];
$reads = [];
$targetTeams = [];

if ($viewAnnouncementId) {
    // Fetch the announcement
    try {
        $stmt = $pdo->prepare(
            'SELECT a.*, l.name AS sender_name,
                    CASE WHEN a.team_id IS NULL THEN \'All Teams\' ELSE t.name END AS target_name
             FROM announcements a
             LEFT JOIN leaders l ON l.id = a.sender_leader_id
             LEFT JOIN teams t ON t.id = a.team_id
             WHERE a.id = ?'
        );
        $stmt->execute([$viewAnnouncementId]);
        $detailAnnouncement = $stmt->fetch();
    } catch (Throwable $e) {
        $detailAnnouncement = null;
    }

    if ($detailAnnouncement) {
        // Get target teams (all active teams if team_id is NULL, otherwise the specific team)
        try {
            if ($detailAnnouncement['team_id'] === null) {
                try {
                    $targetTeams = $pdo->query('SELECT id, name FROM teams WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
                } catch (Throwable $e) {
                    $targetTeams = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC')->fetchAll();
                }
            } else {
                $stmt = $pdo->prepare('SELECT id, name FROM teams WHERE id = ?');
                $stmt->execute([(int)$detailAnnouncement['team_id']]);
                $targetTeams = $stmt->fetchAll();
            }
        } catch (Throwable $e) {
            $targetTeams = [];
        }

        // Get acknowledgements for this announcement
        try {
            $stmt = $pdo->prepare(
                'SELECT aa.*, t.name AS team_name
                 FROM announcement_acknowledgements aa
                 LEFT JOIN teams t ON t.id = aa.team_id
                 WHERE aa.announcement_id = ?
                 ORDER BY aa.acknowledged_at DESC'
            );
            $stmt->execute([$viewAnnouncementId]);
            $acknowledgements = $stmt->fetchAll();
        } catch (Throwable $e) {
            $acknowledgements = [];
        }

        // Get individual reads for this announcement
        try {
            $stmt = $pdo->prepare(
                'SELECT ar.*, t.name AS team_name
                 FROM announcement_reads ar
                 LEFT JOIN teams t ON t.id = ar.team_id
                 WHERE ar.announcement_id = ?
                 ORDER BY ar.read_at DESC'
            );
            $stmt->execute([$viewAnnouncementId]);
            $reads = $stmt->fetchAll();
        } catch (Throwable $e) {
            $reads = [];
        }
    }
}

// Build a lookup of acknowledged team IDs
$acknowledgedTeamIds = [];
foreach ($acknowledgements as $ack) {
    $acknowledgedTeamIds[(int)$ack['team_id']] = $ack;
}

// Build a lookup of reads grouped by team
$readsByTeam = [];
foreach ($reads as $read) {
    $teamId = (int)$read['team_id'];
    if (!isset($readsByTeam[$teamId])) {
        $readsByTeam[$teamId] = [];
    }
    $readsByTeam[$teamId][] = $read;
}

// Get aggregate ack counts for the list view
$ackCounts = [];
try {
    $ackRows = $pdo->query(
        'SELECT announcement_id, COUNT(*) AS ack_count
         FROM announcement_acknowledgements
         GROUP BY announcement_id'
    )->fetchAll();
    foreach ($ackRows as $row) {
        $ackCounts[(int)$row['announcement_id']] = (int)$row['ack_count'];
    }
} catch (Throwable $e) {}

// Get aggregate read counts for the list view
$readCounts = [];
try {
    $readRows = $pdo->query(
        'SELECT announcement_id, COUNT(*) AS read_count
         FROM announcement_reads
         GROUP BY announcement_id'
    )->fetchAll();
    foreach ($readRows as $row) {
        $readCounts[(int)$row['announcement_id']] = (int)$row['read_count'];
    }
} catch (Throwable $e) {}

// Get total active teams count
$totalActiveTeams = 0;
try {
    try {
        $totalActiveTeams = (int)$pdo->query('SELECT COUNT(*) FROM teams WHERE is_active = 1')->fetchColumn();
    } catch (Throwable $e) {
        $totalActiveTeams = (int)$pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
    }
} catch (Throwable $e) {
    $totalActiveTeams = 1;
}

include __DIR__ . '/header.php';
?>

<style>
    .sent-shell {
        max-width: 1060px;
        margin: 0 auto;
        padding: 2rem 1rem;
    }

    .sent-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .sent-panel h2,
    .sent-panel h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .sent-table {
        width: 100%;
        border-collapse: collapse;
        background: #ffffff;
        border: 2px solid #d8d8d8;
    }

    .sent-table th,
    .sent-table td {
        border-bottom: 1px solid #d8d8d8;
        padding: 0.75rem 1rem;
        vertical-align: top;
        text-align: left;
    }

    .sent-table th {
        background: #f3f2f1;
        font-weight: 900;
        font-size: 0.9rem;
    }

    .sent-table td {
        font-size: 0.95rem;
    }

    .sent-table tr:hover {
        background: #f8f8f8;
    }

    .ack-badge {
        display: inline-block;
        padding: 0.2rem 0.5rem;
        font-size: 0.85rem;
        font-weight: 800;
        border-radius: 0;
        border: 2px solid;
    }

    .ack-badge-complete {
        background: #e6f4ea;
        border-color: #00703c;
        color: #00703c;
    }

    .ack-badge-partial {
        background: #fff7bf;
        border-color: #b58900;
        color: #6b5200;
    }

    .ack-badge-none {
        background: #fff1f0;
        border-color: #d4351c;
        color: #d4351c;
    }

    .sent-target {
        display: inline-block;
        padding: 0.15rem 0.45rem;
        background: #f3f2f1;
        border: 1px solid #b1b4b6;
        font-size: 0.85rem;
        font-weight: 800;
    }

    .sent-date {
        color: #505a5f;
        font-size: 0.85rem;
    }

    .sent-empty {
        text-align: center;
        padding: 2rem;
        color: #505a5f;
        font-style: italic;
    }

    .view-btn {
        display: inline-block;
        padding: 0.3rem 0.7rem;
        font-size: 0.85rem;
        font-weight: 800;
        background: #7413dc;
        color: #ffffff;
        border: none;
        text-decoration: none;
        cursor: pointer;
    }

    .view-btn:hover {
        background: #560fa0;
        color: #ffffff;
        text-decoration: none;
    }

    .back-link {
        display: inline-block;
        margin-bottom: 1rem;
        font-weight: 800;
        color: #1d70b8;
        text-decoration: none;
    }

    .back-link:hover {
        color: #003078;
        text-decoration: underline;
    }

    .detail-content {
        background: #f3f2f1;
        border: 1px solid #d8d8d8;
        padding: 1rem 1.25rem;
        margin: 1rem 0;
        line-height: 1.6;
    }

    .team-breakdown {
        margin-top: 1.5rem;
    }

    .team-card {
        border: 2px solid #d8d8d8;
        padding: 1rem 1.25rem;
        margin-bottom: 1rem;
        background: #ffffff;
    }

    .team-card--acked {
        border-left: 6px solid #00703c;
    }

    .team-card--not-acked {
        border-left: 6px solid #d4351c;
    }

    .team-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 0.5rem;
    }

    .team-card-name {
        font-weight: 900;
        font-size: 1.05rem;
    }

    .team-card-status {
        font-size: 0.85rem;
        font-weight: 800;
        padding: 0.2rem 0.5rem;
    }

    .team-card-status--acked {
        background: #e6f4ea;
        color: #00703c;
        border: 1px solid #00703c;
    }

    .team-card-status--not-acked {
        background: #fff1f0;
        color: #d4351c;
        border: 1px solid #d4351c;
    }

    .readers-list {
        margin-top: 0.5rem;
        padding: 0;
        list-style: none;
    }

    .readers-list li {
        padding: 0.4rem 0;
        border-bottom: 1px solid #f0f0f0;
        font-size: 0.9rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .readers-list li:last-child {
        border-bottom: none;
    }

    .reader-name {
        font-weight: 700;
    }

    .reader-time {
        color: #505a5f;
        font-size: 0.85rem;
    }

    .no-readers {
        color: #505a5f;
        font-style: italic;
        font-size: 0.9rem;
        margin-top: 0.5rem;
    }

    .summary-stats {
        display: flex;
        gap: 1.5rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
    }

    .stat-box {
        flex: 1;
        min-width: 140px;
        border: 2px solid #d8d8d8;
        padding: 1rem;
        text-align: center;
        background: #ffffff;
    }

    .stat-box-number {
        font-size: 2rem;
        font-weight: 900;
        line-height: 1.2;
    }

    .stat-box-label {
        font-size: 0.85rem;
        color: #505a5f;
        font-weight: 700;
        margin-top: 0.25rem;
    }

    .stat-box--green .stat-box-number { color: #00703c; }
    .stat-box--amber .stat-box-number { color: #b58900; }
    .stat-box--red .stat-box-number { color: #d4351c; }
    .stat-box--purple .stat-box-number { color: #7413dc; }
</style>

<div class="sent-shell">

    <?php if ($detailAnnouncement): ?>
        <!-- DETAIL VIEW: Single announcement breakdown -->
        <a href="<?= e(url('announcements_sent.php')) ?>" class="back-link">&larr; Back to all sent announcements</a>

        <h1 style="font-weight: 900; margin-bottom: 0.5rem;"><?= e($detailAnnouncement['title']) ?></h1>
        <p style="margin-bottom: 1.5rem;">
            <span class="sent-target"><?= e($detailAnnouncement['target_name']) ?></span>
            &nbsp;
            <span class="sent-date">Sent by <?= e($detailAnnouncement['sender_name'] ?? 'Unknown') ?> on <?= e(format_datetime($detailAnnouncement['created_at'])) ?></span>
        </p>

        <div class="detail-content">
            <?= nl2br(e($detailAnnouncement['content'])) ?>
        </div>

        <!-- Summary stats -->
        <?php
        $totalTarget = count($targetTeams);
        $totalAcked = count($acknowledgements);
        $totalReads = count($reads);
        $totalNotAcked = $totalTarget - $totalAcked;
        if ($totalNotAcked < 0) $totalNotAcked = 0;
        ?>
        <div class="summary-stats">
            <div class="stat-box stat-box--purple">
                <div class="stat-box-number"><?= $totalTarget ?></div>
                <div class="stat-box-label">Teams targeted</div>
            </div>
            <div class="stat-box stat-box--green">
                <div class="stat-box-number"><?= $totalAcked ?></div>
                <div class="stat-box-label">Acknowledged</div>
            </div>
            <div class="stat-box stat-box--red">
                <div class="stat-box-number"><?= $totalNotAcked ?></div>
                <div class="stat-box-label">Not acknowledged</div>
            </div>
            <div class="stat-box stat-box--amber">
                <div class="stat-box-number"><?= $totalReads ?></div>
                <div class="stat-box-label">Individual reads</div>
            </div>
        </div>

        <!-- Team-by-team breakdown -->
        <div class="sent-panel">
            <h3>Team Breakdown</h3>

            <?php if (empty($targetTeams)): ?>
                <p class="sent-empty">No target team information available.</p>
            <?php else: ?>
                <div class="team-breakdown">
                    <?php foreach ($targetTeams as $tTeam): ?>
                        <?php
                        $teamId = (int)$tTeam['id'];
                        $isAcked = isset($acknowledgedTeamIds[$teamId]);
                        $ackInfo = $isAcked ? $acknowledgedTeamIds[$teamId] : null;
                        $teamReads = $readsByTeam[$teamId] ?? [];
                        ?>
                        <div class="team-card <?= $isAcked ? 'team-card--acked' : 'team-card--not-acked' ?>">
                            <div class="team-card-header">
                                <span class="team-card-name"><?= e($tTeam['name']) ?></span>
                                <?php if ($isAcked): ?>
                                    <span class="team-card-status team-card-status--acked">
                                        &#10003; Acknowledged by <?= e($ackInfo['acknowledged_by_name'] ?? 'Unknown') ?>
                                        <span class="reader-time">(<?= e(format_datetime($ackInfo['acknowledged_at'] ?? null)) ?>)</span>
                                    </span>
                                <?php else: ?>
                                    <span class="team-card-status team-card-status--not-acked">
                                        &#10007; Not yet acknowledged
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($teamReads)): ?>
                                <strong style="font-size: 0.85rem; color: #505a5f;">Individual reads (<?= count($teamReads) ?>):</strong>
                                <ul class="readers-list">
                                    <?php foreach ($teamReads as $read): ?>
                                        <li>
                                            <span class="reader-name"><?= e($read['reader_name']) ?></span>
                                            <span class="reader-time"><?= e(format_datetime($read['read_at'] ?? null)) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="no-readers">No individual reads recorded for this team.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- LIST VIEW: All sent announcements -->
        <h1 style="font-weight: 900; margin-bottom: 1.5rem;">Sent Announcements</h1>

        <div class="sent-panel">
            <h2>Announcement Read Tracking</h2>
            <p style="color: #505a5f; margin-bottom: 1rem;">Click "View breakdown" to see who has read and acknowledged each announcement.</p>

            <?php if (empty($announcements)): ?>
                <p class="sent-empty">No announcements have been sent yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="sent-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Target</th>
                                <th>Sent</th>
                                <th>Acknowledged</th>
                                <th>Reads</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($announcements as $a): ?>
                                <?php
                                $aId = (int)$a['id'];
                                $ackCount = $ackCounts[$aId] ?? 0;
                                $readCount = $readCounts[$aId] ?? 0;
                                $expectedTeams = ($a['team_id'] === null) ? $totalActiveTeams : 1;
                                if ($expectedTeams < 1) $expectedTeams = 1;

                                if ($ackCount >= $expectedTeams) {
                                    $badgeClass = 'ack-badge-complete';
                                } elseif ($ackCount > 0) {
                                    $badgeClass = 'ack-badge-partial';
                                } else {
                                    $badgeClass = 'ack-badge-none';
                                }
                                ?>
                                <tr>
                                    <td><strong><?= e($a['title']) ?></strong></td>
                                    <td><span class="sent-target"><?= e($a['target_name'] ?? 'Unknown') ?></span></td>
                                    <td><span class="sent-date"><?= e(format_datetime($a['created_at'] ?? null)) ?></span></td>
                                    <td>
                                        <span class="ack-badge <?= $badgeClass ?>">
                                            <?= $ackCount ?>/<?= $expectedTeams ?> teams
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?= $readCount ?></strong> <span style="color:#505a5f; font-size:0.85rem;">reads</span>
                                    </td>
                                    <td>
                                        <a href="<?= e(url('announcements_sent.php?id=' . $aId)) ?>" class="view-btn">
                                            View breakdown
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <p style="margin-top: 1rem;">
            <a href="<?= e(url('announcements_manage.php')) ?>" style="font-weight: 800; color: #1d70b8;">&larr; Back to Announcement Management</a>
        </p>

    <?php endif; ?>

</div>

<?php include __DIR__ . '/footer.php'; ?>
