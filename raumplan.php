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
    <link rel="stylesheet" href="assets/roomplan/floor-view.css">
    <?php if ($roomiePlanAdmin) : ?>
        <link rel="stylesheet" href="assets/roomplan/floor-editor.css">
        <link rel="stylesheet" href="assets/roomplan/floor-upload.css">
        <link rel="stylesheet" href="assets/roomplan/floor-rooms.css">
    <?php endif; ?>
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
                <div class="rp-floor-actions">
                    <button type="button" id="roomplan-floor-edit" class="roomplan-action roomplan-action-secondary"
                            aria-controls="roomplan-floor-editor" aria-expanded="false" hidden>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3h18v18H3zM12 3v7h9M3 14h7v7" /></svg>
                        Räume &amp; Grundrisse verwalten
                    </button>
                    <details class="rp-legacy-actions">
                        <summary class="roomplan-action roomplan-action-secondary" aria-label="Weitere Optionen">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
                        </summary>
                        <div class="rp-legacy-panel">
                            <button type="button" id="roomplan-edit" class="roomplan-action roomplan-action-secondary"
                                    aria-controls="roomplan-editor" aria-expanded="false">Raster bearbeiten</button>
                        </div>
                    </details>
                </div>
            <?php endif; ?>
        </header>
        <div id="roomplan-view">
            <p class="roomplan-panel roomplan-muted" role="status">Raumplan wird geladen …</p>
        </div>
        <div id="roomplan-editor" hidden></div>
        <?php if ($roomiePlanAdmin) : ?><div id="roomplan-floor-editor" hidden></div><?php endif; ?>
        <noscript>
            <p class="roomplan-panel">Der interaktive Raumplan benötigt JavaScript. <a href="rooms.php">Raumliste öffnen</a></p>
        </noscript>
    </main>
</body>
</html>
