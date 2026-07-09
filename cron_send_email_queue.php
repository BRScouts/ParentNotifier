<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$autoload = __DIR__ . '/vendor/autoload.php';

if (!function_exists('cron_log')) {
    function cron_log(string $message, bool $isError = false): void
    {
        $message = rtrim($message) . PHP_EOL;

        if (!$isError && defined('STDOUT')) {
            fwrite(STDOUT, $message);
            return;
        }

        if ($isError && defined('STDERR')) {
            fwrite(STDERR, $message);
            return;
        }

        error_log($message);
    }
}

if (!file_exists($autoload)) {
    cron_log('Composer autoload not found. Run: composer require phpmailer/phpmailer', true);
    exit(1);
}

require_once $autoload;

use Aws\Ses\SesClient;
use Aws\Exception\AwsException;

$pdo = db();

/**
 * Prevent overlapping cron runs.
 */
$lockFile = sys_get_temp_dir() . '/exbelt_email_queue.lock';
$lockHandle = fopen($lockFile, 'c');

if (!$lockHandle) {
    cron_log('Could not open lock file.', true);
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    cron_log('Another email queue worker is already running.');
    exit(0);
}

/**
 * Helpers
 */

function cron_now_for_database(): string
{
    $timezone = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Helsinki';

    return (new DateTime('now', new DateTimeZone($timezone)))->format('Y-m-d H:i:s');
}

function mail_constant(string $name, $default = null)
{
    return defined($name) ? constant($name) : $default;
}

function app_base_url(): string
{
    $baseUrl = (string)mail_constant('BASE_URL', 'https://exbelt2026.irvalscouts.org.uk');

    return rtrim($baseUrl, '/');
}

function email_template_path(): string
{
    return __DIR__ . '/assets/email_template.html';
}

function table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );

        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function append_tracking_to_url(string $url, string $trackingToken): string
{
    $url = trim($url);

    if ($url === '' || $trackingToken === '') {
        return $url;
    }

    if (!preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    if (preg_match('/[?&]trk=/i', $url)) {
        return $url;
    }

    $fragment = '';
    $base = $url;

    if (strpos($url, '#') !== false) {
        [$base, $fragmentPart] = explode('#', $url, 2);
        $fragment = '#' . $fragmentPart;
    }

    $separator = strpos($base, '?') === false ? '?' : '&';

    return $base . $separator . 'trk=' . rawurlencode($trackingToken) . $fragment;
}

function safe_email_html(string $html): string
{
    $allowedTags = '<p><br><strong><b><em><i><u><a><ol><ul><li><span><blockquote><h1><h2><h3><h4><table><thead><tbody><tr><td><th><hr>';

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

            if (preg_match('/^(color|background-color|font-weight|font-style|text-decoration|margin|margin-top|margin-bottom|padding|line-height|font-size|border-left|display)\s*:\s*[^;"\']+$/i', $rule)) {
                $safeRules[] = $rule;
            }
        }

        if (empty($safeRules)) {
            return '';
        }

        return ' style="' . htmlspecialchars(implode('; ', $safeRules), ENT_QUOTES, 'UTF-8') . '"';
    }, $html);

    $html = preg_replace_callback('/href\s*=\s*([\'"])(.*?)\1/i', function ($matches) {
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

function auto_link_plain_urls_in_html(string $html): string
{
    if (trim($html) === '') {
        return $html;
    }

    /**
     * Protect existing href values so URLs inside <a href=""> are not double-linked.
     */
    $placeholders = [];

    $html = preg_replace_callback('/<a\b[^>]*>.*?<\/a>/is', function ($matches) use (&$placeholders) {
        $key = '%%EXISTING_LINK_' . count($placeholders) . '%%';
        $placeholders[$key] = $matches[0];

        return $key;
    }, $html);

    $html = preg_replace_callback('/(?<!["\'])\bhttps?:\/\/[^\s<]+/i', function ($matches) {
        $url = rtrim($matches[0], '.,);');

        $trailing = substr($matches[0], strlen($url));

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' .
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8') .
            '</a>' .
            $trailing;
    }, $html);

    foreach ($placeholders as $key => $linkHtml) {
        $html = str_replace($key, $linkHtml, $html);
    }

    return $html;
}

function plain_text_to_html(string $text): string
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

        $paragraph = preg_replace_callback('/\bhttps?:\/\/[^\s<]+/i', function ($matches) {
            $url = rtrim($matches[0], '.,);');
            $trailing = substr($matches[0], strlen($url));

            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' .
                htmlspecialchars($url, ENT_QUOTES, 'UTF-8') .
                '</a>' .
                $trailing;
        }, $paragraph);

        $html .= '<p style="margin:0 0 14px 0;color:#1d1d1d;font-size:16px;line-height:1.6;">'
            . nl2br($paragraph)
            . '</p>';
    }

    return $html;
}

