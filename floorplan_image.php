<?php
declare(strict_types=1);
define('ROOMIE_FLOORPLAN_API', true);
require_once __DIR__ . '/floorplan_helpers.php';

roomieFloorplanMethod('GET');
roomieFloorplanAccount();

$id = roomiePositiveId($_GET['id'] ?? null);
if ($id === null) {
    roomieFloorplanError('Raumplan nicht gefunden.', 404);
}

try {
    $db = getDatabaseConnection();
    $stmt = $db->prepare('SELECT image_png FROM floorplans WHERE id = ?');
    $stmt->execute([$id]);
    $png = $stmt->fetchColumn();
    if (is_resource($png)) {
        $png = stream_get_contents($png, ROOMIE_FLOORPLAN_MAX_BYTES + 1);
    }
    if (!is_string($png) || !str_starts_with($png, "\x89PNG\r\n\x1a\n") || strlen($png) > ROOMIE_FLOORPLAN_MAX_BYTES) {
        roomieFloorplanError('Raumplan nicht gefunden.', 404);
    }

    roomieFloorplanHeaders();
    header('Content-Type: image/png');
    header("Content-Disposition: inline; filename=\"raumplan-$id.png\"");
    header('Content-Length: ' . strlen($png));
    echo $png;
} catch (PDOException $error) {
    error_log('Roomie floorplan image unavailable');
    roomieFloorplanError('Raumplan derzeit nicht verfügbar.', 503);
}
