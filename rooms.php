<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_access.php';
require_once __DIR__ . '/room_visual.php';
require_once __DIR__ . '/room_photo_store.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
header('Cache-Control: private, no-store');

function roomsEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function roomsListUrl(string $query, bool $onlyFavorites, string $date): string
{
    $params = [];
    if ($query !== '') $params['q'] = $query;
    if ($onlyFavorites) $params['favorites'] = '1';
    if ($date !== '') $params['date'] = $date;
    return 'rooms.php' . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}

function roomsTypeLabel(string $type): string
{
    return match ($type) {
        'shared-desk' => 'Shared Desk',
        'fk-office', 'fk-buro' => 'FK-Büro',
        'spez-abt-buero', 'spez-abt-buro' => 'Abteilungsbüro',
        'office' => 'Büro',
        'open-office' => 'Großraumbüro',
        'meeting' => 'Besprechungsraum',
        'mediaroom' => 'Mediaraum',
        default => 'Sonstiger Raum',
    };
}

$q = $_GET['q'] ?? '';
$q = is_string($q) && strlen($q) <= 120 && preg_match('/\A[^\p{C}]*\z/u', $q) === 1 ? trim($q) : '';
$onlyFavorites = ($_GET['favorites'] ?? null) === '1';
$date = parseBookingDate($_GET['date'] ?? null)?->format('Y-m-d') ?? '';
$replyUrl = roomsListUrl($q, $onlyFavorites, $date);
$userId = (int) $_SESSION['user_id'];
$admin = isAdmin();
$rooms = [];
$photoSet = [];
$error = $_SESSION['roomie_rooms_error'] ?? null;
$notice = $_SESSION['roomie_rooms_notice'] ?? null;
unset($_SESSION['roomie_rooms_error'], $_SESSION['roomie_rooms_notice']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    try {
        $favorited = roomieToggleFavorite(getDatabaseConnection(), $userId, $admin, $_POST['favorite_room_id'] ?? null);
        $_SESSION['roomie_rooms_notice'] = $favorited ? 'Zu Favoriten hinzugefügt.' : 'Aus Favoriten entfernt.';
    } catch (Throwable $exception) {
        error_log('Roomie room favorite failed');
        $_SESSION['roomie_rooms_error'] = 'Favorit konnte nicht geändert werden.';
    }
    header('Location: ' . $replyUrl, true, 303);
    exit;
}

try {
    $db = getDatabaseConnection();
    // Restrict to visible rooms before applying viewer-controlled filters.
    $visible = roomieListRooms($db, $userId, $admin);
    $photoSet = array_fill_keys(roomiePhotoIds($db), true);
    $favoriteSet = array_fill_keys(roomieFavoriteIds($db, $userId), true);
    $terms = roomieRoomSearchTokens($q);
    foreach ($visible as $room) {
        $isFavorite = isset($favoriteSet[(int) $room['id']]);
        if ($onlyFavorites && !$isFavorite) continue;
        if (!roomieRoomMatchesSearch($terms, (string) $room['name'], (string) ($room['number'] ?? ''))) continue;
        $room['viewer_favorite'] = $isFavorite;
        $rooms[] = $room;
    }
    usort($rooms, static fn(array $a, array $b): int =>
        ($b['viewer_favorite'] <=> $a['viewer_favorite']) ?: strnatcasecmp($a['name'], $b['name']));
} catch (Throwable $exception) {
    error_log('Roomie room overview failed');
    $error = 'Raumübersicht nicht verfügbar.';
}