function content_to_html(string $content): string
{
    $content = trim($content);

    if ($content === '') {
        return '';
    }

    if ($content !== strip_tags($content)) {
        return auto_link_plain_urls_in_html(safe_email_html($content));
    }

    return plain_text_to_html($content);
}

function content_to_plain_text(string $content): string
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

function extract_first_url(string $content): string
{
    if (preg_match('/href\s*=\s*([\'"])(https?:\/\/.*?)\1/i', $content, $matches)) {
        return trim((string)$matches[2]);
    }

    if (preg_match('/https?:\/\/[^\s<>"\']+/i', $content, $matches)) {
        return rtrim($matches[0], '.,)');
    }

    return '';
}

function track_html_links(string $html, string $trackingToken): string
{
    if ($trackingToken === '') {
        return $html;
    }

    return preg_replace_callback('/href\s*=\s*([\'"])(.*?)\1/i', function ($matches) use ($trackingToken) {
        $quote = $matches[1];
        $href = html_entity_decode(trim((string)$matches[2]), ENT_QUOTES, 'UTF-8');

        $trackedHref = append_tracking_to_url($href, $trackingToken);

        return 'href=' . $quote . htmlspecialchars($trackedHref, ENT_QUOTES, 'UTF-8') . $quote;
    }, $html);
}

function track_plain_text_links(string $text, string $trackingToken): string
{
    if ($trackingToken === '') {
        return $text;
    }

    return preg_replace_callback('/https?:\/\/[^\s<>"\']+/i', function ($matches) use ($trackingToken) {
        $url = rtrim($matches[0], '.,)');

        return append_tracking_to_url($url, $trackingToken);
    }, $text);
}

function add_open_tracking_pixel(string $html, string $openToken): string
{
    if ($openToken === '') {
        return $html;
    }

    $pixelUrl = app_base_url()
        . '/email_open.php?ot='
        . rawurlencode($openToken)
        . '&r='
        . rawurlencode(bin2hex(random_bytes(4)));

    $pixel = '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" alt="" style="display:none;width:1px;height:1px;border:0;line-height:0;font-size:0;" />';

    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $pixel . '</body>', $html, 1);
    }

    return $html . $pixel;
}

function ensure_email_tracking_token(PDO $pdo, array $email): array
{
    if (!table_exists($pdo, 'email_tracking_tokens')) {
        return [
            'id' => null,
            'token' => '',
            'open_token' => '',
        ];
    }

    $emailQueueId = (int)$email['id'];

    $stmt = $pdo->prepare(
        'SELECT *
         FROM email_tracking_tokens
         WHERE email_queue_id = ?
         LIMIT 1'
    );

    $stmt->execute([$emailQueueId]);
    $existing = $stmt->fetch();

    if ($existing) {
        return [
            'id' => (int)$existing['id'],
            'token' => (string)$existing['token'],
            'open_token' => (string)$existing['open_token'],
        ];
    }

    $token = bin2hex(random_bytes(32));
    $openToken = bin2hex(random_bytes(32));
    $now = cron_now_for_database();

    $stmt = $pdo->prepare(
        'INSERT INTO email_tracking_tokens
            (
                email_queue_id,
                token,
                open_token,
                recipient_email,
                related_team_id,
                related_post_id,
                created_at
            )
         VALUES
            (?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $emailQueueId,
        $token,
        $openToken,
        $email['to_email'] ?? null,
        $email['related_team_id'] ?? null,
        $email['related_post_id'] ?? null,
        $now,
    ]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'token' => $token,
        'open_token' => $openToken,
    ];
}

