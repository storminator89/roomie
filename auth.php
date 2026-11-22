<?php
$sessionCookie = session_get_cookie_params();
$https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
$sessionCookie['secure'] = $sessionCookie['secure'] || (in_array($https, ['on', '1'], true));
$sessionCookie['httponly'] = true;
if (empty($sessionCookie['samesite'])) {
    $sessionCookie['samesite'] = 'Lax';
}
session_set_cookie_params($sessionCookie);
session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1]);

// Clear the legacy remember-me cookies, which stored credentials in plaintext.
foreach (['email', 'password'] as $legacyCookie) {
    if (isset($_COOKIE[$legacyCookie])) {
        setcookie($legacyCookie, '', time() - 3600, '/');
    }
}

require_once __DIR__ . '/database.php';

function registerUser($name, $email, $password) {
    $db = getDatabaseConnection();
    
    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        return false; // Email already exists
    }
    
    // Hash the password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert new user
    $stmt = $db->prepare("INSERT INTO users (name, email, password, is_admin) VALUES (:name, :email, :password, 0)");
    return $stmt->execute([
        'name' => $name,
        'email' => $email,
        'password' => $hashedPassword
    ]);
}

function loginUser($email, $password) {
    $db = getDatabaseConnection();
    
    $stmt = $db->prepare("SELECT id, name, password, is_admin FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['is_admin'] = $user['is_admin'];
        return true;
    }
    
    return false;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function logoutUser() {
    session_unset();
    session_destroy();
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
