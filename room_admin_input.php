<?php
declare(strict_types=1);

function roomieAdminJson(): array
{
    header('Content-Type: application/json');
    if (!isAdmin()) {
        roomieAdminError('Nur Administratoren können Räume und Layout bearbeiten.', 403);
    }
    requireCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, true);
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (!is_string($raw) || strlen($raw) > 65536) {
        roomieAdminError('Anfrage zu groß.', 413);
    }
    $body = json_decode($raw, true, 12);
    if (!is_array($body)) {
        roomieAdminError('Ungültige Daten.', 422);
    }
    return $body;
}

function roomieAdminError(string $message, int $status): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function roomieRoomId(mixed $value): int
{
    $id = (is_int($value) || is_string($value))
        ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    if ($id === false) {
        roomieAdminError('Ungültige Raum-ID.', 422);
    }
    return $id;
}

const ROOMIE_ROOM_AMENITIES = [
    'desk', 'monitor', 'dock', 'phone', 'whiteboard', 'projector', 'display',
    'meeting', 'wifi', 'video-conference', 'docking-station', 'webcam',
];

function roomieRoomInput(array $data): array
{
    $limits = ['name' => 160, 'number' => 40];
    $clean = [];
    foreach ($limits as $field => $max) {
        $value = is_string($data[$field] ?? null) ? trim($data[$field]) : null;
        if ($value === null || strlen($value) > $max || ($field === 'name' && $value === '')
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            roomieAdminError('Name oder Raumnummer ungültig.', 422);
        }
        $clean[$field] = $value;
    }
    $type = $data['type'] ?? null;
    $types = array_merge(['mediaroom', 'office', 'open-office', 'meeting', 'shared-desk'], ROOMIE_RESTRICTED_ROOM_TYPES);
    $capacity = $data['capacity'] ?? null;
    $amenities = $data['amenities'] ?? null;
    if (!in_array($type, $types, true) || !is_int($capacity) || $capacity < 1 || $capacity > 500
        || !is_array($amenities) || !array_is_list($amenities) || count($amenities) > count(ROOMIE_ROOM_AMENITIES)) {
        roomieAdminError('Raumtyp, Kapazität oder Ausstattung ungültig.', 422);
    }
    foreach ($amenities as $item) {
        if (!in_array($item, ROOMIE_ROOM_AMENITIES, true)) {
            roomieAdminError('Unbekannte Ausstattung.', 422);
        }
    }
    return [$clean['name'], $clean['number'], $type, $capacity, json_encode(array_values(array_unique($amenities)), JSON_THROW_ON_ERROR)];
}

function roomieAdminWrite(callable $write): void
{
    $db = getDatabaseConnection();
    $started = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $started = true;
        $actor = currentRoomieUser(true);
        if (!$actor || (int) $actor['is_admin'] !== 1) {
            throw new DomainException('Die Admin-Sitzung ist nicht mehr gültig.', 403);
        }
        $result = $write($db);
        $db->exec('COMMIT');
        echo json_encode(['success' => true] + $result);
    } catch (Throwable $error) {
        if ($started) {
            try {
                $db->exec('ROLLBACK');
            } catch (PDOException) {
                error_log('Roomie room or layout rollback failed');
            }
        }
        if ($error instanceof DomainException && in_array($error->getCode(), [403, 404, 409, 422], true)) {
            roomieAdminError($error->getMessage(), $error->getCode());
        }
        error_log('Roomie room or layout write failed');
        roomieAdminError('Änderung konnte nicht gespeichert werden.', 500);
    }
}
