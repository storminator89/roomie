<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/room_blocks.php';
require_once __DIR__ . '/booking_approvals.php';
require_once __DIR__ . '/room_visual.php';
require_once __DIR__ . '/room_photo_store.php';
require_once __DIR__ . '/room_checkin.php';
require_once __DIR__ . '/room_watches.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit;
}

header('Cache-Control: private, no-store');
$userId = (int) $_SESSION['user_id'];
$room_id = roomiePositiveId($_GET['id'] ?? null);
$selected_date = $_GET['date'] ?? roomieBookingToday()->format('Y-m-d');
if (!$room_id) {
    http_response_code(404);
    exit('Raum nicht gefunden.');
}
$parsedDate = parseBookingDate($selected_date);
if (!$parsedDate) {
    http_response_code(400);
    exit('Ungültiges Datum.');
}

try {
    $db = getDatabaseConnection();
    $admin = isAdmin();
    $room = roomieVisibleRoom($db, $room_id, $userId, $admin);
    if (!$room) {
        http_response_code(404);
        exit('Raum nicht gefunden.');
    }
    $hasPhoto = roomieHasPhoto($db, $room_id);
    $requiresApproval = roomieRoomRequiresApproval($db, $room_id);
    // A visible room is not necessarily bookable, including for administrators.
    $canBookRoom = roomieCanBookRoom($db, $room_id, $userId) !== null;
    [$acl, $aclParams] = roomieBookingAccessSql('r', $userId, $admin);
    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $stmt = $db->prepare("SELECT b.start_time, b.end_time, b.user_id, b.user_id = :owner AS own,
                         CASE WHEN :canSeeNames = 1 OR b.user_id = :viewer THEN u.name END AS user_name
                          FROM bookings b JOIN users u ON b.user_id = u.id JOIN rooms r ON r.id = $effectiveRoom
                          WHERE r.id = :room_id AND b.date = :selected_date AND $acl
                          ORDER BY b.start_time, b.end_time, b.id LIMIT 501");
    $params = $aclParams + ['canSeeNames' => (int) $admin, 'viewer' => $userId, 'owner' => $userId,
        'room_id' => $room_id, 'selected_date' => $selected_date];
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($bookings) > 500) {
        http_response_code(422);
        exit('Zu viele Buchungen für dieses Datum.');
    }
    // The helper returns only time bounds, never staff, type or internal block data.
    $roomBlocks = roomieRoomBlocksForDate($db, $room_id, $selected_date);
} catch (Throwable) {
    error_log('Roomie could not load room details');
    http_response_code(503);
    exit('Raumdetails nicht verfügbar.');
}

function getRoomTypeName($type)
{
    switch ($type) {
        case 'shared-desk':
            return 'Shared Desk Büro';
        case 'fk-office':
        case 'fk-buro':
            return 'FK-Büro';
        case 'spez-abt-buero':
        case 'spez-abt-buro':
            return 'Spez. Abt-Büro';
        case 'office':
            return 'Büro';
        case 'open-office':
            return 'Großraumbüro';
        case 'meeting':
            return 'Besprechungsraum';
        case 'mediaroom':
            return 'Mediaraum';
        default:
            return 'Sonstiger Raum';
    }
}
function detailEscape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function detailUrl(int $roomId, string $date, array $context): string
{
    return 'room_details.php?' . http_build_query(['id' => $roomId, 'date' => $date] + $context);
}

$capacity = max(0, (int) $room['capacity']);
$now = new DateTimeImmutable('now', $parsedDate->getTimezone());
$today = $now->setTime(0, 0);
$canBookDate = $parsedDate >= $today && $parsedDate <= $today->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days');
$periods = [
    'ganzerTag' => ['Ganztags', ROOMIE_BOOKING_OPEN_TIME, ROOMIE_BOOKING_CLOSE_TIME],
    'vormittags' => ['Vormittags', ROOMIE_BOOKING_OPEN_TIME, '12:00'],
    'nachmittags' => ['Nachmittags', '13:00', ROOMIE_BOOKING_CLOSE_TIME],
];
$firstPeriod = null;
foreach ($periods as $key => &$period) {
    $blocked = roomieRoomBlockOverlaps($roomBlocks, $period[1], $period[2]);
    $period[] = $canBookRoom && $canBookDate && !$blocked
        && roomieBookingPresetIsOpen($parsedDate, $period[1], $period[2], $now, $requiresApproval)
        && roomieBookingConflict($bookings, $capacity, $userId, $period[1], $period[2]) === null;
    $period[] = $blocked;
    if ($period[3] && $firstPeriod === null) $firstPeriod = $key;
}
unset($period);

