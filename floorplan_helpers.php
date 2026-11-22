<?php
declare(strict_types=1);

if (!defined('ROOMIE_FLOORPLAN_API')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_helpers.php';

const ROOMIE_FLOORPLAN_MAX_BYTES = 5 * 1024 * 1024;
const ROOMIE_FLOORPLAN_MAX_DIMENSION = 2400;
const ROOMIE_FLOORPLAN_MAX_PIXELS = 3000000;
const ROOMIE_FLOORPLAN_MAX_PLANS = 12;
const ROOMIE_FLOORPLAN_MAX_MARKERS = 200;
const ROOMIE_FLOORPLAN_MAX_JSON = 65536;

function roomieFloorplanHeaders(): void
{
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
}

function roomieFloorplanResponse(array $body, int $status = 200): never
{
    roomieFloorplanHeaders();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function roomieFloorplanError(string $message, int $status): never
{
    roomieFloorplanResponse(['success' => false, 'message' => $message], $status);
}

function roomieFloorplanMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        roomieFloorplanError('Methode nicht erlaubt.', 405);
    }
}

function roomieFloorplanAccount(bool $admin = false): void
{
    $user = currentRoomieUser();
    if ($user === null) {
        roomieFloorplanError('Bitte anmelden.', 401);
    }
    if ($admin) {
        if ((int) $user['is_admin'] !== 1) {
            roomieFloorplanError('Nur Administratoren dürfen Raumpläne bearbeiten.', 403);
        }
        roomieFloorplanHeaders();
        header('Content-Type: application/json; charset=utf-8');
        requireCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null, true);
    }
}

final class RoomieFloorplanException extends RuntimeException {}

function roomieFloorplanWrite(PDO $db, callable $write): mixed
{
    $open = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $open = true;
        $actor = currentRoomieUser(true);
        if ($actor === null || (int) $actor['is_admin'] !== 1) {
            throw new RoomieFloorplanException('Keine Berechtigung mehr vorhanden.', 403);
        }
        $result = $write($db);
        $db->exec('COMMIT');
        $open = false;
        return $result;
    } catch (Throwable $error) {
        if ($open) {
            $db->exec('ROLLBACK');
        }
        throw $error;
    }
}

function roomieFloorplanTitle(mixed $title): ?string
{
    if (!is_string($title) || strlen($title) > 480) {
        return null;
    }
    $title = trim($title);
    return preg_match('/\A[^\p{C}]{1,120}\z/u', $title) === 1 ? $title : null;
}

function roomieFloorplanPng(mixed $image): array
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
        throw new RoomieFloorplanException('Bild-Upload benötigt PHP GD mit PNG-Unterstützung.', 503);
    }
    if (!is_array($image) || ($image['error'] ?? null) !== UPLOAD_ERR_OK
        || !is_string($image['tmp_name'] ?? null) || !is_uploaded_file($image['tmp_name'])
        || !is_int($image['size'] ?? null) || $image['size'] < 1 || $image['size'] > ROOMIE_FLOORPLAN_MAX_BYTES) {
        throw new RoomieFloorplanException('Bitte ein PNG oder JPEG bis 5 MiB auswählen.', 422);
    }
    $data = file_get_contents($image['tmp_name'], false, null, 0, ROOMIE_FLOORPLAN_MAX_BYTES + 1);
    if (!is_string($data) || $data === '' || strlen($data) > ROOMIE_FLOORPLAN_MAX_BYTES) {
        throw new RoomieFloorplanException('Bilddatei ist zu groß oder nicht lesbar.', 422);
    }
    $size = @getimagesizefromstring($data);
    if ($size === false || !in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
        || $size[0] < 1 || $size[1] < 1 || $size[0] > ROOMIE_FLOORPLAN_MAX_DIMENSION
        || $size[1] > ROOMIE_FLOORPLAN_MAX_DIMENSION || $size[0] * $size[1] > ROOMIE_FLOORPLAN_MAX_PIXELS) {
        throw new RoomieFloorplanException('PNG oder JPEG: maximal 2400 Pixel je Seite und 3 Megapixel.', 422);
    }
    $decoded = @imagecreatefromstring($data);
    unset($data);
    if ($decoded === false || imagesx($decoded) !== $size[0] || imagesy($decoded) !== $size[1]) {
        throw new RoomieFloorplanException('Das Bild konnte nicht gelesen werden.', 422);
    }
    imagesavealpha($decoded, true);
    $stream = tmpfile();
    if ($stream === false) {
        throw new RuntimeException('Temporary image buffer unavailable');
    }
    try {
        if (!@imagepng($decoded, $stream, 6) || !rewind($stream)) {
            throw new RoomieFloorplanException('Das Bild konnte nicht gespeichert werden.', 422);
        }
        $png = stream_get_contents($stream, ROOMIE_FLOORPLAN_MAX_BYTES + 1);
    } finally {
        fclose($stream);
    }
    if (!is_string($png) || $png === '' || strlen($png) > ROOMIE_FLOORPLAN_MAX_BYTES) {
        throw new RoomieFloorplanException('Das gespeicherte PNG wäre größer als 5 MiB.', 422);
    }
    return [$size[0], $size[1], $png];
}

function roomieFloorplanMarkerList(mixed $markers): array
{
    if (!is_array($markers) || !array_is_list($markers) || count($markers) > ROOMIE_FLOORPLAN_MAX_MARKERS) {
        throw new RoomieFloorplanException('Ungültige Raummarkierungen.', 422);
    }

    $valid = [];
    foreach ($markers as $marker) {
        $roomId = is_array($marker) ? roomiePositiveId($marker['roomId'] ?? null) : null;
        if ($roomId === null || isset($valid[$roomId])) {
            throw new RoomieFloorplanException('Raum ungültig oder doppelt markiert.', 422);
        }
        $rect = [];
        foreach (['x', 'y', 'w', 'h'] as $key) {
            $value = $marker[$key] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
                || $value < 0 || $value > 1) {
                throw new RoomieFloorplanException('Ungültige Markierungsposition.', 422);
            }
            $rect[$key] = round((float) $value, 8);
        }
        if ($rect['w'] <= 0 || $rect['h'] <= 0 || round($rect['x'] + $rect['w'], 8) > 1
            || round($rect['y'] + $rect['h'], 8) > 1) {
            throw new RoomieFloorplanException('Die Markierung muss im Raumplan liegen.', 422);
        }
        // A width calculated as 1-x avoids floating-point spill at the right edge.
        $rect['w'] = min($rect['w'], 1 - $rect['x']);
        $rect['h'] = min($rect['h'], 1 - $rect['y']);
        $valid[$roomId] = $rect;
    }
    return $valid;
}
