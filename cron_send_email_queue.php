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

function safe_email_html(string $html): string
{
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

            if (preg_match('/^(color|background-color|font-weight|font-style|text-decoration)\s*:\s*[^;"\']+$/i', $rule)) {
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
        $paragraph = trim($paragraph);

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

    $text = html_entity_decode(strip_tags($content), ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/\n{3,}/", "\n\n", (string)$text);

    return trim((string)$text);
}

function extract_first_url(string $content): string
{
    if (preg_match('/https?:\/\/[^\s<>"\']+/i', $content, $matches)) {
        return rtrim($matches[0], '.,)');
    }

    return '';
}

function build_email_template(string $subject, string $content): string
{
    $appName = htmlspecialchars((string)mail_constant('APP_NAME', 'Explorer Belt Live'), ENT_QUOTES, 'UTF-8');
    $logoUrl = htmlspecialchars((string)mail_constant('MAIL_LOGO_URL', ''), ENT_QUOTES, 'UTF-8');
    $ckUrl = htmlspecialchars((string)mail_constant('MAIL_CK_URL', 'https://ckenterprises.co.uk'), ENT_QUOTES, 'UTF-8');
    $heading = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $contentHtml = content_to_html($content);

    $ctaUrlRaw = extract_first_url($content);
    $ctaUrl = htmlspecialchars($ctaUrlRaw, ENT_QUOTES, 'UTF-8');

    $logoHtml = '';

    if ($logoUrl !== '') {
        $logoHtml = '
            <img
                src="' . $logoUrl . '"
                alt="' . $appName . '"
                width="180"
                style="display:block;width:180px;max-width:180px;height:auto;border:0;background:transparent;"
            >
        ';
    } else {
        $logoHtml = '
            <div style="color:#ffffff;font-size:22px;font-weight:900;">
                ' . $appName . '
            </div>
        ';
    }

    $ctaHtml = '';

    if ($ctaUrl !== '') {
        $ctaHtml = '
            <tr>
                <td style="padding:20px 24px 24px 24px;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                        <tr>
                            <td style="background:#1d70b8;border:2px solid #1d70b8;">
                                <a
                                    href="' . $ctaUrl . '"
                                    style="display:inline-block;padding:12px 18px;color:#ffffff;text-decoration:none;font-weight:900;font-size:16px;"
                                >
                                    View update
                                </a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        ';
    }

    return '<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>' . $heading . '</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>

<body style="margin:0;padding:0;background:#f3f2f1;color:#1d1d1d;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;">

    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
        ' . $heading . '
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f3f2f1;margin:0;padding:0;">
        <tr>
            <td align="center" style="padding:24px 12px;">

                <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:#ffffff;border-collapse:collapse;">

                    <tr>
                        <td style="background:#7413dc;padding:20px 24px;color:#ffffff;">
                            ' . $logoHtml . '
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px 24px 12px 24px;">
                            <h1 style="margin:0;color:#1d1d1d;font-size:28px;line-height:1.2;font-weight:900;">
                                ' . $heading . '
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 24px 8px 24px;">
                            <div style="color:#1d1d1d;font-size:16px;line-height:1.6;">
                                ' . $contentHtml . '
                            </div>
                        </td>
                    </tr>

                    ' . $ctaHtml . '

                    <tr>
                        <td style="padding:16px 24px 24px 24px;">
                            <div style="border-left:8px solid #ffdd00;background:#fff7bf;padding:14px 16px;">
                                <p style="margin:0 0 8px 0;color:#1d1d1d;font-weight:900;">
                                    No news is not bad news.
                                </p>
                                <p style="margin:0;color:#1d1d1d;font-size:15px;line-height:1.5;">
                                    Updates and check-ins are added manually by leaders. They may not appear straight away,
                                    and may be delayed until all groups are confirmed settled for the night.
                                </p>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#4d0b95;padding:18px 24px;color:#ffffff;">
                            <p style="margin:0 0 8px 0;color:#ffffff;font-size:14px;line-height:1.5;">
                                <strong>' . $appName . '</strong><br>
                                Explorer Belt trip portal
                            </p>

                            <p style="margin:0 0 8px 0;color:#ffffff;font-size:13px;line-height:1.5;">
                                You are receiving this email because your address is listed for trip updates.
                            </p>

                            <p style="margin:0;color:#ffffff;font-size:13px;line-height:1.5;">
                                Provided by
                                <a href="' . $ckUrl . '" style="color:#ffffff;font-weight:900;text-decoration:underline;">
                                    CK Enterprises UK
                                </a>
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>';
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

flock($lockHandle, LOCK_UN);
fclose($lockHandle);