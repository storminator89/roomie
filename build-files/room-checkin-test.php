<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../room_checkin.php';

$checks = 0;
function verify(bool $condition, string $name): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($name);
    }
    ++$checks;
}

function denies(callable $action, int $status, string $name): void
{
    try {
        $action();
    } catch (DomainException $error) {
        verify($error->getCode() === $status, $name);
        return;
    }
    throw new RuntimeException($name . ': accepted');
}

$at = static fn(string $clock): DateTimeImmutable =>
    new DateTimeImmutable('2026-10-25 ' . $clock, new DateTimeZone('Europe/Berlin'));

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec(<<<'SQL'
    PRAGMA foreign_keys=ON;
    CREATE TABLE users (id INTEGER PRIMARY KEY);
    CREATE TABLE rooms (id INTEGER PRIMARY KEY, type TEXT);
    CREATE TABLE seats (id INTEGER PRIMARY KEY, room_id INTEGER REFERENCES rooms(id));
    CREATE TABLE permissions (user_id INTEGER, room_id INTEGER);
    CREATE TABLE bookings (
        id INTEGER PRIMARY KEY,
        user_id INTEGER REFERENCES users(id),
        room_id INTEGER REFERENCES rooms(id),
        seat_id INTEGER REFERENCES seats(id),
        date TEXT, start_time TEXT, end_time TEXT
    );
    INSERT INTO users VALUES (1),(2);
    INSERT INTO rooms VALUES (1,'meeting'),(2,'fk-office');
    INSERT INTO seats VALUES (5,1);
    INSERT INTO bookings VALUES
        (11,1,1,NULL,'2026-10-25','10:15','10:19'),
        (12,1,NULL,5,'2026-10-25','10:15:20','10:45:50'),
        (13,2,1,NULL,'2026-10-25','10:15','10:45'),
        (14,1,2,NULL,'2026-10-25','10:15','10:45'),
        (15,1,1,NULL,'2026-10-25','09:00','09:20');
    SQL);

$write = static function (callable $action) use ($db): void {
    $db->exec('BEGIN IMMEDIATE');
    try {
        $action();
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
};

$row = roomieCheckinBooking($db, 11, 1);
$token = roomieCheckinSnapshot($row);
$legacy = roomieCheckinBooking($db, 12, 1);
$legacyToken = roomieCheckinSnapshot($legacy);
$check = static fn(int $id, int $actor, string $snapshot, string $clock) =>
    $write(static fn() => roomieCheckinConfirm($db, $id, $actor, $snapshot, $at($clock)));

verify(!roomieCheckinRoomPolicy($db, 1), 'optional storage defaults off');
verify(roomieCheckinFlags($db, [$row], $at('10:10')) === [], 'no CTA by default');
denies(fn() => $check(11, 1, $token, '10:10'), 409, 'no check-in when disabled');
$write(fn() => roomieCheckinSetPolicy($db, 1, true, $at('09:30')));
verify(roomieCheckinRoomPolicy($db, 1) && !roomieCheckinRoomPolicy($db, 2), 'scope only enabled room');
verify(roomieCheckinForRoom($db, 1, 1, $at('10:04:59')) === [], 'QR does not show early check-in');
verify(roomieCheckinForRoom($db, 1, 1, $at('10:06')) === [
    ['state' => 'eligible', 'snapshot' => $token, 'booking_id' => 11, 'time' => '10:15–10:19'],
    ['state' => 'eligible', 'snapshot' => $legacyToken, 'booking_id' => 12, 'time' => '10:15–10:45'],
], 'room QR only lists active current owner bookings, including legacy seats');
verify(roomieCheckinForRoom($db, 2, 1, $at('10:06')) === [], 'QR does not show check-in for a disabled room');

denies(fn() => $check(11, 2, $token, '10:10'), 404, 'other owner');
denies(fn() => $check(14, 1, $token, '10:10'), 404, 'restricted without permission');
denies(fn() => $check(11, 1, str_repeat('0', 64), '10:10'), 409, 'stale fingerprint');
denies(fn() => $check(11, 1, $token, '10:04:59'), 409, 'one second too early');
verify(roomieCheckinFlags($db, [$row], $at('10:05')) === [
    11 => ['state' => 'eligible', 'snapshot' => $token],
], 'start minus 10 is inclusive');
$check(11, 1, $token, '10:05');
$check(11, 1, $token, '10:06');
verify(roomieCheckinForRoom($db, 1, 1, $at('10:06'))[0] === [
    'state' => 'checked', 'booking_id' => 11, 'time' => '10:15–10:19',
], 'QR shows check-in receipt without resubmission form');

verify(roomieCheckinFlags($db, [$row], $at('10:06')) === [
    11 => ['state' => 'checked'],
], 'durable idempotent check-in marker');
denies(fn() => $check(11, 1, $token, '10:19'), 409, 'window capped by end, exclusive');
denies(fn() => $check(12, 1, $legacyToken, '10:05:19'), 409, 'legacy seconds retained');
$check(12, 1, $legacyToken, '10:05:20');
denies(fn() => $check(12, 1, $legacyToken, '10:25:20'), 409, 'ten after start is exclusive');
verify(roomieCheckinRoomReport($db, 1, $at('10:14')) === ['booked' => 0, 'checked' => 0], 'report excludes future');
verify(roomieCheckinRoomReport($db, 1, $at('10:20')) === ['booked' => 3, 'checked' => 2], 'admin aggregate excludes pre-activation');
$db->exec("UPDATE bookings SET start_time='10:18' WHERE id=11");
denies(fn() => $check(11, 1, $token, '10:10'), 409, 'stale after booking change');
$db->exec("UPDATE bookings SET start_time='10:15' WHERE id=11");
verify(!roomieCheckinAlreadyChecked($db, 11, 1), 'no marker returns after ABA time move');
$db->exec('UPDATE seats SET room_id=2 WHERE id=5; UPDATE seats SET room_id=1 WHERE id=5');
verify(!roomieCheckinAlreadyChecked($db, 12, 1), 'legacy seat move clears marker');
$check(12, 1, $legacyToken, '10:06');
$db->exec('DELETE FROM bookings WHERE id=12');
verify(!roomieCheckinAlreadyChecked($db, 12, 1), 'deleted booking erases marker');
$check(11, 1, $token, '10:07');
$write(fn() => roomieCheckinSetPolicy($db, 1, false, $at('10:08')));
verify(!roomieCheckinRoomPolicy($db, 1) && !roomieCheckinAlreadyChecked($db, 11, 1), 'policy off clears markers');
$write(fn() => roomieCheckinSetPolicy($db, 1, true, $at('10:20')));
denies(fn() => $check(11, 1, $token, '10:20'), 409, 'reenable never backdates');
verify(roomieCheckinSameOrigin(['HTTP_HOST' => 'rooms.example.org', 'HTTPS' => 'on',
    'HTTP_ORIGIN' => 'https://rooms.example.org', 'HTTP_SEC_FETCH_SITE' => 'same-origin']), 'HTTPS same origin');
verify(!roomieCheckinSameOrigin(['HTTP_HOST' => 'rooms.example.org', 'HTTPS' => 'on',
    'HTTP_ORIGIN' => 'https://elsewhere.example.org']), 'different origin rejected');
verify(!roomieCheckinSameOrigin(['HTTP_SEC_FETCH_SITE' => 'cross-site']), 'cross-site fetch metadata rejected');
echo "Room check-in: $checks checks passed.\n";
