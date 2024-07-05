<?php
header('Content-Type: application/json');

try {
    $db = new PDO('sqlite:roomie.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $date = $_GET['date'] ?? date('Y-m-d');

    $stmt = $db->prepare('SELECT b.id, b.user_name as name, r.name as room, b.date, b.start_time, b.end_time 
                          FROM bookings b 
                          JOIN rooms r ON b.room_id = r.id 
                          WHERE b.date = :date');
    $stmt->execute(['date' => $date]);

    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($bookings);
} catch(PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}