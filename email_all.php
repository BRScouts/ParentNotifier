<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

$error = '';
$success = '';

/**
 * Helpers
 */

function email_all_clean_html(string $html): string
{
    $html = trim($html);

    if ($html === '') {
        return '';
    }

    $allowedTags = '<p><br><strong><b><em><i><u><a><ol><ul><li><span><blockquote>';

    $html = strip_tags($html, $allowedTags);
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/href\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', 'href="#"', $html);

    $html = preg_replace_callback('/style\s*=\s*([\'"])(.*?)\1/i', function ($matches) {
        $style = $matches[2];
        $safeRules = [];

        foreach (explode(';', $style) as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            if (preg_match('/^(color|background-color)\s*:\s*(#[0-9a-f]{3,6}|rgb\([0-9,\s]+\)|[a-z]+)$/i', $rule)) {
                $safeRules[] = $rule;
            }
        }

        if (empty($safeRules)) {
            return '';
        }

        return ' style="' . e(implode('; ', $safeRules)) . '"';
    }, $html);

    return $html;
}

function email_all_decode_json_list(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return [];
    }

    $items = [];

    foreach ($decoded as $item) {
        if (is_string($item)) {
            $item = trim($item);

            if ($item !== '') {
                $items[] = $item;
            }
        }
    }

    return $items;
}

function email_all_decode_emergency_contact_emails(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return [];
    }

    $emails = [];

    foreach ($decoded as $contact) {
        if (!is_array($contact)) {
            continue;
        }

        $email = trim((string)($contact['email'] ?? ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[strtolower($email)] = $email;
        }
    }

    return array_values($emails);
}

function email_all_valid_emails_from_text(string $text): array
{
    $text = str_replace(["\r\n", "\r", "\n", ";"], ',', $text);
    $parts = explode(',', $text);
    $emails = [];

    foreach ($parts as $part) {
        $email = trim($part);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[strtolower($email)] = $email;
        }
    }

    return array_values($emails);
}

function email_all_unique_valid_emails(array $emails): array
{
    $clean = [];

    foreach ($emails as $email) {
        $email = trim((string)$email);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $clean[strtolower($email)] = $email;
        }
    }

    return array_values($clean);
}

function email_all_team_link(array $team): string
{
    return url('dashboard.php?token=' . $team['parent_token']);
}

function email_all_main_app_link(): string
{
    return url('dashboard.php');
}

function email_all_queue_email(
    PDO $pdo,
    string $toEmail,
    string $subject,
    string $content,
    ?int $teamId = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO email_queue
            (to_email, subject, content, related_team_id)
         VALUES
            (?, ?, ?, ?)'
    );

    $stmt->execute([
        $toEmail,
        $subject,
        $content,
        $teamId,
    ]);
}

function email_all_build_content(
    string $messageHtml,
    string $ctaUrl,
    string $ctaLabel
): string {
    $html = $messageHtml;

    $html .= '<hr>';
    $html .= '<p><strong>' . e($ctaLabel) . ':</strong><br>';
    $html .= '<a href="' . e($ctaUrl) . '">' . e($ctaUrl) . '</a></p>';

    return $html;
}

function email_all_recipient_type_label(string $type): string
{
    if ($type === 'emergency') {
        return 'Emergency contacts';
    }

    if ($type === 'all') {
        return 'All contact emails';
    }

    return 'Parent update emails';
}

/**
 * Fetch teams.
 */
$teams = $pdo->query(
    'SELECT *
     FROM teams
     ORDER BY name ASC'
)->fetchAll();

$teamsById = [];

foreach ($teams as $team) {
    $teamsById[(int)$team['id']] = $team;
}

/**
 * Fetch leaders.
 */
$leaders = [];

try {
    $leaders = $pdo->query(
        'SELECT id, name, email
         FROM leaders
         WHERE email IS NOT NULL
           AND email <> ""
           AND (
                is_active = 1
                OR is_active IS NULL
           )
         ORDER BY name ASC'
    )->fetchAll();
} catch (Throwable $exception) {
    $leaders = $pdo->query(
        'SELECT id, name, email
         FROM leaders
         WHERE email IS NOT NULL
           AND email <> ""
         ORDER BY name ASC'
    )->fetchAll();
}

