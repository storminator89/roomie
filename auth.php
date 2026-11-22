<?php
$sessionCookie = session_get_cookie_params();
$https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
$sessionCookie['secure'] = $sessionCookie['secure'] || in_array($https, ['on', '1'], true);
$sessionCookie['httponly'] = true;
if (empty($sessionCookie['samesite'])) {
    $sessionCookie['samesite'] = 'Lax';
}
session_set_cookie_params($sessionCookie);
session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1]);

foreach (['email', 'password'] as $legacyCookie) {
    if (isset($_COOKIE[$legacyCookie])) {
        setcookie($legacyCookie, '', time() - 3600, '/');
    }
}

require_once __DIR__ . '/database.php';

function validRoomiePassword($password): bool {
    // PASSWORD_DEFAULT currently uses bcrypt, whose input is limited to 72 bytes.
    return is_string($password) && strlen($password) >= 12
        && strlen($password) <= 72 && !str_contains($password, "\0");
}

function validatedRoomieName($name): ?string {
    if (!is_string($name)) {
        return null;
    }
    $name = trim($name);
    return $name !== '' && strlen($name) <= 255 && preg_match('/\A[^\p{C}]+\z/u', $name) === 1 ? $name : null;
}

function validatedRoomieEmail($email): ?string {
    if (!is_string($email)) {
        return null;
    }
    $email = trim($email);
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
}

function logoutUser(): void {
    $_SESSION = [];
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $cookie = session_get_cookie_params();
    unset($cookie['lifetime']);
    setcookie(session_name(), '', ['expires' => time() - 3600] + $cookie);
    session_destroy();
}

/**
 * Authorization derives from current database state, not a cached session role.
 * Request-local caching is safe for reads; role-changing transactions refresh it
 * after reserving the SQLite write lock.
 */
function currentRoomieUser(bool $refresh = false): ?array {
    static $cached = null;
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    if (!isset($_SESSION['session_version'])) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    $userId = (int) $_SESSION['user_id'];
    $version = (int) $_SESSION['session_version'];
    if (!$refresh && $cached !== null && (int) $cached['id'] === $userId
        && (int) $cached['session_version'] === $version) {
        return $cached;
    }
    $db = getDatabaseConnection();
    $stmt = $db->prepare('SELECT id, name, is_admin, session_version FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || (int) $user['session_version'] !== $version) {
        $cached = null;
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    $_SESSION['user_name'] = $user['name'];
    return $cached = $user;
}

function isLoggedIn(): bool {
    return currentRoomieUser() !== null;
}

function isAdmin(): bool {
    return (int) (currentRoomieUser()['is_admin'] ?? 0) === 1;
}

/**
 * Reserve a login attempt before password verification so concurrent requests
 * count. Bound state and expire it lazily, independent of PHP session IDs.
 */
function reserveLoginAttempt(PDO $db, string $email): bool {
    $source = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $source = filter_var($source, FILTER_VALIDATE_IP) ? $source : 'unknown';
    $keys = [
        'source:' . hash('sha256', $source) => 40,
        'account:' . hash('sha256', strtolower($email)) => 10,
    ];
    $now = time();
    $cutoff = $now - 900;
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $cleanup = $db->prepare('DELETE FROM login_throttle WHERE window_start <= ?');
        $cleanup->execute([$cutoff]);
        $lookup = $db->prepare('SELECT attempts FROM login_throttle WHERE key = ?');
        $newKeys = 0;
        $allowed = true;
        foreach ($keys as $key => $limit) {
            $lookup->execute([$key]);
            $attempts = $lookup->fetchColumn();
            $lookup->closeCursor();
            if ($attempts === false) {
                ++$newKeys;
            } elseif ((int) $attempts >= $limit) {
                $allowed = false;
            }
        }
        if ($allowed && $newKeys > 0) {
            $stateSize = (int) $db->query('SELECT COUNT(*) FROM login_throttle')->fetchColumn();
            $allowed = $stateSize + $newKeys <= 4096;
        }
        if ($allowed) {
            $reserve = $db->prepare(
                'INSERT INTO login_throttle (key, window_start, attempts) VALUES (?, ?, 1)
                 ON CONFLICT(key) DO UPDATE SET attempts = attempts + 1'
            );
            foreach (array_keys($keys) as $key) {
                $reserve->execute([$key, $now]);
            }
        }
        $db->exec('COMMIT');
        return $allowed;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function loginUser($email, $password): string {
    $email = validatedRoomieEmail($email);
    if ($email === null || !is_string($password) || strlen($password) > 4096 || str_contains($password, "\0")) {
        return 'invalid';
    }
    try {
        $db = getDatabaseConnection();
        if (!reserveLoginAttempt($db, $email)) {
            return 'throttled';
        }
        $stmt = $db->prepare('SELECT id, name, password, session_version FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        // Keep lookup misses on the same verification path as invalid credentials.
        $hash = $user['password'] ?? '$2y$12$Rb35PvTMMj43E78DlkDGNelxSyLlCvqZEPxLYAYMJkXFoh.cSlg92';
        $isValid = password_verify($password, $hash);
        if (!$user || !$isValid) {
            return 'invalid';
        }
        if (!session_regenerate_id(true)) {
            return 'unavailable';
        }
        $_SESSION = [
            'user_id' => (int) $user['id'],
            'user_name' => $user['name'],
            'session_version' => (int) $user['session_version'],
        ];
        return 'authenticated';
    } catch (PDOException $error) {
        error_log('Roomie login unavailable');
        return 'unavailable';
    }
}

function csrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireCsrfToken($provided, bool $json = false): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        $message = 'Methode nicht erlaubt.';
    } elseif (!is_string($provided) || !isset($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $provided)) {
        http_response_code(403);
        $message = 'Sitzung prüfen: Bitte die Seite neu laden.';
    } else {
        return;
    }
    echo $json ? json_encode(['success' => false, 'message' => $message]) : $message;
    exit;
}
