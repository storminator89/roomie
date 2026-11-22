<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_admin_input.php';

$data = roomieAdminJson();
$rows = $data['rows'] ?? null;
$cols = $data['cols'] ?? null;
$layout = $data['layout'] ?? null;
$revision = null;
// Older standalone clients did not send a revision; keep their write contract.
if (array_key_exists('revision', $data)) {
    $revision = $data['revision'];
    if (!is_string($revision) || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $revision) !== 1) {
        roomieAdminError('Ungültige Layout-Version. Bitte Editor neu öffnen.', 422);
    }
}
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
roomieAdminWrite(static function (PDO $db) use ($rows, $cols, $layout, $revision): array {
    // Compare inside the same BEGIN IMMEDIATE transaction as the insertion.
    $latest = (string) ($db->query('SELECT id FROM grid_config ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
    if ($revision !== null && $revision !== $latest) {
        throw new DomainException('Das Layout wurde inzwischen geändert. Dein Entwurf bleibt erhalten. Öffne den Editor neu, um den aktuellen Stand zu laden.', 409);
    }
    $existingIds = array_fill_keys($db->query('SELECT id FROM rooms')->fetchAll(PDO::FETCH_COLUMN), true);
    foreach ($layout as $id) {
        if ($id !== null && !isset($existingIds[$id])) {
            throw new DomainException('Raum nicht gefunden. Bitte Seite neu laden.', 409);
        }
    }
    $db->prepare('INSERT INTO grid_config (rows, cols, room_layout) VALUES (?, ?, ?)')->execute([$rows, $cols, json_encode($layout, JSON_THROW_ON_ERROR)]);
    $nextRevision = $db->lastInsertId();
    $db->exec('UPDATE rooms SET grid_position = NULL');
    $move = $db->prepare('UPDATE rooms SET grid_position = ? WHERE id = ?');
    foreach ($layout as $position => $id) {
        if ($id !== null) {
            $move->execute([$position, $id]);
        }
    }
    return ['revision' => $nextRevision];
});
