<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

const ROOMIE_BOOKING_HORIZON_DAYS = 90;
const ROOMIE_BOOKING_PER_USER_LIMIT = 60;
const ROOMIE_MAIL_COOLDOWN_SECONDS = 60;

function roomieBookingToday(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
}

function parseBookingDate($date): ?DateTimeImmutable
{
    if (!is_string($date) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)) {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Berlin'));
    return $parsed && $parsed->format('Y-m-d') === $date && $parsed->format('Y') !== '0000'
        ? $parsed : null;
}

function roomiePositiveId($value): ?int
{
    if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[1-9][0-9]*\z/', (string) $value)) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

// The alias is an internal SQL identifier, never a value from the request.
function roomieBookingAccessSql(string $roomAlias, int $userId, bool $isAdmin): array
{
    if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $roomAlias)) {
        throw new InvalidArgumentException('Invalid room alias');
    }
    if ($isAdmin) {
        return ['1=1', []];
    }
    $params = ['roomie_acl_uid' => $userId];
    $types = [];
    foreach (ROOMIE_RESTRICTED_ROOM_TYPES as $i => $type) {
        $name = 'roomie_acl_type_' . $i;
        $types[] = ':' . $name;
        $params[$name] = $type;
    }
    $typeList = implode(', ', $types);
    return ["($roomAlias.type NOT IN ($typeList) OR EXISTS (
        SELECT 1 FROM permissions acl WHERE acl.room_id = $roomAlias.id AND acl.user_id = :roomie_acl_uid
    ))", $params];
}

function roomieVisibleRoom(PDO $db, int $roomId, int $userId, bool $isAdmin): ?array
{
    [$rule, $params] = roomieBookingAccessSql('r', $userId, $isAdmin);
    $stmt = $db->prepare("SELECT r.* FROM rooms r WHERE r.id = :room_id AND $rule");
    $stmt->execute(['room_id' => $roomId] + $params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function roomieBookingJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
}

function roomieRequireBookingViewer(): int
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    if (!isLoggedIn()) {
        roomieBookingJson(['error' => 'Nicht angemeldet.'], 401);
        exit;
    }
    return (int) $_SESSION['user_id'];
}