$planUrl = 'raumplan.php' . ($date !== '' ? '?date=' . rawurlencode($date) : '');
$equipmentLabels = [
    'wifi' => 'WLAN', 'projector' => 'Beamer', 'whiteboard' => 'Whiteboard',
    'desk' => 'Schreibtisch', 'monitor' => 'Monitor', 'phone' => 'Telefon', 'dock' => 'Dockingstation',
    'display' => 'Display', 'video-conference' => 'Videokonferenz',
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Räume · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/bookings/booking-pages.css">
    <link rel="stylesheet" href="assets/rooms/rooms.css">
    <link rel="stylesheet" href="assets/rooms/room-visual.css">
</head>
<body class="roomplan-page">
<?php $roomieActivePage = 'rooms.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="roomplan-main rb-page" tabindex="-1">
    <header class="rb-header">
        <h1>Räume</h1>
        <div class="rp-floor-actions">
            <?php if (isAdmin()) : ?>
                <a class="roomplan-action roomplan-action-secondary" href="<?= roomsEscape($planUrl . (str_contains($planUrl, '?') ? '&' : '?') . 'manage=1') ?>">Räume verwalten</a>
            <?php endif; ?>
            <a class="roomplan-action roomplan-action-secondary" href="<?= roomsEscape($planUrl) ?>">Raumplan</a>
        </div>
    </header>
    <nav class="rb-segments" aria-label="Raumauswahl">
        <a href="<?= roomsEscape(roomsListUrl($q, false, $date)) ?>"<?= !$onlyFavorites ? ' aria-current="page"' : '' ?>>Alle Räume</a>
        <a href="<?= roomsEscape(roomsListUrl($q, true, $date)) ?>"<?= $onlyFavorites ? ' aria-current="page"' : '' ?>>Favoriten</a>
    </nav>
    <form action="rooms.php" method="get" class="rb-filter">
        <?php if ($onlyFavorites) : ?><input type="hidden" name="favorites" value="1"><?php endif; ?>
        <?php if ($date !== '') : ?><input type="hidden" name="date" value="<?= roomsEscape($date) ?>"><?php endif; ?>
        <label>Raum suchen<input type="search" name="q" value="<?= roomsEscape($q) ?>" placeholder="Name oder Nummer" maxlength="120"></label>
        <button class="roomplan-action roomplan-action-secondary" type="submit"><svg class="rr-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="5.5"/><path d="m15 15 4 4"/></svg>Suchen</button>
        <?php if ($q !== '') : ?><a class="rb-inline-link" href="<?= roomsEscape(roomsListUrl('', $onlyFavorites, $date)) ?>">Suche zurücksetzen</a><?php endif; ?>
    </form>

    <?php if (is_string($notice)) : ?><p class="rb-notice" role="status"><?= roomsEscape($notice) ?></p><?php endif; ?>
    <?php if (is_string($error)) : ?><p class="rb-notice rb-notice-error" role="alert"><?= roomsEscape($error) ?></p><?php endif; ?>
    <?php if (!$rooms && !is_string($error)) : ?>
        <p class="roomplan-panel rb-empty rr-empty"><img src="assets/illustrations/room-finder.svg" width="80" height="64" alt=""><span><?=
            $q !== '' ? 'Keine Räume für diese Suche.'
            : ($onlyFavorites ? 'Du hast noch keine Favoriten.' : 'Keine Räume verfügbar.') ?></span></p>
    <?php elseif ($rooms) : ?>
        <ul class="rr-rooms">
        <?php foreach ($rooms as $room) :
            $name = (string) $room['name'];
            $number = (string) ($room['number'] ?? '');
            $capacity = max(0, (int) $room['capacity']);
            $detailsUrl = 'room_details.php?' . http_build_query(
                ['id' => (int) $room['id']] + ($date !== '' ? ['date' => $date] : []), '', '&', PHP_QUERY_RFC3986
            );
            $equipment = json_decode((string) $room['equipment'], true);
            $equipment = is_array($equipment) ? array_slice(array_unique(array_filter($equipment, 'is_string')), 0, 8) : [];
        ?>
            <li class="roomplan-panel rr-card">
                <?php roomieRenderVisual($room, $equipment, isset($photoSet[(int) $room['id']])); ?>
                <div class="rr-card-heading">
                    <h2><a class="rb-inline-link" href="<?= roomsEscape($detailsUrl) ?>"><?= roomsEscape($name) ?></a></h2>
                    <form action="<?= roomsEscape($replyUrl) ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?= roomsEscape(csrfToken()) ?>">
                        <input type="hidden" name="favorite_room_id" value="<?= (int) $room['id'] ?>">
                        <button class="roomplan-action roomplan-action-secondary rr-star" type="submit"
                                aria-label="Favorit: <?= roomsEscape($name) ?>"
                                aria-pressed="<?= $room['viewer_favorite'] ? 'true' : 'false' ?>">
                            <span aria-hidden="true"><?= $room['viewer_favorite'] ? '★' : '☆' ?></span>
                        </button>
                    </form>
                </div>
                <div class="rb-tags">
                    <?php if ($number !== '') : ?><span>Nr. <?= roomsEscape($number) ?></span><?php endif; ?>
                    <span><?= roomsEscape(roomsTypeLabel((string) $room['type'])) ?></span>
                    <span class="rr-capacity"><svg class="rr-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="7" r="3"/><path d="M6 19v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/></svg><?= $capacity === 1 ? '1 Platz' : $capacity . ' Plätze' ?></span>
                </div>
                <?php if ($equipment) : ?>
                <p class="rb-empty rr-equipment"><?= roomsEscape(implode(' · ', array_map(
                    static fn(string $item): string => $equipmentLabels[$item] ?? $item,
                    $equipment
                ))) ?></p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>