/**
 * Handle submit.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedTeamIds = $_POST['team_ids'] ?? [];
    $selectedTeamIds = is_array($selectedTeamIds) ? $selectedTeamIds : [];

    $selectedTeamIds = array_values(array_unique(array_filter(array_map(static function ($value) {
        return (int)$value;
    }, $selectedTeamIds))));

    $teamRecipientTypes = $_POST['team_recipient_type'] ?? [];
    $teamRecipientTypes = is_array($teamRecipientTypes) ? $teamRecipientTypes : [];

    $selectedLeaderIds = $_POST['leader_ids'] ?? [];
    $selectedLeaderIds = is_array($selectedLeaderIds) ? $selectedLeaderIds : [];

    $selectedLeaderIds = array_values(array_unique(array_filter(array_map(static function ($value) {
        return (int)$value;
    }, $selectedLeaderIds))));

    $subject = trim($_POST['subject'] ?? '');
    $messageHtml = email_all_clean_html($_POST['message_html'] ?? '');
    $manualEmailsText = trim($_POST['manual_emails'] ?? '');

    if (empty($selectedTeamIds)) {
        $error = 'Choose at least one team.';
    }

    if ($subject === '') {
        $error = 'Email subject is required.';
    }

    if ($messageHtml === '') {
        $error = 'Email message is required.';
    }

    $selectedTeams = [];

    foreach ($selectedTeamIds as $teamId) {
        if (isset($teamsById[$teamId])) {
            $selectedTeams[$teamId] = $teamsById[$teamId];
        }
    }

    if (empty($selectedTeams)) {
        $error = 'The selected teams could not be found.';
    }

    if ($error === '') {
        try {
            $mainAppLink = email_all_main_app_link();

            /**
             * Team links.
             */
            $teamLinks = [];

            foreach ($selectedTeams as $teamId => $team) {
                if (!empty($team['parent_token'])) {
                    $teamLinks[$teamId] = email_all_team_link($team);
                }
            }

            if (empty($teamLinks)) {
                throw new RuntimeException('None of the selected teams have parent links configured.');
            }

            $recipientMap = [];

            /**
             * Team contacts.
             *
             * Recipient type is selected per team card:
             * - parents: parent_emails_json
             * - emergency: emergency_contacts_json
             * - all: both merged
             */
            $placeholders = implode(',', array_fill(0, count($selectedTeamIds), '?'));

            $stmt = $pdo->prepare(
                'SELECT 
                    yp.id,
                    yp.name,
                    yp.team_id,
                    yp.parent_emails_json,
                    yp.emergency_contacts_json,
                    t.name AS team_name,
                    t.parent_token
                 FROM young_people yp
                 INNER JOIN teams t ON t.id = yp.team_id
                 WHERE yp.team_id IN (' . $placeholders . ')
                   AND yp.is_active = 1
                 ORDER BY t.name ASC, yp.name ASC'
            );

            $stmt->execute($selectedTeamIds);

            foreach ($stmt->fetchAll() as $row) {
                $teamId = (int)$row['team_id'];

                $teamLink = !empty($row['parent_token'])
                    ? url('dashboard.php?token=' . $row['parent_token'])
                    : '';

                if ($teamLink === '') {
                    continue;
                }

                $recipientType = $teamRecipientTypes[$teamId] ?? 'parents';

                if (!in_array($recipientType, ['parents', 'emergency', 'all'], true)) {
                    $recipientType = 'parents';
                }

                $parentEmails = email_all_unique_valid_emails(
                    email_all_decode_json_list($row['parent_emails_json'] ?? null)
                );

                $emergencyEmails = email_all_unique_valid_emails(
                    email_all_decode_emergency_contact_emails($row['emergency_contacts_json'] ?? null)
                );

                if ($recipientType === 'emergency') {
                    $emailsToUse = $emergencyEmails;
                } elseif ($recipientType === 'all') {
                    $emailsToUse = email_all_unique_valid_emails(array_merge($parentEmails, $emergencyEmails));
                } else {
                    $emailsToUse = $parentEmails;
                }

                foreach ($emailsToUse as $email) {
                    $key = strtolower($email);

                    if (!isset($recipientMap[$key])) {
                        $recipientMap[$key] = [
                            'email' => $email,
                            'team_id' => $teamId,
                            'cta_url' => $teamLink,
                            'cta_label' => 'View the team portal',
                            'source' => 'team',
                            'recipient_type' => $recipientType,
                        ];
                    }
                }
            }

            /**
             * Manual recipients.
             * These recipients get the main app URL only, not a team link.
             */
            $manualEmails = email_all_valid_emails_from_text($manualEmailsText);

            foreach ($manualEmails as $email) {
                $key = strtolower($email);

                if (!isset($recipientMap[$key])) {
                    $recipientMap[$key] = [
                        'email' => $email,
                        'team_id' => null,
                        'cta_url' => $mainAppLink,
                        'cta_label' => 'Open the portal',
                        'source' => 'manual',
                        'recipient_type' => 'manual',
                    ];
                }
            }

            /**
             * Selected leaders.
             * These recipients get the main app URL.
             */
            if (!empty($selectedLeaderIds)) {
                $leaderPlaceholders = implode(',', array_fill(0, count($selectedLeaderIds), '?'));

                $stmt = $pdo->prepare(
                    'SELECT id, name, email
                     FROM leaders
                     WHERE id IN (' . $leaderPlaceholders . ')
                       AND email IS NOT NULL
                       AND email <> ""
                     ORDER BY name ASC'
                );

                $stmt->execute($selectedLeaderIds);

                foreach ($stmt->fetchAll() as $leader) {
                    $email = trim((string)$leader['email']);

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }

                    $key = strtolower($email);

                    if (!isset($recipientMap[$key])) {
                        $recipientMap[$key] = [
                            'email' => $email,
                            'team_id' => null,
                            'cta_url' => $mainAppLink,
                            'cta_label' => 'Open the portal',
                            'source' => 'leader',
                            'recipient_type' => 'leader',
                        ];
                    }
                }
            }

            /**
             * Always send a copy to the logged-in leader.
             */
            $loggedInLeaderEmail = trim((string)($user['email'] ?? ''));

            if ($loggedInLeaderEmail !== '' && filter_var($loggedInLeaderEmail, FILTER_VALIDATE_EMAIL)) {
                $key = strtolower($loggedInLeaderEmail);

                if (!isset($recipientMap[$key])) {
                    $recipientMap[$key] = [
                        'email' => $loggedInLeaderEmail,
                        'team_id' => null,
                        'cta_url' => $mainAppLink,
                        'cta_label' => 'Open the portal',
                        'source' => 'sender_copy',
                        'recipient_type' => 'leader',
                    ];
                }
            }

            if (empty($recipientMap)) {
                throw new RuntimeException('No valid recipient email addresses were found.');
            }

            $pdo->beginTransaction();

            $queuedCount = 0;

            foreach ($recipientMap as $recipient) {
                $content = email_all_build_content(
                    $messageHtml,
                    $recipient['cta_url'],
                    $recipient['cta_label']
                );

                email_all_queue_email(
                    $pdo,
                    $recipient['email'],
                    $subject,
                    $content,
                    $recipient['team_id']
                );

                $queuedCount++;
            }

            $pdo->commit();

            $success = 'Email queued. '
                . $queuedCount . ' email' . ($queuedCount === 1 ? '' : 's') . ' added to the queue.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = 'Could not queue the email. ' . $exception->getMessage();
        }
    }
}

