<?php
declare(strict_types=1);

if (!defined('ROOMIE_SERIES_CONTROLLER')) {
    http_response_code(404);
    exit;
}

$seriesEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$selectedRoom = null;
foreach ($rooms as $option) {
    if ((string) $option['id'] === (string) $values['room_id']) {
        $selectedRoom = $option;
        break;
    }
}
$fixedRoom = $selectedRoom !== null && roomiePositiveId($_GET['room_id'] ?? null) === (int) $selectedRoom['id'];
$seriesBack = 'raumplan.php';
$seriesAction = 'series_booking.php';
if ($fixedRoom) {
    $seriesAction .= '?room_id=' . (int) $selectedRoom['id'];
    $context = ['id' => $selectedRoom['id']];
    if (parseBookingDate($values['date']) !== null) {
        $context['date'] = $values['date'];
    }
    if ($values['start_time'] !== '' && $values['end_time'] !== '') {
        $context['start_time'] = $values['start_time'];
        $context['end_time'] = $values['end_time'];
    }
    $seriesBack = 'room_details.php?' . http_build_query($context);
}
$seriesTimes = [];
$seriesClose = $today->modify(ROOMIE_BOOKING_CLOSE_TIME);
$seriesStep = new DateInterval('PT' . ROOMIE_BOOKING_STEP_MINUTES . 'M');
for ($slot = $today->modify(ROOMIE_BOOKING_OPEN_TIME); $slot <= $seriesClose; $slot = $slot->add($seriesStep)) {
    $seriesTimes[] = $slot->format('H:i');
}
$seriesAvailable = static fn (array $row): bool => ($row['available'] ?? (($row['status'] ?? null) === 'available')) === true;
$canConfirm = $selectedRoom !== null && $previewToken !== null && $previewToken !== '' && !empty($preview)
    && count(array_filter($preview, $seriesAvailable)) === count($preview);
$latestStart = $horizon->modify('-' . (7 * (ROOMIE_SERIES_MIN - 1)) . ' days');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wöchentlich buchen · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
</head>
<body class="roomplan-page">
<?php if (isLoggedIn()) : ?>
    <?php $roomieActivePage = 'room_details.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<?php endif; ?>
<main id="roomie-series" class="roomplan-main rb-page" tabindex="-1">
    <a class="rb-inline-link rb-back" href="<?= $seriesEscape($seriesBack) ?>">← <?= $fixedRoom ? 'Raumdetails' : 'Raumplan' ?></a>
    <header class="rb-header"><h1>Wöchentlich buchen</h1></header>
    <?php if ($error !== null && $error !== '') : ?>
        <p class="rb-notice rb-notice-error" role="alert"><?= $seriesEscape($error) ?></p>
    <?php endif; ?>

    <?php if ($selectedRoom !== null && !empty($preview)) : ?>
    <section id="rb-series-results" class="roomplan-panel rb-room-panel rb-series-panel" aria-labelledby="rb-series-results-title">
        <h2 id="rb-series-results-title"><?= count($preview) ?> Termine</h2>
        <p><?= $seriesEscape($selectedRoom['name']) ?> · <?= $seriesEscape($values['start_time']) ?>–<?= $seriesEscape($values['end_time']) ?> Uhr</p>
        <ul class="rb-room-list rb-series-results" aria-label="Verfügbarkeit der Termine">
        <?php foreach ($preview as $occurrence) :
            $available = $seriesAvailable($occurrence);
            $day = parseBookingDate($occurrence['date']);
        ?>
            <li>
                <time datetime="<?= $seriesEscape($occurrence['date']) ?>"><?= $seriesEscape($day?->format('d.m.Y') ?? $occurrence['date']) ?></time>
                <strong class="rb-series-<?= $available ? 'free' : 'busy' ?>"><?= $available ? 'Frei' : 'Belegt' ?></strong>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php if ($canConfirm) : ?>
        <form id="rb-series-book" method="post" action="<?= $seriesEscape($seriesAction) ?>">
            <input type="hidden" name="csrf_token" value="<?= $seriesEscape(csrfToken()) ?>">
            <input type="hidden" name="stage" value="book">
            <input type="hidden" name="preview_token" value="<?= $seriesEscape($previewToken) ?>">
            <button type="submit" class="roomplan-action">Serie buchen</button>
        </form>
        <?php elseif ($error === null || $error === '') : ?>
        <p class="rb-empty">Nicht alle Termine sind frei. Es wurde nichts gebucht.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($rooms !== []) : ?>
    <?php if ($canConfirm) : ?><details class="roomplan-panel rb-more rb-series-panel"><summary>Termine ändern</summary>
    <?php else : ?><section class="roomplan-panel rb-series-panel" aria-label="Wöchentliche Termine auswählen"><?php endif; ?>
        <?php if ($fixedRoom) : ?><p class="rb-series-fixed-room"><strong><?= $seriesEscape($selectedRoom['name']) ?></strong></p><?php endif; ?>
        <form id="rb-series-preview" class="rb-filter" method="post" action="<?= $seriesEscape($seriesAction) ?>">
            <input type="hidden" name="csrf_token" value="<?= $seriesEscape(csrfToken()) ?>">
            <input type="hidden" name="stage" value="preview">
            <?php if ($fixedRoom) : ?>
            <input type="hidden" name="room_id" value="<?= (int) $selectedRoom['id'] ?>">
            <?php else : ?>
            <label class="rb-series-room">Raum
                <select name="room_id" required>
                    <option value="">Raum auswählen</option>
                    <?php foreach ($rooms as $option) : ?>
                    <option value="<?= (int) $option['id'] ?>"<?= (string) $values['room_id'] === (string) $option['id'] ? ' selected' : '' ?>><?= $seriesEscape($option['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label>Erster Termin
                <input name="date" type="date" min="<?= $today->format('Y-m-d') ?>" max="<?= $latestStart->format('Y-m-d') ?>" value="<?= $seriesEscape($values['date']) ?>" required>
            </label>
            <label>Von
                <select name="start_time" required>
                    <option value="">Uhrzeit</option>
                    <?php foreach (array_slice($seriesTimes, 0, -1) as $time) : ?>
                        <option value="<?= $time ?>"<?= $time === $values['start_time'] ? ' selected' : '' ?>><?= $time ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Bis
                <select name="end_time" required>
                    <option value="">Uhrzeit</option>
                    <?php foreach (array_slice($seriesTimes, 1) as $time) : ?>
                        <option value="<?= $time ?>"<?= $time === $values['end_time'] ? ' selected' : '' ?>><?= $time ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Anzahl Termine
                <select name="count" required>
                    <?php for ($number = ROOMIE_SERIES_MIN; $number <= ROOMIE_SERIES_MAX; $number++) : ?>
                        <option value="<?= $number ?>"<?= (string) $number === (string) $values['count'] ? ' selected' : '' ?>><?= $number ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <button type="submit" class="roomplan-action">Termine prüfen</button>
        </form>
    <?php if ($canConfirm) : ?></details><?php else : ?></section><?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
