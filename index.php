<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_access.php';
require_once __DIR__ . '/booking_approvals.php';

header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
$today = $now->format('Y-m-d');
$quickRoomParams = ['view' => 'list', 'free' => '1'];
$quickStart = max(9 * 60, (intdiv((int) $now->format('G') * 60 + (int) $now->format('i'), 15) + 1) * 15);
if ($quickStart >= 17 * 60) {
    $quickRoomParams['date'] = $now->modify('+1 day')->format('Y-m-d');
    $quickStart = 9 * 60;
}
$quickRoomParams['start_time'] = sprintf('%02d:%02d', intdiv($quickStart, 60), $quickStart % 60);
$quickRoomParams['end_time'] = sprintf('%02d:%02d', intdiv($quickStart + 15, 60), ($quickStart + 15) % 60);
$quickRoomUrl = 'raumplan.php?' . http_build_query($quickRoomParams);
$lastDate = $now->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days')->format('Y-m-d');
$dashboardEscape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$rooms = [];
$approvalRooms = [];
$nextBooking = null;
$loadFailed = false;
try {
    $db = getDatabaseConnection();
    // The list reflects the write endpoint's room grants, including for administrators.
    $rooms = roomieListBookableRooms($db, $userId);
    $approvalRooms = array_fill_keys(roomieApprovalRoomIds($db), true);
    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $query = $db->prepare(
        "SELECT r.name AS room_name, b.date, b.start_time, b.end_time
           FROM bookings b JOIN rooms r ON r.id = $effectiveRoom
          WHERE b.user_id = :user AND b.date >= :today
            AND (b.date > :today OR time(b.end_time) > :now)
          ORDER BY b.date, time(b.start_time), b.id LIMIT 1"
    );
    $query->execute(['user' => $userId, 'today' => $today, 'now' => $now->format('H:i:s')]);
    $nextBooking = $query->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $error) {
    error_log('Roomie dashboard data unavailable');
    $loadFailed = true;
}

$isCurrent = $nextBooking !== null && $nextBooking['date'] === $today
    && str_pad($nextBooking['start_time'], 8, ':00') <= $now->format('H:i:s');
$bookingDateLabel = $nextBooking === null ? '' : (
    $nextBooking['date'] === $today ? 'Heute'
        : (parseBookingDate($nextBooking['date'])?->format('d.m.Y') ?? '')
);
$flashError = $_SESSION['error'] ?? '';
$flashSuccess = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="de" class="rm-dashboard-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Übersicht · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/roomie-dashboard.css">
    <script src="assets/roomie-dashboard.js" defer></script>