include __DIR__ . '/header.php';
?>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

<style>
    .page-hero,
    .page-hero h1,
    .page-hero p,
    .page-hero .lead {
        color: #ffffff !important;
    }

    .email-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.5rem;
    }

    .email-panel h2 {
        margin-top: 0;
        font-weight: 900;
    }

    .email-panel label {
        font-weight: 800;
    }

    .team-check-grid,
    .leader-check-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.5rem;
    }

    @media (max-width: 650px) {
        .team-check-grid,
        .leader-check-grid {
            grid-template-columns: 1fr;
        }
    }

    .team-check,
    .leader-check {
        border: 2px solid #d8d8d8;
        background: #f8f8f8;
        padding: 0.75rem;
    }

    .team-check label,
    .leader-check label {
        margin-bottom: 0;
        display: flex;
        gap: 0.5rem;
        align-items: flex-start;
        cursor: pointer;
    }

    .team-card-top {
        display: flex;
        gap: 0.5rem;
        align-items: flex-start;
        margin-bottom: 0.65rem;
    }

    .team-card-title {
        font-weight: 900;
        line-height: 1.25;
    }

    .team-card-title small {
        display: block;
        color: #505a5f;
        font-weight: 400;
        margin-top: 0.2rem;
    }

    .team-recipient-select {
        margin-top: 0.5rem;
    }

    .team-recipient-select label {
        display: block;
        font-weight: 800;
        margin-bottom: 0.25rem;
    }

    .team-check small,
    .leader-check small {
        display: block;
        color: #505a5f;
        margin-top: 0.25rem;
    }

    .editor-wrap {
        border: 1px solid #ced4da;
        background: #ffffff;
    }

    #emailEditor {
        min-height: 280px;
        background: #ffffff;
    }

    .ql-toolbar.ql-snow {
        border: 0;
        border-bottom: 1px solid #ced4da;
    }

    .ql-container.ql-snow {
        border: 0;
        font-size: 1rem;
    }

    .warning-box {
        border-left: 8px solid #ffdd00;
        background: #fff7bf;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .muted {
        color: #505a5f;
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>Email</h1>
        <p class="lead">
            Send an email to selected team contacts.
        </p>
    </div>
</section>

<main id="main-content" class="container my-5">

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

    <p>
        <a href="<?= e(url('dashboard.php')) ?>">
            Back to dashboard
        </a>
    </p>

    <section class="email-panel">
        <h2>Email details</h2>

        <form method="post" id="emailAllForm" novalidate>

            <div class="form-group">
                <label for="subject">Subject</label>
                <input
                    class="form-control"
                    id="subject"
                    name="subject"
                    required
                >
            </div>

            <div class="form-group">
                <label>Teams</label>

                <?php if (empty($teams)): ?>
                    <div class="alert alert-warning">
                        No teams have been created yet.
                    </div>
                <?php else: ?>
                    <div class="team-check-grid">
                        <?php foreach ($teams as $team): ?>
                            <?php $teamId = (int)$team['id']; ?>

                            <div class="team-check">
                                <div class="team-card-top">
                                    <input
                                        type="checkbox"
                                        id="team_<?= $teamId ?>"
                                        name="team_ids[]"
                                        value="<?= $teamId ?>"
                                        <?= empty($team['parent_token']) ? 'disabled' : '' ?>
                                    >

                                    <label for="team_<?= $teamId ?>" class="team-card-title">
                                        <?= e($team['name']) ?>

                                        <?php if (empty($team['parent_token'])): ?>
                                            <small>No parent link configured</small>
                                        <?php endif; ?>
                                    </label>
                                </div>

                                <div class="team-recipient-select">
                                    <label for="team_recipient_type_<?= $teamId ?>">
                                        Recipients
                                    </label>

                                    <select
                                        class="form-control form-control-sm"
                                        id="team_recipient_type_<?= $teamId ?>"
                                        name="team_recipient_type[<?= $teamId ?>]"
                                        <?= empty($team['parent_token']) ? 'disabled' : '' ?>
                                    >
                                        <option value="parents" selected>
                                            Parent update emails
                                        </option>
                                        <option value="emergency">
                                            Emergency contact emails
                                        </option>
                                        <option value="all">
                                            All contact emails
                                        </option>
                                    </select>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Leaders</label>

                <?php if (empty($leaders)): ?>
                    <p class="muted mb-0">
                        No leader email addresses found.
                    </p>
                <?php else: ?>
                    <div class="leader-check-grid">
                        <?php foreach ($leaders as $leader): ?>
                            <?php
                            $leaderEmail = trim((string)$leader['email']);
                            $isCurrentUser = strtolower($leaderEmail) === strtolower((string)($user['email'] ?? ''));
                            ?>
                            <div class="leader-check">
                                <label>
                                    <input
                                        type="checkbox"
                                        name="leader_ids[]"
                                        value="<?= (int)$leader['id'] ?>"
                                        <?= $isCurrentUser ? 'checked disabled' : '' ?>
                                    >
                                    <?php if ($isCurrentUser): ?>
                                        <input type="hidden" name="leader_ids[]" value="<?= (int)$leader['id'] ?>">
                                    <?php endif; ?>

                                    <span>
                                        <?= e($leader['name']) ?>
                                        <small>
                                            <?= e($leaderEmail) ?>
                                            <?= $isCurrentUser ? ' · copy always sent' : '' ?>
                                        </small>
                                    </span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="manual_emails">Manual recipients</label>
                <textarea
                    class="form-control"
                    id="manual_emails"
                    name="manual_emails"
                    rows="4"
                    placeholder="Optional. Enter email addresses separated by commas or new lines."
                ></textarea>
            </div>

            <div class="form-group">
                <label for="emailEditor">Message</label>

                <div class="editor-wrap">
                    <div id="emailEditor"></div>
                </div>

                <textarea id="message_html" name="message_html" hidden></textarea>
            </div>

            <div class="warning-box">
                <strong>This will queue the email for sending.</strong>
                <p class="mb-0">
                    A copy will also be sent to you.
                </p>
            </div>

            <button class="btn btn-primary" type="submit">
                Queue email
            </button>
        </form>
    </section>

</main>

<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

<script>
    (function () {
        var toolbarOptions = [
            ['bold', 'italic', 'underline'],
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            ['link'],
            [{ 'color': [] }, { 'background': [] }],
            ['clean']
        ];

        var quill = new Quill('#emailEditor', {
            theme: 'snow',
            modules: {
                toolbar: toolbarOptions
            },
            placeholder: 'Write the email message here...'
        });

        var form = document.getElementById('emailAllForm');
        var hiddenMessage = document.getElementById('message_html');

        form.addEventListener('submit', function (event) {
            hiddenMessage.value = quill.root.innerHTML.trim();

            var plainText = quill.getText().trim();

            if (plainText === '') {
                event.preventDefault();
                alert('Please enter the email message.');
                return;
            }

            var subject = document.getElementById('subject');

            if (!subject || subject.value.trim() === '') {
                event.preventDefault();
                alert('Please enter an email subject.');
                if (subject) {
                    subject.focus();
                }
                return;
            }

            var checkedTeams = document.querySelectorAll('input[name="team_ids[]"]:checked');

            if (checkedTeams.length === 0) {
                event.preventDefault();
                alert('Please choose at least one team.');
                return;
            }
        });
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>