<?php
declare(strict_types=1);
define('ROOMIE_FLOORPLAN_API', true);
require_once __DIR__ . '/floorplan_helpers.php';

roomieFloorplanMethod('GET');
roomieFloorplanAccount();
$db = getDatabaseConnection();

try {
    $hasSchema = $db->query("SELECT COUNT(*) FROM sqlite_schema WHERE type='table' AND name IN ('floorplans', 'floorplan_markers')")->fetchColumn();
    if ((int) $hasSchema !== 2) {
        roomieFloorplanResponse(['success' => true, 'plans' => []]);
    }

    $plans = [];
    $query = $db->query('SELECT id, title, width, height, revision FROM floorplans ORDER BY id LIMIT ' . ROOMIE_FLOORPLAN_MAX_PLANS);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        $plans[$id] = [
            'id' => $id, 'title' => (string) $row['title'],
            'width' => (int) $row['width'], 'height' => (int) $row['height'],
            'revision' => (string) $row['revision'], 'markers' => [],
        ];
    }

    if ($plans !== []) {
        [$roomAccess, $params] = roomieBookingAccessSql('r', (int) currentRoomieUser()['id'], isAdmin());
        $planPlaceholders = [];
        foreach (array_keys($plans) as $position => $id) {
            $placeholder = ':floorplan_' . $position;
            $planPlaceholders[] = $placeholder;
            $params[$placeholder] = $id;
        }
        $in = implode(', ', $planPlaceholders);
        $stmt = $db->prepare("SELECT m.floorplan_id, m.room_id, m.x, m.y, m.w, m.h
                             FROM floorplan_markers m JOIN rooms r ON r.id = m.room_id
                             WHERE m.floorplan_id IN ($in) AND ($roomAccess)
                             ORDER BY m.floorplan_id, m.room_id");
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $plans[(int) $row['floorplan_id']]['markers'][] = [
                'roomId' => (int) $row['room_id'],
                'x' => (float) $row['x'], 'y' => (float) $row['y'],
                'w' => (float) $row['w'], 'h' => (float) $row['h'],
            ];
        }
    }
    roomieFloorplanResponse(['success' => true, 'plans' => array_values($plans)]);
} catch (PDOException $error) {
    error_log('Roomie floorplan list unavailable');
    roomieFloorplanError('Raumpläne derzeit nicht verfügbar.', 503);
}
