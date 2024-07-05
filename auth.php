<?php
session_start();

function getDatabaseConnection() {
    try {
        $db = new PDO('sqlite:roomie.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    } catch(PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

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

// Ensure tables exist when this file is included
ensureTablesExist();

function ensureTablesExist() {
    $db = getDatabaseConnection();
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        is_admin INTEGER DEFAULT 0,
        profile_image TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}
