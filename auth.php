<?php
declare(strict_types=1);
$composerAutoload = __DIR__ . '/vendor/autoload.php';

if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user(): ?array
{
    if (empty($_SESSION['leader_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, name, email, role, bio, photo_url, phone, is_active FROM leaders WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([(int)$_SESSION['leader_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_trip_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'trip_admin';
}

function is_readonly(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'readonly';
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

function require_trip_admin(): void
{
    require_login();

    if (!is_trip_admin()) {
        redirect('403.php');
    }
}

function login_leader(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT id, password_hash, is_active FROM leaders WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $leader = $stmt->fetch();

    if (!$leader || (int)$leader['is_active'] !== 1) {
        return false;
    }

    if (!password_verify($password, $leader['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['leader_id'] = (int)$leader['id'];

    $stmt = db()->prepare('UPDATE leaders SET last_login_at = NOW() WHERE id = ?');
    $stmt->execute([(int)$leader['id']]);

    return true;
}

function logout_leader(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }

    session_destroy();
}

function parent_access_team(): ?array
{
    $token = trim($_GET['token'] ?? $_SESSION['parent_token'] ?? '');

    if ($token === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM teams WHERE parent_token = ? AND is_public = 1 LIMIT 1');
    $stmt->execute([$token]);
    $team = $stmt->fetch();

    if (!$team) {
        return null;
    }

    $_SESSION['parent_token'] = $token;
    $_SESSION['parent_team_id'] = (int)$team['id'];

    return $team;
}

function require_parent_or_leader(): ?array
{
    if (is_logged_in()) {
        return null;
    }

    $team = parent_access_team();
    if (!$team) {
        redirect('403.php');
    }

    return $team;
}
use TheNetworg\OAuth2\Client\Provider\Azure;

if (!function_exists('microsoft_provider')) {
    function microsoft_provider(): Azure
    {
        if (!class_exists(Azure::class)) {
            throw new RuntimeException('Microsoft SSO package is not installed. Run: composer require thenetworg/oauth2-azure');
        }

        $provider = new Azure([
            'clientId' => MS_CLIENT_ID,
            'clientSecret' => MS_CLIENT_SECRET,
            'redirectUri' => MS_REDIRECT_URI,
            'tenant' => MS_TENANT_ID,
        ]);

        $provider->defaultEndPointVersion = Azure::ENDPOINT_VERSION_2_0;

        return $provider;
    }
}

if (!function_exists('email_domain_allowed')) {
    function email_domain_allowed(string $email): bool
    {
        if (empty(MS_ALLOWED_EMAIL_DOMAINS)) {
            return true;
        }

        $parts = explode('@', strtolower($email));
        $domain = end($parts);

        return in_array($domain, array_map('strtolower', MS_ALLOWED_EMAIL_DOMAINS), true);
    }
}

if (!function_exists('find_leader_by_email')) {
    function find_leader_by_email(string $email): ?array
    {
        $stmt = db()->prepare(
            'SELECT *
             FROM leaders
             WHERE LOWER(email) = LOWER(?)
             LIMIT 1'
        );

        $stmt->execute([$email]);
        $leader = $stmt->fetch();

        return $leader ?: null;
    }
}

if (!function_exists('login_leader_from_record')) {
    function login_leader_from_record(array $leader, string $method = 'microsoft'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        session_regenerate_id(true);

        $_SESSION['leader_id'] = (int)$leader['id'];
        $_SESSION['leader_email'] = $leader['email'] ?? '';
        $_SESSION['leader_name'] = $leader['name'] ?? '';
        $_SESSION['auth_method'] = $method;
    }
}

if (!function_exists('microsoft_extract_email')) {
    function microsoft_extract_email(array $resourceOwner): string
    {
        $candidates = [
            $resourceOwner['mail'] ?? '',
            $resourceOwner['userPrincipalName'] ?? '',
            $resourceOwner['upn'] ?? '',
            $resourceOwner['email'] ?? '',
            $resourceOwner['preferred_username'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('microsoft_login_from_callback')) {
    function microsoft_login_from_callback(string $code, string $state): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $expectedState = $_SESSION['ms_oauth_state'] ?? '';
        unset($_SESSION['ms_oauth_state']);

        if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
            throw new RuntimeException('Microsoft sign-in could not be verified. Please try again.');
        }

        $provider = microsoft_provider();

        $token = $provider->getAccessToken('authorization_code', [
            'code' => $code,
            'scope' => ['openid', 'profile', 'email', 'User.Read'],
        ]);

        $resourceOwner = $provider->getResourceOwner($token);
        $resourceOwnerArray = $resourceOwner->toArray();

        $email = microsoft_extract_email($resourceOwnerArray);

        if ($email === '') {
            throw new RuntimeException('Microsoft did not return a usable email address.');
        }

        if (!email_domain_allowed($email)) {
            throw new RuntimeException('This Microsoft account is not allowed to sign in.');
        }

        $leader = find_leader_by_email($email);

        if (!$leader) {
            throw new RuntimeException('Your Microsoft account is valid, but no leader account exists for ' . $email . '.');
        }

        if (isset($leader['is_active']) && (int)$leader['is_active'] !== 1) {
            throw new RuntimeException('This leader account is not active.');
        }

        login_leader_from_record($leader, 'microsoft');

        return $leader;
    }
}
/**
 * Lightweight analytics tracking.
 *
 * Tracks:
 * - email click attribution from ?trk=TOKEN
 * - page visits against the visitor session
 *
 * The token is intentionally opaque. Do not put email addresses directly in URLs.
 */

function analytics_now_for_database(): string
{
    $timezone = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Helsinki';

    return (new DateTime('now', new DateTimeZone($timezone)))->format('Y-m-d H:i:s');
}

function analytics_ip_hash(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($ip === '') {
        return null;
    }

    $secret = defined('APP_SECRET')
        ? APP_SECRET
        : (defined('DB_PASS') ? DB_PASS : 'exbelt-analytics');

    return hash_hmac('sha256', $ip, $secret);
}

function analytics_user_agent(): string
{
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2000);
}

function analytics_referrer(): string
{
    return mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 2000);
}

function analytics_should_skip_page_visit(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return true;
    }

    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $path = strtolower((string)$path);

    if ($path === '') {
        return true;
    }

    $skipExact = [
        '/email_open.php',
        '/cron_send_email_queue.php',
    ];

    if (in_array($path, $skipExact, true)) {
        return true;
    }

    if (preg_match('/\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|map|woff|woff2|ttf|eot)$/i', $path)) {
        return true;
    }

    return false;
}

function analytics_table_exists(PDO $pdo, string $table): bool
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

function analytics_capture_email_click(PDO $pdo, string $token): void
{
    $token = trim($token);

    if ($token === '' || !analytics_table_exists($pdo, 'email_tracking_tokens')) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM email_tracking_tokens
         WHERE token = ?
         LIMIT 1'
    );

    $stmt->execute([$token]);
    $tracking = $stmt->fetch();

    if (!$tracking) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['email_tracking'] = [
        'email_tracking_token_id' => (int)$tracking['id'],
        'email_queue_id' => (int)$tracking['email_queue_id'],
        'recipient_email' => (string)($tracking['recipient_email'] ?? ''),
        'related_team_id' => $tracking['related_team_id'] !== null ? (int)$tracking['related_team_id'] : null,
        'related_post_id' => $tracking['related_post_id'] !== null ? (int)$tracking['related_post_id'] : null,
    ];

    $now = analytics_now_for_database();

    $stmt = $pdo->prepare(
        'UPDATE email_tracking_tokens
         SET click_count = click_count + 1,
             first_clicked_at = COALESCE(first_clicked_at, ?),
             last_clicked_at = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $now,
        $now,
        (int)$tracking['id'],
    ]);

    if (analytics_table_exists($pdo, 'email_tracking_events')) {
        $stmt = $pdo->prepare(
            'INSERT INTO email_tracking_events
                (
                    email_tracking_token_id,
                    email_queue_id,
                    event_type,
                    recipient_email,
                    related_team_id,
                    related_post_id,
                    tracked_url,
                    request_path,
                    session_id,
                    ip_hash,
                    user_agent,
                    referrer,
                    created_at
                )
             VALUES
                (?, ?, "click", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            (int)$tracking['id'],
            (int)$tracking['email_queue_id'],
            $tracking['recipient_email'] ?? null,
            $tracking['related_team_id'] ?? null,
            $tracking['related_post_id'] ?? null,
            (string)($_SERVER['REQUEST_URI'] ?? ''),
            parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
            session_id(),
            analytics_ip_hash(),
            analytics_user_agent(),
            analytics_referrer(),
            $now,
        ]);
    }
}

function analytics_track_page_visit(): void
{
    static $alreadyTracked = false;

    if ($alreadyTracked || analytics_should_skip_page_visit()) {
        return;
    }

    $alreadyTracked = true;

    try {
        $pdo = db();

        if (!analytics_table_exists($pdo, 'page_visits')) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $trackingToken = trim((string)($_GET['trk'] ?? ''));

        if ($trackingToken !== '') {
            analytics_capture_email_click($pdo, $trackingToken);
        }

        $sessionTracking = $_SESSION['email_tracking'] ?? [];

        $leader = null;
        $parentTeamForTracking = null;

        if (function_exists('current_user')) {
            $leader = current_user();
        }

        if (function_exists('parent_access_team')) {
            $parentTeamForTracking = parent_access_team();
        }

        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($requestUri, PHP_URL_PATH);
        $query = parse_url($requestUri, PHP_URL_QUERY);

        $pageKey = basename((string)$path);
        $now = analytics_now_for_database();

        $stmt = $pdo->prepare(
            'INSERT INTO page_visits
                (
                    session_id,
                    email_tracking_token_id,
                    email_queue_id,
                    recipient_email,
                    related_team_id,
                    related_post_id,
                    leader_id,
                    parent_team_id,
                    request_path,
                    query_string,
                    page_key,
                    request_method,
                    ip_hash,
                    user_agent,
                    referrer,
                    visited_at
                )
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            session_id(),
            $sessionTracking['email_tracking_token_id'] ?? null,
            $sessionTracking['email_queue_id'] ?? null,
            $sessionTracking['recipient_email'] ?? null,
            $sessionTracking['related_team_id'] ?? null,
            $sessionTracking['related_post_id'] ?? null,
            !empty($leader['id']) ? (int)$leader['id'] : null,
            !empty($parentTeamForTracking['id']) ? (int)$parentTeamForTracking['id'] : null,
            mb_substr((string)$path, 0, 500),
            $query !== null ? (string)$query : null,
            mb_substr((string)$pageKey, 0, 255),
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            analytics_ip_hash(),
            analytics_user_agent(),
            analytics_referrer(),
            $now,
        ]);
    } catch (Throwable $exception) {
        /**
         * Analytics must never break the app.
         */
        error_log('Analytics tracking failed: ' . $exception->getMessage());
    }
}

analytics_track_page_visit();