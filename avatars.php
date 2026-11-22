<?php
declare(strict_types=1);

const ROOMIE_AVATAR_MAX_INPUT = 3 * 1024 * 1024;
const ROOMIE_AVATAR_MAX_EDGE = 2048;
const ROOMIE_AVATAR_MAX_PIXELS = 3000000;
const ROOMIE_AVATAR_RENDER_EDGE = 256;

function roomieIsPrivateAvatar(mixed $key): bool
{
    return is_string($key) && preg_match('/\A[a-f0-9]{48}\.png\z/D', $key) === 1;
}

function roomieAvatarDirectory(bool $create = false): string
{
    $base = dirname(roomieDatabasePath());
    $directory = $base . '/avatars';
    if ($create && !is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
        throw new RuntimeException('Privater Bildspeicher ist nicht verfügbar.');
    }
    if (is_link($directory) || realpath($directory) !== $directory) {
        throw new RuntimeException('Privater Bildspeicher ist nicht verfügbar.');
    }
    if (PHP_OS_FAMILY === 'Linux' && (fileowner($directory) !== posix_geteuid() || (fileperms($directory) & 0077) !== 0)) {
        throw new RuntimeException('Privater Bildspeicher ist nicht verfügbar.');
    }
    return $directory;
}

/** Validate before decoding, then produce a new small PNG without carrying original bytes or metadata. */
function roomieAvatarPng(string $input): string
{
    if (strlen($input) > ROOMIE_AVATAR_MAX_INPUT || $input === '') {
        throw new InvalidArgumentException('Das Bild darf höchstens 3 MB groß sein.');
    }
    if (!extension_loaded('gd')) {
        throw new RuntimeException('Bildverarbeitung ist nicht verfügbar.');
    }
    $info = @getimagesizefromstring($input);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
        throw new InvalidArgumentException('Bitte ein JPEG-, PNG- oder GIF-Bild auswählen.');
    }
    [$width, $height] = $info;
    if ($width < 1 || $height < 1 || max($width, $height) > ROOMIE_AVATAR_MAX_EDGE || $width * $height > ROOMIE_AVATAR_MAX_PIXELS) {
        throw new InvalidArgumentException('Das Bild ist zu groß.');
    }
    $source = @imagecreatefromstring($input);
    if ($source === false || imagesx($source) !== $width || imagesy($source) !== $height) {
        throw new InvalidArgumentException('Das Bild ist beschädigt.');
    }
    $scale = min(ROOMIE_AVATAR_RENDER_EDGE / max($width, $height), 1.0);
    $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagefilledrectangle($target, 0, 0, imagesx($target) - 1, imagesy($target) - 1, imagecolorallocatealpha($target, 0, 0, 0, 127));
    if (!imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height)) {
        throw new RuntimeException('Bildverarbeitung fehlgeschlagen.');
    }
    ob_start();
    try {
        if (!imagepng($target)) {
            throw new RuntimeException('Bildverarbeitung fehlgeschlagen.');
        }
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

/** Only PHP-verified uploads go through this entry point. */
function roomieStoreAvatar(array $upload): string
{
    $source = $upload['tmp_name'] ?? null;
    if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($source) || !is_uploaded_file($source)) {
        throw new InvalidArgumentException('Bild konnte nicht geladen werden.');
    }
    $input = file_get_contents($source, false, null, 0, ROOMIE_AVATAR_MAX_INPUT + 1);
    if ($input === false) {
        throw new RuntimeException('Bild konnte nicht geladen werden.');
    }
    $png = roomieAvatarPng($input);
    $key = bin2hex(random_bytes(24)) . '.png';
    $path = roomieAvatarDirectory(true) . '/' . $key;
    $previousMask = umask(0077);
    try {
        $handle = @fopen($path, 'xb');
    } finally {
        umask($previousMask);
    }
    if ($handle === false) {
        throw new RuntimeException('Bild konnte nicht gespeichert werden.');
    }
    $written = fwrite($handle, $png);
    fclose($handle);
    if ($written !== strlen($png)) {
        @unlink($path);
        throw new RuntimeException('Bild konnte nicht gespeichert werden.');
    }
    return $key;
}

function roomieDiscardAvatar(mixed $key): void
{
    if (!roomieIsPrivateAvatar($key)) {
        return;
    }
    try {
        @unlink(roomieAvatarDirectory() . '/' . $key);
    } catch (RuntimeException) {
        error_log('Roomie could not clean up avatar');
    }
}
