<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_approvals.php';

header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    header('Location: login.php', true, 303);
    exit;
}
if (!isAdmin()) {
    http_response_code(403);
    exit('Diese Seite erfordert Administratorrechte.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Methode nicht erlaubt.');
}

$requestEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$requests = [];
$error = null;
try {
    $requests = roomieListPendingBookingRequests(getDatabaseConnection());
} catch (Throwable) {
    error_log('Roomie booking requests list unavailable');
    http_response_code(503);
    $error = 'Anfragen sind zurzeit nicht verfügbar.';
}
$notice = match ($_GET['request'] ?? null) {
    'approved' => 'Anfrage genehmigt.',
    'declined' => 'Anfrage abgelehnt.',
    default => null,
};
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
$today = $now->format('Y-m-d');
$clock = $now->format('H:i:s');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchungsanfragen · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
    <link rel="stylesheet" href="assets/admin/area-visuals.css">
</head>
<body class="roomplan-page">
<?php $roomieActivePage = 'bookings.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main id="rb-admin-booking-requests" class="roomplan-main rb-page" tabindex="-1">
    <a class="rb-inline-link rb-back" href="bookings.php">← Buchungen</a>
    <header class="rb-header rm-area-head"><h1>Buchungsanfragen</h1><img class="rm-area-art" src="assets/illustrations/admin-requests.svg" width="96" height="64" alt=""></header>
    <?php if ($error !== null) : ?><p class="rb-notice rb-notice-error" role="alert"><?= $requestEscape($error) ?></p>
    <?php elseif ($notice !== null) : ?><p class="rb-notice" role="status"><?= $requestEscape($notice) ?></p><?php endif; ?>

    <?php if (!$requests && $error === null) : ?><p class="roomplan-panel rb-empty">Keine offenen Anfragen.</p><?php endif; ?>
    <?php if ($requests) : ?>
    <ul class="rb-list" aria-label="Offene Anfragen">
    <?php foreach ($requests as $request) :
        $date = parseBookingDate((string) $request['date']);
        $starts = roomieBookingClock($request['start_time']);
        $canApprove = $date !== null && $starts !== null
            && ($request['date'] > $today || ($request['date'] === $today && $starts > $clock));
    ?>
        <li class="rb-entry">
            <div class="rb-entry-main">
                <h2><?= $requestEscape($request['room_name']) ?></h2>
                <p><time datetime="<?= $requestEscape($request['date']) ?>"><?= $requestEscape($date?->format('d.m.Y')) ?></time> ·
                    <?= $requestEscape(substr((string) $request['start_time'], 0, 5) . '–' . substr((string) $request['end_time'], 0, 5)) ?> Uhr</p>
                <p>Anfrage von <?= $requestEscape($request['user_name']) ?></p>
            </div>
            <form class="rb-entry-actions" method="post" action="booking_request_action.php">
                <input type="hidden" name="csrf_token" value="<?= $requestEscape(csrfToken()) ?>">
                <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                <?php if ($canApprove) : ?>
                    <button type="submit" name="action" value="approve" class="roomplan-action">Genehmigen</button>
                <?php else : ?><span class="rb-request-status">Termin verstrichen</span><?php endif; ?>
                <button type="submit" name="action" value="decline" class="roomplan-action roomplan-action-secondary">Ablehnen</button>
            </form>
        </li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</main>
</body>
</html>
