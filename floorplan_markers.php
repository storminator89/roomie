<?php
declare(strict_types=1);
define('ROOMIE_FLOORPLAN_API', true);
require_once __DIR__ . '/floorplan_helpers.php';

roomieFloorplanMethod('POST');
roomieFloorplanAccount(true);

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    roomieFloorplanError('JSON erwartet.', 415);
}
$raw = file_get_contents('php://input', false, null, 0, ROOMIE_FLOORPLAN_MAX_JSON + 1);
if (!is_string($raw) || strlen($raw) > ROOMIE_FLOORPLAN_MAX_JSON) {
    roomieFloorplanError('Anfrage ist zu groß.', 413);
}

try {
    $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    $id = is_array($data) ? roomiePositiveId($data['id'] ?? null) : null;
    $revisionText = is_array($data) ? ($data['revision'] ?? null) : null;
    $revision = is_string($revisionText) ? roomiePositiveId($revisionText) : null;
    if ($id === null || $revision === null || $revision === PHP_INT_MAX) {
        throw new RoomieFloorplanException('Raumplan oder Version ungültig.', 422);
    }
    $markers = roomieFloorplanMarkerList($data['markers'] ?? null);
    $newRevision = roomieFloorplanWrite(getDatabaseConnection(), static function (PDO $db) use ($id, $revision, $markers): string {
        $query = $db->prepare('SELECT revision FROM floorplans WHERE id = ?');
        $query->execute([$id]);
        $stored = $query->fetchColumn();
        $query->closeCursor();
        if ($stored === false) {
            throw new RoomieFloorplanException('Raumplan nicht gefunden.', 404);
        }
        if ((int) $stored !== $revision) {
            throw new RoomieFloorplanException('Der Raumplan wurde geändert. Bitte neu laden.', 409);
        }

        if ($markers !== []) {
            $ids = array_keys($markers);
            $params = implode(', ', array_fill(0, count($ids), '?'));
            $roomCheck = $db->prepare("SELECT COUNT(*) FROM rooms WHERE id IN ($params)");
            $roomCheck->execute($ids);
            if ((int) $roomCheck->fetchColumn() !== count($ids)) {
                throw new RoomieFloorplanException('Mindestens ein markierter Raum ist nicht mehr vorhanden.', 422);
            }
        }

        $db->prepare('DELETE FROM floorplan_markers WHERE floorplan_id = ?')->execute([$id]);
        $insert = $db->prepare('INSERT INTO floorplan_markers (floorplan_id, room_id, x, y, w, h) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($markers as $roomId => $rect) {
            $insert->execute([$id, $roomId, $rect['x'], $rect['y'], $rect['w'], $rect['h']]);
        }
        $db->prepare('UPDATE floorplans SET revision = revision + 1 WHERE id = ?')->execute([$id]);
        return (string) ($revision + 1);
    });
    roomieFloorplanResponse(['success' => true, 'revision' => $newRevision]);
} catch (RoomieFloorplanException $error) {
    roomieFloorplanError($error->getMessage(), $error->getCode());
} catch (JsonException $error) {
    roomieFloorplanError('Ungültiges JSON.', 422);
} catch (Throwable $error) {
    error_log('Roomie floorplan marker update unavailable');
    roomieFloorplanError('Raummarkierungen konnten nicht gespeichert werden.', 503);
}
