<?php
declare(strict_types=1);

if (!defined('ROOMIE_ROOM_INSIGHTS_CONTROLLER')) {
    http_response_code(404);
    exit;
}

$insightsEscape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$insightsCsv = 'admin_room_insights.php?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'csv']);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Raumauswertung · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/admin/admin.css">
    <link rel="stylesheet" href="assets/admin/room-insights.css">
</head>
<body class="admin-page">
<?php $roomieActivePage = 'admin_room_insights.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="admin-main room-insights">
    <header class="admin-top insights-head">
        <h1 class="insights-title">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 4v16h16M8 16v-4m4 4V7m4 9v-6m4 6V5"/></svg>
            Raumauswertung
        </h1>
        <?php if ($report !== null && $error === null) : ?>
        <a class="admin-button" href="<?= $insightsEscape($insightsCsv) ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 4v11m-4-4 4 4 4-4M5 16v3a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3"/></svg>
            CSV herunterladen
        </a>
        <?php endif; ?>
    </header>
    <section class="admin-card" aria-label="Zeitraum">
        <form class="admin-toolbar insights-filter" method="get" action="admin_room_insights.php">
            <label class="admin-field">Von <input name="from" type="date" value="<?= $insightsEscape($from) ?>" required></label>
            <label class="admin-field">Bis <input name="to" type="date" value="<?= $insightsEscape($to) ?>" required></label>
            <button class="admin-button" type="submit">Anzeigen</button>
        </form>
        <p class="admin-note">Max. 31 Tage · Nur Buchungen, keine Anwesenheit</p>
    </section>

    <?php if ($error !== null) : ?>
    <p class="admin-error" role="alert"><?= $insightsEscape($error) ?></p>
    <?php elseif ($report !== null) : ?>
    <section class="admin-card" aria-labelledby="insights-room-heading">
        <h2 id="insights-room-heading">Buchungen je Raum</h2>
        <?php if ($report['rooms'] === []) : ?>
        <p class="admin-note">Keine Räume vorhanden.</p>
        <?php else : ?>
        <p class="admin-note insights-note">Insgesamt: <?= $insightsEscape(roomieInsightsDuration($report['booked_seconds'])) ?> gebucht</p>
        <table class="insights-table" aria-labelledby="insights-room-heading">
            <thead><tr><th scope="col">Raum</th><th scope="col">Gebuchte Zeit</th><th scope="col">Stoßzeit</th></tr></thead>
            <tbody>
            <?php foreach ($report['rooms'] as $room) : ?>
                <tr>
                    <th scope="row"><?= $insightsEscape($room['room_name']) ?></th>
                    <td><?= $insightsEscape(roomieInsightsDuration($room['booked_seconds'])) ?></td>
                    <td>
                        <?php if ($room['peak_hours'] === []) : ?>
                            <span aria-label="Keine Buchung">–</span>
                        <?php else : ?>
                            <?= $insightsEscape(implode(', ', array_map('roomieInsightsHourLabel', $room['peak_hours']))) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
