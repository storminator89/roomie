<?php
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht angemeldet.']);
    exit;
}

try {
    // Verbindung zur SQLite-Datenbank herstellen
    $db = getDatabaseConnection();

    // Abfrage, um die Raumdaten abzurufen
    $stmt = $db->query('SELECT * FROM rooms');

    $rooms = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Decodiere das equipment-Feld
        $row['equipment'] = json_decode($row['equipment'], true);
        $rooms[] = $row;
    }

    // JSON-Ausgabe der Raumdaten
    echo json_encode($rooms);
} catch(PDOException $e) {
    // Fehlerbehandlung bei Datenbankfehlern
    http_response_code(500);
    echo json_encode(['error' => 'Datenbankabfrage fehlgeschlagen.']);
}
?>
