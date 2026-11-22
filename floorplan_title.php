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

$limit = 4096;
$raw = file_get_contents('php://input', false, null, 0, $limit + 1);
if (!is_string($raw) || strlen($raw) > $limit) {
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
    $title = roomieFloorplanTitle($data['title'] ?? null);
    if ($title === null) {
        throw new RoomieFloorplanException('Bitte einen Namen mit 1 bis 120 Zeichen eingeben.', 422);
    }

    $newRevision = roomieFloorplanWrite(getDatabaseConnection(), static function (PDO $db) use ($id, $revision, $title): string {
        $update = $db->prepare('UPDATE floorplans SET title = ?, revision = revision + 1 WHERE id = ? AND revision = ?');
        $update->execute([$title, $id, $revision]);
        if ($update->rowCount() === 1) {
            return (string) ($revision + 1);
        }

        $query = $db->prepare('SELECT 1 FROM floorplans WHERE id = ?');
        $query->execute([$id]);
        if ($query->fetchColumn() === false) {
            throw new RoomieFloorplanException('Raumplan nicht gefunden.', 404);
        }
        throw new RoomieFloorplanException('Der Raumplan wurde geändert. Bitte neu laden.', 409);
    });
    roomieFloorplanResponse(['success' => true, 'revision' => $newRevision]);
} catch (RoomieFloorplanException $error) {
    roomieFloorplanError($error->getMessage(), $error->getCode());
} catch (JsonException $error) {
    roomieFloorplanError('Ungültiges JSON.', 422);
} catch (Throwable $error) {
    error_log('Roomie floorplan title update unavailable');
    roomieFloorplanError('Der Name konnte nicht gespeichert werden.', 503);
}
