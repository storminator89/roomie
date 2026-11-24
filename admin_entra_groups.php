<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/entra_sync.php';
require_once __DIR__ . '/entra_graph.php';
require_once __DIR__ . '/entra_groups.php';

header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
$actor = currentRoomieUser();
if (!$actor || (int)$actor['is_admin'] !== 1) {
    http_response_code($actor ? 403 : 401);
    exit('Kein Zugriff.');
}
$actorId = (int)$actor['id'];
$authorize = static function () use ($actorId): bool {
    $current = currentRoomieUser(true);
    return $current !== null && (int)$current['id'] === $actorId && (int)$current['is_admin'] === 1;
};

function entraGroupEscape(string|int $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function entraGroupId(mixed $value): ?int
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    return $id !== false ? $id : null;
}
function entraGroupText(mixed $value): string
{
    return is_string($value) ? trim($value) : '';
}
function entraGroupRoomProof(array $groupIds): string
{
    $ids = array_map('intval', $groupIds);
    sort($ids, SORT_NUMERIC);
    return hash('sha256', implode(',', $ids));
}
function entraGroupIsFresh(array $group, ?array $settings, int $now): bool
{
    $at = $group['synced_at'];
    return $settings !== null && $settings['has_secret'] && $settings['enabled']
        && $group['tenant_id'] === $settings['tenant_id']
        && $at !== null && $at <= $now && $at >= $now - ROOMIE_ENTRA_GROUP_MAX_AGE;
}
function entraGroupPage(?int $room = null): string
{
    return 'admin_entra_groups.php' . ($room === null ? '' : '?room=' . $room . '#room-rule');
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Methode nicht erlaubt.');
}
$db = getDatabaseConnection();
$settings = roomieEntraSettings($db);
$notice = $_SESSION['roomie_entra_group_notice'] ?? null;
unset($_SESSION['roomie_entra_group_notice']);
$error = null;
$selectedRoom = entraGroupId($_GET['room'] ?? null);
if ($method === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = entraGroupText($_POST['action'] ?? null);
    $selectedRoom = entraGroupId($_POST['room_id'] ?? null) ?? $selectedRoom;
    try {
        if ($action === 'add' && $settings && $settings['has_secret']) {
            roomieEntraGroupAdd($db, $settings, entraGroupText($_POST['object_id'] ?? null), entraGroupText($_POST['label'] ?? null), $authorize);
            $notice = 'Gruppe hinzugefügt. Jetzt synchronisieren, bevor du sie einem Raum zuweist.';
        } elseif ($action === 'refresh' && $settings && $settings['enabled'] && $settings['has_secret']) {
            $result = roomieEntraGroupRefresh($db, $settings, roomieEntraGroupGraphFetcher(), $authorize);
            $notice = count($result) . ' Gruppen synchronisiert.';
        } elseif ($action === 'remove' && ($groupId = entraGroupId($_POST['group_id'] ?? null)) !== null) {
            roomieEntraGroupRemove($db, $groupId, $authorize);
            $notice = 'Gruppe entfernt.';
        } elseif (in_array($action, ['save_room', 'clear_room'], true) && $selectedRoom !== null) {
            $ids = [];
            if ($action === 'save_room') {
                $raw = $_POST['groups'] ?? null;
                if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > ROOMIE_ENTRA_GROUP_LIMIT) {
                    throw new DomainException('Wähle mindestens eine Gruppe.');
                }
                foreach ($raw as $value) {
                    $ids[] = entraGroupId($value) ?? throw new DomainException('Ungültige Gruppe.');
                }
            } elseif (($_POST['confirm_clear'] ?? null) !== '1') {
                throw new DomainException('Bestätige zuerst die Rückkehr zu den bisherigen Raumrechten.');
            }
            $proof = entraGroupText($_POST['rule_proof'] ?? null);
            $confirmCurrentRule = static function () use ($authorize, $db, $selectedRoom, $proof, $ids): bool {
                if (!$authorize()) return false;
                $existing = roomieEntraRoomGroupIds($db, $selectedRoom);
                if (!hash_equals(entraGroupRoomProof($existing), $proof)) {
                    throw new DomainException('Die Raumregel wurde geändert. Prüfe die aktuelle Auswahl.');
                }
                $added = array_diff($ids, $existing);
                if ($added) {
                    $config = roomieEntraSettings($db);
                    $eligible = array_column(array_filter(roomieEntraGroupList($db),
                        static fn(array $group): bool => entraGroupIsFresh($group, $config, time())), 'id');
                    if (array_diff($added, $eligible)) {
                        throw new DomainException('Aktualisiere die Gruppe, bevor du sie einem Raum zuweist.');
                    }
                }
                return true;
            };
            roomieEntraRoomGroupsSave($db, $selectedRoom, $ids, $confirmCurrentRule);
            $notice = $ids ? 'Buchungsregel gespeichert.' : 'Bisherige Raumrechte wiederhergestellt.';
        } else {
            throw new DomainException('Diese Aktion ist derzeit nicht verfügbar.');
        }
        $_SESSION['roomie_entra_group_notice'] = $notice;
        header('Location: ' . entraGroupPage($selectedRoom), true, 303);
        exit;
    } catch (DomainException|RoomieEntraSyncException|RoomieEntraGraphException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (Throwable) {
        http_response_code(503);
        $error = 'Änderung konnte nicht ausgeführt werden.';
        error_log('Roomie Entra booking group admin operation failed');
    }
}
$groups = roomieEntraGroupList($db);
$rules = roomieEntraGroupSchemaExists($db)
    ? $db->query('SELECT group_id, COUNT(*) AS rooms FROM roomie_entra_room_groups GROUP BY group_id')->fetchAll(PDO::FETCH_KEY_PAIR)
    : [];
