<?php
/**
 * Check-in Approved Confirmation Page
 *
 * Shows a success message after a leader approves a check-in,
 * with a WhatsApp button to send a confirmation message to the explorer.
 */
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

$checkinId = (int)($_GET['checkin_id'] ?? 0);
$teamId = (int)($_GET['team_id'] ?? 0);

if ($checkinId <= 0 || $teamId <= 0) {
    redirect('team_links.php');
}

// Fetch the check-in details
$stmt = $pdo->prepare(
    'SELECT ec.*, t.name AS team_name
     FROM explorer_checkins ec
     JOIN teams t ON t.id = ec.team_id
     WHERE ec.id = ? AND ec.team_id = ?'
);
$stmt->execute([$checkinId, $teamId]);
$checkin = $stmt->fetch();

if (!$checkin) {
    redirect('team_links.php?view=team&team_id=' . $teamId . '&tab=pending');
}

$submitterName = $checkin['submitted_by'] ?? '';
$locationName = $checkin['location_name'] ?? '';
$teamName = $checkin['team_name'] ?? 'Team';
$leaderName = $user['name'] ?? 'Your leader';

// Look up the submitter's phone from their young_people record (participant_phone)
$submitterPhone = '';
if ($submitterName !== '') {
    $stmt = $pdo->prepare(
        'SELECT participant_phone
         FROM young_people
         WHERE team_id = ? AND name = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$teamId, $submitterName]);
    $phoneRow = $stmt->fetch();
    if ($phoneRow && !empty($phoneRow['participant_phone'])) {
        $submitterPhone = preg_replace('/[^0-9+]/', '', trim($phoneRow['participant_phone']));
    }
}

// Randomized encouraging messages
$encouragements = [
    "Hopefully you're all doing well — great effort, keep going!",
    "Sounds like you're smashing it out there — keep up the brilliant work!",
    "Hope the team is in good spirits. You're doing amazingly, keep it up!",
    "Brilliant stuff — rest up well tonight and keep pushing tomorrow!",
    "You lot are doing fantastic. Enjoy the evening and recharge for tomorrow!",
    "Great going team — hope you're having a brilliant time. Keep at it!",
    "Well done on another great day. Rest up and go again tomorrow!",
    "Top effort today — hope you're all feeling good. Keep smashing it!",
    "Ace work out there. Get some good rest and keep the momentum going!",
    "Proper impressive stuff — enjoy your evening and keep the energy up!",
];

$encouragement = $encouragements[array_rand($encouragements)];

// Build the WhatsApp message
$firstName = explode(' ', trim($submitterName))[0] ?: $submitterName;

$whatsappMessage = "Hi {$firstName}\n\n";
if ($locationName !== '') {
    $whatsappMessage .= "We have got your check in and can see you are staying at {$locationName}.\n\n";
} else {
    $whatsappMessage .= "We have got your check in.\n\n";
}
$whatsappMessage .= "{$encouragement}\n\n";
$whatsappMessage .= "Thanks\n\n";
$whatsappMessage .= $leaderName;

// Build the wa.me URL (strip leading + for the URL format)
$phoneForUrl = ltrim($submitterPhone, '+');
$whatsappUrl = $phoneForUrl !== ''
    ? 'https://wa.me/' . rawurlencode($phoneForUrl) . '?text=' . rawurlencode($whatsappMessage)
    : '';

include __DIR__ . '/header.php';
?>

<div class="container" style="max-width: 600px; padding-top: 3rem; padding-bottom: 3rem;">

    <div style="text-align: center; margin-bottom: 2rem;">
        <div style="font-size: 3rem; margin-bottom: 0.5rem;">✅</div>
        <h1 style="font-weight: 900; margin-bottom: 0.5rem;">Check-in Approved</h1>
        <p class="muted">
            <?= e($teamName) ?>'s check-in has been approved, published, and parents have been emailed.
        </p>
    </div>

    <?php if ($whatsappUrl !== ''): ?>
        <div style="background: #dcf8c6; border: 2px solid #25d366; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem;">
            <h2 style="font-weight: 700; font-size: 1.1rem; margin-top: 0; margin-bottom: 0.75rem; color: #075e54;">
                Send WhatsApp confirmation to <?= e($firstName) ?>
            </h2>

            <div style="background: #fff; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; font-size: 0.9rem; white-space: pre-line; color: #333; border: 1px solid #e0e0e0;"><?= e($whatsappMessage) ?></div>

            <a
                href="<?= e($whatsappUrl) ?>"
                target="_blank"
                rel="noopener"
                style="display: inline-block; background: #25d366; color: #fff; font-weight: 700; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-size: 1rem;"
            >
                <span style="margin-right: 0.4rem;">💬</span> Send via WhatsApp
            </a>

            <p style="margin-top: 0.75rem; font-size: 0.8rem; color: #666;">
                This will open WhatsApp with the message pre-filled. Just hit send.
            </p>
        </div>
    <?php else: ?>
        <div style="background: #f3f2f1; border: 1px solid #d8d8d8; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
            <p style="margin: 0; color: #666;">
                No phone number on file for <?= e($submitterName ?: 'the submitter') ?>, so WhatsApp confirmation isn't available.
                You can add their number in their person record for next time.
            </p>
        </div>
    <?php endif; ?>

    <div style="text-align: center; margin-top: 2rem;">
        <a
            href="<?= e(url('team_links.php?view=team&team_id=' . $teamId . '&tab=pending')) ?>"
            class="btn btn-outline-primary"
        >
            Back to pending check-ins
        </a>
    </div>

</div>

<?php include __DIR__ . '/footer.php'; ?>
