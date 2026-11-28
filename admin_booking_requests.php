<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_approvals.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=utf-8');
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
$focused = array_key_exists('id', $_GET);
$id = $focused ? roomiePositiveId($_GET['id']) : null;
$selected = null;
$queue = ['upcoming' => [], 'elapsed' => []];
$error = null;
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
try {
    if ($focused) {
        if ($id === null) {
            http_response_code(400);
            $error = 'Ungültige Anfrage.';
        } else {
            $selected = roomieFindAdminBookingRequest(getDatabaseConnection(), $id);
            if ($selected === null) {
                http_response_code(404);
                $error = 'Anfrage nicht gefunden.';
            }
        }
    } else {
        $queue = roomieListAdminBookingRequests(getDatabaseConnection(), $now);
    }
} catch (Throwable) {
    error_log('Roomie booking requests unavailable');
    http_response_code(503);
    $error = 'Anfragen sind zurzeit nicht verfügbar.';
}
$notice = $focused ? null : match ($_GET['request'] ?? null) {
    'approved' => 'Anfrage genehmigt.',
    'declined' => 'Anfrage abgelehnt.',
    default => null,
};
$today = $now->format('Y-m-d');
$clock = $now->format('H:i:s');
$renderItem = static function (array $request, bool $isFocused) use ($requestEscape, $today, $clock): void {
    $date = parseBookingDate((string) $request['date']);
    $starts = roomieBookingClock($request['start_time']);
    $pending = $request['status'] === 'pending';
    $upcoming = $date !== null && $starts !== null
        && ($request['date'] > $today || ($request['date'] === $today && $starts > $clock));
    $status = match ($request['status']) {
        'pending' => $upcoming ? null : 'Termin verstrichen',
        'approved' => 'Genehmigt',
        'declined' => 'Abgelehnt',
        'cancelled' => 'Zurückgezogen',
        default => 'Nicht verfügbar',
    };
    $heading = $isFocused ? 'h2' : 'h3';
    ?>
    <li class="rb-entry">
        <div class="rb-entry-main">
            <<?= $heading ?>><?= $requestEscape($request['room_name']) ?></<?= $heading ?>>
            <p><time datetime="<?= $requestEscape($request['date']) ?>"><?= $requestEscape($date?->format('d.m.Y')) ?></time> ·
                <?= $requestEscape(substr((string) $request['start_time'], 0, 5) . '–' . substr((string) $request['end_time'], 0, 5)) ?> Uhr</p>
            <p>Anfrage von <?= $requestEscape($request['user_name']) ?></p>
            <?php if ($status !== null) : ?><p class="rb-request-status"><?= $requestEscape($status) ?></p><?php endif; ?>
        </div>
        <?php if ($pending) : ?>
            <form class="rb-entry-actions" method="post" action="booking_request_action.php">
                <input type="hidden" name="csrf_token" value="<?= $requestEscape(csrfToken()) ?>">
                <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                <?php if ($upcoming) : ?>
                    <button type="submit" name="action" value="approve" class="roomplan-action">Genehmigen</button>
                <?php endif; ?>
                <button type="submit" name="action" value="decline" class="roomplan-action roomplan-action-secondary">Ablehnen</button>
            </form>
        <?php endif; ?>
    </li>
    <?php
};
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
    <a class="rb-inline-link rb-back" href="<?= $focused ? 'admin_booking_requests.php' : 'admin_dashboard.php' ?>"><?= $focused ? '← Alle Anfragen' : '← Admin-Übersicht' ?></a>
    <header class="rb-header rm-area-head"><h1><?= $focused ? 'Buchungsanfrage' : 'Buchungsanfragen' ?></h1><img class="rm-area-art" src="assets/illustrations/admin-requests.svg" width="96" height="64" alt=""></header>
    <?php if ($error !== null) : ?><p class="rb-notice rb-notice-error" role="alert"><?= $requestEscape($error) ?></p>
    <?php elseif ($notice !== null) : ?><p class="rb-notice" role="status"><?= $requestEscape($notice) ?></p><?php endif; ?>

    <?php if ($selected !== null) : ?>
        <ul class="rb-list"><?php $renderItem($selected, true); ?></ul>
    <?php elseif (!$focused && $error === null) : ?>
        <?php if ($queue['upcoming']) : ?>
            <section aria-labelledby="rb-upcoming-requests">
                <h2 id="rb-upcoming-requests">Anstehend</h2>
                <ul class="rb-list"><?php foreach ($queue['upcoming'] as $request) { $renderItem($request, false); } ?></ul>
            </section>
        <?php else : ?>
            <p class="roomplan-panel rb-empty">Keine anstehenden Anfragen.</p>
        <?php endif; ?>
        <?php if ($queue['elapsed']) : ?>
            <details class="roomplan-panel rb-request-cleanup">
                <summary>Verstrichene Anfragen</summary>
                <ul class="rb-list"><?php foreach ($queue['elapsed'] as $request) { $renderItem($request, false); } ?></ul>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
