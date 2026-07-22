<?php
require_once __DIR__ . '/auth.php';

require_login();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();
$user = current_user();

$error = '';
$success = '';
$previewHtml = '';
$previewCount = 0;
$showPreview = false;
$previewRecipient = null;
$previewSubject = '';

const EMAIL_ALL_TEMPLATE_PATH = __DIR__ . '/assets/email_template.html';

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

function email_all_main_app_link(): string
{
    return url('dashboard.php');
}

function email_all_apply_placeholders(string $value, array $context): string
{
    $replacements = [
        '{{young_person_name}}' => $context['young_person_name'] ?? '',
        '{{team_name}}' => $context['team_name'] ?? '',
        '{{team_link}}' => $context['team_link'] ?? '',
        '{{portal_link}}' => $context['portal_link'] ?? '',
        '{{recipient_email}}' => $context['recipient_email'] ?? '',
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $value);
}

function email_all_source_label(string $source): string
{
    $labels = [
        'participant' => 'Participant',
        'updates' => 'Update contact',
        'emergency' => 'Emergency contact',
        'manual' => 'Manual recipient',
        'leader' => 'Leader',
        'sender_copy' => 'Sender copy',
    ];

    return $labels[$source] ?? ucfirst(str_replace('_', ' ', $source));
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

/**
 * These helpers intentionally mirror the cron email worker so the preview uses
 * the same template placeholder replacement as the real queued email send.
 */
function email_all_mail_constant(string $name, $default = null)
{
    return defined($name) ? constant($name) : $default;
}

function email_all_safe_email_html(string $html): string
{
    $allowedTags = '<p><br><strong><b><em><i><u><a><ol><ul><li><span><blockquote><h1><h2><h3><h4><table><thead><tbody><tr><td><th>';

    $html = strip_tags($html, $allowedTags);

    /**
     * Remove inline JS/event handlers.
     */
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

    /**
     * Remove javascript: links.
     */
    $html = preg_replace('/href\s*=\s*([\'\"])\s*javascript:[^\'\"]*\1/i', 'href="#"', $html);

    /**
     * Permit the same basic inline styles as the cron worker.
     */
    $html = preg_replace_callback('/style\s*=\s*([\'\"])(.*?)\1/i', function ($matches) {
        $style = $matches[2];
        $safeRules = [];

        foreach (explode(';', $style) as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            if (preg_match('/^(color|background-color|font-weight|font-style|text-decoration|margin|margin-top|margin-bottom|padding|line-height|font-size|border-left|display)\s*:\s*[^;"\']+$/i', $rule)) {
                $safeRules[] = $rule;
            }
        }

        if (empty($safeRules)) {
            return '';
        }

        return ' style="' . htmlspecialchars(implode('; ', $safeRules), ENT_QUOTES, 'UTF-8') . '"';
    }, $html);

    /**
     * Permit safe link targets only.
     */
    $html = preg_replace_callback('/href\s*=\s*([\'\"])(.*?)\1/i', function ($matches) {
        $quote = $matches[1];
        $href = trim(html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8'));

        if (
            preg_match('/^https?:\/\//i', $href)
            || preg_match('/^mailto:/i', $href)
            || preg_match('/^tel:/i', $href)
        ) {
            return 'href=' . $quote . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . $quote;
        }

        return 'href="#"';
    }, $html);

    return $html;
}

function email_all_plain_text_to_html(string $text): string
{
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $paragraphs = preg_split("/\n{2,}/", $escaped);
    $html = '';

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim((string)$paragraph);

        if ($paragraph === '') {
            continue;
        }

        $html .= '<p style="margin:0 0 14px 0;color:#1d1d1d;font-size:16px;line-height:1.6;">'
            . nl2br($paragraph)
            . '</p>';
    }

    return $html;
}

function email_all_content_to_html(string $content): string
{
    $content = trim($content);

    if ($content === '') {
        return '';
    }

    if ($content !== strip_tags($content)) {
        return email_all_safe_email_html($content);
    }

    return email_all_plain_text_to_html($content);
}

function email_all_content_to_plain_text(string $content): string
{
    $content = trim($content);

    if ($content === '') {
        return '';
    }

    $content = preg_replace('/<br\s*\/?>/i', "\n", $content);
    $content = preg_replace('/<\/p>/i', "\n\n", $content);
    $content = preg_replace('/<\/li>/i', "\n", $content);

    $text = html_entity_decode(strip_tags((string)$content), ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/\n{3,}/", "\n\n", (string)$text);

    return trim((string)$text);
}

function email_all_extract_first_url(string $content): string
{
    if (preg_match('/https?:\/\/[^\s<>"\']+/i', $content, $matches)) {
        return rtrim($matches[0], '.,)');
    }

    if (preg_match('/href\s*=\s*([\'\"])(https?:\/\/.*?)\1/i', $content, $matches)) {
        return trim((string)$matches[2]);
    }

    return '';
}

function email_all_make_template_replacements(string $subject, string $content): array
{
    $appName = (string)email_all_mail_constant('APP_NAME', 'Explorer Belt Live');
    $logoUrl = (string)email_all_mail_constant(
        'MAIL_LOGO_URL',
        'https://exbelt2026.irvalscouts.org.uk/assets/logo-generator-linear-blackwhite-png.png'
    );

    $ckUrl = (string)email_all_mail_constant('MAIL_CK_URL', 'https://ckenterprises.co.uk');

    $contentHtml = email_all_content_to_html($content);
    $plainText = email_all_content_to_plain_text($content);
    $firstUrl = email_all_extract_first_url($content);

    $preheader = mb_substr(
        trim(preg_replace('/\s+/', ' ', $plainText)),
        0,
        140
    );

    if ($preheader === '') {
        $preheader = $subject;
    }

    $ctaUrl = $firstUrl !== '' ? $firstUrl : (string)email_all_mail_constant('BASE_URL', '');
    $ctaText = $firstUrl !== '' ? 'View update' : 'Open portal';

    $introText = 'There is a new update from the Explorer Belt portal.';

    return [
        '{{APP_NAME}}' => htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'),
        '{{LOGO_URL}}' => htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'),
        '{{EMAIL_TITLE}}' => htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'),
        '{{PREHEADER_TEXT}}' => htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8'),
        '{{EMAIL_HEADING}}' => htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'),
        '{{INTRO_TEXT}}' => htmlspecialchars($introText, ENT_QUOTES, 'UTF-8'),
        '{{CONTENT_HTML}}' => $contentHtml,
        '{{CTA_URL}}' => htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8'),
        '{{CTA_TEXT}}' => htmlspecialchars($ctaText, ENT_QUOTES, 'UTF-8'),
        '{{CK_ENTERPRISES_URL}}' => htmlspecialchars($ckUrl, ENT_QUOTES, 'UTF-8'),
        '{{CURRENT_YEAR}}' => date('Y'),
    ];
}

/**
 * Render the final HTML email exactly as the cron/template system sends it.
 */
function email_all_render_template_preview(string $subject, string $content): string
{
    if (!is_file(EMAIL_ALL_TEMPLATE_PATH)) {
        return '
            <div style="font-family:Arial,sans-serif;max-width:680px;margin:0 auto;border:1px solid #ddd;background:#ffffff;">
                <div style="background:#7413dc;color:#fff;padding:20px;text-align:center;">
                    <img src="https://exbelt2026.irvalscouts.org.uk/assets/logo-generator-linear-blackwhite-png.png" alt="' . e(APP_NAME) . '" width="180" style="display:block;width:180px;max-width:180px;height:auto;border:0;margin:0 auto;">
                </div>
                <div style="padding:24px;">
                    <h2>' . e($subject) . '</h2>
                    ' . email_all_content_to_html($content) . '
                </div>
                <div style="background:#4d0b95;color:#fff;padding:16px;font-size:13px;">
                    Provided by CK Enterprises UK
                </div>
            </div>
        ';
    }

    $template = file_get_contents(EMAIL_ALL_TEMPLATE_PATH);

    if ($template === false || trim($template) === '') {
        throw new RuntimeException('Email template could not be read or is empty.');
    }

    return strtr($template, email_all_make_template_replacements($subject, $content));
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
    $participantEmailSelect = email_all_column_exists($pdo, 'young_people', 'participant_email')
        ? 'SUM(CASE WHEN participant_email IS NOT NULL AND participant_email <> "" THEN 1 ELSE 0 END)'
        : '0';

    $stmt = $pdo->query(
        'SELECT
            team_id,
            COUNT(*) AS people_count,
            ' . $participantEmailSelect . ' AS participant_email_count
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
 * Build recipient map from posted data.
 */
function email_all_build_recipient_map(PDO $pdo, array $teamsById, array $leaders, array $user, array $post): array
{
    $preset = $post['preset'] ?? 'custom';
    $mainAppLink = email_all_main_app_link();
    $recipientMap = [];

    $selectedTeamIds = $post['team_ids'] ?? [];
    $selectedTeamIds = is_array($selectedTeamIds) ? $selectedTeamIds : [];

    $selectedTeamIds = array_values(array_unique(array_filter(array_map(static function ($value) {
        return (int)$value;
    }, $selectedTeamIds))));

    $teamRecipientTypes = $post['team_recipient_types'] ?? [];
    $teamRecipientTypes = is_array($teamRecipientTypes) ? $teamRecipientTypes : [];

    $includeLeaders = isset($post['include_leaders']) ? 1 : 0;

    $selectedLeaderIds = $post['leader_ids'] ?? [];
    $selectedLeaderIds = is_array($selectedLeaderIds) ? $selectedLeaderIds : [];

    $selectedLeaderIds = array_values(array_unique(array_filter(array_map(static function ($value) {
        return (int)$value;
    }, $selectedLeaderIds))));

    /**
     * Presets decide selected teams and recipient types server-side too.
     */
    if ($preset !== 'custom') {
        $selectedTeamIds = [];

        foreach ($teamsById as $teamId => $team) {
            if (!empty($team['parent_token'])) {
                $selectedTeamIds[] = (int)$teamId;
            }
        }

        $teamRecipientTypes = [];

        foreach ($selectedTeamIds as $teamId) {
            if ($preset === 'all_contacts_participants_leaders') {
                $teamRecipientTypes[$teamId] = ['participants', 'updates', 'emergency'];
                $includeLeaders = 1;
            } elseif ($preset === 'emergency_only') {
                $teamRecipientTypes[$teamId] = ['emergency'];
            } elseif ($preset === 'participants_only') {
                $teamRecipientTypes[$teamId] = ['participants'];
            } elseif ($preset === 'leaders_only') {
                $teamRecipientTypes[$teamId] = [];
                $selectedTeamIds = [];
                $includeLeaders = 1;
            }
        }
    }

    $selectedTeams = [];

    foreach ($selectedTeamIds as $teamId) {
        if (isset($teamsById[$teamId])) {
            $selectedTeams[$teamId] = $teamsById[$teamId];
        }
    }

    /**
     * Team recipients.
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
     */
    $manualEmails = email_all_valid_emails_from_text(trim($post['manual_emails'] ?? ''));

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
     * Always send a copy to logged-in leader.
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

    return $recipientMap;
}

/**
 * Preview / queue actions.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_readonly()) {
        $error = 'Your account has read-only access and cannot send emails.';
    } else {
    $action = $_POST['action'] ?? '';

    if ($action === 'preview') {
        $subject = trim($_POST['subject'] ?? '');
        $messageHtml = email_all_clean_html($_POST['message_html'] ?? '');

        if ($subject === '') {
            $error = 'Email subject is required.';
        } elseif ($messageHtml === '') {
            $error = 'Email message is required.';
        } else {
            try {
                $recipientMap = email_all_build_recipient_map($pdo, $teamsById, $leaders, $user, $_POST);

                if (empty($recipientMap)) {
                    throw new RuntimeException('No valid recipient email addresses were found.');
                }

                $firstRecipient = reset($recipientMap);

                $sampleSubject = email_all_apply_placeholders($subject, $firstRecipient['context']);
                $sampleContent = email_all_build_content(
                    $messageHtml,
                    $firstRecipient['cta_url'],
                    $firstRecipient['cta_label'],
                    $firstRecipient['context']
                );

                $_SESSION['email_all_draft'] = [
                    'subject' => $subject,
                    'message_html' => $messageHtml,
                    'recipients' => $recipientMap,
                ];

                $previewCount = count($recipientMap);
                $previewRecipient = $firstRecipient;
                $previewSubject = $sampleSubject;
                $previewHtml = email_all_render_template_preview($sampleSubject, $sampleContent);
                $showPreview = true;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }

    if ($action === 'queue') {
        $draft = $_SESSION['email_all_draft'] ?? null;

        if (!$draft || empty($draft['recipients']) || empty($draft['subject']) || empty($draft['message_html'])) {
            $error = 'The email preview has expired. Please build the email again.';
        } else {
            try {
                $pdo->beginTransaction();

                $queuedCount = 0;

                foreach ($draft['recipients'] as $recipient) {
                    $subject = email_all_apply_placeholders($draft['subject'], $recipient['context']);

                    $content = email_all_build_content(
                        $draft['message_html'],
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

                unset($_SESSION['email_all_draft']);

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
    } // end else (not readonly)
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

    .step-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
    }

    .step-pill {
        border: 2px solid #d8d8d8;
        background: #f3f2f1;
        padding: 0.5rem 0.75rem;
        font-weight: 900;
    }

    .step-pill.active {
        background: #7413dc;
        border-color: #7413dc;
        color: #ffffff;
    }

    .step-section {
        display: none;
    }

    .step-section.active {
        display: block;
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

    .preview-frame {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
        max-height: 720px;
        overflow: auto;
    }

    .preview-count {
        font-size: 1.4rem;
        font-weight: 900;
        margin-bottom: 1rem;
    }

    .preview-recipient-panel {
        border: 2px solid #1d1d1d;
        background: #f8f8f8;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .preview-recipient-panel h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .preview-recipient-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }

    @media (max-width: 720px) {
        .preview-recipient-grid {
            grid-template-columns: 1fr;
        }
    }

    .preview-recipient-grid p {
        margin-bottom: 0;
    }

    .button-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 1rem;
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

    <?php if ($showPreview): ?>
        <section class="email-panel">
            <h2>Preview and confirm</h2>

            <p class="preview-count">
                This will send to <?= (int)$previewCount ?> contact<?= $previewCount === 1 ? '' : 's' ?>.
            </p>

            <div class="warning-box">
                <strong>Check the preview carefully.</strong>
                <p class="mb-0">
                    The example below is the actual email template rendered for one recipient.
                    Other recipients will have their own placeholders and links replaced when queued.
                </p>
            </div>

            <?php if ($previewRecipient): ?>
                <div class="preview-recipient-panel">
                    <h3>Example recipient used for this preview</h3>

                    <div class="preview-recipient-grid">
                        <p>
                            <strong>To:</strong><br>
                            <?= e($previewRecipient['email']) ?>
                        </p>

                        <p>
                            <strong>Recipient type:</strong><br>
                            <?= e(email_all_source_label($previewRecipient['source'] ?? '')) ?>
                        </p>

                        <p>
                            <strong>Subject after placeholders:</strong><br>
                            <?= e($previewSubject) ?>
                        </p>

                        <p>
                            <strong>Link used:</strong><br>
                            <a href="<?= e($previewRecipient['cta_url']) ?>" target="_blank" rel="noopener">
                                <?= e($previewRecipient['cta_url']) ?>
                            </a>
                        </p>

                        <p>
                            <strong>Young person placeholder:</strong><br>
                            <?= e($previewRecipient['context']['young_person_name'] ?: 'Blank for this recipient') ?>
                        </p>

                        <p>
                            <strong>Team placeholder:</strong><br>
                            <?= e($previewRecipient['context']['team_name'] ?: 'Blank for this recipient') ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="preview-frame">
                <iframe
                    title="Email preview"
                    srcdoc="<?= e($previewHtml) ?>"
                    style="width:100%;min-height:680px;border:0;background:#ffffff;"
                    sandbox="allow-popups allow-popups-to-escape-sandbox"
                ></iframe>
            </div>

            <div class="button-row">
                <form method="post">
                    <input type="hidden" name="action" value="queue">
                    <button class="btn btn-primary btn-lg"<?php if (is_readonly()): ?> disabled<?php endif; ?>>
                        Send to <?= (int)$previewCount ?> contact<?= $previewCount === 1 ? '' : 's' ?>
                    </button>
                </form>

                <a class="btn btn-outline-secondary btn-lg" href="<?= e(url('email_all.php')) ?>">
                    Start again
                </a>
            </div>
        </section>
    <?php else: ?>

        <div class="tool-intro">
            <h2>What this tool does</h2>
            <p class="mb-0">
                This queues an email for selected recipients. Team recipients receive their team’s private update link.
                Leaders and manual recipients receive the main portal link. A copy is always queued to you.
            </p>
        </div>

        <form method="post" id="emailAllForm" novalidate>
            <input type="hidden" name="action" value="preview">
            <input type="hidden" name="preset" id="selectedPreset" value="custom">

            <div class="step-bar">
                <span class="step-pill active" data-step-pill="1">1. Preset</span>
                <span class="step-pill" data-step-pill="2">2. Recipients</span>
                <span class="step-pill" data-step-pill="3">3. Message</span>
            </div>

            <section class="email-panel step-section active" data-step="1">
                <h2>1. Choose who this is for</h2>

                <div class="preset-grid">
                    <label class="preset-card">
                        <input type="radio" name="preset_choice" value="all_contacts_participants_leaders">
                        <span class="preset-title">Email all contacts, participants and leaders</span>
                        <span class="preset-text">
                            Sends to participants, update contacts, emergency contacts and leaders.
                        </span>
                    </label>

                    <label class="preset-card">
                        <input type="radio" name="preset_choice" value="emergency_only">
                        <span class="preset-title">Email emergency contacts</span>
                        <span class="preset-text">
                            Sends to emergency contact email addresses for all teams.
                        </span>
                    </label>

                    <label class="preset-card">
                        <input type="radio" name="preset_choice" value="participants_only">
                        <span class="preset-title">Email participants</span>
                        <span class="preset-text">
                            Sends to participant contact email addresses for all teams.
                        </span>
                    </label>

                    <label class="preset-card">
                        <input type="radio" name="preset_choice" value="leaders_only">
                        <span class="preset-title">Email leaders only</span>
                        <span class="preset-text">
                            Sends to active leaders only.
                        </span>
                    </label>

                    <label class="preset-card">
                        <input type="radio" name="preset_choice" value="custom" checked>
                        <span class="preset-title">Custom selection</span>
                        <span class="preset-text">
                            Choose specific teams and recipient types.
                        </span>
                    </label>
                </div>

                <div class="button-row">
                    <button type="button" class="btn btn-primary" id="presetNextButton">
                        Continue
                    </button>
                </div>
            </section>

            <section class="step-section" data-step="2">
                <div class="email-layout">
                    <div>
                        <section class="email-panel">
                            <h2>2. Custom teams and recipients</h2>

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
                    </aside>
                </div>

                <div class="button-row">
                    <button type="button" class="btn btn-outline-secondary" data-back-step="1">
                        Back
                    </button>

                    <button type="button" class="btn btn-primary" data-next-step="3">
                        Continue to message
                    </button>
                </div>
            </section>

            <section class="step-section" data-step="3">
                <div class="email-layout">
                    <div>
                        <section class="email-panel">
                            <h2>3. Write the message</h2>

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
                                <strong>Next step:</strong>
                                <p class="mb-0">
                                    You will see the email template rendered for one actual recipient before anything is added to the queue.
                                </p>
                            </div>

                            <div class="button-row">
                                <button type="button" class="btn btn-outline-secondary" id="messageBackButton">
                                    Back
                                </button>

                                <button class="btn btn-primary" type="submit"<?php if (is_readonly()): ?> disabled<?php endif; ?>>
                                    Preview email
                                </button>

                                <?php if (is_readonly()): ?>
                                    <p class="text-muted mt-2"><em>Your account has read-only access.</em></p>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>

                    <aside>
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
            </section>
        </form>
    <?php endif; ?>

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

        function setStep(step) {
            document.querySelectorAll('.step-section').forEach(function (section) {
                section.classList.toggle('active', section.dataset.step === String(step));
            });

            document.querySelectorAll('.step-pill').forEach(function (pill) {
                pill.classList.toggle('active', pill.dataset.stepPill === String(step));
            });

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

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

            if (includeLeaders) {
                includeLeaders.checked = false;
            }

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

            if (preset === 'all_contacts_participants_leaders') {
                selectAllTeams();
                setRecipientType('participants', true);
                setRecipientType('updates', true);
                setRecipientType('emergency', true);

                if (includeLeaders) {
                    includeLeaders.checked = true;
                }
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

        function customSelectionHasRecipients() {
            var checkedTeams = document.querySelectorAll('.js-team-checkbox:checked');
            var checkedRecipients = document.querySelectorAll('.js-recipient-type:checked');
            var manualEmails = document.getElementById('manual_emails');
            var leadersChecked = includeLeaders && includeLeaders.checked;

            return checkedTeams.length > 0 || checkedRecipients.length > 0 || leadersChecked || (manualEmails && manualEmails.value.trim() !== '');
        }

        document.querySelectorAll('input[name="preset_choice"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (radio.checked) {
                    selectedPreset.value = radio.value;

                    if (radio.value !== 'custom') {
                        applyPreset(radio.value);
                    } else {
                        clearTeamSelections();
                    }
                }
            });
        });

        document.getElementById('presetNextButton').addEventListener('click', function () {
            var preset = selectedPreset.value || 'custom';

            if (preset === 'custom') {
                setStep(2);
                return;
            }

            applyPreset(preset);
            setStep(3);
        });

        document.querySelectorAll('[data-next-step]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (selectedPreset.value === 'custom' && !customSelectionHasRecipients()) {
                    alert('Please select at least one team, leader group, or manual recipient.');
                    return;
                }

                setStep(button.dataset.nextStep);
            });
        });

        document.querySelectorAll('[data-back-step]').forEach(function (button) {
            button.addEventListener('click', function () {
                setStep(button.dataset.backStep);
            });
        });

        document.getElementById('messageBackButton').addEventListener('click', function () {
            if (selectedPreset.value === 'custom') {
                setStep(2);
            } else {
                setStep(1);
            }
        });

        document.addEventListener('change', function (event) {
            if (
                event.target.classList.contains('js-team-checkbox') ||
                event.target.classList.contains('js-recipient-type') ||
                event.target.classList.contains('js-leader-checkbox') ||
                event.target.id === 'include_leaders'
            ) {
                if (selectedPreset.value === 'custom') {
                    updateTeamCards();
                }
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

            if (selectedPreset.value === 'custom' && !customSelectionHasRecipients()) {
                event.preventDefault();
                alert('Please choose recipients before previewing the email.');
                setStep(2);
                return;
            }
        });

        updateTeamCards();
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>