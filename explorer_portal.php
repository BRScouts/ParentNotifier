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

$tokenParam = urlencode($token);
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 2rem;">

    <!-- Hero / Team Name Panel -->
    <section style="background: #7413dc; color: #ffffff; padding: 2rem 1.5rem; margin-bottom: 2rem; border-radius: 0;">
        <h1 style="font-weight: 900; margin: 0 0 0.25rem 0; font-size: 2rem;">
            <?= e($team['name']) ?>
        </h1>
        <p style="margin: 0; opacity: 0.9; font-size: 1.1rem;">Explorer Portal</p>
    </section>

    <!-- Welcome Panel -->
    <section style="background: #ffffff; border: 2px solid #d8d8d8; padding: 1.5rem; margin-bottom: 2rem;">
        <h2 style="font-weight: 900; margin-bottom: 1rem; color: #1d1d1d;">Welcome to your Expedition Portal</h2>
        <p style="font-size: 1.05rem; line-height: 1.6; margin-bottom: 0.5rem;">
            This is your team's central hub during the expedition. Use the tabs above or the quick links below to:
        </p>
        <ul style="font-size: 1.05rem; line-height: 1.8; margin-bottom: 0;">
            <li>Submit your daily check-ins with location and welfare information</li>
            <li>View announcements from the leadership team</li>
            <li>Find contact details for on-duty leaders</li>
            <li>Access emergency contact information</li>
        </ul>
    </section>

    <!-- Quick Links -->
    <section style="margin-bottom: 2rem;">
        <h2 style="font-weight: 900; margin-bottom: 1rem; color: #1d1d1d;">Quick Links</h2>
        <div class="row">
            <div class="col-sm-6 col-lg-3 mb-3">
                <a href="<?= e(url('explorer_checkin.php?token=' . $tokenParam)) ?>"
                   style="display: block; background: #ffffff; border: 2px solid #d8d8d8; padding: 1.25rem; text-decoration: none; color: #1d1d1d; height: 100%;">
                    <strong style="display: block; font-size: 1.1rem; margin-bottom: 0.5rem; color: #7413dc;">Check In</strong>
                    <span style="font-size: 0.95rem;">Submit your daily location and welfare check-in.</span>
                </a>
            </div>
            <div class="col-sm-6 col-lg-3 mb-3">
                <a href="<?= e(url('explorer_announcements.php?token=' . $tokenParam)) ?>"
                   style="display: block; background: #ffffff; border: 2px solid #d8d8d8; padding: 1.25rem; text-decoration: none; color: #1d1d1d; height: 100%;">
                    <strong style="display: block; font-size: 1.1rem; margin-bottom: 0.5rem; color: #7413dc;">Announcements</strong>
                    <span style="font-size: 0.95rem;">View messages and updates from the leadership team.</span>
                </a>
            </div>
            <div class="col-sm-6 col-lg-3 mb-3">
                <a href="<?= e(url('explorer_contact.php?token=' . $tokenParam)) ?>"
                   style="display: block; background: #ffffff; border: 2px solid #d8d8d8; padding: 1.25rem; text-decoration: none; color: #1d1d1d; height: 100%;">
                    <strong style="display: block; font-size: 1.1rem; margin-bottom: 0.5rem; color: #7413dc;">Contact Leaders</strong>
                    <span style="font-size: 0.95rem;">Find on-duty leaders and their phone numbers.</span>
                </a>
            </div>
            <div class="col-sm-6 col-lg-3 mb-3">
                <a href="<?= e(url('explorer_emergencies.php?token=' . $tokenParam)) ?>"
                   style="display: block; background: #ffffff; border: 2px solid #d8d8d8; padding: 1.25rem; text-decoration: none; color: #1d1d1d; height: 100%;">
                    <strong style="display: block; font-size: 1.1rem; margin-bottom: 0.5rem; color: #7413dc;">Emergencies</strong>
                    <span style="font-size: 0.95rem;">Access emergency contact information quickly.</span>
                </a>
            </div>
        </div>
    </section>

</div>

<?php include __DIR__ . '/explorer_footer.php'; ?>
