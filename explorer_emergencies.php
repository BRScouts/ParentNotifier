<?php
/**
 * Explorer Portal - Emergencies Page
 *
 * Displays the emergency contact phone number prominently with high-visibility
 * styling and guidance text for teams.
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

// Define explorer_contact_phone() if not already available (shared with explorer_checkin.php)
if (!function_exists('explorer_contact_phone')) {
    function explorer_contact_phone(): string
    {
        if (defined('EXPLORER_EMERGENCY_PHONE')) {
            return (string)EXPLORER_EMERGENCY_PHONE;
        }

        if (defined('CONTACT_PHONE')) {
            return (string)CONTACT_PHONE;
        }

        return 'the emergency phone number provided by the leadership team';
    }
}

$emergencyPhone = explorer_contact_phone();

include __DIR__ . '/explorer_header.php';
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 2rem;">

    <h1 style="font-weight: 900; margin-bottom: 1.5rem;">Emergency Contact</h1>

    <!-- High-visibility emergency panel -->
    <section
        style="border-left: 8px solid #d4351c; background: #fef3f0; padding: 2rem 1.5rem; margin-bottom: 2rem;"
        aria-label="Emergency contact information"
    >
        <p style="font-size: 1.2rem; font-weight: 700; color: #d4351c; margin-bottom: 1rem;">
            If you need urgent help, call this number now:
        </p>

        <a
            href="tel:<?= e($emergencyPhone) ?>"
            style="display: inline-block; font-size: 2.5rem; font-weight: 900; color: #ffffff; background: #d4351c; padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 4px; line-height: 1.2;"
            aria-label="Call emergency number <?= e($emergencyPhone) ?>"
        >
            &#x1F4DE; <?= e($emergencyPhone) ?>
        </a>
    </section>

    <!-- Guidance text -->
    <section style="background: #ffffff; border: 2px solid #d8d8d8; padding: 1.5rem; margin-bottom: 2rem;">
        <h2 style="font-weight: 700; font-size: 1.3rem; margin-bottom: 1rem;">Call this number if:</h2>
        <ul style="font-size: 1.1rem; margin-bottom: 1.5rem; padding-left: 1.25rem;">
            <li style="margin-bottom: 0.5rem;">Someone is seriously injured or ill</li>
            <li style="margin-bottom: 0.5rem;">There is an immediate safety concern</li>
            <li style="margin-bottom: 0.5rem;">You need urgent help that cannot wait</li>
        </ul>
        <p style="font-size: 1rem; color: #505a5f; margin-bottom: 0;">
            For non-urgent questions, use the <strong>Contact Leaders</strong> tab instead.
        </p>
    </section>

</div>

<?php include __DIR__ . '/explorer_footer.php'; ?>
