<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_helpers.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
header('Cache-Control: private, no-store');

$roomieActivePage = 'raumplan.php';
$roomiePlanAdmin = isAdmin();
$roomiePlanToday = roomieBookingToday();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Raumplan · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/roomplan/editor.css">
    <script type="module" src="assets/roomplan/view.js"></script>
</head>
<body class="roomplan-page">
    <?php require __DIR__ . '/roomplan_shell.php'; ?>
    <main id="roomplan" class="roomplan-main" tabindex="-1"
          data-today="<?= $roomiePlanToday->format('Y-m-d') ?>"
          data-max-date="<?= $roomiePlanToday->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days')->format('Y-m-d') ?>"
          data-admin="<?= $roomiePlanAdmin ? '1' : '0' ?>"
          data-csrf="<?= csrfToken() ?>">
        <header class="roomplan-heading">
            <h1>Raumplan</h1>
            <?php if ($roomiePlanAdmin) : ?>
                <button type="button" id="roomplan-edit" class="roomplan-action roomplan-action-secondary"
                        aria-controls="roomplan-editor" aria-expanded="false">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15.5 5.5 3 3M5 19l4-.8L19.3 7.9a2.1 2.1 0 0 0-3-3L6 15.2 5 19Z" /></svg>
                    Layout bearbeiten
                </button>
            <?php endif; ?>
        </header>
        <div id="roomplan-view">
            <p class="roomplan-panel roomplan-muted" role="status">Raumplan wird geladen …</p>
        </div>
        <div id="roomplan-editor" hidden></div>
        <noscript>
            <p class="roomplan-panel">Der interaktive Raumplan benötigt JavaScript. <a href="rooms.php">Raumliste öffnen</a></p>
        </noscript>
    </main>
</body>
</html>
