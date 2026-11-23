<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_series_store.php';

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
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$id = roomiePositiveId($_GET['id'] ?? null);
if ($id === null) {
    http_response_code(404);
    exit('Serie nicht gefunden.');
}
$userId = (int) $_SESSION['user_id'];
$db = getDatabaseConnection();
$action = 'edit';
$start = ROOMIE_BOOKING_OPEN_TIME;
$end = '10:00';
$rows = [];
$error = null;
$previewToken = null;
$open = false;
$stage = $method === 'POST' ? ($_POST['stage'] ?? null) : 'list';
$draft = null;

try {
    if (!in_array($stage, ['list', 'preview', 'apply'], true) || ($stage === 'list' && $method !== 'GET')) {
        throw new RoomieSeriesChangeRejected('Ungültiger Schritt. Bitte öffne die Serie erneut.', 400);
    }
    if ($stage === 'apply') {
        $token = $_POST['preview_token'] ?? null;
        if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            throw new RoomieSeriesChangeRejected('Vorschau nicht mehr gültig.', 409);
        }
        $draft = $_SESSION['roomie_series_changes'][$token] ?? null;
        if ($draft === null || $draft['userId'] !== $userId || $draft['seriesId'] !== $id || $draft['expires'] <= time()) {
            throw new RoomieSeriesChangeRejected('Vorschau nicht mehr gültig.', 409);
        }
        unset($_SESSION['roomie_series_changes'][$token]);
        // Persist one-time consumption independently of a failed write or response.
        if (!session_write_close() || !session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1])) {
            throw new RuntimeException('Roomie series management session unavailable');
        }
        $action = $draft['action'];
        $start = $draft['start'];
        $end = $draft['end'];
    } elseif ($stage === 'preview') {
        $submitted = $_POST['action'] ?? null;
        if (!in_array($submitted, ['edit', 'cancel'], true)) {
            throw new RoomieSeriesChangeRejected('Ungültige Aktion.', 400);
        }
        $action = $submitted;
        if ($action === 'edit') {
            $start = $_POST['start_time'] ?? '';
            $end = $_POST['end_time'] ?? '';
            if (!is_string($start) || !is_string($end) || strlen($start) !== 5 || strlen($end) !== 5
                || roomieBookingClock($start) === null || roomieBookingClock($end) === null) {
                $start = ROOMIE_BOOKING_OPEN_TIME;
                $end = '10:00';
                throw new RoomieSeriesChangeRejected('Bitte wähle gültige Uhrzeiten.', 400);
            }
        }
    }

    $db->exec($stage === 'apply' ? 'BEGIN IMMEDIATE' : 'BEGIN');
    $open = true;
    $actor = currentRoomieUser(true);
    if ($actor === null || (int) $actor['id'] !== $userId) {
        throw new RoomieSeriesChangeRejected('Sitzung nicht mehr gültig.', 403);
    }
    if ($stage === 'apply' && $draft['expires'] <= time()) {
        throw new RoomieSeriesChangeRejected('Vorschau abgelaufen.', 409);
    }
    $now = roomieSeriesManageNow();
    $future = roomieSeriesFuture($db, $userId, $id, $now);
    $snapshot = roomieSeriesManageSnapshot($future);
    if ($stage === 'apply' && !hash_equals($draft['snapshot'], $snapshot)) {
        throw new RoomieSeriesChangeRejected('Die Termine haben sich geändert. Bitte prüfe die Serie erneut.', 409);
    }
    // Listing shares the same ACL validation, but has no proposed change.
    [$rows, $error] = roomieSeriesChangePreview($db, $userId, $future, $now,
        $stage === 'list' ? 'cancel' : $action, $start, $end);
    if ($stage === 'list' && $future !== []) {
        $firstStart = substr($future[0]['start_time'], 0, 5);
        $firstEnd = substr($future[0]['end_time'], 0, 5);
        $start = roomieBookingClock($firstStart) !== null ? $firstStart : $start;
        $end = roomieBookingClock($firstEnd) !== null ? $firstEnd : $end;
    }
    if ($stage === 'apply') {
        if ($error !== null) {
            throw new RoomieSeriesChangeRejected($error, 409);
        }
        roomieSeriesChangeApply($db, $userId, $future, $action, $start, $end);
    }
    $db->exec('COMMIT');
    $open = false;
    if ($stage === 'apply') {
        $_SESSION['roomie_booking_notice'] = $action === 'cancel'
            ? 'Kommende Serientermine storniert.' : 'Uhrzeit der kommenden Serientermine geändert.';
        header('Location: bookings.php?series_change=' . $action, true, 303);
        exit;
    }
    if ($stage === 'preview') {
        if ($error === null) {
            $clock = time();
            $drafts = array_filter($_SESSION['roomie_series_changes'] ?? [],
                static fn(array $entry): bool => $entry['userId'] === $userId && $entry['expires'] > $clock);
            if (count($drafts) >= 8) {
                array_shift($drafts);
            }
            $previewToken = bin2hex(random_bytes(32));
            $drafts[$previewToken] = ['userId' => $userId, 'seriesId' => $id, 'expires' => $clock + 600,
                'snapshot' => $snapshot, 'action' => $action, 'start' => $start, 'end' => $end];
            $_SESSION['roomie_series_changes'] = $drafts;
        } else {
            http_response_code(409);
        }
    }
} catch (Throwable $failure) {
    if ($open) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
            error_log('Roomie series management rollback failed');
        }
    }
    if ($failure instanceof RoomieSeriesChangeRejected) {
        http_response_code($failure->getCode());
        $error = $failure->getMessage();
    } else {
        http_response_code(503);
        error_log('Roomie series management failed');
        $error = 'Die Änderung konnte nicht bestätigt werden. Bitte prüfe deine Buchungen.';
    }
}
if (in_array(http_response_code(), [403, 404, 503], true)) {
    // Never echo an inaccessible series, even after a late validation failure.
    exit(htmlspecialchars($error ?? 'Serie nicht verfügbar.', ENT_QUOTES, 'UTF-8'));
}

define('ROOMIE_SERIES_MANAGE_CONTROLLER', true);
require __DIR__ . '/series_manage_view.php';