$customTimes = [];
$opens = $parsedDate->modify(ROOMIE_BOOKING_OPEN_TIME);
$closes = $parsedDate->modify(ROOMIE_BOOKING_CLOSE_TIME);
$step = new DateInterval('PT' . ROOMIE_BOOKING_STEP_MINUTES . 'M');
$firstCustom = null;
$freeCustomStarts = [];
for ($slot = $opens; $slot <= $closes; $slot = $slot->add($step)) {
    $from = $slot->format('H:i');
    $customTimes[] = $from;
    $end = $slot->add($step);
    if ($end <= $closes) {
        $to = $end->format('H:i');
        if ($canBookRoom && $canBookDate && $slot > $now && !roomieRoomBlockOverlaps($roomBlocks, $from, $to)
            && roomieBookingConflict($bookings, $capacity, $userId, $from, $to) === null) {
            $freeCustomStarts[$from] = true;
            $firstCustom ??= [$from, $to];
        }
    }
}
// A continuous range is available only while every quarter within it is free.
// Send only the resulting time limits to the controls, not booking records.
$availableUntil = [];
$lastFreeEnd = null;
for ($i = count($customTimes) - 2; $i >= 0; --$i) {
    $from = $customTimes[$i];
    if (!isset($freeCustomStarts[$from])) {
        $lastFreeEnd = null;
        continue;
    }
    $lastFreeEnd ??= $customTimes[$i + 1];
    $availableUntil[$from] = $lastFreeEnd;
}
$detailContext = [];
$givenStart = $_GET['start_time'] ?? null;
$givenEnd = $_GET['end_time'] ?? null;
$requestedCustom = null;
$chosenCustom = null;
$requestedRange = false;
$preferredPeriod = null;
if (in_array($givenStart, $customTimes, true) && in_array($givenEnd, $customTimes, true) && $givenStart < $givenEnd) {
    $requestedRange = true;
    $detailContext += ['start_time' => $givenStart, 'end_time' => $givenEnd];
    $matchingPreset = null;
    foreach ($periods as $id => [, $from, $to, $available]) {
        if ($from === $givenStart && $to === $givenEnd) {
            $matchingPreset = $id;
            if ($available) $preferredPeriod = $id;
            break;
        }
    }
    try {
        $requestedCustom = roomieParseCustomBookingTimes($parsedDate, $givenStart, $givenEnd, $now);
        if ($matchingPreset === null && isset($availableUntil[$givenStart]) && $givenEnd <= $availableUntil[$givenStart]) {
            $chosenCustom = $requestedCustom;
            $preferredPeriod = 'individuell';
        }
    } catch (DomainException) {
        // Preserve the range on the back link even if it is no longer bookable.
    }
}
if (($_GET['free'] ?? null) === '1') $detailContext['free'] = '1';
if ($firstPeriod === null && $firstCustom) $firstPeriod = 'individuell';
$selectedPeriod = $requestedRange ? $preferredPeriod : $firstPeriod;
[$customStart, $customEnd] = $chosenCustom ?? $firstCustom ?? [ROOMIE_BOOKING_OPEN_TIME, ROOMIE_BOOKING_CLOSE_TIME];

