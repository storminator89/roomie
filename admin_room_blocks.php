<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_blocks.php';

header('Cache-Control: private, no-store');
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
if (!isAdmin()) {
    http_response_code(403);
    exit('Diese Seite erfordert Administratorrechte.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Methode nicht erlaubt.');
}
if ($method === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
}

const ROOMIE_BLOCK_ADMIN_KINDS = ['maintenance' => 'Wartung', 'closed' => 'Raum geschlossen'];
const ROOMIE_BLOCK_ADMIN_PAGE_SIZE = 25;

function roomieBlockAdminHtml(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function roomieBlockAdminUrl(int $roomId, string $date, int $page = 1): string
{
    return 'admin_room_blocks.php?' . http_build_query(['room_id' => $roomId, 'date' => $date, 'page' => $page]);
}

function roomieBlockAdminRoomLabel(array $room): string
{
    return $room['name'] . (($room['number'] ?? '') !== '' ? ' · ' . $room['number'] : '');
}

class RoomieBlockAdminAccessChanged extends RuntimeException {}

/** Create or remove without affecting existing reservations. */
function roomieBlockAdminMutate(PDO $db, ?int $roomId, array $input): array
{
    $action = $input['action'] ?? null;
    if (!in_array($action, ['create', 'remove'], true) || $roomId === null) {
        throw new DomainException('Bitte einen gültigen Raum und eine Aktion wählen.', 400);
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        if ((int) (currentRoomieUser(true)['is_admin'] ?? 0) !== 1) {
            throw new RoomieBlockAdminAccessChanged();
        }
        $room = $db->prepare('SELECT 1 FROM rooms WHERE id = ?');
        $room->execute([$roomId]);
        $exists = $room->fetchColumn() !== false;
        $room->closeCursor();
        if (!$exists) {
            throw new DomainException('Der Raum ist nicht mehr verfügbar.', 404);
        }

        $now = new DateTimeImmutable('now', roomieBookingToday()->getTimezone());
        if ($action === 'create') {
            $date = parseBookingDate($input['date'] ?? null);
            $kind = $input['kind'] ?? 'maintenance';
            if ($date === null || !is_string($kind) || !isset(ROOMIE_BLOCK_ADMIN_KINDS[$kind])) {
                throw new DomainException('Bitte ein gültiges Datum und eine gültige Sperrart wählen.', 422);
            }
            [$start, $end] = roomieParseRoomBlockTimes($date, $input['start_time'] ?? null, $input['end_time'] ?? null, $now);
            $day = $date->format('Y-m-d');
            if (roomieRoomHasBlock($db, $roomId, $day, $start, $end)) {
                throw new DomainException('In diesem Zeitraum gibt es bereits eine Raumsperre.', 409);
            }
            if (roomieCountBookingsBlockingRoomBlock($db, $roomId, $day, $start, $end) > 0) {
                throw new DomainException('Der Raum ist in diesem Zeitraum bereits gebucht. Es wurde keine Buchung geändert.', 409);
            }
            $stmt = $db->prepare('INSERT INTO main.room_blocks (room_id, date, start_time, end_time, kind) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$roomId, $day, $start, $end, $kind]);
            $result = [$day, 'Raumsperre gespeichert.'];
        } else {
            $blockId = roomiePositiveId($input['block_id'] ?? null);
            if ($blockId === null) {
                throw new DomainException('Ungültige Raumsperre.', 400);
            }
            $today = $now->format('Y-m-d');
            $lastDay = $now->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days')->format('Y-m-d');
            $match = $db->prepare('SELECT date FROM main.room_blocks WHERE id = ? AND room_id = ?
                AND date BETWEEN ? AND ? AND (date > ? OR end_time > ?)');
            $match->execute([$blockId, $roomId, $today, $lastDay, $today, $now->format('H:i')]);
            $day = $match->fetchColumn();
            $match->closeCursor();
            if (!is_string($day)) {
                throw new DomainException('Diese Raumsperre ist für den gewählten Raum nicht mehr verfügbar.', 404);
            }
            $delete = $db->prepare('DELETE FROM main.room_blocks WHERE id = ? AND room_id = ?');
            $delete->execute([$blockId, $roomId]);
            $result = [$day, 'Raumsperre aufgehoben.'];
        }

        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

$now = new DateTimeImmutable('now', roomieBookingToday()->getTimezone());
$today = $now->setTime(0, 0);
$lastDay = $today->modify('+' . ROOMIE_BOOKING_HORIZON_DAYS . ' days');
$requestedDate = parseBookingDate($method === 'POST' ? ($_POST['date'] ?? null) : ($_GET['date'] ?? null));
$selectedDate = $requestedDate !== null && $requestedDate >= $today && $requestedDate <= $lastDay
    ? $requestedDate
    : ($now < $today->modify(ROOMIE_BOOKING_OPEN_TIME) ? $today : $today->modify('+1 day'));
$firstSlot = $selectedDate->modify(ROOMIE_BOOKING_OPEN_TIME);
$lastSlot = $selectedDate->modify(ROOMIE_BOOKING_CLOSE_TIME);
$step = new DateInterval('PT' . ROOMIE_BOOKING_STEP_MINUTES . 'M');
$times = [];
$defaultStart = null;
for ($slot = $firstSlot; $slot <= $lastSlot; $slot = $slot->add($step)) {
    $times[] = $slot->format('H:i');
    if ($defaultStart === null && $slot > $now && $slot < $lastSlot) {
        $defaultStart = $slot->format('H:i');
    }
}
if ($defaultStart === null) {
    $selectedDate = $today->modify('+1 day');
    $defaultStart = ROOMIE_BOOKING_OPEN_TIME;
}
$enteredStart = $_POST['start_time'] ?? null;
$enteredEnd = $_POST['end_time'] ?? null;
$enteredKind = $_POST['kind'] ?? null;
$startValue = in_array($enteredStart, array_slice($times, 0, -1), true) ? $enteredStart : $defaultStart;
$endValue = in_array($enteredEnd, array_slice($times, 1), true) ? $enteredEnd : ROOMIE_BOOKING_CLOSE_TIME;
$kindValue = is_string($enteredKind) && isset(ROOMIE_BLOCK_ADMIN_KINDS[$enteredKind]) ? $enteredKind : 'maintenance';
$rawRoomId = $method === 'POST' ? ($_POST['room_id'] ?? null) : ($_GET['room_id'] ?? null);
$roomId = roomiePositiveId($rawRoomId);
$page = roomiePositiveId($_GET['page'] ?? null) ?? 1;
$rooms = $blocks = [];
$selectedRoom = null;
$hasNext = false;
$readFailed = false;
$error = null;

try {
    $db = getDatabaseConnection();
    if ($method === 'POST') {
        try {
            [$messageDay, $message] = roomieBlockAdminMutate($db, $roomId, $_POST);
            $_SESSION['admin_room_block_status'] = ['room_id' => $roomId, 'message' => $message];
            header('Location: ' . roomieBlockAdminUrl($roomId, $messageDay), true, 303);
            exit;
        } catch (RoomieBlockAdminAccessChanged) {
            http_response_code(403);
            exit('Diese Aktion erfordert Administratorrechte.');
        } catch (DomainException $exception) {
            http_response_code(in_array($exception->getCode(), [400, 404, 409, 422], true) ? $exception->getCode() : 422);
            $error = $exception->getMessage();
        }
    }

    $rooms = $db->query('SELECT id, name, number FROM rooms ORDER BY name COLLATE NOCASE, id')->fetchAll(PDO::FETCH_ASSOC);
    if ($rawRoomId === null && $rooms) {
        $roomId = (int) $rooms[0]['id'];
    }
    foreach ($rooms as $room) {
        if ((int) $room['id'] === $roomId) {
            $selectedRoom = $room;
            break;
        }
    }
    if ($rawRoomId !== null && !$selectedRoom && $error === null) {
        http_response_code(404);
        $error = 'Der gewählte Raum ist nicht verfügbar.';
    }

    if ($selectedRoom !== null) {
        $where = ' WHERE room_id = :room AND date BETWEEN :today AND :last AND (date > :today OR end_time > :time)';
        $params = ['room' => $roomId, 'today' => $today->format('Y-m-d'), 'last' => $lastDay->format('Y-m-d'), 'time' => $now->format('H:i')];
        $count = $db->prepare('SELECT COUNT(*) FROM main.room_blocks' . $where);
        $count->execute($params);
        $page = min($page, max(1, (int) ceil((int) $count->fetchColumn() / ROOMIE_BLOCK_ADMIN_PAGE_SIZE)));
        $count->closeCursor();
        $stmt = $db->prepare('SELECT id, date, start_time, end_time, kind FROM main.room_blocks' . $where
            . ' ORDER BY date, start_time, id LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', ROOMIE_BLOCK_ADMIN_PAGE_SIZE + 1, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * ROOMIE_BLOCK_ADMIN_PAGE_SIZE, PDO::PARAM_INT);
        $stmt->execute();
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasNext = count($blocks) > ROOMIE_BLOCK_ADMIN_PAGE_SIZE;
        $blocks = array_slice($blocks, 0, ROOMIE_BLOCK_ADMIN_PAGE_SIZE);
    }
} catch (PDOException | UnexpectedValueException) {
    error_log('Roomie admin room block operation failed');
    http_response_code(503);
    $error = 'Die Raumsperren sind gerade nicht verfügbar. Bitte später erneut versuchen.';
    $readFailed = true;
}
$csrf = csrfToken();
$day = $selectedDate->format('Y-m-d');
$status = $_SESSION['admin_room_block_status'] ?? null;
if ($status !== null && ($status['room_id'] ?? null) === $roomId) {
    unset($_SESSION['admin_room_block_status']);
} else {
    $status = null;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Raumsperren · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/admin/admin.css">
    <link rel="stylesheet" href="assets/admin/area-visuals.css">
</head>
<body class="admin-page">
<?php $roomieActivePage = 'admin_room_blocks.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="admin-main">
    <div class="admin-top rm-area-head"><h1>Raumsperren</h1><img class="rm-area-art" src="assets/illustrations/admin-closed.svg" width="96" height="64" alt=""></div>
    <nav aria-label="Verwaltung">
        <ul class="admin-tabs">
            <li><a href="admin_users.php"><span class="rm-area-label"><svg class="rm-area-icon" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="assets/admin/area-icons.svg#person"></use></svg><span>Benutzer</span></span></a></li>
            <li><a href="admin_permissions.php"><span class="rm-area-label"><svg class="rm-area-icon" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="assets/admin/area-icons.svg#key"></use></svg><span>Berechtigungen</span></span></a></li>
            <li><a href="admin_room_blocks.php" aria-current="page"><span class="rm-area-label"><svg class="rm-area-icon" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="assets/admin/area-icons.svg#lock"></use></svg><span>Raumsperren</span></span></a></li>
        </ul>
    </nav>
    <?php if ($error !== null) : ?>
        <p class="admin-error" role="alert"><?= roomieBlockAdminHtml($error) ?></p>
    <?php endif; ?>
    <?php if ($status !== null) : ?>
        <p class="admin-ok" role="status"><?= roomieBlockAdminHtml($status['message']) ?></p>
    <?php endif; ?>

    <?php if ($rooms) : ?>
        <section class="admin-card" aria-labelledby="choose-room-title">
            <h2 id="choose-room-title"><span class="rm-area-label"><svg class="rm-area-icon" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="assets/admin/area-icons.svg#room"></use></svg><span>Raum auswählen</span></span></h2>
            <form method="get" action="admin_room_blocks.php" class="admin-toolbar">
                <input type="hidden" name="date" value="<?= roomieBlockAdminHtml($day) ?>">
                <label class="admin-field">Raum
                    <select name="room_id" required>
                        <?php if ($selectedRoom === null) : ?><option value="" selected disabled>Bitte wählen</option><?php endif; ?>
                        <?php foreach ($rooms as $room) : ?>
                            <option value="<?= (int) $room['id'] ?>" <?= (int) $room['id'] === $roomId ? 'selected' : '' ?>><?= roomieBlockAdminHtml(roomieBlockAdminRoomLabel($room)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="admin-button" type="submit">Raum anzeigen</button>
                <?php if ($selectedRoom !== null) : ?>
                    <a class="admin-button" href="<?= roomieBlockAdminHtml('room_details.php?' . http_build_query(['id' => $roomId, 'date' => $day])) ?>">Zu den Raumdetails</a>
                <?php endif; ?>
            </form>
        </section>
    <?php elseif (!$readFailed) : ?>
        <p class="admin-note">Es sind noch keine Räume angelegt.</p>
    <?php endif; ?>

    <?php if ($selectedRoom !== null && !$readFailed) : ?>
        <section class="admin-card" aria-labelledby="add-block-title">
            <h2 id="add-block-title">Sperre anlegen: <?= roomieBlockAdminHtml(roomieBlockAdminRoomLabel($selectedRoom)) ?></h2>
            <p class="admin-note" id="room-block-time-hint">Ganztägig sperren: 09:00 bis 17:00 Uhr wählen. Sperren sind bis zu <?= ROOMIE_BOOKING_HORIZON_DAYS ?> Tage im Voraus möglich. Bestehende Buchungen werden nicht geändert.</p>
            <form method="post" action="<?= roomieBlockAdminHtml(roomieBlockAdminUrl($roomId, $day)) ?>" aria-describedby="room-block-time-hint">
                <input type="hidden" name="csrf_token" value="<?= roomieBlockAdminHtml($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="room_id" value="<?= $roomId ?>">
                <div class="admin-fields">
                    <label class="admin-field">Datum
                        <input type="date" name="date" required value="<?= roomieBlockAdminHtml($day) ?>" min="<?= $today->format('Y-m-d') ?>" max="<?= $lastDay->format('Y-m-d') ?>">
                    </label>
                    <label class="admin-field">Von (Uhr)<select name="start_time" required>
                        <?php foreach (array_slice($times, 0, -1) as $time) : ?>
                            <option value="<?= $time ?>" <?= $time === $startValue ? 'selected' : '' ?>><?= $time ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label class="admin-field">Bis (Uhr)<select name="end_time" required>
                        <?php foreach (array_slice($times, 1) as $time) : ?>
                            <option value="<?= $time ?>" <?= $time === $endValue ? 'selected' : '' ?>><?= $time ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label class="admin-field">Sperrart<select name="kind">
                        <?php foreach (ROOMIE_BLOCK_ADMIN_KINDS as $key => $label) : ?>
                            <option value="<?= $key ?>" <?= $key === $kindValue ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select></label>
                </div>
                <button class="admin-button admin-primary" type="submit">Raum sperren</button>
            </form>
        </section>

        <section class="admin-card" aria-labelledby="active-blocks-title">
            <h2 id="active-blocks-title">Laufende und anstehende Sperren</h2>
            <?php if (!$blocks) : ?>
                <p class="admin-note">Für diesen Raum sind keine Sperren eingetragen.</p>
            <?php else : ?>
                <ul class="admin-list">
                    <?php foreach ($blocks as $block) : ?>
                        <?php $period = (parseBookingDate($block['date'])?->format('d.m.Y') ?? $block['date'])
                            . ', ' . $block['start_time'] . ' bis ' . $block['end_time'] . ' Uhr'; ?>
                        <li>
                            <div class="admin-entry">
                                <strong><?= roomieBlockAdminHtml($period) ?></strong>
                                <span class="admin-sub"><?= roomieBlockAdminHtml(ROOMIE_BLOCK_ADMIN_KINDS[$block['kind']] ?? 'Gesperrt') ?></span>
                            </div>
                            <form method="post" action="<?= roomieBlockAdminHtml(roomieBlockAdminUrl($roomId, $day, $page)) ?>">
                                <input type="hidden" name="csrf_token" value="<?= roomieBlockAdminHtml($csrf) ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="room_id" value="<?= $roomId ?>">
                                <input type="hidden" name="block_id" value="<?= (int) $block['id'] ?>">
                                <button type="submit" class="admin-button" aria-label="<?= roomieBlockAdminHtml('Sperre aufheben: ' . $period) ?>">Sperre aufheben</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($page > 1 || $hasNext) : ?>
                    <nav class="admin-paging" aria-label="Seiten der Raumsperren">
                        <?php if ($page > 1) : ?><a class="admin-button" href="<?= roomieBlockAdminHtml(roomieBlockAdminUrl($roomId, $day, $page - 1)) ?>">Zurück</a><?php endif; ?>
                        <span>Seite <?= $page ?></span>
                        <?php if ($hasNext) : ?><a class="admin-button" href="<?= roomieBlockAdminHtml(roomieBlockAdminUrl($roomId, $day, $page + 1)) ?>">Weiter</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
