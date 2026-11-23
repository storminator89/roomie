<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_calendar.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

requireCsrfToken($_POST['csrf_token'] ?? null);

$bookingId = roomiePositiveId($_POST['booking_id'] ?? null);

if ($bookingId === null) {
    die("Fehler: Keine Buchungs-ID angegeben.");
}

try {
    $db = getDatabaseConnection();

    // Überprüfen, ob die Buchung dem angemeldeten Benutzer gehört
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = :id");
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking || $booking['user_id'] != $_SESSION['user_id']) {
        die("Fehler: Sie sind nicht berechtigt, diese Buchung zu stornieren.");
    }

    // Buchungsdetails für die E-Mail
    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $stmt = $db->prepare("SELECT r.name as room_name, u.name as user_name, u.email as user_email, b.date, b.start_time, b.end_time
                          FROM bookings b
                          JOIN rooms r ON r.id = $effectiveRoom
                          JOIN users u ON b.user_id = u.id
                          WHERE b.id = :id");
    $stmt->execute(['id' => $bookingId]);
    $bookingDetails = $stmt->fetch(PDO::FETCH_ASSOC);

    // Buchung löschen
    $stmt = $db->prepare("DELETE FROM bookings WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $bookingId, 'user_id' => $_SESSION['user_id']]);
    if ($stmt->rowCount() !== 1) {
        http_response_code(409);
        exit('Die Buchung ist nicht mehr verfügbar.');
    }

    // E-Mail-Benachrichtigung senden
    if ($bookingDetails) {
        roomieSendBookingMail([$bookingDetails], (string) $bookingDetails['user_email'], true);
    }

    header("Location: bookings.php?cancelled=1");
    exit;
} catch (PDOException $e) {
    error_log('Roomie booking cancellation failed');
    http_response_code(500);
    exit('Stornierung fehlgeschlagen.');
}
