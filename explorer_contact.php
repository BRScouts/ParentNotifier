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

include __DIR__ . '/explorer_header.php';

// --- Query on-duty leaders with phone numbers for today ---
$onDutyLeaders = [];

try {
    $stmt = $pdo->prepare(
        'SELECT l.name, l.phone
         FROM leader_duty_roster r
         JOIN leaders l ON l.id = r.leader_id
         WHERE r.duty_date = CURDATE()
           AND r.status = \'on_duty\'
           AND l.phone IS NOT NULL
           AND l.phone != \'\'
         ORDER BY l.name ASC'
    );
    $stmt->execute();
    $onDutyLeaders = $stmt->fetchAll();
} catch (Throwable $e) {
    // Table may not exist yet — gracefully show fallback
    $onDutyLeaders = [];
}
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 2rem;">

    <h1 style="font-weight: 900; margin-bottom: 1.5rem;">Contact Leaders</h1>

    <?php if (!empty($onDutyLeaders)): ?>

        <p style="font-size: 1.1rem; margin-bottom: 1.5rem;">
            The following leaders are on duty today. Tap to call them directly.
        </p>

        <div class="row">
            <?php foreach ($onDutyLeaders as $leader): ?>
                <div class="col-12 col-md-6 mb-3">
                    <div class="card" style="border: 2px solid #7413dc;">
                        <div class="card-body text-center" style="padding: 1.5rem;">
                            <h5 class="card-title" style="font-weight: 800; margin-bottom: 1rem;">
                                <?= e($leader['name']) ?>
                            </h5>
                            <a href="tel:<?= e($leader['phone']) ?>"
                               class="btn btn-lg btn-primary"
                               style="background: #7413dc; border-color: #7413dc; font-weight: 700; font-size: 1.1rem; padding: 0.75rem 1.5rem;">
                                📞 Call <?= e($leader['name']) ?>
                            </a>
                            <p class="mt-2 mb-0" style="color: #555; font-size: 0.95rem;">
                                <?= e($leader['phone']) ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>

        <div class="alert alert-warning" style="border: 2px solid #ffc107; padding: 1.5rem; font-size: 1.1rem;">
            <h5 style="font-weight: 800; margin-bottom: 0.75rem;">No leaders currently on duty</h5>
            <p style="margin-bottom: 1rem;">
                No leaders are currently listed as on duty. For urgent support, please call the emergency number:
            </p>
            <?php
            $emergencyPhone = explorer_contact_phone();
            $isPhoneNumber = preg_match('/^[\d\s\+\-()]+$/', $emergencyPhone);
            ?>
            <?php if ($isPhoneNumber): ?>
                <a href="tel:<?= e($emergencyPhone) ?>"
                   class="btn btn-lg btn-danger"
                   style="font-weight: 700; font-size: 1.1rem; padding: 0.75rem 1.5rem;">
                    📞 Call <?= e($emergencyPhone) ?>
                </a>
            <?php else: ?>
                <p style="font-weight: 700; font-size: 1.1rem;">
                    <?= e($emergencyPhone) ?>
                </p>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<?php include __DIR__ . '/explorer_footer.php'; ?>