$equipmentLabels = [
    'wifi' => 'WLAN', 'projector' => 'Beamer', 'whiteboard' => 'Whiteboard',
    'desk' => 'Schreibtisch', 'monitor' => 'Monitor', 'phone' => 'Telefon', 'dock' => 'Dockingstation',
    'display' => 'Display', 'video-conference' => 'Videokonferenz',
];
$equipment = json_decode((string) $room['equipment'], true);
$equipment = is_array($equipment) ? array_slice(array_unique(array_filter($equipment, 'is_string')), 0, 20) : [];
$watch = null;
$showWatch = false;
$watchNotice = $_SESSION['roomie_watch_notice'] ?? '';
unset($_SESSION['roomie_watch_notice']);
if ($canBookRoom && !$requiresApproval && $capacity > 0 && $requestedCustom !== null) {
    try {
        [$watchStart, $watchEnd] = $requestedCustom;
        $watch = roomieWatchFind($db, $userId, $room_id, $selected_date, $watchStart, $watchEnd);
        $conflict = roomieBookingConflict($bookings, $capacity, $userId, $watchStart, $watchEnd);
        $showWatch = $watch !== null || ($conflict !== 'own'
            && ($conflict === 'capacity' || roomieRoomBlockOverlaps($roomBlocks, $watchStart, $watchEnd)));
    } catch (Throwable $error) {
        error_log('Roomie watch controls unavailable');
    }
}
$roomName = (string) $room['name'];
$planUrl = 'raumplan.php?' . http_build_query(['date' => $selected_date] + $detailContext);
$blocksAdminUrl = 'admin_room_blocks.php?' . http_build_query(['room_id' => $room_id, 'date' => $selected_date]);
$seriesParams = ['room_id' => $room_id, 'date' => $selected_date];
if ($requestedCustom) {
    $seriesParams += ['start_time' => $requestedCustom[0], 'end_time' => $requestedCustom[1]];
}
$seriesUrl = 'series_booking.php?' . http_build_query($seriesParams);
$occupancy = $bookings;
foreach ($roomBlocks as $block) {
    $occupancy[] = $block + ['blocked' => true];
}
usort($occupancy, static fn (array $a, array $b): int =>
    [$a['start_time'], $a['end_time']] <=> [$b['start_time'], $b['end_time']]);
