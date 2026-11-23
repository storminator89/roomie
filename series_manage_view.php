<?php
declare(strict_types=1);

if (!defined('ROOMIE_SERIES_MANAGE_CONTROLLER')) {
    http_response_code(404);
    exit;
}

$manageEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$manageUrl = 'series_manage.php?id=' . $id;
$manageTimes = [];
$manageOpen = new DateTimeImmutable(ROOMIE_BOOKING_OPEN_TIME, new DateTimeZone('Europe/Berlin'));
$manageClose = $manageOpen->modify(ROOMIE_BOOKING_CLOSE_TIME);
$manageStep = new DateInterval('PT' . ROOMIE_BOOKING_STEP_MINUTES . 'M');
for ($slot = $manageOpen; $slot <= $manageClose; $slot = $slot->add($manageStep)) {
    $manageTimes[] = $slot->format('H:i');
}
$manageConflict = count(array_filter($rows, static fn (array $row): bool => !empty($row['blocked']))) > 0;
$manageConfirm = !$manageConflict && is_string($previewToken) && $previewToken !== '';
$manageFirst = $rows[0] ?? [];
$manageRooms = array_unique(array_column($rows, 'room_name'));
$manageStart = substr($start !== '' ? $start : (string) ($manageFirst['start_time'] ?? ''), 0, 5);
$manageEnd = substr($end !== '' ? $end : (string) ($manageFirst['end_time'] ?? ''), 0, 5);
$manageCount = count($rows);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Serie bearbeiten · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
</head>
<body class="roomplan-page">
<?php $roomieActivePage = 'bookings.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="roomplan-main rb-page rb-series-page" tabindex="-1">
    <a class="rb-inline-link rb-back" href="bookings.php">← Meine Buchungen</a>
    <header class="rb-header"><h1>Serie bearbeiten</h1></header>

    <?php if ($error !== null && $error !== '') : ?>
        <p class="rb-notice rb-notice-error" role="alert"><?= $manageEscape($error) ?></p>
    <?php endif; ?>

    <?php if ($manageCount === 0) : ?>
        <p class="roomplan-panel rb-empty rb-series-panel">Keine kommenden Termine.</p>
    <?php else : ?>
        <section class="roomplan-panel rb-room-panel rb-series-panel" aria-labelledby="rb-series-manage-summary">
            <h2 id="rb-series-manage-summary"><?= $manageCount === 1 ? '1 kommender Termin' : $manageCount . ' kommende Termine' ?></h2>
            <?php if (count($manageRooms) === 1) : ?><p><strong><?= $manageEscape(reset($manageRooms)) ?></strong></p><?php endif; ?>
            <?php if ($manageConfirm && $action === 'edit') : ?>
                <p>Neue Uhrzeit: <strong><?= $manageEscape($manageStart) ?>–<?= $manageEscape($manageEnd) ?> Uhr</strong></p>
            <?php elseif ($manageConfirm && $action === 'cancel') : ?>
                <p>Die folgenden Termine werden storniert.</p>
            <?php elseif ($manageConflict) : ?>
                <p class="rb-empty">Mindestens ein Konflikt. Es wurde nichts geändert.</p>
            <?php endif; ?>
            <ul class="rb-room-list" aria-label="Serientermine">
                <?php foreach ($rows as $row) :
                    $day = parseBookingDate($row['date'] ?? null);
                    $from = substr((string) $row['start_time'], 0, 5);
                    $until = substr((string) $row['end_time'], 0, 5);
                ?>
                <li>
                    <time datetime="<?= $manageEscape($row['date']) ?>"><?= $manageEscape($day?->format('d.m.Y') ?? $row['date']) ?></time>
                    <span><?= count($manageRooms) > 1 ? $manageEscape($row['room_name']) . ' · ' : '' ?><?= $manageEscape($from) ?>–<?= $manageEscape($until) ?> Uhr</span>
                    <?php if (!empty($row['blocked'])) : ?>
                        <strong class="rb-series-busy"><?= $manageEscape($row['message'] ?? 'Konflikt') ?></strong>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($manageConfirm) : ?>
                <div class="rb-entry-actions">
                    <form method="post" action="<?= $manageEscape($manageUrl) ?>">
                        <input type="hidden" name="csrf_token" value="<?= $manageEscape(csrfToken()) ?>">
                        <input type="hidden" name="stage" value="apply">
                        <input type="hidden" name="preview_token" value="<?= $manageEscape($previewToken) ?>">
                        <button type="submit" class="roomplan-action<?= $action === 'cancel' ? ' roomplan-action-secondary rb-danger' : '' ?>"><?= $action === 'cancel' ? 'Kommende Termine stornieren' : 'Neue Uhrzeit bestätigen' ?></button>
                    </form>
                    <a class="rb-inline-link" href="<?= $manageEscape($manageUrl) ?>">Abbrechen</a>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!$manageConfirm) : ?>
        <details class="roomplan-panel rb-more rb-series-panel" name="rb-series-manage"<?= $action === 'edit' ? ' open' : '' ?>>
            <summary>Uhrzeit ändern</summary>
            <form method="post" action="<?= $manageEscape($manageUrl) ?>" class="rb-filter">
                <input type="hidden" name="csrf_token" value="<?= $manageEscape(csrfToken()) ?>">
                <input type="hidden" name="stage" value="preview">
                <input type="hidden" name="action" value="edit">
                <label>Von
                    <select name="start_time" required>
                        <option value="">Uhrzeit</option>
                        <?php foreach (array_slice($manageTimes, 0, -1) as $time) : ?>
                            <option value="<?= $time ?>"<?= $time === $manageStart ? ' selected' : '' ?>><?= $time ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Bis
                    <select name="end_time" required>
                        <option value="">Uhrzeit</option>
                        <?php foreach (array_slice($manageTimes, 1) as $time) : ?>
                            <option value="<?= $time ?>"<?= $time === $manageEnd ? ' selected' : '' ?>><?= $time ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="roomplan-action">Termine prüfen</button>
            </form>
        </details>
        <details class="roomplan-panel rb-more rb-series-panel" name="rb-series-manage"<?= $action === 'cancel' ? ' open' : '' ?>>
            <summary>Kommende Termine stornieren</summary>
            <form method="post" action="<?= $manageEscape($manageUrl) ?>" class="rb-entry-actions">
                <input type="hidden" name="csrf_token" value="<?= $manageEscape(csrfToken()) ?>">
                <input type="hidden" name="stage" value="preview">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="roomplan-action roomplan-action-secondary">Stornierung prüfen</button>
            </form>
        </details>
        <?php endif; ?>
        <p class="rb-empty">Laufende und vergangene Termine bleiben unverändert.</p>
    <?php endif; ?>
</main>
</body>
</html>
