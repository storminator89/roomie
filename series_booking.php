<?php
declare(strict_types=1);

require_once __DIR__ . '/room_access.php';
require_once __DIR__ . '/room_blocks.php';
require_once __DIR__ . '/booking_approvals.php';

const ROOMIE_SERIES_MIN = 2;
const ROOMIE_SERIES_MAX = 12;
const ROOMIE_SERIES_PREVIEW_SECONDS = 600;
const ROOMIE_SERIES_PREVIEW_LIMIT = 8;

final class RoomieSeriesRejected extends RuntimeException
{
}

/** @return array{roomId:int, start:string, end:string, dates:list<string>} */
function roomieSeriesPlan(array $values, DateTimeImmutable $now): array
{
    $roomId = roomiePositiveId($values['room_id'] ?? null);
    $count = roomiePositiveId($values['count'] ?? null);
    $date = parseBookingDate($values['date'] ?? null);
    if ($roomId === null) {
        throw new DomainException('Bitte wähle einen gültigen Raum.');
    }
    if ($count === null || $count < ROOMIE_SERIES_MIN || $count > ROOMIE_SERIES_MAX) {
        throw new DomainException('Bitte wähle ' . ROOMIE_SERIES_MIN . ' bis ' . ROOMIE_SERIES_MAX . ' Termine.');
    }
    if ($date === null) {
        throw new DomainException('Bitte wähle ein gültiges Startdatum.');
    }
    $now = $now->setTimezone(new DateTimeZone('Europe/Berlin'));
    [$start, $end] = roomieParseCustomBookingTimes($date, $values['start_time'] ?? null, $values['end_time'] ?? null, $now);
    $horizon = $now->setTime(0, 0)->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days');
    $last = $date->modify('+' . (7 * ($count - 1)) . ' days');
    if ($last > $horizon) {
        throw new DomainException('Der letzte Termin muss innerhalb der nächsten ' . ROOMIE_BOOKING_HORIZON_DAYS . ' Tage liegen.');
    }
    $dates = [];
    for ($day = $date; $day <= $last; $day = $day->modify('+7 days')) {
        $dates[] = $day->format('Y-m-d');
    }
    return ['roomId' => $roomId, 'start' => $start, 'end' => $end, 'dates' => $dates];
}

function roomieSeriesFormValues(array $raw): array
{
    $values = [];
    foreach (['room_id', 'date', 'start_time', 'end_time', 'count'] as $field) {
        $value = $raw[$field] ?? null;
        $values[$field] = is_string($value) && strlen($value) <= 24 ? $value : '';
    }
    return $values;
}

/** Read each slot and quota from the transaction's snapshot; disclose no member information. */
function roomieSeriesInspect(PDO $db, int $userId, array $plan, DateTimeImmutable $today): array
{
    $room = roomieVisibleRoom($db, $plan['roomId'], $userId, false);
    if ($room === null) {
        throw new RoomieSeriesRejected('Dieser Raum steht dir nicht zur Buchung zur Verfügung.', 403);
    }
    if (roomieRoomRequiresApproval($db, $plan['roomId'])) {
        throw new RoomieSeriesRejected('Dieser Raum braucht eine Freigabe. Es sind nur Anfragen für einzelne Termine möglich.', 409);
    }
    $rows = [];
    $anyConflict = false;
    foreach ($plan['dates'] as $date) {
        if (roomieRoomHasBlock($db, $plan['roomId'], $date, $plan['start'], $plan['end'])) {
            $status = 'blocked';
        } else {
            $bookings = roomieRoomBookingRows($db, $plan['roomId'], $date);
            $status = roomieBookingConflict($bookings, (int) $room['capacity'], $userId, $plan['start'], $plan['end']);
        }
        $anyConflict = $anyConflict || $status !== null;
        $rows[] = [
            'date' => $date,
            'status' => $status ?? 'available',
            'available' => $status === null,
            'message' => match ($status) {
                'own' => 'Du hast diesen Raum schon zu dieser Zeit gebucht.',
                'capacity' => 'Keine freie Kapazität.',
                'blocked' => 'Der Raum ist zu dieser Zeit gesperrt.',
                default => 'Verfügbar.',
            },
        ];
    }
    $quota = $db->prepare('SELECT COUNT(*) FROM bookings WHERE user_id = ? AND date >= ?');
    $quota->execute([$userId, $today->format('Y-m-d')]);
    $overQuota = (int) $quota->fetchColumn() + count($plan['dates']) > ROOMIE_BOOKING_PER_USER_LIMIT;
    $quota->closeCursor();
    $reason = $anyConflict ? 'Mindestens ein Termin ist nicht verfügbar. Es wurde nichts gebucht.'
        : ($overQuota ? 'Mit dieser Serie würdest du die Grenze von ' . ROOMIE_BOOKING_PER_USER_LIMIT . ' aktiven Buchungen überschreiten.' : null);
    return [$rows, $reason];
}

