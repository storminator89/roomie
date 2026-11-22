<?php
require_once 'auth.php';

if (!isAdmin()) {
    header("Location: index.php");
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
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
    header('Location: admin_permissions.php', true, 303);
    exit;
}

$users = $db->query('SELECT id, name FROM users')->fetchAll(PDO::FETCH_ASSOC);
$placeholders = implode(', ', array_fill(0, count(ROOMIE_RESTRICTED_ROOM_TYPES), '?'));
$restrictedRooms = $db->prepare("SELECT id, name FROM rooms WHERE type IN ($placeholders)");
$restrictedRooms->execute(ROOMIE_RESTRICTED_ROOM_TYPES);
$rooms = $restrictedRooms->fetchAll(PDO::FETCH_ASSOC);
$permissions = $db->query("
    SELECT permissions.id, users.name AS user_name, rooms.name AS room_name 
    FROM permissions 
    JOIN users ON permissions.user_id = users.id 
    JOIN rooms ON permissions.room_id = rooms.id
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Berechtigungen verwalten</title>
    <link rel="stylesheet" href="assets/roomie-tailwind.css">
    <script defer src="assets/alpine-3.14.9.min.js"></script>
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="assets/roomplan/roomplan.css">
</head>

<body class="bg-gray-100" x-data>
    <?php $roomieActivePage = 'admin_permissions.php'; require __DIR__ . '/roomplan_shell.php'; ?>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="px-4 py-6 sm:px-0">
            <!-- Fehler- und Erfolgsnachrichten -->
            <?php if (isset($_SESSION['error'])) : ?>
                <div class="mb-4 p-4 bg-red-100 text-red-800 rounded-lg">
                    <?php echo htmlspecialchars($_SESSION['error']);
                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])) : ?>
                <div class="mb-4 p-4 bg-green-100 text-green-800 rounded-lg">
                    <?php echo htmlspecialchars($_SESSION['success']);
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <div class="bg-white shadow-lg rounded-2xl overflow-hidden border-2 border-yellow-300 mb-8">
                <div class="flex flex-row items-center justify-between p-4 bg-yellow-50 text-gray-800 border-b border-yellow-300">
                    <h2 class="text-xl font-semibold">Berechtigungen verwalten</h2>
                </div>
                <div class="p-4">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <div>
                            <label for="user_id" class="block text-sm font-medium text-gray-700"><i class="fas fa-user"></i>&nbsp;Benutzer</label>
                            <select id="user_id" name="user_id" class="w-full bg-white border border-gray-300 rounded-md p-2">
                                <?php foreach ($users as $user) : ?>
                                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="room_id" class="block text-sm font-medium text-gray-700"><i class="fas fa-door-open"></i>&nbsp;Raum</label>
                            <select id="room_id" name="room_id" class="w-full bg-white border border-gray-300 rounded-md p-2">
                                <?php foreach ($rooms as $room) : ?>
                                    <option value="<?php echo $room['id']; ?>"><?php echo htmlspecialchars($room['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" name="add_permission" class="w-full bg-yellow-400 hover:bg-yellow-500 text-white rounded-md p-2 transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-plus"></i>&nbsp;Berechtigung hinzufügen
                        </button>
                    </form>
                </div>
            </div>

            <div class="bg-white shadow-lg rounded-2xl overflow-hidden border-2 border-yellow-300">
                <div class="flex flex-row items-center justify-between p-4 bg-yellow-50 text-gray-800 border-b border-yellow-300">
                    <h2 class="text-xl font-semibold">Erteilte Berechtigungen</h2>
                </div>
                <div class="p-4">
                    <table class="min-w-full bg-white">
                        <thead>
                            <tr>
                                <th class="py-2 text-left px-4">Benutzer</th>
                                <th class="py-2 text-left px-4">Raum</th>
                                <th class="py-2 text-left px-4">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permissions as $permission) : ?>
                                <tr>
                                    <td class="border px-4 py-2"><?php echo htmlspecialchars($permission['user_name']); ?></td>
                                    <td class="border px-4 py-2"><?php echo htmlspecialchars($permission['room_name']); ?></td>
                                    <td class="border px-4 py-2">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="permission_id" value="<?php echo $permission['id']; ?>">
                                            <button type="submit" name="remove_permission" class="bg-red-500 hover:bg-red-600 text-white rounded-md px-2 py-1">
                                                <i class="fas fa-trash"></i>&nbsp;Entfernen
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($permissions)) : ?>
                                <tr>
                                    <td colspan="3" class="border px-4 py-2 text-center">Keine Berechtigungen gefunden.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</body>

</html>
