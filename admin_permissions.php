<?php
require_once 'auth.php';

if (!isAdmin()) {
    header("Location: index.php");
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
}

$search = is_string($_GET['search'] ?? null) ? trim(substr($_GET['search'], 0, 255)) : '';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
function roomiePermissionsLink(array $extra = []): string
{
    global $page, $search;
    return 'admin_permissions.php?' . http_build_query(array_merge(['search' => $search, 'page' => $page], $extra));
}
function adminHtml(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$db = getDatabaseConnection();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $db->exec('BEGIN IMMEDIATE');
        $actor = currentRoomieUser(true);
        if (!$actor || (int) $actor['is_admin'] !== 1) {
            throw new DomainException('Admin-Sitzung ist nicht mehr gültig.');
        }
        if (isset($_POST['add_permission'])) {
            $userId = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $roomId = filter_var($_POST['room_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $room = $db->prepare('SELECT type FROM rooms WHERE id = ?');
            $room->execute([$roomId]);
            $type = $room->fetchColumn();
            $room->closeCursor();
            if (!$userId || !$roomId || !in_array($type, ROOMIE_RESTRICTED_ROOM_TYPES, true)) {
                throw new DomainException('Bitte einen Benutzer und einen eingeschränkten Raum auswählen.');
            }
            $permission = $db->prepare('INSERT INTO permissions (user_id, room_id)
                SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE user_id = ? AND room_id = ?)');
            $permission->execute([$userId, $roomId, $userId, $roomId]);
            $_SESSION['success'] = 'Berechtigung gespeichert.';
        } elseif (isset($_POST['remove_permission'])) {
            $id = filter_var($_POST['permission_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                throw new DomainException('Ungültige Berechtigung.');
            }
            $db->prepare('DELETE FROM permissions WHERE id = ?')->execute([$id]);
            $_SESSION['success'] = 'Berechtigung entfernt.';
        }
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $db->exec('ROLLBACK');
        } catch (PDOException) {
            // No open write transaction.
        }
        if (!$error instanceof DomainException) {
            error_log('Roomie permission write failed');
        }
        $_SESSION['error'] = $error instanceof DomainException ? $error->getMessage() : 'Berechtigung konnte nicht gespeichert werden.';
    }
    header('Location: ' . roomiePermissionsLink(), true, 303);
    exit;
}

$users = $db->query('SELECT id, name, email FROM users ORDER BY name COLLATE NOCASE, id')->fetchAll(PDO::FETCH_ASSOC);
$placeholders = implode(', ', array_fill(0, count(ROOMIE_RESTRICTED_ROOM_TYPES), '?'));
$restrictedRooms = $db->prepare("SELECT id, name, number FROM rooms WHERE type IN ($placeholders) ORDER BY name COLLATE NOCASE, id");
$restrictedRooms->execute(ROOMIE_RESTRICTED_ROOM_TYPES);
$rooms = $restrictedRooms->fetchAll(PDO::FETCH_ASSOC);
$filter = "FROM permissions p JOIN users u ON p.user_id = u.id JOIN rooms r ON p.room_id = r.id
    WHERE (:search = '' OR instr(lower(u.name), lower(:search)) > 0
      OR instr(lower(u.email), lower(:search)) > 0 OR instr(lower(r.name), lower(:search)) > 0
      OR instr(lower(r.number), lower(:search)) > 0)";
$count = $db->prepare("SELECT COUNT(*) $filter");
$count->execute(['search' => $search]);
$page = min($page, max(1, (int) ceil((int) $count->fetchColumn() / 25)));
$stmt = $db->prepare("SELECT p.id, u.name AS user_name, r.name AS room_name, r.number AS room_number $filter
    ORDER BY r.name COLLATE NOCASE, u.name COLLATE NOCASE, p.id LIMIT 26 OFFSET :offset");
$stmt->bindValue(':search', $search, PDO::PARAM_STR);
$stmt->bindValue(':offset', ($page - 1) * 25, PDO::PARAM_INT);
$stmt->execute();
$permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$hasNext = count($permissions) > 25;
$permissions = array_slice($permissions, 0, 25);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Berechtigungen · Roomie</title>
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
    <link rel="stylesheet" href="assets/admin/admin.css">
</head>
<body class="admin-page">
<?php $roomieActivePage = 'admin_permissions.php'; require __DIR__ . '/roomplan_shell.php'; ?>
<main class="admin-main">
    <div class="admin-top"><h1>Verwaltung</h1></div>
    <nav aria-label="Verwaltung">
        <ul class="admin-tabs">
            <li><a href="admin_users.php">Benutzer</a></li>
            <li><a href="admin_permissions.php" aria-current="page">Berechtigungen</a></li>
            <li><a href="admin_mcp.php">MCP-Zugang</a></li>
        </ul>
    </nav>

    <?php if (isset($_SESSION['error'])) : ?>
        <p class="admin-error" role="alert"><?= adminHtml($_SESSION['error']) ?></p>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['success'])) : ?>
        <p class="admin-ok" role="status"><?= adminHtml($_SESSION['success']) ?></p>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <section class="admin-card" aria-labelledby="add-permission">
        <h2 id="add-permission">Zugang vergeben</h2>
        <?php if ($users && $rooms) : ?>
            <form method="POST" action="<?= adminHtml(roomiePermissionsLink()) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="admin-fields">
                    <label class="admin-field">Benutzer
                        <select name="user_id" required>
                            <option value="">Benutzer wählen</option>
                            <?php foreach ($users as $user) : ?>
                                <option value="<?= (int) $user['id'] ?>"><?= adminHtml($user['name']) ?> · <?= adminHtml($user['email']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="admin-field">Raum
                        <select name="room_id" required>
                            <option value="">Raum wählen</option>
                            <?php foreach ($rooms as $room) : ?>
                                <option value="<?= (int) $room['id'] ?>"><?= adminHtml($room['name']) ?><?= $room['number'] ? ' · ' . adminHtml($room['number']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <button type="submit" name="add_permission" value="1" class="admin-button admin-primary">Zugang vergeben</button>
            </form>
        <?php else : ?>
            <p class="admin-note">Keine eingeschränkten Räume verfügbar.</p>
            <a class="admin-link" href="raumplan.php">Zum Raumplan</a>
        <?php endif; ?>
    </section>

    <section class="admin-card" aria-labelledby="permission-list">
        <h2 id="permission-list">Erteilte Zugänge</h2>
        <form method="GET" action="admin_permissions.php" class="admin-toolbar">
            <label class="admin-field">Benutzer oder Raum
                <input type="search" name="search" class="admin-search" maxlength="255" value="<?= adminHtml($search) ?>">
            </label>
            <button type="submit" class="admin-button">Suchen</button>
            <?php if ($search !== '') : ?><a href="admin_permissions.php" class="admin-button">Zurücksetzen</a><?php endif; ?>
        </form>
        <ul class="admin-list">
            <?php foreach ($permissions as $permission) : ?>
                <li>
                    <div class="admin-entry">
                        <strong><?= adminHtml($permission['user_name']) ?></strong>
                        <span class="admin-sub"><?= adminHtml($permission['room_name']) ?><?= $permission['room_number'] ? ' · ' . adminHtml($permission['room_number']) : '' ?></span>
                    </div>
                    <div class="admin-actions">
                        <form method="POST" action="<?= adminHtml(roomiePermissionsLink()) ?>">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="permission_id" value="<?= (int) $permission['id'] ?>">
                            <button type="submit" name="remove_permission" value="1" class="admin-button admin-danger"
                                    aria-label="Zugang für <?= adminHtml($permission['user_name']) ?> zu <?= adminHtml($permission['room_name']) ?> entziehen">Entziehen</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (!$permissions) : ?><p class="admin-note">Keine Zugänge gefunden.</p><?php endif; ?>
        <?php if ($page > 1 || $hasNext) : ?>
            <nav class="admin-paging" aria-label="Zugangsliste">
                <?php if ($page > 1) : ?><a class="admin-button" href="<?= adminHtml(roomiePermissionsLink(['page' => $page - 1])) ?>">Zurück</a><?php endif; ?>
                <span>Seite <?= $page ?></span>
                <?php if ($hasNext) : ?><a class="admin-button" href="<?= adminHtml(roomiePermissionsLink(['page' => $page + 1])) ?>">Weiter</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