function roomieSeriesPrunePreviews(int $userId): void
{
    $previous = $_SESSION['roomie_series_previews'] ?? [];
    $now = time();
    $_SESSION['roomie_series_previews'] = array_filter($previous,
        static fn (array $entry): bool => $entry['expires_at'] > $now && $entry['user_id'] === $userId);
}

// Keep date expansion testable with the local CLI without running authentication or the controller.
if (PHP_SAPI === 'cli') {
    return;
}

require_once __DIR__ . '/auth.php';

header('Cache-Control: private, no-store');
header('Content-Type: text/html; charset=utf-8');
if (!isLoggedIn()) {
    header('Location: login.php', true, 303);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
} elseif ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Methode nicht erlaubt.');
}

$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
$today = $now->setTime(0, 0);
$horizon = $today->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days');
$defaults = ['room_id' => '', 'date' => $today->modify('+1 day')->format('Y-m-d'),
    'start_time' => ROOMIE_BOOKING_OPEN_TIME, 'end_time' => '10:00', 'count' => (string) ROOMIE_SERIES_MIN];
$userId = (int) $_SESSION['user_id'];
$db = getDatabaseConnection();
$values = roomieSeriesFormValues($method === 'GET' ? array_replace($defaults, $_GET) : $_POST);
$rooms = [];
$preview = null;
$previewToken = null;
$error = null;
$status = 200;
$transactionOpen = false;

