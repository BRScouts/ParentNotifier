<?php
declare(strict_types=1);

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

if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return !empty($_SESSION['leader_id']);
    }
}

if (!function_exists('microsoft_authorize_url')) {
    function microsoft_authorize_url(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $state = bin2hex(random_bytes(24));
        $_SESSION['ms_oauth_state'] = $state;

        $params = [
            'client_id' => MS_CLIENT_ID,
            'response_type' => 'code',
            'redirect_uri' => MS_REDIRECT_URI,
            'response_mode' => 'query',
            'scope' => 'openid profile email User.Read',
            'state' => $state,
            'prompt' => 'select_account',
        ];

        return 'https://login.microsoftonline.com/' . rawurlencode(MS_TENANT_ID) . '/oauth2/v2.0/authorize?' . http_build_query($params);
    }
}

if (!function_exists('microsoft_post')) {
    function microsoft_post(string $url, array $fields): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new RuntimeException('Microsoft request failed: ' . $error);
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new RuntimeException('Microsoft returned an invalid response.');
        }

        if ($status < 200 || $status >= 300) {
            $message = $data['error_description'] ?? $data['error'] ?? 'Microsoft authentication failed.';
            throw new RuntimeException($message);
        }

        return $data;
    }
}

if (!function_exists('microsoft_get_json')) {
    function microsoft_get_json(string $url, string $accessToken): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new RuntimeException('Microsoft Graph request failed: ' . $error);
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new RuntimeException('Microsoft Graph returned an invalid response.');
        }

        if ($status < 200 || $status >= 300) {
            $message = $data['error']['message'] ?? 'Could not read Microsoft profile.';
            throw new RuntimeException($message);
        }

        return $data;
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
    function login_leader_from_record(array $leader): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        session_regenerate_id(true);

        $_SESSION['leader_id'] = (int)$leader['id'];
        $_SESSION['leader_email'] = $leader['email'] ?? '';
        $_SESSION['leader_name'] = $leader['name'] ?? '';
        $_SESSION['auth_method'] = 'microsoft';
    }
}

if (!function_exists('microsoft_login_with_code')) {
    function microsoft_login_with_code(string $code): array
    {
        $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode(MS_TENANT_ID) . '/oauth2/v2.0/token';

        $tokenData = microsoft_post($tokenUrl, [
            'client_id' => MS_CLIENT_ID,
            'client_secret' => MS_CLIENT_SECRET,
            'code' => $code,
            'redirect_uri' => MS_REDIRECT_URI,
            'grant_type' => 'authorization_code',
            'scope' => 'openid profile email User.Read',
        ]);

        if (empty($tokenData['access_token'])) {
            throw new RuntimeException('Microsoft did not return an access token.');
        }

        $profile = microsoft_get_json(
            'https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName',
            $tokenData['access_token']
        );

        $email = trim((string)($profile['mail'] ?? ''));

        if ($email === '') {
            $email = trim((string)($profile['userPrincipalName'] ?? ''));
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

        login_leader_from_record($leader);

        return $leader;
    }
}