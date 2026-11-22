<?php
declare(strict_types=1);
define('ROOMIE_FLOORPLAN_API', true);
require_once __DIR__ . '/floorplan_helpers.php';

roomieFloorplanMethod('POST');
roomieFloorplanAccount(true);
$title = roomieFloorplanTitle($_POST['title'] ?? null);
if ($title === null) {
    roomieFloorplanError('Bitte einen Namen mit 1 bis 120 Zeichen eingeben.', 422);
}

try {
    [$width, $height, $png] = roomieFloorplanPng($_FILES['image'] ?? null);
    $id = roomieFloorplanWrite(getDatabaseConnection(), static function (PDO $db) use ($title, $width, $height, $png): int {
        if ((int) $db->query('SELECT COUNT(*) FROM floorplans')->fetchColumn() >= ROOMIE_FLOORPLAN_MAX_PLANS) {
            throw new RoomieFloorplanException('Es können bis zu 12 Raumpläne gespeichert werden.', 409);
        }
        $stmt = $db->prepare('INSERT INTO floorplans (title, width, height, image_png) VALUES (?, ?, ?, ?)');
        $stmt->bindValue(1, $title, PDO::PARAM_STR);
        $stmt->bindValue(2, $width, PDO::PARAM_INT);
        $stmt->bindValue(3, $height, PDO::PARAM_INT);
        $stmt->bindValue(4, $png, PDO::PARAM_LOB);
        $stmt->execute();
        return (int) $db->lastInsertId();
    });
    roomieFloorplanResponse(['success' => true, 'id' => $id]);
} catch (RoomieFloorplanException $error) {
    roomieFloorplanError($error->getMessage(), $error->getCode());
} catch (Throwable $error) {
    error_log('Roomie floorplan upload unavailable');
    roomieFloorplanError('Raumplan konnte nicht gespeichert werden.', 503);
}