try {
    if ($method === 'POST') {
        $stage = $_POST['stage'] ?? null;
        if ($stage !== 'preview' && $stage !== 'book') {
            throw new RoomieSeriesRejected('Ungültiger Schritt. Bitte prüfe die Termine erneut.', 400);
        }
        roomieSeriesPrunePreviews($userId);
        $confirming = $stage === 'book';
        if ($confirming) {
            $token = $_POST['preview_token'] ?? null;
            $draft = is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token) === 1
                ? ($_SESSION['roomie_series_previews'][$token] ?? null) : null;
            if ($draft === null) {
                $values = $defaults;
                throw new RoomieSeriesRejected('Diese Vorschau ist nicht mehr gültig. Bitte prüfe die Termine erneut.', 409);
            }
            $values = $draft['values'];
            foreach ($values as $field => $value) {
                if (array_key_exists($field, $_POST) && $_POST[$field] !== $value) {
                    throw new RoomieSeriesRejected('Die Buchungsdaten stimmen nicht mit der Vorschau überein.', 400);
                }
            }
            // Persist one-time consumption before any database write, including if the response is interrupted.
            unset($_SESSION['roomie_series_previews'][$token]);
            if (!session_write_close() || !session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1])) {
                throw new RuntimeException('Roomie series session unavailable');
            }
        }

        // Preview reads a consistent snapshot; confirmation serializes with all other booking writes.
        $db->exec($confirming ? 'BEGIN IMMEDIATE' : 'BEGIN');
        $transactionOpen = true;
        if ($confirming) {
            $account = currentRoomieUser(true);
            if (!$account || (int) $account['id'] !== $userId) {
                throw new RoomieSeriesRejected('Deine Sitzung ist nicht mehr gültig. Bitte melde dich erneut an.', 403);
            }
            if ($draft['expires_at'] <= time()) {
                throw new RoomieSeriesRejected('Die Vorschau ist abgelaufen. Bitte prüfe die Termine erneut.', 409);
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
            $today = $now->setTime(0, 0);
            $horizon = $today->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days');
        }
        try {
            $plan = roomieSeriesPlan($confirming ? $values : $_POST, $now);
        } catch (DomainException $validation) {
            throw new RoomieSeriesRejected($validation->getMessage(), $confirming ? 409 : 400, $validation);
        }
        [$preview, $error] = roomieSeriesInspect($db, $userId, $plan, $today);
        if ($confirming) {
            if ($error !== null) {
                throw new RoomieSeriesRejected($error, 409);
            }
            $insert = $db->prepare('INSERT INTO bookings (user_id, room_id, seat_id, date, start_time, end_time)
                VALUES (?, ?, NULL, ?, ?, ?)');
            foreach ($plan['dates'] as $date) {
                $insert->execute([$userId, $plan['roomId'], $date, $plan['start'], $plan['end']]);
            }
        }
        $db->exec('COMMIT');
        $transactionOpen = false;
        if ($confirming) {
            header('Location: bookings.php?series=1', true, 303);
            exit;
        }
        if ($error === null) {
            $previewToken = bin2hex(random_bytes(32));
            $drafts = &$_SESSION['roomie_series_previews'];
            if (count($drafts) >= ROOMIE_SERIES_PREVIEW_LIMIT) {
                array_shift($drafts);
            }
            $drafts[$previewToken] = ['user_id' => $userId, 'expires_at' => time() + ROOMIE_SERIES_PREVIEW_SECONDS,
                'values' => $values];
            unset($drafts);
        }
    }
} catch (Throwable $failure) {
    if ($transactionOpen) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $rollback) {
            error_log('Roomie series rollback failed');
            http_response_code(500);
            exit('Die Buchung konnte nicht bestätigt werden. Bitte prüfe deine Buchungen.');
        }
    }
    if ($failure instanceof RoomieSeriesRejected) {
        $status = $failure->getCode();
        $error = $failure->getMessage();
    } else {
        error_log('Roomie series request failed');
        $status = 500;
        $error = 'Die Anfrage konnte nicht bestätigt werden. Bitte prüfe deine Buchungen und versuche es erneut.';
        $preview = null;
    }
}

try {
    // Bookability, not administrator visibility, defines which rooms the form may show.
    if (currentRoomieUser() && (int) $_SESSION['user_id'] === $userId) {
        $rooms = roomieListRooms($db, $userId, false);
    }
    if ($method === 'GET' && array_key_exists('room_id', $_GET)) {
        $roomId = roomiePositiveId($_GET['room_id']);
        if ($roomId === null || !in_array($roomId, array_map('intval', array_column($rooms, 'id')), true)) {
            $values['room_id'] = '';
            $status = $roomId === null ? 400 : 403;
            $error = 'Dieser Raum steht dir nicht zur Buchung zur Verfügung.';
        }
    } elseif ($method === 'GET' && $rooms) {
        $values['room_id'] = (string) $rooms[0]['id'];
    }
    if (!$rooms && $error === null) {
        $error = 'Es sind keine buchbaren Räume verfügbar.';
    }
} catch (Throwable $failure) {
    error_log('Roomie series rooms unavailable');
    $status = 503;
    $error = 'Die Räume sind zurzeit nicht verfügbar.';
    $rooms = [];
    $preview = null;
    $previewToken = null;
}

http_response_code($status);
define('ROOMIE_SERIES_CONTROLLER', true);
require __DIR__ . '/series_booking_view.php';
