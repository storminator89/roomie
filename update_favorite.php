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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['favorite_room_id'])) {
        $favoriteRoomId = $_POST['favorite_room_id'];
        $stmt = $db->prepare('UPDATE rooms SET is_favorite = 1 - is_favorite WHERE id = :id');
        $stmt->execute(['id' => $favoriteRoomId]);
        
        // Hole den aktualisierten Favoritenstatus
        $stmt = $db->prepare('SELECT is_favorite FROM rooms WHERE id = :id');
        $stmt->execute(['id' => $favoriteRoomId]);
        $newFavoriteStatus = $stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'is_favorite' => $newFavoriteStatus]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Ungültige Anfrage']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => "Datenbankfehler: " . $e->getMessage()]);
}