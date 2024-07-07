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
        $stmt = $db->prepare("SELECT * FROM bookings WHERE id = :id");
        $stmt->execute(['id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking || $booking['user_id'] != $_SESSION['user_id']) {
            die("Fehler: Sie sind nicht berechtigt, diese Buchung zu stornieren.");
        }

        // Buchungsdetails für die E-Mail
        $stmt = $db->prepare("SELECT r.name as room_name, u.name as user_name, u.email as user_email, b.date, b.start_time, b.end_time 
                              FROM bookings b 
                              JOIN rooms r ON b.room_id = r.id 
                              JOIN users u ON b.user_id = u.id 
                              WHERE b.id = :id");
        $stmt->execute(['id' => $bookingId]);
        $bookingDetails = $stmt->fetch(PDO::FETCH_ASSOC);

        // Buchung löschen
        $stmt = $db->prepare("DELETE FROM bookings WHERE id = :id");
        $stmt->execute(['id' => $bookingId]);

        // E-Mail-Benachrichtigung senden
        sendCancellationEmail($bookingDetails, $bookingDetails['user_email']);

        header("Location: bookings.php?cancelled=1");
        exit;
    } catch (PDOException $e) {
        die("Fehler bei der Stornierung: " . $e->getMessage());
    }
} else {
    header("Location: bookings.php");
    exit;
}

function sendCancellationEmail($booking, $recipientEmail) {
    $subject = "Stornierung Ihrer Buchung für " . $booking['room_name'];
    $message = "Sehr geehrte/r " . $booking['user_name'] . ",\n\n" .
               "Ihre Buchung für den Raum " . $booking['room_name'] . " am " .
               formatGermanDate($booking['date']) . " von " . $booking['start_time'] . " bis " .
               $booking['end_time'] . " wurde erfolgreich storniert.\n\n" .
               "Mit freundlichen Grüßen,\nIhr Roomie Team";

    $headers = "From: Roomie Booking System <your-email@example.com>\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n";

    mail($recipientEmail, "=?UTF-8?B?".base64_encode($subject)."?=", $message, $headers);
}

function formatGermanDate($date) {
    return date('d.m.Y', strtotime($date));
}
?>
