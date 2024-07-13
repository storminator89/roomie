<?php
require_once 'auth.php';

if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Nicht eingeloggt']);
    exit;
}

header('Content-Type: application/json');

try {
    $db = getDatabaseConnection();
    $userId = $_SESSION['user_id'];

    $stmt = $db->prepare('SELECT room_id FROM user_favorites WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    $favorites = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['success' => true, 'favorites' => $favorites]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => "Datenbankfehler: " . $e->getMessage()]);
}