function make_placeholder_replacements(string $subject, string $content): array
{
    $appName = (string)mail_constant('APP_NAME', 'Explorer Belt Live');

    $logoUrl = (string)mail_constant(
        'MAIL_LOGO_URL',
        app_base_url() . '/assets/logo.png'
    );

    $ckUrl = (string)mail_constant('MAIL_CK_URL', 'https://ckenterprises.co.uk');

    $contentHtml = content_to_html($content);
    $plainText = content_to_plain_text($content);
    $firstUrl = extract_first_url($content);

    $preheader = mb_substr(
        trim(preg_replace('/\s+/', ' ', $plainText)),
        0,
        140
    );

    if ($preheader === '') {
        $preheader = $subject;
    }

    $ctaUrl = $firstUrl !== '' ? $firstUrl : app_base_url();
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

function build_email_template(string $subject, string $content): string
{
    $templatePath = email_template_path();

    if (!file_exists($templatePath)) {
        throw new RuntimeException('Email template was not found at assets/email_template.html');
    }

    $template = file_get_contents($templatePath);

    if ($template === false || trim($template) === '') {
        throw new RuntimeException('Email template could not be read or is empty.');
    }

    $replacements = make_placeholder_replacements($subject, $content);

    return strtr($template, $replacements);
}

function build_plain_text_email(string $subject, string $content): string
{
    $appName = (string)mail_constant('APP_NAME', 'Explorer Belt Portal');

    return $subject . "\n\n" .
        content_to_plain_text($content) .
        "\n\n" .
        "No news is not bad news. Updates and check-ins are added manually by leaders and may be delayed.\n\n" .
        $appName . "\n" .
        "Explorer Belt trip portal\n" .
        "Provided by CK Enterprises UK";
}

function make_ses_client(): SesClient
{
    return new SesClient([
        'version'     => 'latest',
        'region'      => trim((string)mail_constant('SES_AWS_REGION', 'eu-west-2')),
        'credentials' => [
            'key'    => trim((string)mail_constant('SES_AWS_ACCESS_KEY_ID', '')),
            'secret' => trim((string)mail_constant('SES_AWS_SECRET_ACCESS_KEY', '')),
        ],
    ]);
}

function send_ses_email(SesClient $ses, string $toEmail, string $subject, string $htmlBody, string $plainBody): void
{
    $fromAddress = (string)mail_constant('MAIL_FROM_ADDRESS',
        (string)mail_constant('MAIL_FROM_EMAIL', 'updates@exbelt2026.irvalscouts.org.uk')
    );
    $fromName = (string)mail_constant('MAIL_FROM_NAME', 'Explorer Belt Live');

    $source = $fromName !== '' ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromAddress}>" : $fromAddress;

    $replyToEmail = (string)mail_constant('MAIL_REPLY_TO_EMAIL', '');
    $replyToAddresses = [];

    if ($replyToEmail !== '') {
        $replyToName = (string)mail_constant('MAIL_REPLY_TO_NAME', 'Explorer Belt Team');
        $replyToAddresses[] = $replyToName !== ''
            ? "=?UTF-8?B?" . base64_encode($replyToName) . "?= <{$replyToEmail}>"
            : $replyToEmail;
    }

    $params = [
        'Source' => $source,
        'Destination' => [
            'ToAddresses' => [$toEmail],
        ],
        'Message' => [
            'Subject' => [
                'Data' => $subject,
                'Charset' => 'UTF-8',
            ],
            'Body' => [
                'Html' => [
                    'Data' => $htmlBody,
                    'Charset' => 'UTF-8',
                ],
                'Text' => [
                    'Data' => $plainBody,
                    'Charset' => 'UTF-8',
                ],
            ],
        ],
    ];

    if (!empty($replyToAddresses)) {
        $params['ReplyToAddresses'] = $replyToAddresses;
    }

    $ses->sendEmail($params);
}

function reset_stale_processing_emails(PDO $pdo): void
{
    $now = cron_now_for_database();

    $stmt = $pdo->prepare(
        'UPDATE email_queue
         SET status = "pending",
             last_error = "Reset from stale processing state",
             updated_at = ?
         WHERE status = "processing"
           AND updated_at < DATE_SUB(?, INTERVAL 30 MINUTE)'
    );

    $stmt->execute([
        $now,
        $now,
    ]);
}

