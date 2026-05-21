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

function email_all_apply_placeholders(string $html, array $context): string
{
    $replacements = [
        '{{young_person_name}}' => $context['young_person_name'] ?? '',
        '{{team_name}}' => $context['team_name'] ?? '',
        '{{team_link}}' => $context['team_link'] ?? '',
        '{{portal_link}}' => $context['portal_link'] ?? '',
        '{{recipient_email}}' => $context['recipient_email'] ?? '',
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $html);
}

function email_all_build_content(
    string $messageHtml,
    string $ctaUrl,
    string $ctaLabel,
    array $context
): string {
    $html = email_all_apply_placeholders($messageHtml, $context);

    $html .= '<hr>';
    $html .= '<p><strong>' . e($ctaLabel) . ':</strong><br>';
    $html .= '<a href="' . e($ctaUrl) . '">' . e($ctaUrl) . '</a></p>';

    return $html;
}

function email_all_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?'
        );

        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $exception) {
        return false;
    }
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
 * Count people per team for display.
 */
$teamCounts = [];

try {
    $stmt = $pdo->query(
        'SELECT
            team_id,
            COUNT(*) AS people_count,
            SUM(CASE WHEN participant_email IS NOT NULL AND participant_email <> "" THEN 1 ELSE 0 END) AS participant_email_count
         FROM young_people
         WHERE is_active = 1
         GROUP BY team_id'
    );

    foreach ($stmt->fetchAll() as $row) {
        $teamCounts[(int)$row['team_id']] = [
            'people_count' => (int)$row['people_count'],
            'participant_email_count' => (int)$row['participant_email_count'],
        ];
    }
} catch (Throwable $exception) {
    $teamCounts = [];
}