$rooms = $db->query('SELECT id, name, number FROM rooms ORDER BY name COLLATE NOCASE, id')->fetchAll(PDO::FETCH_ASSOC);
$selected = null;
foreach ($rooms as $room) {
    if ((int)$room['id'] === $selectedRoom) {
        $selected = $room;
        break;
    }
}
$chosen = $selected === null ? [] : array_map('intval', roomieEntraRoomGroupIds($db, $selectedRoom));
$roomProof = entraGroupRoomProof($chosen);
$connected = $settings && $settings['has_secret'];
$enabled = $connected && $settings['enabled'];
$now = time();
$recent = static fn(array $group): bool => entraGroupIsFresh($group, $settings, $now);
$roomLabel = static fn(array $room): string => $room['name'] . ($room['number'] !== '' ? ' · ' . $room['number'] : '');
function entraGroupValue(string $name, string|int $value): void
{
    ?><input type="hidden" name="<?= entraGroupEscape($name) ?>" value="<?= entraGroupEscape($value) ?>"><?php
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entra-Raumrechte · Roomie</title>
    <?php require __DIR__ . '/roomie_brand_head.php'; ?>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/admin/admin.css">
    <link rel="stylesheet" href="assets/admin/entra-groups.css">
</head>
<body class="admin-page">
<?php $roomieActivePage = 'admin_entra.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="admin-main eg-main">
    <header class="admin-top eg-top">
        <div><p class="eg-kicker">Microsoft Entra</p><h1>Entra-Raumrechte</h1></div>
        <span class="eg-pill <?= $enabled ? 'eg-pill-ready' : 'eg-pill-muted' ?>"><?= $enabled ? 'Abgleich aktiv' : 'Abgleich aus' ?></span>
    </header>
    <nav aria-label="Entra-Verwaltung"><ul class="admin-tabs eg-tabs">
        <li><a href="admin_entra.php">Benutzerabgleich</a></li>
        <li><a href="admin_entra_groups.php" aria-current="page">Gruppen &amp; Räume</a></li>
    </ul></nav>
    <?php if ($notice !== null) : ?><p class="admin-ok" role="status"><?= entraGroupEscape($notice) ?></p><?php endif; ?>
    <?php if ($error !== null) : ?><p class="admin-error" role="alert"><?= entraGroupEscape($error) ?></p><?php endif; ?>

    <?php if ($groups && !$enabled && array_sum($rules) > 0) : ?><p class="eg-help eg-warning">Räume mit Gruppenregel bleiben ohne aktiven Abgleich gesperrt.</p><?php endif; ?>

    <div class="eg-grid">
        <section class="admin-card eg-card" aria-labelledby="group-list">
            <div class="eg-card-header">
                <h2 id="group-list">Entra-Gruppen <span class="eg-counter"><?= count($groups) ?></span></h2>
                <?php if ($enabled && $groups) : ?><form method="post">
                    <?php entraGroupValue('csrf_token', csrfToken()); if ($selectedRoom !== null) entraGroupValue('room_id', $selectedRoom); ?>
                    <button class="admin-button" type="submit" name="action" value="refresh">Aktualisieren</button>
                </form><?php endif; ?>
            </div>
            <?php if (!$connected) : ?>
            <p class="eg-help">Für Gruppen zuerst <a class="admin-link" href="admin_entra.php">Entra verbinden</a>.</p>
            <?php elseif (!$enabled) : ?>
            <p class="eg-help">Zum Aktualisieren den <a class="admin-link" href="admin_entra.php">Entra-Abgleich aktivieren</a>.</p>
            <?php endif; ?>
            <?php if ($groups) : ?>
            <ul class="eg-groups">
                <?php foreach ($groups as $group) : $id = (int)$group['id']; $used = (int)($rules[$id] ?? 0); $fresh = $recent($group); ?>
                <li>
                    <div class="eg-item-title">
                        <strong><?= entraGroupEscape($group['label']) ?></strong>
                        <span class="eg-pill <?= $fresh ? 'eg-pill-ready' : 'eg-pill-muted' ?>"><?= $fresh ? 'Aktuell' : ($group['synced_at'] === null ? 'Nicht synchronisiert' : ($enabled ? 'Abgelaufen' : 'Pausiert')) ?></span>
                    </div>
                    <p class="eg-meta"><?= (int)$group['members'] ?> zugeordnete <?= (int)$group['members'] === 1 ? 'Person' : 'Personen' ?> · <?= $used ?> <?= $used === 1 ? 'Raum' : 'Räume' ?></p>
                    <details class="eg-group-details"><summary>Gruppendetails</summary>
                        <p class="eg-meta">Objekt-ID: <code><?= entraGroupEscape($group['object_id']) ?></code></p>
                        <?php if ($group['synced_at'] !== null) : ?><p class="eg-meta">Stand: <?= gmdate('d.m.Y, H:i', (int)$group['synced_at']) ?> UTC</p><?php endif; ?>
                        <?php if ($used === 0) : ?><form method="post">
                            <?php entraGroupValue('csrf_token', csrfToken()); entraGroupValue('group_id', $id); if ($selectedRoom !== null) entraGroupValue('room_id', $selectedRoom); ?>
                            <button class="admin-button admin-danger" type="submit" name="action" value="remove">Gruppe entfernen</button>
                        </form><?php endif; ?>
                    </details>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php elseif ($connected) : ?><p class="eg-help">Noch keine Gruppen hinzugefügt.</p><?php endif; ?>
            <?php if ($connected && count($groups) < ROOMIE_ENTRA_GROUP_LIMIT) : ?>
            <details class="eg-add"<?= !$groups ? ' open' : '' ?>>
                <summary>Gruppe hinzufügen</summary>
                <form method="post">
                    <?php entraGroupValue('csrf_token', csrfToken()); if ($selectedRoom !== null) entraGroupValue('room_id', $selectedRoom); ?>
                    <div class="eg-fields">
                        <label class="admin-field">Name in Roomie<input type="text" name="label" maxlength="100" placeholder="z. B. IT-Team" required></label>
                        <label class="admin-field">Entra-Gruppenobjekt-ID<input type="text" name="object_id" maxlength="36" spellcheck="false" required></label>
                    </div>
                    <button class="admin-button" type="submit" name="action" value="add">Hinzufügen</button>
                </form>
            </details>
            <?php endif; ?>
        </section>

        <section class="admin-card eg-card" id="room-rule" aria-labelledby="room-rule-title">
            <div class="eg-card-header"><h2 id="room-rule-title">Wer darf buchen?</h2></div>
            <?php if (!$rooms) : ?><p class="eg-help">Lege zuerst einen Raum an.</p><?php else : ?>
            <form method="get" action="admin_entra_groups.php#room-rule" class="eg-room-picker">
                <label class="admin-field">Raum<select name="room" required>
                    <option value="">Raum auswählen</option>
                    <?php foreach ($rooms as $room) : $id = (int)$room['id']; ?>
                    <option value="<?= $id ?>"<?= $selectedRoom === $id ? ' selected' : '' ?>><?= entraGroupEscape($roomLabel($room)) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <button class="admin-button" type="submit">Anzeigen</button>
            </form>
            <?php endif; ?>
            <?php if ($selected !== null) : ?>
            <div class="eg-room-status">
                <strong><?= entraGroupEscape($roomLabel($selected)) ?></strong>
                <span class="eg-pill <?= $chosen ? 'eg-pill-rule' : 'eg-pill-muted' ?>"><?= $chosen ? count($chosen) . (count($chosen) === 1 ? ' Gruppe' : ' Gruppen') : 'Wie bisher' ?></span>
            </div>
            <?php if ($groups) : ?>
                <form method="post">
                    <?php entraGroupValue('csrf_token', csrfToken()); entraGroupValue('room_id', $selectedRoom); entraGroupValue('rule_proof', $roomProof); ?>
                    <fieldset class="eg-choice-list">
                        <legend>Erlaubte Gruppen</legend>
                        <?php foreach ($groups as $group) : $id = (int)$group['id']; $checked = in_array($id, $chosen, true); $fresh = $recent($group); ?>
                        <label class="eg-choice">
                            <input type="checkbox" name="groups[]" value="<?= $id ?>"<?= $checked ? ' checked' : '' ?><?= !$fresh && !$checked ? ' disabled' : '' ?>>
                            <span><?= entraGroupEscape($group['label']) ?></span>
                            <?php if (!$fresh) : ?><small>Nicht aktuell</small><?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="eg-help">Eine der gewählten Gruppen genügt. Bestehende Raumrechte gelten zusätzlich.</p>
                    <button class="admin-button admin-primary" type="submit" name="action" value="save_room">Buchungsregel speichern</button>
                </form>
            <?php else : ?><p class="eg-help">Füge links eine Gruppe hinzu und synchronisiere sie.</p><?php endif; ?>
            <?php if ($chosen) : ?>
                <details class="eg-reset"><summary>Wie bisher buchen lassen</summary>
                    <form method="post">
                        <?php entraGroupValue('csrf_token', csrfToken()); entraGroupValue('room_id', $selectedRoom); entraGroupValue('rule_proof', $roomProof); ?>
                        <label class="admin-check"><input type="checkbox" name="confirm_clear" value="1" required> Gruppenauflage entfernen</label>
                        <button class="admin-button" type="submit" name="action" value="clear_room">Bisherige Rechte wiederherstellen</button>
                    </form>
                </details>
            <?php endif; ?>
            <?php else : ?><p class="eg-help">Wähle einen Raum, um seine Buchungsregel zu ändern.</p><?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
