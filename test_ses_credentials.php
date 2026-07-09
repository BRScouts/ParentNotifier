<?php
/**
 * Temporary SES credential diagnostic script.
 * Run via CLI: php test_ses_credentials.php
 * Or via browser: https://yourdomain/test_ses_credentials.php
 * DELETE THIS FILE after debugging.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Aws\Ses\SesClient;
use Aws\Exception\AwsException;

$region    = trim((string)(defined('SES_AWS_REGION') ? SES_AWS_REGION : ''));
$accessKey = trim((string)(defined('SES_AWS_ACCESS_KEY_ID') ? SES_AWS_ACCESS_KEY_ID : ''));
$secretKey = trim((string)(defined('SES_AWS_SECRET_ACCESS_KEY') ? SES_AWS_SECRET_ACCESS_KEY : ''));

echo "=== SES Credential Diagnostics ===\n";
echo "Region:            {$region}\n";
echo "Access Key Prefix: " . substr($accessKey, 0, 4) . "\n";
echo "Access Key Length:  " . strlen($accessKey) . "\n";
echo "Secret Key Length:  " . strlen($secretKey) . "\n\n";

echo "=== Environment Variable Check ===\n";
$envKey     = getenv('AWS_ACCESS_KEY_ID');
$envSecret  = getenv('AWS_SECRET_ACCESS_KEY');
$envRegion  = getenv('AWS_DEFAULT_REGION');
$envSession = getenv('AWS_SESSION_TOKEN');
echo "AWS_ACCESS_KEY_ID:     " . ($envKey !== false ? "SET (prefix=" . substr($envKey, 0, 4) . ", len=" . strlen($envKey) . ")" : "NOT SET") . "\n";
echo "AWS_SECRET_ACCESS_KEY: " . ($envSecret !== false ? "SET (len=" . strlen($envSecret) . ")" : "NOT SET") . "\n";
echo "AWS_DEFAULT_REGION:    " . ($envRegion !== false ? $envRegion : "NOT SET") . "\n";
echo "AWS_SESSION_TOKEN:     " . ($envSession !== false ? "SET (len=" . strlen($envSession) . ") ← LIKELY CAUSE OF 'security token invalid'" : "NOT SET") . "\n\n";

// Validation
$issues = [];
if ($region !== 'eu-north-1') $issues[] = "Region is '{$region}', expected 'eu-north-1'";
if (substr($accessKey, 0, 4) !== 'AKIA') $issues[] = "Access key prefix is '" . substr($accessKey, 0, 4) . "', expected 'AKIA'";
if (strlen($accessKey) !== 20) $issues[] = "Access key length is " . strlen($accessKey) . ", expected 20";
if (strlen($secretKey) !== 40) $issues[] = "Secret key length is " . strlen($secretKey) . ", expected 40";

if (!empty($issues)) {
    echo "=== ⚠️  ISSUES FOUND ===\n";
    foreach ($issues as $issue) echo "  - {$issue}\n";
    echo "\n";
} else {
    echo "=== ✅ All credential checks PASSED ===\n\n";
}

echo "=== Testing SendEmail API call ===\n";
try {
    $ses = new SesClient([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => [
            'key'    => $accessKey,
            'secret' => $secretKey,
        ],
    ]);

    $ses->sendEmail([
        'Source' => defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@app.irvalscouts.org.uk',
        'Destination' => ['ToAddresses' => ['charlie@ckenterprises.co.uk']],
        'Message' => [
            'Subject' => ['Data' => 'SES Test', 'Charset' => 'UTF-8'],
            'Body' => ['Text' => ['Data' => 'Credential test.', 'Charset' => 'UTF-8']],
        ],
    ]);
    echo "✅ SendEmail SUCCEEDED\n";
} catch (AwsException $e) {
    $code = $e->getAwsErrorCode() ?? 'Unknown';
    $msg  = $e->getAwsErrorMessage() ?? $e->getMessage();
    echo "❌ {$code}: {$msg}\n\n";

    if ($code === 'MessageRejected') {
        echo "→ Credentials WORK. SES rejected the message (sandbox: recipient not verified).\n";
        echo "→ Verify your test recipient in SES eu-north-1 → Identities.\n";
    } elseif (str_contains($msg, 'security token')) {
        echo "→ 'Security token invalid' with AKIA keys means something is injecting a session token.\n";
        echo "→ Check: AWS_SESSION_TOKEN env var, ~/.aws/credentials, or EC2 instance metadata.\n";
    } elseif ($code === 'AccessDenied') {
        echo "→ IAM policy missing ses:SendEmail permission for this user.\n";
    }
} catch (Throwable $e) {
    echo "❌ " . $e->getMessage() . "\n";
}
