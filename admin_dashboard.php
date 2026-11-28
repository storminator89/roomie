<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_approvals.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php', true, 303);
    exit;
}

$now = new DateTimeImmutable('now', roomieBookingToday()->getTimezone());
$args = ['today' => $now->format('Y-m-d'), 'clock' => $now->format('H:i')];
$db = getDatabaseConnection();
$overview = null;
try {
    $db->beginTransaction();
    $actor = currentRoomieUser(true);
    if ($actor === null) {
        $db->rollBack();
        header('Location: login.php', true, 303);
        exit;
    }
    if ((int) $actor['is_admin'] !== 1) {
        $db->rollBack();
        http_response_code(403);
        exit('Diese Seite erfordert Administratorrechte.');
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM bookings WHERE date = ?');
    $stmt->execute([$args['today']]);
    $booked = (int) $stmt->fetchColumn();
    $pending = 0;
    $requests = [];
    if (roomieBookingApprovalsAvailable($db)) {
        // Match the pending queue, including expired entries that still need clearing.
        $pending = (int) $db->query("SELECT COUNT(*) FROM main.booking_requests q
            JOIN rooms r ON r.id = q.room_id JOIN users u ON u.id = q.user_id
            WHERE q.status = 'pending'")->fetchColumn();
        $stmt = $db->prepare("SELECT q.date, q.start_time, q.end_time, r.name AS room_name
            FROM main.booking_requests q JOIN rooms r ON r.id = q.room_id JOIN users u ON u.id = q.user_id
            WHERE q.status = 'pending' AND (q.date > :today OR (q.date = :today AND q.start_time > :clock))
            ORDER BY q.date, q.start_time, q.id LIMIT 3");
        $stmt->execute($args);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $blocked = 0;
    $blocks = [];
    if (roomieRoomBlocksTableExists($db)) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM main.room_blocks WHERE date = :today AND end_time > :clock');
        $stmt->execute($args);
        $blocked = (int) $stmt->fetchColumn();
        $stmt = $db->prepare('SELECT b.room_id, b.date, b.start_time, b.end_time, r.name AS room_name
            FROM main.room_blocks b JOIN rooms r ON r.id = b.room_id
            WHERE b.date = :today AND b.end_time > :clock ORDER BY b.start_time, b.id LIMIT 3');
        $stmt->execute($args);
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        roomieAssertStoredRoomBlockWindows($blocks);
    }
    $db->commit();
    $overview = compact('booked', 'pending', 'requests', 'blocked', 'blocks');
} catch (Throwable) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // Do not render admin navigation or data when the role could not be verified.
    if (!isset($actor) || (int) $actor['is_admin'] !== 1) {
        http_response_code(503);
        exit('Admin-Übersicht derzeit nicht verfügbar.');
    }
    http_response_code(503);
    error_log('Roomie admin dashboard data unavailable');
}

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$icon = static function (string $name) use ($e): string {
    return '<svg class="rm-area-icon" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="assets/admin/area-icons.svg#' . $e($name) . '"></use></svg>';
};
$management = [
    ['room', 'Räume verwalten', 'rooms.php'],
    ['person', 'Benutzer', 'admin_users.php'],
    ['key', 'Rechte', 'admin_permissions.php'],
    ['image', 'Raumplan', 'raumplan.php'],
    ['calendar', 'Auswertung', 'admin_room_insights.php'],
];
$setupLinks = [
    ['person', 'Entra-Abgleich', 'admin_entra.php'],
    ['key', 'MCP-Zugang', 'admin_mcp.php'],
];
$metrics = [
    ['calendar', 'Offene Freigaben', 'pending', 'admin_booking_requests.php'],
    ['room', 'Buchungen heute', 'booked', 'bookings.php?scope=all&date=' . $args['today']],
    ['lock', 'Offene Sperren heute', 'blocked', 'admin_room_blocks.php?date=' . $args['today']],
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin-Übersicht · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
    <link rel="stylesheet" href="assets/admin/area-visuals.css">
    <link rel="stylesheet" href="assets/admin/dashboard.css">
</head>
<body class="roomplan-page">
<?php $roomieActivePage = 'admin_dashboard.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="roomplan-main rb-page ad-page" id="main-content" tabindex="-1">
    <header class="ad-header">
        <div><p class="ad-date"><time datetime="<?= $e($args['today']) ?>"><?= $e($now->format('d.m.Y')) ?></time></p><h1>Admin-Übersicht</h1></div>
        <img class="rm-area-art" src="assets/illustrations/admin-requests.svg" width="96" height="64" alt="">
    </header>
    <?php if ($overview === null) : ?>
        <p class="rb-notice rb-notice-error" role="alert">Kennzahlen derzeit nicht verfügbar.</p>
    <?php else : ?>
        <section aria-label="Kennzahlen">
            <ul class="ad-metrics">
            <?php foreach ($metrics as [$glyph, $label, $key, $url]) : ?>
                <li><a class="ad-metric" href="<?= $e($url) ?>">
                    <?= $icon($glyph) ?><strong data-metric="<?= $e($key) ?>"><?= $overview[$key] ?></strong>
                    <span><?= $e($label) ?></span><span class="ad-arrow" aria-hidden="true"></span>
                </a></li>
            <?php endforeach; ?>
            </ul>
        </section>
        <div class="ad-grid ad-agenda">
            <section class="roomplan-panel ad-panel" aria-labelledby="ad-requests">
                <header><h2 id="ad-requests">Nächste Anfragen</h2><a href="admin_booking_requests.php">Alle Anfragen <span aria-hidden="true">›</span></a></header>
                <?php if (!$overview['requests']) : ?>
                    <p class="ad-empty"><?= $overview['pending'] > 0 ? 'Nur verstrichene Anfragen.' : 'Keine offenen Anfragen.' ?></p>
                <?php else : ?>
                    <ul class="ad-items">
                    <?php foreach ($overview['requests'] as $row) : ?>
                        <li><strong><?= $e($row['room_name']) ?></strong><span><time datetime="<?= $e($row['date']) ?>"><?= $e(parseBookingDate($row['date'])?->format('d.m.')) ?></time> · <?= $e($row['start_time'] . '–' . $row['end_time']) ?></span></li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <section class="roomplan-panel ad-panel" aria-labelledby="ad-blocks">
                <header><h2 id="ad-blocks">Raumsperren heute</h2><a href="<?= $e($metrics[2][3]) ?>">Verwalten <span aria-hidden="true">›</span></a></header>
                <?php if (!$overview['blocks']) : ?>
                    <p class="ad-empty">Keine laufenden oder geplanten Sperren für heute.</p>
                <?php else : ?>
                    <ul class="ad-items">
                    <?php foreach ($overview['blocks'] as $row) : ?>
                        <li><a href="<?= $e('admin_room_blocks.php?' . http_build_query(['room_id' => (int) $row['room_id'], 'date' => $row['date']])) ?>"><?= $e($row['room_name']) ?></a><span><?= $e($row['start_time'] . '–' . $row['end_time']) ?></span></li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
    <section class="ad-management" aria-labelledby="ad-manage">
        <h2 id="ad-manage">Verwalten</h2>
        <ul class="ad-grid ad-action-list">
            <?php foreach ($management as [$glyph, $label, $url]) : ?>
                <li><a href="<?= $e($url) ?>"><?= $icon($glyph) ?><span><?= $e($label) ?></span><span class="ad-arrow" aria-hidden="true"></span></a></li>
            <?php endforeach; ?>
        </ul>
        <details class="ad-settings">
            <summary>Integrationen</summary>
            <ul class="ad-grid ad-action-list">
                <?php foreach ($setupLinks as [$glyph, $label, $url]) : ?>
                    <li><a href="<?= $e($url) ?>"><?= $icon($glyph) ?><span><?= $e($label) ?></span><span class="ad-arrow" aria-hidden="true"></span></a></li>
                <?php endforeach; ?>
            </ul>
        </details>
    </section>
</main>
</body>
</html>
