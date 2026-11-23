<?php
declare(strict_types=1);

if (($_SERVER['SCRIPT_FILENAME'] ?? null) === __FILE__) {
    http_response_code(404);
    exit;
}

const ROOMIE_PHOTO_MAX_BYTES = 5 * 1024 * 1024;
const ROOMIE_PHOTO_MAX_EDGE = 4096;
const ROOMIE_PHOTO_MAX_PIXELS = 12500000;
const ROOMIE_PHOTO_RENDER_EDGE = 1600;

final class RoomiePhotoException extends RuntimeException {}

function roomiePhotoTableExists(PDO $db): bool
{
    return (bool) $db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'room_photos'")->fetchColumn();
}

/** @return list<int> IDs only; callers must limit their results to visible rooms. */
function roomiePhotoIds(PDO $db): array
{
    if (!roomiePhotoTableExists($db)) {
        return [];
    }
    return array_map('intval', $db->query('SELECT room_id FROM room_photos')->fetchAll(PDO::FETCH_COLUMN));
}

function roomieHasPhoto(PDO $db, int $roomId): bool
{
    if (!roomiePhotoTableExists($db)) {
        return false;
    }
    $query = $db->prepare('SELECT 1 FROM room_photos WHERE room_id = ?');
    $query->execute([$roomId]);
    return (bool) $query->fetchColumn();
}

/** Input MIME, filename and extension supplied by the client are not used. */
function roomieUploadedPhoto(mixed $upload): string
{
    $error = is_array($upload) ? ($upload['error'] ?? null) : null;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new RoomiePhotoException('Das Foto ist zu groß (maximal 5 MiB, abhängig vom Serverlimit).', 413);
    }
    if (in_array($error, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
        throw new RoomiePhotoException('Der Foto-Upload ist gerade nicht verfügbar.', 503);
    }
    $file = is_array($upload) ? ($upload['tmp_name'] ?? null) : null;
    if ($error !== UPLOAD_ERR_OK || !is_string($file) || !is_uploaded_file($file)) {
        throw new RoomiePhotoException('Bitte ein JPEG-, PNG- oder WebP-Foto auswählen.', 422);
    }
    $size = $upload['size'] ?? null;
    if (!is_int($size) || $size < 1) {
        throw new RoomiePhotoException('Das Foto ist leer oder konnte nicht gelesen werden.', 422);
    }
    if ($size > ROOMIE_PHOTO_MAX_BYTES) {
        throw new RoomiePhotoException('Das Foto darf höchstens 5 MiB groß sein.', 413);
    }
    $bytes = @file_get_contents($file, false, null, 0, ROOMIE_PHOTO_MAX_BYTES + 1);
    if (!is_string($bytes) || strlen($bytes) !== $size) {
        throw new RoomiePhotoException('Das Foto konnte nicht gelesen werden.', 422);
    }
    return roomiePhotoJpeg($bytes, $file);
}

/** Bound the decode, then encode pixels into a new static image without original metadata. */
function roomiePhotoJpeg(string $bytes, string $uploadPath): string
{
    if ($bytes === '' || strlen($bytes) > ROOMIE_PHOTO_MAX_BYTES) {
        throw new RoomiePhotoException('Das Foto darf höchstens 5 MiB groß sein.', 413);
    }
    $info = @getimagesizefromstring($bytes);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RoomiePhotoException('Nur JPEG-, PNG- und WebP-Fotos sind erlaubt.', 422);
    }
    [$width, $height, $type] = $info;
    if ($width < 1 || $height < 1 || $width > ROOMIE_PHOTO_MAX_EDGE || $height > ROOMIE_PHOTO_MAX_EDGE
        || $width * $height > ROOMIE_PHOTO_MAX_PIXELS) {
        throw new RoomiePhotoException('Fotos dürfen maximal 4096 Pixel je Seite und 12,5 Megapixel haben.', 422);
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg') || !function_exists('imagetypes')) {
        throw new RoomiePhotoException('Der Foto-Upload benötigt PHP GD mit JPEG-Unterstützung.', 503);
    }
    $format = match ($type) {
        IMAGETYPE_JPEG => IMG_JPG,
        IMAGETYPE_PNG => IMG_PNG,
        IMAGETYPE_WEBP => IMG_WEBP,
    };
    $supported = imagetypes();
    if (($supported & IMG_JPG) === 0) {
        throw new RoomiePhotoException('Der Foto-Upload benötigt PHP GD mit JPEG-Unterstützung.', 503);
    }
    if (($supported & $format) === 0) {
        throw new RoomiePhotoException('Dieses Bildformat kann der Server nicht verarbeiten. Bitte JPEG verwenden.', 422);
    }

    $source = @imagecreatefromstring($bytes);
    if ($source === false || imagesx($source) !== $width || imagesy($source) !== $height) {
        throw new RoomiePhotoException('Das Foto ist beschädigt oder kann nicht gelesen werden.', 422);
    }
    $scale = min(1.0, ROOMIE_PHOTO_RENDER_EDGE / max($width, $height));
    $outWidth = max(1, (int) round($width * $scale));
    $outHeight = max(1, (int) round($height * $scale));
    $target = imagecreatetruecolor($outWidth, $outHeight);
    if ($target === false || !imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255))
        || !imagecopyresampled($target, $source, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height)) {
        throw new RoomiePhotoException('Das Foto konnte nicht verarbeitet werden.', 503);
    }
    unset($source);
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($uploadPath, null, true, false);
        $orientation = $exif['IFD0']['Orientation'] ?? null;
        if (is_int($orientation)) {
            $target = roomieOrientPhoto($target, $orientation);
        }
        unset($exif);
    }

    $stream = tmpfile();
    if ($stream === false) {
        throw new RoomiePhotoException('Das Foto konnte nicht verarbeitet werden.', 503);
    }
    try {
        $jpeg = @imagejpeg($target, $stream, 86) && rewind($stream)
            ? stream_get_contents($stream, ROOMIE_PHOTO_MAX_BYTES + 1) : false;
    } finally {
        fclose($stream);
    }
    if (!roomiePhotoIsJpeg($jpeg)) {
        throw new RoomiePhotoException('Das Foto konnte nicht verarbeitet werden.', 503);
    }
    return $jpeg;
}

