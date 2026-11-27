<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/roomie_public_url.php';

function roomieDateLabel(string $date): string
{
    return parseBookingDate($date)?->format('d.m.Y') ?? '';
}

function roomieCalendarText(string $value): string
{
    // ICS TEXT differs from HTML: escaped \n cannot open a new property.
    return str_replace(["\\", "\r\n", "\r", "\n", ";", ","], ["\\\\", "\\n", "\\n", "\\n", "\\;", "\\,"], $value);
}

function roomieCalendarFold(string $line): string
{
    $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) {
        throw new RuntimeException('Invalid calendar encoding');
    }
    $segments = [];
    $segment = '';
    foreach ($chars as $char) {
        if (strlen($segment) + strlen($char) > 75) {
            $segments[] = $segment;
            $segment = ' ';
        }
        $segment .= $char;
    }
    $segments[] = $segment;
    return implode("\r\n", $segments);
}

function roomieCalendar(array $bookings): string
{
    $namespace = getenv('ROOMIE_ICS_NAMESPACE');
    if (!is_string($namespace) || $namespace === '') {
        $namespace = roomieDatabasePath();
    }
    $instance = substr(hash('sha256', $namespace), 0, 32);
    $utc = new DateTimeZone('UTC');
    $timezone = new DateTimeZone('Europe/Berlin');
    $base = roomiePublicBase(getenv('ROOMIE_PUBLIC_BASE_URL'));
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Roomie//Room Booking//DE', 'CALSCALE:GREGORIAN'];
    foreach ($bookings as $booking) {
        $date = parseBookingDate($booking['date']);
        if ($date === null || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $booking['start_time'])
            || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $booking['end_time'])) {
            throw new RuntimeException('Invalid calendar date');
        }
        $start = new DateTimeImmutable($booking['date'] . ' ' . $booking['start_time'], $timezone);
        $end = new DateTimeImmutable($booking['date'] . ' ' . $booking['end_time'], $timezone);
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . (int) $booking['id'] . '-' . $instance . '@roomie.invalid';
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z');
        $lines[] = 'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z');
        $lines[] = 'SUMMARY:' . roomieCalendarText($booking['room_name']);
        $lines[] = 'DESCRIPTION:' . roomieCalendarText($booking['user_name']);
        if ($base !== null && ($id = roomiePositiveId($booking['id'])) !== null) {
            $lines[] = 'URL:' . $base . '/room_way.php?booking_id=' . $id;
        }
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('roomieCalendarFold', $lines)) . "\r\n";
}

function roomieMailFrom(): ?string
{
    $value = getenv('ROOMIE_MAIL_FROM');
    return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
}

function roomieSendBookingMail(array $bookings, string $recipient, bool $cancelled = false): bool
{
    $from = roomieMailFrom();
    if ($from === null || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || !$bookings) {
        return false;
    }
    try {
        $booking = $bookings[0];
        $dateLabels = array_map(static fn(array $item): string => roomieDateLabel($item['date']), $bookings);
        $subject = $cancelled ? 'Roomie: Buchung storniert' : 'Roomie: Buchung bestätigt';
        $message = "Hallo " . $booking['user_name'] . ",\r\n\r\n"
            . $booking['room_name'] . ', ' . implode(', ', $dateLabels) . "\r\n"
            . $booking['start_time'] . '–' . $booking['end_time'] . " Uhr\r\n";
        $headers = "From: " . $from . "\r\nMIME-Version: 1.0\r\n";
        if ($cancelled) {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
            $body = chunk_split(base64_encode($message));
        } else {
            $boundary = bin2hex(random_bytes(24));
            $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($message))
                . "--$boundary\r\nContent-Type: text/calendar; charset=UTF-8; name=\"booking.ics\"\r\n"
                . "Content-Disposition: attachment; filename=\"booking.ics\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode(roomieCalendar($bookings)))
                . "--$boundary--\r\n";
        }
        return @mail($recipient, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    } catch (Throwable $error) {
        error_log('Roomie booking mail failed');
        return false;
    }
}

function roomieClaimBookingResend(PDO $db, int $userId): bool
{
    $stmt = $db->prepare('INSERT INTO booking_mail_cooldowns (user_id, last_sent_at) VALUES (:uid, :now)
        ON CONFLICT(user_id) DO UPDATE SET last_sent_at = excluded.last_sent_at
        WHERE booking_mail_cooldowns.last_sent_at <= :threshold');
    $now = time();
    $stmt->execute(['uid' => $userId, 'now' => $now, 'threshold' => $now - ROOMIE_MAIL_COOLDOWN_SECONDS]);
    return $stmt->rowCount() === 1;
}