</head>
<body class="roomplan-page rm-dashboard-page">
    <?php $roomieActivePage = 'index.php'; require __DIR__ . '/roomplan_shell.php'; ?>

    <main class="roomplan-main rm-dashboard" id="main-content" tabindex="-1">
        <h1>Übersicht</h1>

        <?php if (is_string($flashError) && $flashError !== '') : ?>
            <p class="rp-status rp-status-error" role="alert"><?= $dashboardEscape($flashError) ?></p>
        <?php endif; ?>
        <?php if (is_string($flashSuccess) && $flashSuccess !== '') : ?>
            <p class="rp-status" role="status"><?= $dashboardEscape($flashSuccess) ?></p>
        <?php endif; ?>

        <div class="rm-dash-overview">
            <section class="rm-dash-hero" aria-labelledby="rm-dash-find-title">
                <div class="rm-dash-find">
                    <h2 id="rm-dash-find-title">Raum finden</h2>
                    <a href="<?= $dashboardEscape($quickRoomUrl) ?>" class="roomplan-action">
                        <svg class="rm-dash-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 11h8m0-7v16m4-5h4"/></svg>
                        Freien Raum finden
                    </a>
                </div>
                <img class="rm-dash-illustration" src="assets/illustrations/meeting-room.webp" alt="" width="760" height="507" decoding="async">
            </section>

            <section class="rm-dash-next" aria-labelledby="rm-next-title">
                <h2 id="rm-next-title">
                    <svg class="rm-dash-heading-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3.5" y="5.5" width="17" height="15" rx="2"/><path d="M8 3.5v4m8-4v4M4 10h16m-11 5 2 2 4-4"/></svg>
                    <span id="rm-next-title-label"><?= $isCurrent ? 'Laufende Buchung' : 'Nächste Buchung' ?></span>
                </h2>
                <div id="rm-next-summary">
                    <?php if ($nextBooking !== null) : ?>
                        <p class="rm-dash-room"><?= $dashboardEscape($nextBooking['room_name']) ?></p>
                        <p class="rm-dash-meta">
                            <time datetime="<?= $dashboardEscape($nextBooking['date']) ?>"><?= $dashboardEscape($bookingDateLabel) ?></time>
                            <span><?= $dashboardEscape(substr($nextBooking['start_time'], 0, 5)) ?>–<?= $dashboardEscape(substr($nextBooking['end_time'], 0, 5)) ?> Uhr</span>
                        </p>
                    <?php elseif ($loadFailed) : ?>
                        <p class="rm-dash-meta">Buchungen konnten nicht geladen werden.</p>
                    <?php else : ?>
                        <p class="rm-dash-meta">Keine anstehende Buchung.</p>
                    <?php endif; ?>
                </div>
                <?php if (!$loadFailed) : ?>
                    <img class="rm-dash-booking-illustration" src="assets/illustrations/<?= $nextBooking === null ? 'booking-calendar.svg' : 'booking-next.svg' ?>" alt="" width="124" height="104" decoding="async">
                <?php endif; ?>
                <a href="bookings.php" class="roomplan-action roomplan-action-secondary">Meine Buchungen</a>
            </section>
        </div>

        <?php if ($rooms !== []) : ?>
            <details class="roomplan-panel rm-dash-range">
                <summary>
                    <svg class="rm-dash-range-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3.5" y="5.5" width="17" height="15" rx="2"/><path d="M8 3.5v4m8-4v4M4 10h16m-12 5h8"/>
                    </svg>
                    <span>Mehrere Tage buchen</span>
                    <svg class="rm-dash-range-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg>
                </summary>
                <form id="bookingForm" action="process_booking.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $dashboardEscape(csrfToken()) ?>">
                    <div class="rm-dash-form-fields">
                        <label class="roomplan-field">
                            <span>Raum</span>
                            <select name="selectedWorkspace" required>
                                <option value="">Raum auswählen</option>
                                <?php foreach ($rooms as $room) : $needsApproval = isset($approvalRooms[(int) $room['id']]); ?>
                                    <option value="<?= $dashboardEscape($room['id']) ?>"<?= $needsApproval ? ' data-requires-approval="1"' : '' ?>><?= $dashboardEscape($room['name']) ?><?= $needsApproval ? ' · Freigabe nötig' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="roomplan-field">
                            <span>Von</span>
                            <input type="date" name="start_date" required min="<?= $today ?>" max="<?= $lastDate ?>" value="<?= $today ?>">
                        </label>
                        <label class="roomplan-field">
                            <span>Bis</span>
                            <input type="date" name="end_date" required min="<?= $today ?>" max="<?= $lastDate ?>" value="<?= $today ?>">
                        </label>
                        <label class="roomplan-field">
                            <span>Zeitraum</span>
                            <select name="time_period" required>
                                <option value="ganzerTag">Ganztags · 09:00–17:00</option>
                                <option value="vormittags">Vormittags · 09:00–12:00</option>
                                <option value="nachmittags">Nachmittags · 13:00–17:00</option>
                            </select>
                        </label>
                    </div>
                    <div class="rm-dash-form-actions">
                        <button type="submit" class="roomplan-action">Zeitraum buchen</button>
                        <p id="rm-range-approval" class="rm-dash-feedback" hidden>Nur einzelne Tage. Der Raum ist erst nach Freigabe gebucht.</p>
                        <p id="rm-range-feedback" class="rm-dash-feedback" role="status" aria-atomic="true"></p>
                        <a class="roomplan-action roomplan-action-secondary" href="bookings.php" id="rm-range-bookings" hidden>Buchungen ansehen</a>
                    </div>
                </form>
            </details>
        <?php endif; ?>
    </main>
</body>
</html>
