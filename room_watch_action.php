<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_watches.php';

header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    header('Location: login.php', true, 303);
    exit;
}
requireCsrfToken($_POST['csrf_token'] ?? null);
$userId = (int) $_SESSION['user_id'];
$action = $_POST['action'] ?? null;
$target = $_POST['return_to'] ?? null;
$transaction = false;
$redirect = 'index.php#rm-room-watches';
try {
    $db = getDatabaseConnection();
    $db->exec('BEGIN IMMEDIATE');
    $transaction = true;
    if (currentRoomieUser(true) === null) {
        throw new DomainException('Bitte melde dich erneut an.');
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    if ($action === 'subscribe') {
        $roomId = roomiePositiveId($_POST['room_id'] ?? null);
        $date = $_POST['date'] ?? null;
        $range = roomieWatchWindow($date, $_POST['start_time'] ?? null, $_POST['end_time'] ?? null, $now);
        $room = $roomId === null ? null : roomieWatchEligibleRoom($db, $roomId, $userId);
        if ($range === null || $room === null) {
            throw new DomainException('Für diesen Zeitraum ist keine Frei-Meldung möglich.');
        }
        [$start, $end] = $range;
        $redirect = roomieWatchRoomUrl($roomId, $date, $start, $end) . '#rb-room-watch';
        $message = roomieWatchSubscribe($db, $userId, $room, $date, $start, $end, $now);
    } elseif ($action === 'remove') {
        $watchId = roomiePositiveId($_POST['watch_id'] ?? null);
        if ($watchId === null) {
            throw new DomainException('Frei-Meldung nicht gefunden.');
        }
        $row = roomieWatchRemove($db, $userId, $watchId);
        if ($row !== null && $target === 'room'
            && roomieWatchEligibleRoom($db, (int) $row['room_id'], $userId) !== null) {
            $redirect = roomieWatchRoomUrl((int) $row['room_id'], $row['date'], $row['start_time'], $row['end_time']) . '#rb-room-watch';
        }
        $message = $row === null ? 'Frei-Meldung nicht gefunden.' : 'Frei-Meldung entfernt.';
    } else {
        throw new DomainException('Unbekannte Aktion.');
    }
    $db->exec('COMMIT');
    $transaction = false;
} catch (Throwable $error) {
    if ($transaction) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
            error_log('Roomie watch rollback failed');
        }
    }
    if ($error instanceof DomainException) {
        $message = $error->getMessage();
    } else {
        error_log('Roomie watch action failed');
        $message = 'Frei-Meldung konnte nicht geändert werden.';
    }
}
$_SESSION['roomie_watch_notice'] = $message;
header('Location: ' . $redirect, true, 303);
exit;