/**
 * Handle submit.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $preset = $_POST['preset'] ?? 'custom';

    $selectedTeamIds = $_POST['team_ids'] ?? [];
    $selectedTeamIds = is_array($selectedTeamIds) ? $selectedTeamIds : [];

    $selectedTeamIds = array_values(array_unique(array_filter(array_map(static function ($value) {
        return (int)$value;
    }, $selectedTeamIds))));

    $teamRecipientTypes = $_POST['team_recipient_types'] ?? [];
    $teamRecipientTypes = is_array($teamRecipientTypes) ? $teamRecipientTypes : [];

    $includeLeaders = isset($_POST['include_leaders']) ? 1 : 0;

    $selectedLeaderIds = $_POST['leader_ids'] ?? [];
    $selectedLeaderIds = is_array($selectedLeaderIds) ? $selectedLeaderIds : [];

    $selectedLeaderIds = array_values(array_unique(array_filter(array_map(static function ($value) {
        return (int)$value;
    }, $selectedLeaderIds))));

    $subject = trim($_POST['subject'] ?? '');
    $messageHtml = email_all_clean_html($_POST['message_html'] ?? '');
    $manualEmailsText = trim($_POST['manual_emails'] ?? '');

    $selectedTeams = [];

    foreach ($selectedTeamIds as $teamId) {
        if (isset($teamsById[$teamId])) {
            $selectedTeams[$teamId] = $teamsById[$teamId];
        }
    }

    $manualEmails = email_all_valid_emails_from_text($manualEmailsText);
    $hasAnyTeamSelection = !empty($selectedTeams);
    $hasManualRecipients = !empty($manualEmails);
    $hasLeaderSelection = $includeLeaders || !empty($selectedLeaderIds);

    if (!$hasAnyTeamSelection && !$hasManualRecipients && !$hasLeaderSelection) {
        $error = 'Choose at least one team, leader group, or manual recipient.';
    }

    if ($subject === '') {
        $error = 'Email subject is required.';
    }

    if ($messageHtml === '') {
        $error = 'Email message is required.';
    }

    if ($error === '') {
        try {
            $mainAppLink = email_all_main_app_link();
            $recipientMap = [];

            /**
             * Team recipients.
             *
             * Team recipient types:
             * - participants
             * - emergency
             * - updates
             */
            if (!empty($selectedTeams)) {
                $placeholders = implode(',', array_fill(0, count($selectedTeamIds), '?'));

                $participantEmailSelect = email_all_column_exists($pdo, 'young_people', 'participant_email')
                    ? 'yp.participant_email'
                    : 'NULL AS participant_email';

                $stmt = $pdo->prepare(
                    'SELECT
                        yp.id,
                        yp.name,
                        yp.team_id,
                        ' . $participantEmailSelect . ',
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

                    if (!isset($selectedTeams[$teamId])) {
                        continue;
                    }

                    $selectedTypes = $teamRecipientTypes[$teamId] ?? [];
                    $selectedTypes = is_array($selectedTypes) ? $selectedTypes : [];

                    if (empty($selectedTypes)) {
                        continue;
                    }

                    $teamLink = !empty($row['parent_token'])
                        ? url('dashboard.php?token=' . $row['parent_token'])
                        : $mainAppLink;

                    $youngPersonName = trim((string)($row['name'] ?? ''));
                    $teamName = trim((string)($row['team_name'] ?? ''));

                    $contextBase = [
                        'young_person_name' => $youngPersonName,
                        'team_name' => $teamName,
                        'team_link' => $teamLink,
                        'portal_link' => $mainAppLink,
                    ];

                    if (in_array('participants', $selectedTypes, true)) {
                        $participantEmail = trim((string)($row['participant_email'] ?? ''));

                        if ($participantEmail !== '' && filter_var($participantEmail, FILTER_VALIDATE_EMAIL)) {
                            $key = 'participant|' . strtolower($participantEmail) . '|person:' . (int)$row['id'];

                            $recipientMap[$key] = [
                                'email' => $participantEmail,
                                'team_id' => $teamId,
                                'cta_url' => $teamLink,
                                'cta_label' => 'View the team portal',
                                'source' => 'participant',
                                'context' => array_merge($contextBase, [
                                    'recipient_email' => $participantEmail,
                                ]),
                            ];
                        }
                    }

                    if (in_array('updates', $selectedTypes, true)) {
                        $updateEmails = email_all_unique_valid_emails(
                            email_all_decode_json_list($row['parent_emails_json'] ?? null)
                        );

                        foreach ($updateEmails as $email) {
                            $key = 'updates|' . strtolower($email) . '|person:' . (int)$row['id'];

                            $recipientMap[$key] = [
                                'email' => $email,
                                'team_id' => $teamId,
                                'cta_url' => $teamLink,
                                'cta_label' => 'View the team portal',
                                'source' => 'updates',
                                'context' => array_merge($contextBase, [
                                    'recipient_email' => $email,
                                ]),
                            ];
                        }
                    }

                    if (in_array('emergency', $selectedTypes, true)) {
                        $emergencyEmails = email_all_unique_valid_emails(
                            email_all_decode_emergency_contact_emails($row['emergency_contacts_json'] ?? null)
                        );

                        foreach ($emergencyEmails as $email) {
                            $key = 'emergency|' . strtolower($email) . '|person:' . (int)$row['id'];

                            $recipientMap[$key] = [
                                'email' => $email,
                                'team_id' => $teamId,
                                'cta_url' => $teamLink,
                                'cta_label' => 'View the team portal',
                                'source' => 'emergency',
                                'context' => array_merge($contextBase, [
                                    'recipient_email' => $email,
                                ]),
                            ];
                        }
                    }
                }
            }

            /**
             * Manual recipients.
             * Manual recipients get the main portal URL, not a team link.
             */
            foreach ($manualEmails as $email) {
                $key = 'manual|' . strtolower($email);

                $recipientMap[$key] = [
                    'email' => $email,
                    'team_id' => null,
                    'cta_url' => $mainAppLink,
                    'cta_label' => 'Open the portal',
                    'source' => 'manual',
                    'context' => [
                        'young_person_name' => '',
                        'team_name' => '',
                        'team_link' => '',
                        'portal_link' => $mainAppLink,
                        'recipient_email' => $email,
                    ],
                ];
            }

            /**
             * Leaders.
             */
            if ($includeLeaders) {
                foreach ($leaders as $leader) {
                    $email = trim((string)$leader['email']);

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }

                    $key = 'leader|' . strtolower($email);

                    $recipientMap[$key] = [
                        'email' => $email,
                        'team_id' => null,
                        'cta_url' => $mainAppLink,
                        'cta_label' => 'Open the portal',
                        'source' => 'leader',
                        'context' => [
                            'young_person_name' => '',
                            'team_name' => '',
                            'team_link' => '',
                            'portal_link' => $mainAppLink,
                            'recipient_email' => $email,
                        ],
                    ];
                }
            } elseif (!empty($selectedLeaderIds)) {
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

                    $key = 'leader|' . strtolower($email);

                    $recipientMap[$key] = [
                        'email' => $email,
                        'team_id' => null,
                        'cta_url' => $mainAppLink,
                        'cta_label' => 'Open the portal',
                        'source' => 'leader',
                        'context' => [
                            'young_person_name' => '',
                            'team_name' => '',
                            'team_link' => '',
                            'portal_link' => $mainAppLink,
                            'recipient_email' => $email,
                        ],
                    ];
                }
            }

            /**
             * Always send a copy to the logged-in leader.
             */
            $loggedInLeaderEmail = trim((string)($user['email'] ?? ''));

            if ($loggedInLeaderEmail !== '' && filter_var($loggedInLeaderEmail, FILTER_VALIDATE_EMAIL)) {
                $key = 'sender_copy|' . strtolower($loggedInLeaderEmail);

                $recipientMap[$key] = [
                    'email' => $loggedInLeaderEmail,
                    'team_id' => null,
                    'cta_url' => $mainAppLink,
                    'cta_label' => 'Open the portal',
                    'source' => 'sender_copy',
                    'context' => [
                        'young_person_name' => '',
                        'team_name' => '',
                        'team_link' => '',
                        'portal_link' => $mainAppLink,
                        'recipient_email' => $loggedInLeaderEmail,
                    ],
                ];
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
                    $recipient['cta_label'],
                    $recipient['context']
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

    .email-shell {
        max-width: 1180px;
    }

    .email-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 340px;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 980px) {
        .email-layout {
            grid-template-columns: 1fr;
        }
    }

    .email-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .email-panel h2,
    .email-panel h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .email-panel label {
        font-weight: 800;
    }

    .tool-intro {
        border-left: 8px solid #1d70b8;
        background: #eef7ff;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .preset-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }

    @media (max-width: 720px) {
        .preset-grid {
            grid-template-columns: 1fr;
        }
    }

    .preset-card {
        border: 2px solid #d8d8d8;
        background: #f8f8f8;
        padding: 1rem;
        cursor: pointer;
        display: block;
        height: 100%;
    }

    .preset-card:hover,
    .preset-card:focus-within {
        border-color: #1d1d1d;
        box-shadow: 0 0 0 3px #ffdd00;
    }

    .preset-card input {
        margin-right: 0.5rem;
    }

    .preset-title {
        font-weight: 900;
        display: block;
    }

    .preset-text {
        color: #505a5f;
        display: block;
        margin-top: 0.25rem;
        font-size: 0.95rem;
    }

    .team-card-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }

    @media (max-width: 820px) {
        .team-card-grid {
            grid-template-columns: 1fr;
        }
    }

    .team-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
    }

    .team-card.is-selected {
        border-color: #7413dc;
        box-shadow: inset 6px 0 0 #7413dc;
    }

    .team-card-header {
        display: flex;
        align-items: flex-start;
        gap: 0.6rem;
        margin-bottom: 0.75rem;
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

    .recipient-options {
        display: grid;
        gap: 0.4rem;
        margin-top: 0.5rem;
    }

    .recipient-option {
        border: 2px solid #d8d8d8;
        background: #f8f8f8;
        padding: 0.5rem;
        display: flex;
        gap: 0.5rem;
        align-items: flex-start;
    }

    .recipient-option span {
        display: block;
        font-weight: 800;
    }

    .recipient-option small {
        display: block;
        color: #505a5f;
        font-weight: 400;
    }

    .leader-list {
        display: grid;
        gap: 0.5rem;
    }

    .leader-row {
        border: 2px solid #d8d8d8;
        background: #f8f8f8;
        padding: 0.65rem;
    }

    .leader-row label {
        display: flex;
        gap: 0.5rem;
        align-items: flex-start;
        margin-bottom: 0;
        cursor: pointer;
    }

    .leader-row small {
        display: block;
        color: #505a5f;
        font-weight: 400;
    }

    .editor-wrap {
        border: 1px solid #ced4da;
        background: #ffffff;
    }

    #emailEditor {
        min-height: 300px;
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

    .placeholder-list {
        display: grid;
        gap: 0.4rem;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .placeholder-list code {
        background: #f3f2f1;
        border: 1px solid #b1b4b6;
        padding: 0.1rem 0.25rem;
        color: #1d1d1d;
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
            Queue targeted emails to participants, parents, emergency contacts and leaders.
        </p>
    </div>
</section>

<main id="main-content" class="container my-5 email-shell">

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

    <div class="tool-intro">
        <h2>What this tool does</h2>
        <p class="mb-0">
            This queues an email for the selected people. Team recipients receive their team’s private update link.
            Leaders and manual recipients receive the main portal link. A copy is always queued to you.
        </p>
    </div>

    <form method="post" id="emailAllForm" novalidate>
        <input type="hidden" name="preset" id="selectedPreset" value="custom">

        <div class="email-layout">
            <div>
                <section class="email-panel">
                    <h2>1. Choose a preset</h2>

                    <div class="preset-grid">
                        <label class="preset-card">
                            <input type="radio" name="preset_choice" value="all_contacts_leaders">
                            <span class="preset-title">Email all contacts and leaders</span>
                            <span class="preset-text">
                                Selects every team, update contacts, emergency contacts and all leaders.
                            </span>
                        </label>

                        <label class="preset-card">
                            <input type="radio" name="preset_choice" value="updates_only">
                            <span class="preset-title">Email update contacts</span>
                            <span class="preset-text">
                                Selects every team and sends to parent/update email addresses only.
                            </span>
                        </label>

                        <label class="preset-card">
                            <input type="radio" name="preset_choice" value="emergency_only">
                            <span class="preset-title">Email emergency contacts</span>
                            <span class="preset-text">
                                Selects every team and sends to emergency contact email addresses only.
                            </span>
                        </label>

                        <label class="preset-card">
                            <input type="radio" name="preset_choice" value="participants_only">
                            <span class="preset-title">Email participants</span>
                            <span class="preset-text">
                                Selects every team and sends to participant contact email addresses only.
                            </span>
                        </label>

                        <label class="preset-card">
                            <input type="radio" name="preset_choice" value="leaders_only">
                            <span class="preset-title">Email leaders only</span>
                            <span class="preset-text">
                                Sends to active leaders only. No team contacts are selected.
                            </span>
                        </label>

                        <label class="preset-card">
                            <input type="radio" name="preset_choice" value="custom" checked>
                            <span class="preset-title">Custom selection</span>
                            <span class="preset-text">
                                Choose specific teams and recipient types below.
                            </span>
                        </label>
                    </div>
                </section>

                <section class="email-panel">
                    <h2>2. Teams and recipients</h2>

                    <?php if (empty($teams)): ?>
                        <div class="alert alert-warning">
                            No teams have been created yet.
                        </div>
                    <?php else: ?>
                        <div class="team-card-grid">
                            <?php foreach ($teams as $team): ?>
                                <?php
                                $teamId = (int)$team['id'];
                                $counts = $teamCounts[$teamId] ?? [
                                    'people_count' => 0,
                                    'participant_email_count' => 0,
                                ];
                                ?>

                                <div class="team-card js-team-card">
                                    <div class="team-card-header">
                                        <input
                                            class="js-team-checkbox"
                                            type="checkbox"
                                            id="team_<?= $teamId ?>"
                                            name="team_ids[]"
                                            value="<?= $teamId ?>"
                                            <?= empty($team['parent_token']) ? 'disabled' : '' ?>
                                        >

                                        <label for="team_<?= $teamId ?>" class="team-card-title">
                                            <?= e($team['name']) ?>

                                            <small>
                                                <?= (int)$counts['people_count'] ?> participant<?= (int)$counts['people_count'] === 1 ? '' : 's' ?>
                                                ·
                                                <?= (int)$counts['participant_email_count'] ?> participant email<?= (int)$counts['participant_email_count'] === 1 ? '' : 's' ?>

                                                <?php if (empty($team['parent_token'])): ?>
                                                    · no parent link configured
                                                <?php endif; ?>
                                            </small>
                                        </label>
                                    </div>

                                    <div class="recipient-options">
                                        <label class="recipient-option">
                                            <input
                                                class="js-recipient-type"
                                                type="checkbox"
                                                name="team_recipient_types[<?= $teamId ?>][]"
                                                value="participants"
                                                <?= empty($team['parent_token']) ? 'disabled' : '' ?>
                                            >
                                            <span>
                                                Participants
                                                <small>Uses participant contact email.</small>
                                            </span>
                                        </label>

                                        <label class="recipient-option">
                                            <input
                                                class="js-recipient-type"
                                                type="checkbox"
                                                name="team_recipient_types[<?= $teamId ?>][]"
                                                value="updates"
                                                <?= empty($team['parent_token']) ? 'disabled' : '' ?>
                                            >
                                            <span>
                                                Update contacts
                                                <small>Uses the parent/update email list.</small>
                                            </span>
                                        </label>

                                        <label class="recipient-option">
                                            <input
                                                class="js-recipient-type"
                                                type="checkbox"
                                                name="team_recipient_types[<?= $teamId ?>][]"
                                                value="emergency"
                                                <?= empty($team['parent_token']) ? 'disabled' : '' ?>
                                            >
                                            <span>
                                                Emergency contacts
                                                <small>Uses emergency contact email addresses.</small>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="email-panel">
                    <h2>3. Message</h2>

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
                </section>
            </div>

            <aside>
                <section class="email-panel">
                    <h2>Leaders</h2>

                    <div class="form-check mb-3">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="include_leaders"
                            name="include_leaders"
                            value="1"
                        >
                        <label class="form-check-label" for="include_leaders">
                            Email all active leaders
                        </label>
                    </div>

                    <?php if (empty($leaders)): ?>
                        <p class="muted mb-0">
                            No leader email addresses found.
                        </p>
                    <?php else: ?>
                        <div class="leader-list">
                            <?php foreach ($leaders as $leader): ?>
                                <?php
                                $leaderEmail = trim((string)$leader['email']);
                                $isCurrentUser = strtolower($leaderEmail) === strtolower((string)($user['email'] ?? ''));
                                ?>

                                <div class="leader-row">
                                    <label>
                                        <input
                                            class="js-leader-checkbox"
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
                </section>

                <section class="email-panel">
                    <h2>Manual recipients</h2>

                    <p class="muted">
                        Manual recipients receive the main portal link, not a team link.
                    </p>

                    <div class="form-group">
                        <label for="manual_emails">Email addresses</label>
                        <textarea
                            class="form-control"
                            id="manual_emails"
                            name="manual_emails"
                            rows="5"
                            placeholder="Optional. Enter email addresses separated by commas or new lines."
                        ></textarea>
                    </div>
                </section>

                <section class="email-panel">
                    <h2>Placeholders</h2>

                    <p class="muted">
                        Add these to the subject or message. They are replaced for each queued email.
                    </p>

                    <ul class="placeholder-list">
                        <li><code>{{young_person_name}}</code> participant name</li>
                        <li><code>{{team_name}}</code> team name</li>
                        <li><code>{{team_link}}</code> private team link</li>
                        <li><code>{{portal_link}}</code> main portal link</li>
                        <li><code>{{recipient_email}}</code> recipient email</li>
                    </ul>

                    <hr>

                    <p class="muted mb-0">
                        Leader and manual emails may not have a young person or team name, so those placeholders will be blank.
                    </p>
                </section>
            </aside>
        </div>
    </form>

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
        var selectedPreset = document.getElementById('selectedPreset');
        var includeLeaders = document.getElementById('include_leaders');

        function allTeamCheckboxes() {
            return Array.prototype.slice.call(document.querySelectorAll('.js-team-checkbox:not(:disabled)'));
        }

        function allRecipientCheckboxes() {
            return Array.prototype.slice.call(document.querySelectorAll('.js-recipient-type:not(:disabled)'));
        }

        function clearTeamSelections() {
            allTeamCheckboxes().forEach(function (box) {
                box.checked = false;
            });

            allRecipientCheckboxes().forEach(function (box) {
                box.checked = false;
            });

            updateTeamCards();
        }

        function selectAllTeams() {
            allTeamCheckboxes().forEach(function (box) {
                box.checked = true;
            });
        }

        function setRecipientType(type, checked) {
            document.querySelectorAll('.js-recipient-type[value="' + type + '"]:not(:disabled)').forEach(function (box) {
                box.checked = checked;
            });
        }

        function updateTeamCards() {
            document.querySelectorAll('.js-team-card').forEach(function (card) {
                var teamBox = card.querySelector('.js-team-checkbox');
                var recipientBoxes = card.querySelectorAll('.js-recipient-type');

                var hasRecipient = false;

                recipientBoxes.forEach(function (box) {
                    if (box.checked) {
                        hasRecipient = true;
                    }
                });

                if (teamBox && hasRecipient && !teamBox.disabled) {
                    teamBox.checked = true;
                }

                if (teamBox && !teamBox.checked) {
                    recipientBoxes.forEach(function (box) {
                        box.checked = false;
                    });
                }

                card.classList.toggle('is-selected', teamBox && teamBox.checked);
            });
        }

        function applyPreset(preset) {
            selectedPreset.value = preset;

            clearTeamSelections();

            if (includeLeaders) {
                includeLeaders.checked = false;
            }

            if (preset === 'all_contacts_leaders') {
                selectAllTeams();
                setRecipientType('updates', true);
                setRecipientType('emergency', true);

                if (includeLeaders) {
                    includeLeaders.checked = true;
                }
            }

            if (preset === 'updates_only') {
                selectAllTeams();
                setRecipientType('updates', true);
            }

            if (preset === 'emergency_only') {
                selectAllTeams();
                setRecipientType('emergency', true);
            }

            if (preset === 'participants_only') {
                selectAllTeams();
                setRecipientType('participants', true);
            }

            if (preset === 'leaders_only') {
                if (includeLeaders) {
                    includeLeaders.checked = true;
                }
            }

            updateTeamCards();
        }

        document.querySelectorAll('input[name="preset_choice"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (radio.checked) {
                    applyPreset(radio.value);
                }
            });
        });

        document.addEventListener('change', function (event) {
            if (
                event.target.classList.contains('js-team-checkbox') ||
                event.target.classList.contains('js-recipient-type') ||
                event.target.classList.contains('js-leader-checkbox') ||
                event.target.id === 'include_leaders'
            ) {
                selectedPreset.value = 'custom';

                var customRadio = document.querySelector('input[name="preset_choice"][value="custom"]');

                if (customRadio) {
                    customRadio.checked = true;
                }

                updateTeamCards();
            }
        });

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

            var checkedTeams = document.querySelectorAll('.js-team-checkbox:checked');
            var checkedRecipients = document.querySelectorAll('.js-recipient-type:checked');
            var manualEmails = document.getElementById('manual_emails');
            var leadersChecked = includeLeaders && includeLeaders.checked;

            if (
                checkedTeams.length === 0 &&
                checkedRecipients.length === 0 &&
                !leadersChecked &&
                (!manualEmails || manualEmails.value.trim() === '')
            ) {
                event.preventDefault();
                alert('Please choose a preset, select recipients, or add manual recipients.');
                return;
            }

            if (checkedTeams.length > 0 && checkedRecipients.length === 0 && !leadersChecked) {
                event.preventDefault();
                alert('Please choose at least one recipient type for the selected teams.');
                return;
            }
        });

        updateTeamCards();
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>