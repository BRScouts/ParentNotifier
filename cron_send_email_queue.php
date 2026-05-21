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

use PHPMailer\PHPMailer\PHPMailer;

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

function mail_constant(string $name, $default = null)
{
    return defined($name) ? constant($name) : $default;
}

function email_template_path(): string
{
    return __DIR__ . '/assets/email_template.html';
}

function safe_email_html(string $html): string
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
    $html = preg_replace('/href\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', 'href="#"', $html);

    /**
     * Permit basic inline styles for email formatting.
     */
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

    /**
     * Permit safe link targets only.
     */
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
        return safe_email_html($content);
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
    if (preg_match('/https?:\/\/[^\s<>"\']+/i', $content, $matches)) {
        return rtrim($matches[0], '.,)');
    }

    if (preg_match('/href\s*=\s*([\'"])(https?:\/\/.*?)\1/i', $content, $matches)) {
        return trim((string)$matches[2]);
    }

    return '';
}

function make_placeholder_replacements(string $subject, string $content): array
{
    $appName = (string)mail_constant('APP_NAME', 'Explorer Belt Live');
    $logoUrl = (string)mail_constant(
        'MAIL_LOGO_URL',
        'https://exbelt2026.irvalscouts.org.uk/assets/photos/logo-generator-linear-blackwhite-png.png'
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

    $ctaUrl = $firstUrl !== '' ? $firstUrl : (string)mail_constant('BASE_URL', '');
    $ctaText = $firstUrl !== '' ? 'View update' : 'Open portal';

    $introText = 'There is a new update from the Explorer Belt Live portal.';

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
    $appName = (string)mail_constant('APP_NAME', 'Explorer Belt Live');

    return $subject . "\n\n" .
        content_to_plain_text($content) .
        "\n\n" .
        "No news is not bad news. Updates and check-ins are added manually by leaders and may be delayed.\n\n" .
        $appName . "\n" .
        "Explorer Belt trip portal\n" .
        "Provided by CK Enterprises UK";
}

function make_mailer(): PHPMailer
{
    $mail = new PHPMailer(true);

    $mail->isSMTP();

    $mail->Host = (string)mail_constant('MAIL_HOST', '');
    $mail->Port = (int)mail_constant('MAIL_PORT', 587);
    $mail->SMTPAuth = true;
    $mail->Username = (string)mail_constant('MAIL_USERNAME', '');
    $mail->Password = (string)mail_constant('MAIL_PASSWORD', '');

    $encryption = strtolower((string)mail_constant('MAIL_ENCRYPTION', 'tls'));

    if ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    }

    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';

    $mail->setFrom(
        (string)mail_constant('MAIL_FROM_EMAIL', 'updates@exbelt2026.irvalscouts.org.uk'),
        (string)mail_constant('MAIL_FROM_NAME', 'Explorer Belt Live')
    );

    $replyToEmail = (string)mail_constant('MAIL_REPLY_TO_EMAIL', '');

    if ($replyToEmail !== '') {
        $mail->addReplyTo(
            $replyToEmail,
            (string)mail_constant('MAIL_REPLY_TO_NAME', 'Explorer Belt Team')
        );
    }

    return $mail;
}

function reset_stale_processing_emails(PDO $pdo): void
{
    $pdo->exec(
        'UPDATE email_queue
         SET status = "pending",
             last_error = "Reset from stale processing state",
             updated_at = NOW()
         WHERE status = "processing"
           AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)'
    );
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
    $stmt = $pdo->prepare(
        'UPDATE email_queue
         SET status = "processing",
             attempts = attempts + 1,
             updated_at = NOW()
         WHERE id = ?
           AND status = "pending"'
    );

    $stmt->execute([$id]);

    return $stmt->rowCount() === 1;
}

function mark_email_sent(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare(
        'UPDATE email_queue
         SET status = "sent",
             sent_at = NOW(),
             last_error = NULL,
             updated_at = NOW()
         WHERE id = ?'
    );

    $stmt->execute([$id]);
}

function mark_email_failed(PDO $pdo, int $id, string $error): void
{
    $stmt = $pdo->prepare(
        'UPDATE email_queue
         SET status = IF(attempts >= 5, "failed", "pending"),
             last_error = ?,
             updated_at = NOW()
         WHERE id = ?'
    );

    $stmt->execute([
        mb_substr($error, 0, 2000),
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

    foreach ($emails as $email) {
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

            $mail = make_mailer();

            $mail->addAddress($toEmail);
            $mail->Subject = $subject;
            $mail->isHTML(true);

            /**
             * This now loads /assets/email_template.html
             * and replaces placeholders before sending.
             */
            $mail->Body = build_email_template($subject, $content);
            $mail->AltBody = build_plain_text_email($subject, $content);

            $mail->send();

            mark_email_sent($pdo, $id);
            $sent++;
        } catch (Throwable $exception) {
            mark_email_failed($pdo, $id, $exception->getMessage());
            $failed++;
        }
    }

    cron_log(sprintf(
        '[%s] Email queue processed. Sent: %d. Failed/requeued: %d. Skipped: %d. Checked: %d.',
        date('Y-m-d H:i:s'),
        $sent,
        $failed,
        $skipped,
        count($emails)
    ));
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}