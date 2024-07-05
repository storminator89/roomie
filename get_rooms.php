<?php
header('Content-Type: application/json');

try {
    // Verbindung zur SQLite-Datenbank herstellen
    $db = new PDO('sqlite:roomie.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
    echo json_encode(['error' => $e->getMessage()]);
}
?>