function fetch_pending_emails(PDO $pdo): array
{
    $batchSize = (int)mail_constant('MAIL_QUEUE_BATCH_SIZE', 20);
    $batchSize = max(1, min(100, $batchSize));

    $stmt = $pdo->prepare(
        'SELECT *
         FROM email_queue
         WHERE status = "pending"
           AND attempts < 5
         ORDER BY queued_at ASC, id ASC
         LIMIT ' . $batchSize
    );

    $stmt->execute();

    return $stmt->fetchAll();
}

function mark_email_processing(PDO $pdo, int $id): bool
{
    $now = cron_now_for_database();

    $stmt = $pdo->prepare(
        'UPDATE email_queue
         SET status = "processing",
             attempts = attempts + 1,
             updated_at = ?
         WHERE id = ?
           AND status = "pending"'
    );

    $stmt->execute([
        $now,
        $id,
    ]);

    return $stmt->rowCount() === 1;
}

function mark_email_sent(PDO $pdo, int $id): void
{
    $now = cron_now_for_database();

    $stmt = $pdo->prepare(
        'UPDATE email_queue
         SET status = "sent",
             sent_at = ?,
             last_error = NULL,
             updated_at = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $now,
        $now,
        $id,
    ]);
}

function mark_email_failed(PDO $pdo, int $id, string $error): void
{
    $now = cron_now_for_database();

    $stmt = $pdo->prepare(
        'UPDATE email_queue
         SET status = IF(attempts >= 5, "failed", "pending"),
             last_error = ?,
             updated_at = ?
         WHERE id = ?'
    );

    $stmt->execute([
        mb_substr($error, 0, 2000),
        $now,
        $id,
    ]);
}

/**
 * Worker
 */

try {
    reset_stale_processing_emails($pdo);

    $emails = fetch_pending_emails($pdo);

    $sent = 0;
    $failed = 0;
    $skipped = 0;

    $ses = make_ses_client();
    $delaySeconds = (int)mail_constant('MAIL_QUEUE_DELAY_SECONDS', 0);

    foreach ($emails as $index => $email) {
        $id = (int)$email['id'];

        if (!mark_email_processing($pdo, $id)) {
            $skipped++;
            continue;
        }

        try {
            $toEmail = trim((string)$email['to_email']);
            $subject = trim((string)$email['subject']);
            $content = (string)$email['content'];

            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid recipient email address: ' . $toEmail);
            }

            if ($subject === '') {
                throw new RuntimeException('Email subject is empty.');
            }

            if (trim($content) === '') {
                throw new RuntimeException('Email content is empty.');
            }

            $tracking = ensure_email_tracking_token($pdo, $email);
            $trackingToken = (string)($tracking['token'] ?? '');
            $openToken = (string)($tracking['open_token'] ?? '');

            /**
             * This order matters:
             *
             * 1. Build the email template, replacing shortcodes such as {{CTA_URL}}.
             * 2. Scan the final HTML for all href links.
             * 3. Append &trk=... or ?trk=... to each http/https link.
             * 4. Add the open tracking pixel.
             *
             * This tracks:
             * - links added in the GUI editor;
             * - plain pasted URLs converted into links;
             * - template/shortcode CTA links;
             * - the plain text fallback links.
             */
            $htmlBody = build_email_template($subject, $content);
            $htmlBody = track_html_links($htmlBody, $trackingToken);
            $htmlBody = add_open_tracking_pixel($htmlBody, $openToken);

            $plainBody = build_plain_text_email($subject, $content);
            $plainBody = track_plain_text_links($plainBody, $trackingToken);

            send_ses_email($ses, $toEmail, $subject, $htmlBody, $plainBody);

            mark_email_sent($pdo, $id);
            $sent++;

            // Respect SES rate-limiting delay between sends
            if ($delaySeconds > 0 && $index < count($emails) - 1) {
                sleep($delaySeconds);
            }
        } catch (AwsException $exception) {
            $errorMsg = $exception->getAwsErrorMessage() ?: $exception->getMessage();
            mark_email_failed($pdo, $id, $errorMsg);
            $failed++;
        } catch (Throwable $exception) {
            mark_email_failed($pdo, $id, $exception->getMessage());
            $failed++;
        }
    }

    cron_log(sprintf(
        '[%s] Email queue processed (SES). Sent: %d. Failed/requeued: %d. Skipped: %d. Checked: %d.',
        cron_now_for_database(),
        $sent,
        $failed,
        $skipped,
        count($emails)
    ));
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}