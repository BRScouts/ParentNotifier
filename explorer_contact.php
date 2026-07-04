<?php
/**
 * Explorer Portal - Contact & Emergency Page
 *
 * Merged page showing emergency numbers (Finnish 112 + leadership team)
 * and on-duty leaders for non-urgent contact.
 */

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

$emergencyPhone = explorer_contact_phone();

// Helper: resolve media URL
function explorer_media_url(?string $path): string
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

// Helper: get initials from a name
function explorer_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $letters = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= strtoupper(substr($part, 0, 1));
        }

        if (strlen($letters) >= 2) {
            break;
        }
    }

    return $letters !== '' ? $letters : '?';
}

include __DIR__ . '/explorer_header.php';

// --- Query on-duty leaders for today ---
$onDutyLeaders = [];

try {
    $stmt = $pdo->prepare(
        'SELECT l.name, l.phone, l.photo_url
         FROM leader_duty_roster r
         JOIN leaders l ON l.id = r.leader_id
         WHERE r.duty_date = CURDATE()
           AND r.status = \'on_duty\'
         ORDER BY l.name ASC'
    );
    $stmt->execute();
    $onDutyLeaders = $stmt->fetchAll();
} catch (Throwable $e) {
    // Table may not exist yet — gracefully show fallback
    $onDutyLeaders = [];
}

// Collect unique phone numbers from on-duty leaders
$onDutyPhones = [];
foreach ($onDutyLeaders as $leader) {
    $phone = trim($leader['phone'] ?? '');
    if ($phone !== '' && !in_array($phone, $onDutyPhones, true)) {
        $onDutyPhones[] = $phone;
    }
}
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 2rem;">

    <h1 style="font-weight: 900; margin-bottom: 1.5rem;">Contact & Emergency</h1>

    <!-- Emergency section -->
    <section
        style="border-left: 8px solid #d4351c; background: #fef3f0; padding: 2rem 1.5rem; margin-bottom: 2rem;"
        aria-label="Emergency contact information"
    >
        <h2 style="font-weight: 900; font-size: 1.4rem; color: #d4351c; margin-top: 0; margin-bottom: 1rem;">
            In an emergency
        </h2>

        <p style="font-size: 1.1rem; margin-bottom: 1rem;">
            If someone is seriously injured, ill, or there is an immediate safety concern, call the <strong>Finnish emergency number</strong> first:
        </p>

        <a
            href="tel:112"
            style="display: inline-block; font-size: 2.5rem; font-weight: 900; color: #ffffff; background: #d4351c; padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 4px; line-height: 1.2; margin-bottom: 1rem;"
            aria-label="Call Finnish emergency number 112"
        >
            &#x1F4DE; 112
        </a>

        <p style="font-size: 1rem; color: #505a5f; margin-bottom: 1rem;">
            This is the Finnish emergency number for police, fire, and ambulance.
        </p>

        <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">
            <strong>Then</strong> call the leadership team:
        </p>

        <a
            href="tel:<?= e($emergencyPhone) ?>"
            style="display: inline-block; font-size: 1.8rem; font-weight: 900; color: #ffffff; background: #7413dc; padding: 0.6rem 1.25rem; text-decoration: none; border-radius: 4px; line-height: 1.2;"
            aria-label="Call leadership team <?= e($emergencyPhone) ?>"
        >
            &#x1F4DE; <?= e($emergencyPhone) ?>
        </a>
    </section>

    <!-- Contact leaders section -->
    <section style="background: #ffffff; border: 2px solid #d8d8d8; padding: 1.5rem; margin-bottom: 2rem;">
        <h2 style="font-weight: 900; font-size: 1.3rem; margin-top: 0; margin-bottom: 1rem;">Contact Leaders</h2>

        <p style="font-size: 1.05rem; margin-bottom: 1.5rem;">
            For non-urgent questions or support, contact the on-duty leaders below.
        </p>

        <?php if (!empty($onDutyLeaders)): ?>

            <!-- Leader photos -->
            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: center; margin-bottom: 1.5rem;">
                <?php foreach ($onDutyLeaders as $leader): ?>
                    <?php
                    $leaderPhotoUrl = !empty($leader['photo_url']) ? explorer_media_url($leader['photo_url']) : '';
                    $leaderInitials = explorer_initials($leader['name'] ?: 'Leader');
                    ?>
                    <?php if ($leaderPhotoUrl !== ''): ?>
                        <img
                            src="<?= e($leaderPhotoUrl) ?>"
                            alt=""
                            style="width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 3px solid #7413dc;"
                        >
                    <?php else: ?>
                        <div style="width: 64px; height: 64px; border-radius: 50%; background: #7413dc; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: 900; border: 3px solid #7413dc;">
                            <?= e($leaderInitials) ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- Phone numbers to call -->
            <?php if (!empty($onDutyPhones)): ?>
                <p style="font-size: 1.05rem; font-weight: 700; margin-bottom: 0.75rem;">Call any of these numbers:</p>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php foreach ($onDutyPhones as $phone): ?>
                        <a href="tel:<?= e($phone) ?>"
                           style="display: inline-block; background: #7413dc; color: #ffffff; font-weight: 800; font-size: 1.2rem; padding: 0.6rem 1.25rem; text-decoration: none; border-radius: 4px; text-align: center;">
                            📞 <?= e($phone) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="font-size: 1rem; color: #505a5f;">
                    No phone numbers currently available. For urgent support, call
                    <a href="tel:<?= e($emergencyPhone) ?>" style="font-weight: 700;"><?= e($emergencyPhone) ?></a>.
                </p>
            <?php endif; ?>

        <?php else: ?>

            <div class="alert alert-warning" style="border: 2px solid #ffc107; padding: 1.5rem; font-size: 1.1rem;">
                <p style="font-weight: 800; margin-bottom: 0.75rem;">No leaders currently on duty</p>
                <p style="margin-bottom: 0;">
                    No leaders are currently listed as on duty. For urgent support, call
                    <a href="tel:<?= e($emergencyPhone) ?>" style="font-weight: 700;"><?= e($emergencyPhone) ?></a>.
                </p>
            </div>

        <?php endif; ?>
    </section>

</div>

<?php include __DIR__ . '/explorer_footer.php'; ?>
