<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_helpers.php';

$userId = roomieRequireBookingViewer();
$isAdmin = isAdmin();
$roomId = isset($_GET['room_id']) ? roomiePositiveId($_GET['room_id']) : null;
$start = parseBookingDate($_GET['start_date'] ?? roomieBookingToday()->format('Y-m-d'));
$end = parseBookingDate($_GET['end_date'] ?? ($start ? $start->format('Y-m-d') : null));
if ((isset($_GET['room_id']) && $roomId === null) || !$start || !$end ||
    $start > $end || $start->diff($end)->days > 30) {
    roomieBookingJson(['error' => 'Ungültiger Raum oder Zeitraum (höchstens 31 Tage).'], 400);
    exit;
}

try {
    $db = getDatabaseConnection();
    if ($roomId !== null && !roomieVisibleRoom($db, $roomId, $userId, $isAdmin)) {
        roomieBookingJson(['error' => 'Raum nicht gefunden.'], 404);
        exit;
    }
    [$rule, $params] = roomieBookingAccessSql('r', $userId, $isAdmin);
    $sql = "SELECT b.id, b.user_id, u.name AS user_name, r.name AS room, r.id AS room_id,
            b.date, b.start_time, b.end_time, b.seat_id FROM bookings b
            JOIN rooms r ON b.room_id = r.id JOIN users u ON b.user_id = u.id
            WHERE b.date BETWEEN :start_date AND :end_date AND $rule";
    $params += ['start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d')];
    if ($roomId !== null) {
        $sql .= ' AND r.id = :room_id';
        $params['room_id'] = $roomId;
    }
    $stmt = $db->prepare($sql . ' ORDER BY b.date, b.start_time, b.id LIMIT 2001');
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($bookings) > 2000) {
        roomieBookingJson(['error' => 'Zu viele Einträge. Bitte Raum oder Zeitraum eingrenzen.'], 422);
        exit;
    }
    foreach ($bookings as &$booking) {
        $booking['own'] = (int) $booking['user_id'] === $userId;
        if (!$isAdmin && !$booking['own']) {
            unset($booking['id'], $booking['user_id'], $booking['seat_id'], $booking['user_name']);
        }
    }
    unset($booking);
    roomieBookingJson($bookings);
} catch (PDOException $e) {
    error_log('Roomie booking availability read failed');
    roomieBookingJson(['error' => 'Buchungen nicht verfügbar.'], 500);
}
