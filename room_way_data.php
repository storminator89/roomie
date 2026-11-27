<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_room_change.php';
require_once __DIR__ . '/roomie_public_url.php';

const ROOMIE_GUEST_MAP_TTL = 7 * 24 * 60 * 60;

/** Owner or a presented capability supplies the only lookup key. No schedule list. */
function roomieWayBooking(PDO $db, int $id, ?int $ownerId = null): ?array
{
    $room = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $query = $db->prepare("SELECT b.id, b.user_id, b.seat_id, b.date, b.start_time, b.end_time, $room AS room_id
                            FROM bookings b WHERE b.id = ? AND b.user_id IS NOT NULL"
                            . ($ownerId === null ? '' : ' AND b.user_id = ?'));
    $query->execute($ownerId === null ? [$id] : [$id, $ownerId]);
    $booking = $query->fetch(PDO::FETCH_ASSOC);
    return $booking ?: null;
}

function roomieWayPlan(PDO $db, int $roomId): ?array
{
    if ((int) $db->query("SELECT count(*) FROM sqlite_schema
            WHERE type = 'table' AND name IN ('floorplans', 'floorplan_markers')")->fetchColumn() !== 2) {
        return null;
    }
    $query = $db->prepare('SELECT p.id, p.title, p.width, p.height, p.revision, m.x, m.y, m.w, m.h
        FROM floorplan_markers m JOIN floorplans p ON p.id = m.floorplan_id
        WHERE m.room_id = ? ORDER BY p.id LIMIT 1');
    $query->execute([$roomId]);
    $plan = $query->fetch(PDO::FETCH_ASSOC);
    if (!$plan || !roomieWayRectValid($plan)) {
        return null;
    }
    return $plan;
}

function roomieWayRectValid(array $plan): bool
{
    foreach (['x', 'y', 'w', 'h'] as $key) {
        if (!is_numeric($plan[$key] ?? null) || !is_finite((float) $plan[$key]) || $plan[$key] < 0) {
            return false;
        }
    }
    return $plan['w'] > 0 && $plan['h'] > 0 && $plan['x'] + $plan['w'] <= 1.00000001
        && $plan['y'] + $plan['h'] <= 1.00000001;
}

function roomieWayKey(): ?string
{
    $hex = getenv('ROOMIE_GUEST_MAP_KEY');
    return is_string($hex) && preg_match('/\A[0-9a-fA-F]{64}\z/D', $hex) === 1
        ? (hex2bin($hex) ?: null) : null;
}

function roomieWayGuestApproved(PDO $db, array $room, ?array $plan): bool
{
    $list = getenv('ROOMIE_GUEST_MAP_APPROVALS');
    if ($plan === null || !is_string($list) || strlen($list) > 4000
        || in_array($room['type'], ROOMIE_RESTRICTED_ROOM_TYPES, true)
        || $plan['w'] * $plan['h'] > .65
        || !in_array($room['id'] . ':' . $plan['id'] . ':' . $plan['revision'], explode(',', $list), true)
        || !function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
        return false;
    }
    // Shared coordinates must not overlap another marked room.
    $q = $db->prepare('SELECT 1 FROM floorplan_markers WHERE floorplan_id = :plan AND room_id <> :room
        AND x < CAST(:right AS REAL) AND x + w > CAST(:left AS REAL)
        AND y < CAST(:bottom AS REAL) AND y + h > CAST(:top AS REAL) LIMIT 1');
    $q->execute(['plan' => $plan['id'], 'room' => $room['id'], 'right' => $plan['x'] + $plan['w'],
        'left' => $plan['x'], 'bottom' => $plan['y'] + $plan['h'], 'top' => $plan['y']]);
    return $q->fetchColumn() === false;
}

function roomieWayEnd(array $booking): ?int
{
    $date = parseBookingDate($booking['date']);
    $start = roomieBookingClock($booking['start_time']);
    $end = roomieBookingClock($booking['end_time']);
    if (!$date || !$start || !$end || $start >= $end) {
        return null;
    }
    return $date->setTime((int) substr($end, 0, 2), (int) substr($end, 3, 2), (int) substr($end, 6, 2))->getTimestamp();
}

function roomieWaySignature(PDO $db, string $key, array $booking, array $room, array $plan, int $expiry): string
{
    $rev = roomieBookingRevisions($db, [(int) $booking['id']])[(int) $booking['id']] ?? 0;
    $data = ['roomie-guest-map-v1', $expiry, roomieBookingRevisionSnapshot($booking, (int) $rev),
        $room['id'], $room['name'], $room['number'], $plan];
    return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), $key);
}

function roomieWayGuestCrop(PDO $db, array $plan): ?string
{
    $query = $db->prepare('SELECT image_png FROM floorplans WHERE id = ? AND revision = ?
        AND length(image_png) BETWEEN 1 AND 5242880');
    $query->execute([$plan['id'], $plan['revision']]);
    $png = $query->fetchColumn();
    if (!is_string($png) || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
        return null;
    }
    $meta = @getimagesizefromstring($png);
    if (!$meta || $meta[2] !== IMAGETYPE_PNG || $meta[0] !== (int) $plan['width'] || $meta[1] !== (int) $plan['height']
        || $meta[0] > 2400 || $meta[1] > 2400 || $meta[0] * $meta[1] > 3000000) {
        return null;
    }
    $x = (int) ceil($plan['x'] * $meta[0]);
    $y = (int) ceil($plan['y'] * $meta[1]);
    $w = (int) floor(($plan['x'] + $plan['w']) * $meta[0]) - $x;
    $h = (int) floor(($plan['y'] + $plan['h']) * $meta[1]) - $y;
    if ($w < 2 || $h < 2 || $x + $w > $meta[0] || $y + $h > $meta[1]) {
        return null;
    }
    $source = @imagecreatefromstring($png);
    if (!$source) {
        return null;
    }
    // Copy approved pixels first. Resampling a full plan directly could read
    // neighbouring pixels. Re-encoding drops all source PNG ancillary chunks.
    $crop = imagecrop($source, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
    unset($source);
    if (!$crop) {
        return null;
    }
    if (max($w, $h) > 640) {
        $scale = 640 / max($w, $h);
        $crop = imagescale($crop, max(1, (int) floor($w * $scale)), max(1, (int) floor($h * $scale)));
        if (!$crop) {
            return null;
        }
    }
    $stream = tmpfile();
    if (!$stream) {
        return null;
    }
    try {
        return imagepng($crop, $stream, 6) && rewind($stream) ? (stream_get_contents($stream) ?: null) : null;
    } finally {
        fclose($stream);
    }
}

function roomieWayToken(string $key, int $id, int $expiry, string $signature): string
{
    $body = $id . '.' . $expiry . '.' . $signature;
    return $body . '.' . hash_hmac('sha256', 'roomie-map-capability:' . $body, $key);
}

/** Check authenticity before loading even booking metadata. */
function roomieWayParseToken(string $key, mixed $token, int $now): ?array
{
    if (!is_string($token) || preg_match('/\A([1-9][0-9]{0,18})\.([1-9][0-9]{0,11})\.([a-f0-9]{64})\.([a-f0-9]{64})\z/D', $token, $match) !== 1) {
        return null;
    }
    $id = roomiePositiveId($match[1]);
    $exp = roomiePositiveId($match[2]);
    return $id !== null && $exp !== null && $exp > $now && $exp <= $now + ROOMIE_GUEST_MAP_TTL
        && hash_equals(roomieWayToken($key, $id, $exp, $match[3]), $token)
        ? [$id, $exp, $match[3]] : null;
}
