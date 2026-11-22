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
$lastDate = $now->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days')->format('Y-m-d');
$dashboardEscape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$rooms = [];
$approvalRooms = [];
$nextBooking = null;
$loadFailed = false;
try {
    $db = getDatabaseConnection();
    // The list reflects the write endpoint's room grants, including for administrators.
    $rooms = roomieListRooms($db, $userId, false);
    $approvalRooms = array_fill_keys(roomieApprovalRoomIds($db), true);
    $query = $db->prepare(
        'SELECT r.name AS room_name, b.date, b.start_time, b.end_time
           FROM bookings b JOIN rooms r ON b.room_id = r.id
          WHERE b.user_id = :user AND b.date >= :today
            AND (b.date > :today OR time(b.end_time) > :now)
          ORDER BY b.date, time(b.start_time), b.id LIMIT 1'
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
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Übersicht · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/roomie-dashboard.css">
    <script src="assets/roomie-dashboard.js" defer></script>
</head>
<body class="roomplan-page">
    <?php $roomieActivePage = 'index.php'; require __DIR__ . '/roomplan_shell.php'; ?>

    <main class="roomplan-main rm-dashboard" id="main-content">
        <h1>Übersicht</h1>

        <?php if (is_string($flashError) && $flashError !== '') : ?>
            <p class="rp-status rp-status-error" role="alert"><?= $dashboardEscape($flashError) ?></p>
        <?php endif; ?>
        <?php if (is_string($flashSuccess) && $flashSuccess !== '') : ?>
            <p class="rp-status" role="status"><?= $dashboardEscape($flashSuccess) ?></p>
        <?php endif; ?>

        <div class="rm-dash-overview">
            <section class="roomplan-panel rm-dash-hero">
                <svg class="rm-dash-illustration" viewBox="0 0 96 96" fill="none" stroke="currentColor" aria-hidden="true">
                    <rect x="13" y="13" width="70" height="70" rx="12" stroke-width="2.5"/>
                    <path d="M45 13v34H13m32 0h13m12 0h13M56 83V61h27" stroke-width="2.5" stroke-linecap="round"/>
                    <rect x="23" y="22" width="13" height="13" rx="4" fill="currentColor" stroke="none"/>
                    <rect x="58" y="22" width="15" height="15" rx="5" fill="currentColor" stroke="none" opacity=".42"/>
                </svg>
                <h2>Raum finden</h2>
                <p>Freie Räume im Plan finden.</p>
                <a href="raumplan.php" class="roomplan-action">Raumplan öffnen <span aria-hidden="true">→</span></a>
            </section>

            <section class="roomplan-panel rm-dash-next">
                <h2 id="rm-next-title"><?= $isCurrent ? 'Laufende Buchung' : 'Nächste Buchung' ?></h2>
                <div id="rm-next-summary">
                    <?php if ($nextBooking !== null) : ?>
                        <p class="rm-dash-room"><?= $dashboardEscape($nextBooking['room_name']) ?></p>
                        <p class="rm-dash-meta">
                            <time datetime="<?= $dashboardEscape($nextBooking['date']) ?>"><?= $dashboardEscape($bookingDateLabel) ?></time>
                            · <?= $dashboardEscape(substr($nextBooking['start_time'], 0, 5)) ?>–<?= $dashboardEscape(substr($nextBooking['end_time'], 0, 5)) ?> Uhr
                        </p>
                    <?php elseif ($loadFailed) : ?>
                        <p class="rm-dash-meta">Buchungen konnten nicht geladen werden.</p>
                    <?php else : ?>
                        <p class="rm-dash-meta">Keine anstehende Buchung.</p>
                    <?php endif; ?>
                </div>
                <a href="bookings.php" class="roomplan-action roomplan-action-secondary">Meine Buchungen</a>
            </section>
        </div>

        <?php if ($rooms !== []) : ?>
            <details class="roomplan-panel rm-dash-range">
                <summary>Mehrere Tage buchen</summary>
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
