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
    $userId = $_SESSION['user_id']; // Annahme: Die Benutzer-ID ist in der Session gespeichert

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['favorite_room_id'])) {
        $favoriteRoomId = $_POST['favorite_room_id'];
        
        // Überprüfen, ob der Raum bereits ein Favorit ist
        $stmt = $db->prepare('SELECT * FROM user_favorites WHERE user_id = :user_id AND room_id = :room_id');
        $stmt->execute(['user_id' => $userId, 'room_id' => $favoriteRoomId]);
        $existingFavorite = $stmt->fetch();

        if ($existingFavorite) {
            // Wenn es bereits ein Favorit ist, entfernen wir ihn
            $stmt = $db->prepare('DELETE FROM user_favorites WHERE user_id = :user_id AND room_id = :room_id');
            $stmt->execute(['user_id' => $userId, 'room_id' => $favoriteRoomId]);
            $newFavoriteStatus = false;
        } else {
            // Wenn es kein Favorit ist, fügen wir ihn hinzu
            $stmt = $db->prepare('INSERT INTO user_favorites (user_id, room_id) VALUES (:user_id, :room_id)');
            $stmt->execute(['user_id' => $userId, 'room_id' => $favoriteRoomId]);
            $newFavoriteStatus = true;
        }
        
        echo json_encode(['success' => true, 'is_favorite' => $newFavoriteStatus]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Ungültige Anfrage']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => "Datenbankfehler: " . $e->getMessage()]);
}