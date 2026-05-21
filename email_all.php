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

    /**
     * Basic formatting from Quill.
     */
    $allowedTags = '<p><br><strong><b><em><i><u><a><ol><ul><li><span><blockquote>';

    $html = strip_tags($html, $allowedTags);

    /**
     * Remove dangerous inline handlers.
     */
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

    /**
     * Remove javascript links.
     */
    $html = preg_replace('/href\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', 'href="#"', $html);

    /**
     * Keep simple colour styles from Quill.
     */
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

function email_all_html_to_text(string $html): string
{
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $html = preg_replace('/<\/p>/i', "\n\n", $html);
    $html = preg_replace('/<\/li>/i', "\n", $html);

    $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/\n{3,}/", "\n\n", (string)$text);

    return trim((string)$text);
}

function email_all_team_link(array $team): string
{
    return url('dashboard.php?token=' . $team['parent_token']);
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
    array $teamLinks,
    ?string $specificTeamLink = null
): string {
    $html = $messageHtml;

    $html .= '<hr>';

    if ($specificTeamLink !== null) {
        $html .= '<p><strong>View the team portal:</strong><br>';
        $html .= '<a href="' . e($specificTeamLink) . '">' . e($specificTeamLink) . '</a></p>';
    } else {
        $html .= '<p><strong>View the selected team portal links:</strong></p>';
        $html .= '<ul>';

        foreach ($teamLinks as $teamName => $link) {
            $html .= '<li><strong>' . e($teamName) . ':</strong> ';
            $html .= '<a href="' . e($link) . '">' . e($link) . '</a></li>';
        }

        $html .= '</ul>';
    }

    $html .= '<p style="color:#505a5f;">';
    $html .= 'This email was sent from the Explorer Belt Live portal. Updates and check-ins are added manually by leaders.';
    $html .= '</p>';

    return $html;
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
 * Handle submit.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedTeamIds = $_POST['team_ids'] ?? [];
    $selectedTeamIds = is_array($selectedTeamIds) ? $selectedTeamIds : [];

    $selectedTeamIds = array_values(array_unique(array_filter(array_map(static function ($value) {
        return (int)$value;
    }, $selectedTeamIds))));

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
            /**
             * Build team links.
             */
            $teamLinks = [];

            foreach ($selectedTeams as $teamId => $team) {
                if (!empty($team['parent_token'])) {
                    $teamLinks[$team['name']] = email_all_team_link($team);
                }
            }

            if (empty($teamLinks)) {
                throw new RuntimeException('None of the selected teams have parent links configured.');
            }

            /**
             * Fetch parent emails for selected teams.
             *
             * Each recipient gets queued once.
             * If an email appears in multiple teams, the first matching team link is used.
             */
            $recipientMap = [];

            $placeholders = implode(',', array_fill(0, count($selectedTeamIds), '?'));

            $stmt = $pdo->prepare(
                'SELECT 
                    yp.id,
                    yp.name,
                    yp.team_id,
                    yp.parent_emails_json,
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

                foreach (email_all_decode_json_list($row['parent_emails_json'] ?? null) as $email) {
                    $email = trim($email);

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }

                    $key = strtolower($email);

                    if (!isset($recipientMap[$key])) {
                        $recipientMap[$key] = [
                            'email' => $email,
                            'team_id' => $teamId,
                            'team_name' => $row['team_name'],
                            'team_link' => $teamLink,
                            'source' => 'team',
                        ];
                    }
                }
            }

            /**
             * Manual recipients.
             *
             * Manual recipients receive the selected team links.
             * If there is only one selected team, their CTA will be that team link.
             * If multiple teams are selected, their CTA will be the first selected team link and all selected links are listed in the email.
             */
            $manualEmails = email_all_valid_emails_from_text($manualEmailsText);
            $firstTeamLink = reset($teamLinks) ?: null;

            foreach ($manualEmails as $email) {
                $key = strtolower($email);

                if (!isset($recipientMap[$key])) {
                    $recipientMap[$key] = [
                        'email' => $email,
                        'team_id' => null,
                        'team_name' => null,
                        'team_link' => count($teamLinks) === 1 ? $firstTeamLink : null,
                        'source' => 'manual',
                    ];
                }
            }

            if (empty($recipientMap)) {
                throw new RuntimeException('No valid recipient email addresses were found.');
            }

            $pdo->beginTransaction();

            $queuedCount = 0;
            $teamRecipientCount = 0;
            $manualRecipientCount = 0;

            foreach ($recipientMap as $recipient) {
                $specificTeamLink = $recipient['team_link'] ?? null;

                $content = email_all_build_content(
                    $messageHtml,
                    $teamLinks,
                    $specificTeamLink
                );

                email_all_queue_email(
                    $pdo,
                    $recipient['email'],
                    $subject,
                    $content,
                    $recipient['team_id']
                );

                $queuedCount++;

                if ($recipient['source'] === 'manual') {
                    $manualRecipientCount++;
                } else {
                    $teamRecipientCount++;
                }
            }

            $pdo->commit();

            $success = 'Email queued successfully. '
                . $queuedCount . ' email' . ($queuedCount === 1 ? '' : 's') . ' added to the queue. '
                . $teamRecipientCount . ' from team contacts, '
                . $manualRecipientCount . ' manual recipient' . ($manualRecipientCount === 1 ? '' : 's') . '.';
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

    .email-all-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 340px;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 900px) {
        .email-all-layout {
            grid-template-columns: 1fr;
        }
    }

    .email-panel,
    .help-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.5rem;
    }

    .email-panel h2,
    .help-panel h2 {
        margin-top: 0;
        font-weight: 900;
    }

    .email-panel label {
        font-weight: 800;
    }

    .team-check-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.5rem;
    }

    @media (max-width: 650px) {
        .team-check-grid {
            grid-template-columns: 1fr;
        }
    }

    .team-check {
        border: 2px solid #d8d8d8;
        background: #f8f8f8;
        padding: 0.75rem;
    }

    .team-check label {
        margin-bottom: 0;
        display: flex;
        gap: 0.5rem;
        align-items: flex-start;
        cursor: pointer;
    }

    .team-check small {
        display: block;
        color: #505a5f;
        margin-top: 0.25rem;
    }

    .editor-wrap {
        border: 1px solid #ced4da;
        background: #ffffff;
    }

    #emailEditor {
        min-height: 260px;
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

    .info-box {
        border-left: 8px solid #1d70b8;
        background: #eef7ff;
        padding: 1rem;
        margin-bottom: 1rem;
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
        <h1>Email to all</h1>
        <p class="lead">
            Send an email to selected team contacts without adding an update to the portal feed.
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

    <div class="email-all-layout">

        <section class="email-panel">
            <h2>Email details</h2>

            <form method="post" id="emailAllForm">

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
                    <label>Teams to include</label>

                    <?php if (empty($teams)): ?>
                        <div class="alert alert-warning">
                            No teams have been created yet.
                        </div>
                    <?php else: ?>
                        <div class="team-check-grid">
                            <?php foreach ($teams as $team): ?>
                                <div class="team-check">
                                    <label>
                                        <input
                                            type="checkbox"
                                            name="team_ids[]"
                                            value="<?= (int)$team['id'] ?>"
                                            <?= empty($team['parent_token']) ? 'disabled' : '' ?>
                                        >
                                        <span>
                                            <?= e($team['name']) ?>

                                            <?php if (empty($team['parent_token'])): ?>
                                                <small>No parent link configured</small>
                                            <?php else: ?>
                                                <small>Team portal link will be included</small>
                                            <?php endif; ?>
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

                    <small class="form-text text-muted">
                        Manual recipients will also receive the selected team portal link or links.
                    </small>
                </div>

                <div class="form-group">
                    <label for="emailEditor">Message</label>

                    <div class="editor-wrap">
                        <div id="emailEditor"></div>
                    </div>

                    <textarea id="message_html" name="message_html" hidden required></textarea>

                    <small class="form-text text-muted">
                        You can use basic formatting such as bold, italic, links, lists and simple colours.
                    </small>
                </div>

                <div class="warning-box">
                    <strong>This will not create a portal update.</strong>
                    <p class="mb-0">
                        Emails are added directly to the email queue. Your cron job will send them using the branded email template.
                    </p>
                </div>

                <button class="btn btn-primary" type="submit">
                    Queue email
                </button>
            </form>
        </section>

        <aside class="help-panel">
            <h2>How recipients are chosen</h2>

            <div class="info-box">
                <p>
                    For each selected team, the system uses the email addresses stored against young people in that team.
                </p>

                <p class="mb-0">
                    Duplicate email addresses are only queued once.
                </p>
            </div>

            <h3>Team portal links</h3>

            <p>
                Each team contact receives the private portal link for their team.
            </p>

            <p>
                Manual recipients receive the selected team link if one team is selected, or a list of selected team links if several teams are selected.
            </p>

            <p class="muted mb-0">
                The email queue content includes the portal link so the email template CTA button can point to it.
            </p>
        </aside>

    </div>

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