/** Apply the orientation to pixels, not to the outgoing JPEG metadata. */
function roomieOrientPhoto(GdImage $image, int $orientation): GdImage
{
    $flip = match ($orientation) {
        2, 5, 7 => IMG_FLIP_HORIZONTAL,
        4 => IMG_FLIP_VERTICAL,
        default => null,
    };
    if ($flip !== null && !imageflip($image, $flip)) {
        throw new RoomiePhotoException('Das Foto konnte nicht ausgerichtet werden.', 503);
    }
    $angle = match ($orientation) {
        3 => 180,
        5, 8 => 90,
        6, 7 => 270,
        default => 0,
    };
    if ($angle === 0) {
        return $image;
    }
    $rotated = imagerotate($image, $angle, 0xffffff);
    if ($rotated === false) {
        throw new RoomiePhotoException('Das Foto konnte nicht ausgerichtet werden.', 503);
    }
    return $rotated;
}

function roomiePhotoIsJpeg(mixed $bytes): bool
{
    return is_string($bytes) && strlen($bytes) <= ROOMIE_PHOTO_MAX_BYTES
        && str_starts_with($bytes, "\xff\xd8\xff") && str_ends_with($bytes, "\xff\xd9");
}

/** Use only after the endpoint has verified room visibility in the same read transaction. */
function roomieReadPhoto(PDO $db, int $roomId): ?string
{
    if (!roomiePhotoTableExists($db)) {
        return null;
    }
    $query = $db->prepare('SELECT image_jpeg FROM room_photos WHERE room_id = ?');
    $query->execute([$roomId]);
    $photo = $query->fetchColumn();
    if (is_resource($photo)) {
        $photo = stream_get_contents($photo, ROOMIE_PHOTO_MAX_BYTES + 1);
    }
    return roomiePhotoIsJpeg($photo) ? $photo : null;
}

/** Use only inside the existing admin write transaction, after checking the room. */
function roomieWritePhoto(PDO $db, int $roomId, ?string $jpeg): void
{
    if ($jpeg === null) {
        if (roomiePhotoTableExists($db)) {
            $db->prepare('DELETE FROM room_photos WHERE room_id = ?')->execute([$roomId]);
        }
        return;
    }
    if (!roomiePhotoIsJpeg($jpeg)) {
        throw new InvalidArgumentException('Expected normalized room photo JPEG');
    }
    // Older installs need no manual migration. Reads and a no-op removal never create the table.
    $db->exec('CREATE TABLE IF NOT EXISTS room_photos (
        room_id INTEGER PRIMARY KEY REFERENCES rooms(id) ON DELETE CASCADE,
        image_jpeg BLOB NOT NULL CHECK (typeof(image_jpeg) = \'blob\' AND length(image_jpeg) BETWEEN 1 AND '
        . ROOMIE_PHOTO_MAX_BYTES . ')
    )');
    $query = $db->prepare('INSERT INTO room_photos (room_id, image_jpeg) VALUES (:room_id, :image_jpeg)
        ON CONFLICT(room_id) DO UPDATE SET image_jpeg = excluded.image_jpeg');
    $query->bindValue(':room_id', $roomId, PDO::PARAM_INT);
    $query->bindValue(':image_jpeg', $jpeg, PDO::PARAM_LOB);
    $query->execute();
}