$prev = parseBookingDate($parsedDate->modify('-1 day')->format('Y-m-d'));
$next = parseBookingDate($parsedDate->modify('+1 day')->format('Y-m-d'));
$checkinEnabled = null;
$checkinReport = null;
$checkinPolicyUpdated = false;
if ($admin) {
    try {
        $checkinEnabled = roomieCheckinRoomPolicy($db, $room_id);
        if ($checkinEnabled) {
            $checkinReport = roomieCheckinRoomReport($db, $room_id, roomieCheckinNow());
        }
        $checkinPolicyUpdated = ($_GET['checkin_policy'] ?? null) === ($checkinEnabled ? '1' : '0');
    } catch (PDOException | UnexpectedValueException) {
        $checkinEnabled = null;
        error_log('Roomie room check-in policy or report unavailable');
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= detailEscape($roomName) ?> · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
    <link rel="stylesheet" href="assets/rooms/room-visual.css">
    <script src="assets/bookings/room-book.js" type="module"></script>
</head>
<body class="roomplan-page">
<?php $roomieActivePage = 'room_details.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="roomplan-main rb-page" tabindex="-1">
    <a class="rb-inline-link rb-back rb-icon-row" href="<?= detailEscape($planUrl) ?>"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 12H5m6-6-6 6 6 6"/></svg>Raumplan</a>
    <div class="rb-room-intro">
        <header class="rb-header">
            <h1 class="rb-icon-row"><svg class="rb-icon rb-icon-tile" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 21h16M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16m-4-9h.01"/></svg><span><?= detailEscape($roomName) ?></span></h1>
            <div class="rb-tags">
                <?php if ((string) ($room['number'] ?? '') !== '') : ?><span>Nr. <?= detailEscape((string) $room['number']) ?></span><?php endif; ?>
                <span><?= detailEscape(getRoomTypeName($room['type'])) ?></span>
                <span class="rb-icon-row"><svg class="rb-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="3"/><path d="M3 19v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2m2-14a3 3 0 0 1 0 6m4 8v-2a4 4 0 0 0-2.5-3.7"/></svg><?= $capacity === 1 ? '1 Platz' : $capacity . ' Plätze' ?></span>
                <?php if ($requiresApproval) : ?><span class="rb-request-status rb-request-pending rb-icon-row"><svg class="rb-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 22s7-3.5 7-10V6l-7-3-7 3v6c0 6.5 7 10 7 10z"/><path d="m9 12 2 2 4-4"/></svg>Freigabe nötig</span><?php endif; ?>
            </div>
        <?php if ($equipment) : ?>
            <ul class="rb-tags" aria-label="Ausstattung">
                <?php foreach ($equipment as $item) : ?><li><?= detailEscape($equipmentLabels[$item] ?? $item) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        </header>
        <?php roomieRenderVisual($room, $equipment, $hasPhoto, true); ?>
    </div>
    <form method="get" action="room_details.php" class="rb-filter">
        <input type="hidden" name="id" value="<?= $room_id ?>">
        <?php foreach ($detailContext as $name => $value) : ?>
            <input type="hidden" name="<?= detailEscape($name) ?>" value="<?= detailEscape($value) ?>">
        <?php endforeach; ?>
        <label>Datum<input type="date" name="date" value="<?= detailEscape($selected_date) ?>" required></label>
        <button type="submit" class="roomplan-action roomplan-action-secondary">Anzeigen</button>
        <?php if ($prev) : ?><a class="roomplan-action roomplan-action-secondary" href="<?= detailEscape(detailUrl($room_id, $prev->format('Y-m-d'), $detailContext)) ?>" aria-label="Vorheriger Tag"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 12H5m6-6-6 6 6 6"/></svg></a><?php endif; ?>
        <?php if ($next) : ?><a class="roomplan-action roomplan-action-secondary" href="<?= detailEscape(detailUrl($room_id, $next->format('Y-m-d'), $detailContext)) ?>" aria-label="Nächster Tag"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></a><?php endif; ?>
        <?php if ($admin) : ?><a class="roomplan-action roomplan-action-secondary" href="<?= detailEscape($blocksAdminUrl) ?>">Sperrzeiten</a><?php endif; ?>
    </form>
    <?php if ($admin) : ?>
    <p><a class="rb-inline-link" href="room_qr_print.php?id=<?= $room_id ?>">Raum-QR drucken</a></p>
    <form id="rb-room-approval" class="rb-filter" method="post" action="booking_request_action.php">
        <input type="hidden" name="csrf_token" value="<?= detailEscape(csrfToken()) ?>">
        <input type="hidden" name="action" value="policy">
        <input type="hidden" name="room_id" value="<?= $room_id ?>">
        <input type="hidden" name="date" value="<?= detailEscape($selected_date) ?>">
        <input type="hidden" name="required" value="<?= $requiresApproval ? '0' : '1' ?>">
        <button type="submit" class="roomplan-action roomplan-action-secondary rb-approval-toggle"
                aria-pressed="<?= $requiresApproval ? 'true' : 'false' ?>">Freigabe erforderlich</button>
        <?php if (($_GET['approval'] ?? null) === ($requiresApproval ? '1' : '0')) : ?>
            <p class="rb-empty" role="status">Freigabe <?= $requiresApproval ? 'aktiviert' : 'deaktiviert' ?>.</p>
        <?php endif; ?>
    </form>
    <?php if ($checkinEnabled !== null) : ?>
    <details class="rb-more" id="rb-room-checkin"<?= $checkinPolicyUpdated ? ' open' : '' ?>>
        <summary>Check-in · <?= $checkinEnabled ? 'aktiv' : 'aus' ?></summary>
        <form method="post" action="room_checkin_action.php" class="rb-filter">
            <input type="hidden" name="csrf_token" value="<?= detailEscape(csrfToken()) ?>">
            <input type="hidden" name="action" value="policy">
            <input type="hidden" name="room_id" value="<?= $room_id ?>">
            <input type="hidden" name="enabled" value="<?= $checkinEnabled ? '0' : '1' ?>">
            <button type="submit" class="roomplan-action roomplan-action-secondary">Check-in <?= $checkinEnabled ? 'deaktivieren' : 'aktivieren' ?></button>
        </form>
        <?php if ($checkinReport !== null) : ?>
            <p>Heute: <?= (int) $checkinReport['checked'] ?> / <?= (int) $checkinReport['booked'] ?> eingecheckt.</p>
        <?php endif; ?>
        <p class="rb-empty"><?= $checkinEnabled ? 'Deaktivieren löscht Check-ins, nicht Buchungen.' : 'Buchungen bleiben auch ohne Check-in bestehen.' ?></p>
        <?php if ($checkinPolicyUpdated) : ?>
            <p class="rb-empty" role="status">Check-in <?= $checkinEnabled ? 'aktiviert' : 'deaktiviert' ?>.</p>
        <?php endif; ?>
    </details>
    <?php endif; ?>
    <?php endif; ?>
    <div class="rb-room-columns">
        <section class="roomplan-panel rb-room-panel" aria-labelledby="rb-room-booking-title">
            <h2 id="rb-room-booking-title" class="rb-icon-row"><svg class="rb-icon rb-icon-accent" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18m-9 3v5m-2.5-2.5h5"/></svg><span><?= $requiresApproval ? 'Raum anfragen' : 'Raum buchen' ?></span></h2>
            <?php if ($showWatch || $watchNotice !== '') : ?>
                <div id="rb-room-watch" class="rb-room-watch">
                    <?php if ($watchNotice !== '') : ?><p class="rb-notice" role="status"><?= detailEscape($watchNotice) ?></p><?php endif; ?>
                    <?php if ($showWatch) : ?>
                        <form method="post" action="room_watch_action.php">
                            <input type="hidden" name="csrf_token" value="<?= detailEscape(csrfToken()) ?>">
                            <input type="hidden" name="return_to" value="room">
                            <?php if ($watch === null) : ?>
                                <input type="hidden" name="action" value="subscribe">
                                <input type="hidden" name="room_id" value="<?= $room_id ?>">
                                <input type="hidden" name="date" value="<?= detailEscape($selected_date) ?>">
                                <input type="hidden" name="start_time" value="<?= detailEscape($watchStart) ?>">
                                <input type="hidden" name="end_time" value="<?= detailEscape($watchEnd) ?>">
                            <?php else : ?>
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="watch_id" value="<?= (int) $watch['id'] ?>">
                            <?php endif; ?>
                            <p class="rb-room-watch-time"><?= detailEscape($watchStart . '–' . $watchEnd) ?> Uhr · Meldung in Roomie</p>
                            <button type="submit" class="roomplan-action roomplan-action-secondary rb-icon-row"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 13h4"/></svg><?= $watch === null ? 'Bei Freiwerden melden' : ($watch['notified_at'] === null ? 'Frei-Meldung abbestellen' : 'Meldung ausblenden') ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!$canBookRoom) : ?><p class="rb-empty">Für diesen Raum fehlt dir die Buchungsberechtigung.</p>
            <?php elseif (!$canBookDate) : ?><p class="rb-empty">Für dieses Datum nicht buchbar.</p>
            <?php elseif ($firstPeriod === null) : ?><p class="rb-empty">Kein buchbarer Zeitraum.</p>
            <?php else : ?>
            <?php if ($requestedRange && $selectedPeriod === null) : ?>
                <p class="rb-notice rb-notice-error">Der im Raumplan gewählte Zeitraum ist nicht verfügbar.</p>
            <?php endif; ?>
            <form id="rb-room-book" method="post" action="process_booking.php" data-requires-approval="<?= $requiresApproval ? '1' : '0' ?>" hidden>
                <input type="hidden" name="csrf_token" value="<?= detailEscape(csrfToken()) ?>">
                <input type="hidden" name="selectedWorkspace" value="<?= $room_id ?>">
                <input type="hidden" name="start_date" value="<?= detailEscape($selected_date) ?>">
                <input type="hidden" name="end_date" value="<?= detailEscape($selected_date) ?>">
                <?php if ($requiresApproval) : ?><input type="hidden" name="approval_request" value="1"><?php endif; ?>
                <fieldset class="rb-periods">
                    <legend>Zeit wählen</legend>
                    <?php foreach ($periods as $id => [$title, $start, $end, $available, $blocked]) : ?>
                    <label>
                        <input type="radio" name="time_period" value="<?= $id ?>"<?= $selectedPeriod === $id ? ' checked' : '' ?><?= !$available ? ' disabled' : '' ?> required>
                        <span><strong><?= $title ?></strong> <small><?= $start ?>–<?= $end ?><?php if (!$available) : ?> · <?= $blocked ? 'gesperrt' : 'nicht verfügbar' ?><?php endif; ?></small></span>
                    </label>
                    <?php endforeach; ?>
                    <label>
                        <input type="radio" name="time_period" value="individuell"<?= $selectedPeriod === 'individuell' ? ' checked' : '' ?><?= !$firstCustom ? ' disabled' : '' ?> required>
                        <span><strong>Individuell</strong><?php if (!$firstCustom) : ?> <small>nicht verfügbar</small><?php endif; ?></span>
                    </label>
                    <?php if ($firstCustom) : ?>
                    <div class="rp-time-range" data-custom-time data-available-until="<?= detailEscape(json_encode($availableUntil, JSON_THROW_ON_ERROR)) ?>"<?= $selectedPeriod !== 'individuell' ? ' hidden' : '' ?>>
                        <label class="rp-time-label" for="rb-book-start-time">Von
                            <select class="rp-date rp-time" id="rb-book-start-time" name="start_time" required<?= $selectedPeriod !== 'individuell' ? ' disabled' : '' ?>>
                                <?php foreach (array_slice($customTimes, 0, -1) as $option) : ?>
                                    <option value="<?= $option ?>"<?= $customStart === $option ? ' selected' : '' ?><?= !isset($availableUntil[$option]) ? ' disabled' : '' ?>><?= $option ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="rp-time-label" for="rb-book-end-time">Bis
                            <select class="rp-date rp-time" id="rb-book-end-time" name="end_time" required<?= $selectedPeriod !== 'individuell' ? ' disabled' : '' ?>>
                                <?php foreach (array_slice($customTimes, 1) as $option) : ?>
                                    <option value="<?= $option ?>"<?= $customEnd === $option ? ' selected' : '' ?><?= $option <= $customStart || $option > $availableUntil[$customStart] ? ' disabled' : '' ?>><?= $option ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <?php endif; ?>
                </fieldset>
                <?php if ($requiresApproval) : ?><p class="rb-empty">Der Raum ist erst nach Freigabe gebucht.</p><?php endif; ?>
                <button type="submit" class="roomplan-action"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg><?= $requiresApproval ? 'Anfrage senden' : 'Buchen' ?></button>
            </form>
            <p id="rb-room-feedback" class="rb-notice" role="status" tabindex="-1" hidden></p>
            <div id="rb-room-bookings" class="rb-segments" hidden>
                <a href="bookings.php?date=<?= rawurlencode($selected_date) ?>" data-confirmed-only>Zu meinen Buchungen</a>
                <a href="<?= detailEscape(detailUrl($room_id, $selected_date, $detailContext)) ?>" data-confirmed-only>Belegung aktualisieren</a>
                <a href="bookings.php#rb-booking-requests" data-pending-only hidden>Zu meinen Anfragen</a>
            </div>
            <noscript><a class="rb-inline-link" href="<?= detailEscape($planUrl) ?>">Im Raumplan buchen</a></noscript>
            <?php endif; ?>
            <?php if ($canBookRoom && $canBookDate && !$requiresApproval) : ?>
                <a class="roomplan-action roomplan-action-secondary rb-series-link" href="<?= detailEscape($seriesUrl) ?>"><svg class="rb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m17 2 4 4-4 4M3 11V8a2 2 0 0 1 2-2h16M7 22l-4-4 4-4m14-1v3a2 2 0 0 1-2 2H3"/></svg>Wöchentlich buchen</a>
            <?php endif; ?>
        </section>
        <section class="roomplan-panel rb-room-panel" aria-labelledby="rb-room-occupancy-title">
            <h2 id="rb-room-occupancy-title" class="rb-icon-row"><svg class="rb-icon rb-icon-muted" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v5l3 2"/></svg><span>Belegung · <time datetime="<?= detailEscape($selected_date) ?>"><?= detailEscape($parsedDate->format('d.m.Y')) ?></time></span></h2>
            <?php if (!$occupancy) : ?>
            <div class="rb-empty-visual rb-empty-visual-compact">
                <img src="assets/illustrations/booking-availability.svg" width="56" height="48" alt="" decoding="async">
                <p class="rb-empty">Keine Buchungen.</p>
            </div>
            <?php else : ?>
            <ul class="rb-room-list">
                <?php foreach ($occupancy as $entry) : ?>
                <li><strong><?= detailEscape(substr($entry['start_time'], 0, 5) . '–' . substr($entry['end_time'], 0, 5)) ?></strong>
                    <?php if (!empty($entry['blocked'])) : ?><span class="rb-period-blocked">Gesperrt</span>
                    <?php else : ?><span><?= $entry['own'] ? 'Eigene Buchung' : detailEscape($entry['user_name'] ?? 'Belegt') ?></span><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
