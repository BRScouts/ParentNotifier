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