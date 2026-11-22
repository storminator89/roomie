<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_admin_input.php';

$data = roomieAdminJson();
$rows = $data['rows'] ?? null;
$cols = $data['cols'] ?? null;
$layout = $data['layout'] ?? null;
if (!is_int($rows) || !is_int($cols) || $rows < 1 || $rows > 20 || $cols < 1 || $cols > 20
    || !is_array($layout) || !array_is_list($layout) || count($layout) !== $rows * $cols) {
    roomieAdminError('Ungültige Abmessungen oder Anordnung.', 422);
}
$ids = [];
foreach ($layout as &$entry) {
    if ($entry !== null) {
        $entry = roomieRoomId($entry);
        if (isset($ids[$entry])) {
            roomieAdminError('Ein Raum darf nur einmal platziert werden.', 422);
        }
        $ids[$entry] = true;
    }
}
unset($entry);
roomieAdminWrite(static function (PDO $db) use ($rows, $cols, $layout): array {
    $existingIds = array_fill_keys($db->query('SELECT id FROM rooms')->fetchAll(PDO::FETCH_COLUMN), true);
    foreach ($layout as $id) {
        if ($id !== null && !isset($existingIds[$id])) {
            throw new DomainException('Raum nicht gefunden. Bitte Seite neu laden.', 409);
        }
    }
    $db->prepare('INSERT INTO grid_config (rows, cols, room_layout) VALUES (?, ?, ?)')->execute([$rows, $cols, json_encode($layout, JSON_THROW_ON_ERROR)]);
    $db->exec('UPDATE rooms SET grid_position = NULL');
    $move = $db->prepare('UPDATE rooms SET grid_position = ? WHERE id = ?');
    foreach ($layout as $position => $id) {
        if ($id !== null) {
            $move->execute([$position, $id]);
        }
    }
    return [];
});
