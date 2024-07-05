<?php
require_once 'auth.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = $_POST['booking_id'] ?? '';

    if (empty($bookingId)) {
        die("Fehler: Keine Buchungs-ID angegeben.");
    }

    try {
        $db = getDatabaseConnection();
        
        // Überprüfen, ob die Buchung dem angemeldeten Benutzer gehört
        $stmt = $db->prepare("SELECT user_id FROM bookings WHERE id = :id");
        $stmt->execute(['id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking || $booking['user_id'] != $_SESSION['user_id']) {
            die("Fehler: Sie sind nicht berechtigt, diese Buchung zu stornieren.");
        }
        
        // Buchung löschen
        $stmt = $db->prepare("DELETE FROM bookings WHERE id = :id");
        $stmt->execute(['id' => $bookingId]);
        
        header("Location: bookings.php?cancelled=1");
        exit;
    } catch (PDOException $e) {
        die("Fehler bei der Stornierung: " . $e->getMessage());
    }
} else {
    header("Location: bookings.php");
    exit;
}
