<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../booking_series_store.php';
require_once __DIR__ . '/../room_checkin.php';

$count = 0;
function assertSeries(bool $ok, string $name): void
{
    global $count;
    if (!$ok) {
        throw new RuntimeException($name);
    }
    ++$count;
}
function seriesDenied(callable $action, int $status, string $name): void
{
    try {
        $action();
    } catch (RoomieSeriesChangeRejected $error) {
        assertSeries($error->getCode() === $status, $name);
        return;
    }
    throw new RuntimeException($name);
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec(<<<'SQL'
    PRAGMA foreign_keys = ON;
    CREATE TABLE users (id INTEGER PRIMARY KEY);
    CREATE TABLE rooms (id INTEGER PRIMARY KEY, name TEXT, type TEXT, capacity INTEGER);
    CREATE TABLE seats (id INTEGER PRIMARY KEY, room_id INTEGER REFERENCES rooms(id));
    CREATE TABLE permissions (user_id INTEGER, room_id INTEGER);
    CREATE TABLE bookings (
        id INTEGER PRIMARY KEY, user_id INTEGER, room_id INTEGER, seat_id INTEGER,
        date TEXT, start_time TEXT, end_time TEXT
    );
    INSERT INTO users VALUES (1),(2);
    INSERT INTO rooms VALUES (1,'Raum A','meeting',1),(2,'Raum B','fk-office',1);
    INSERT INTO seats VALUES (5,1);
    INSERT INTO bookings VALUES
        (11,1,1,NULL,'2026-10-25','09:15','10:15'),
        (12,1,1,NULL,'2026-10-25','11:00','11:45'),
        (13,1,1,NULL,'2026-11-02','11:00','11:45'),
        (14,1,NULL,5,'2026-11-03','11:00:00','11:45:00'),
        (21,1,1,NULL,'2026-11-09','11:00','11:45'),
        (33,2,1,NULL,'2026-11-02','12:30','13:00');
    SQL);
$at = static fn(string $time): DateTimeImmutable =>
    new DateTimeImmutable('2026-10-25 ' . $time, new DateTimeZone('Europe/Berlin'));
$now = $at('10:55');
assertSeries(roomieBookingSeriesIds($db, 1, [11, 21]) === [], 'old schema no inferred groups');
$db->exec('BEGIN IMMEDIATE');
$series = roomieBookingSeriesCreate($db, 1);
$db->exec("INSERT INTO booking_series_members VALUES (11,$series),(12,$series),(13,$series),(14,$series)");
$db->exec('COMMIT');
assertSeries(roomieBookingSeriesIds($db, 1, [21, 12]) === [12 => $series], 'new owner metadata only');
assertSeries(roomieBookingSeriesIds($db, 2, [12]) === [], 'not discoverable by other users');
seriesDenied(fn() => roomieSeriesFuture($db, 2, $series, $now), 404, 'private series');
$future = fn(): array => roomieSeriesFuture($db, 1, $series, $now);
assertSeries(array_column($future(), 'id') === [12,13,14], 'already started removed');
assertSeries(array_column(roomieSeriesFuture($db, 1, $series, $at('11:00')), 'id') === [13,14], 'exact start cutoff');
$originalSnapshot = roomieSeriesManageSnapshot($future());
$db->exec("UPDATE bookings SET date = '2026-11-03' WHERE id=13");
assertSeries($originalSnapshot !== roomieSeriesManageSnapshot($future()), 'moved occurrence invalidates exact snapshot');
$preview = fn(string $start, string $end): array => roomieSeriesChangePreview($db, 1, $future(), $now, 'edit', $start, $end);
[$display, $error] = $preview('13:00', '14:00');
assertSeries($error !== null && array_column($display, 'date') === ['2026-10-25', '2026-11-03', '2026-11-03']
    && str_contains($display[2]['message'] ?? '', 'eigener'), 'planned-vs-planned overlap on changed dates');
$db->exec("UPDATE bookings SET date = '2026-11-02' WHERE id=13");
[$display, $error] = $preview('12:00', '13:00');
assertSeries($error !== null && $display[1]['message'] === 'Keine freie Kapazität.', 'external booking conflict');
roomieInstallRoomBlocksSchema($db);
$db->exec("INSERT INTO room_blocks (room_id,date,start_time,end_time) VALUES (1,'2026-11-03','13:00','14:00')");
[$display, $error] = $preview('13:00', '13:30');
assertSeries($error !== null && $display[2]['message'] === 'Raum gesperrt.', 'closure conflict');
roomieInstallBookingApprovalsSchema($db);
$db->exec('INSERT INTO room_booking_approval_policies VALUES (1)');
[$display, $error] = $preview('14:00', '14:30');
assertSeries($error !== null && $display[0]['message'] === 'Freigabe erforderlich.', 'approval protects edits');
[, $error] = roomieSeriesChangePreview($db, 1, $future(), $now, 'cancel', '', '');
assertSeries($error === null, 'existing reservations can still be canceled with approval now enabled');
$db->exec('DELETE FROM room_booking_approval_policies; UPDATE bookings SET room_id=2 WHERE id=13');
seriesDenied(fn() => $preview('14:00', '14:30'), 403, 'revoked/missing room ACL');
$db->exec('UPDATE bookings SET room_id=1 WHERE id=13');
[, $error] = $preview('14:00', '14:30');
assertSeries($error === null, 'valid time proposal');
$db->exec('BEGIN IMMEDIATE');
roomieCheckinSetPolicy($db, 1, true, $at('09:00'));
$row = roomieCheckinBooking($db, 12, 1);
roomieCheckinConfirm($db, 12, 1, roomieCheckinSnapshot($row), $now);
assertSeries(roomieCheckinAlreadyChecked($db, 12, 1), 'valid check-in fixture');
roomieSeriesChangeApply($db, 1, $future(), 'edit', '14:00', '14:30');
$db->exec('COMMIT');
assertSeries(!roomieCheckinAlreadyChecked($db, 12, 1), 'moved occurrences lose check-in');
assertSeries($db->query('SELECT start_time FROM bookings WHERE id=11')->fetchColumn() === '09:15'
    && array_column($future(), 'start_time') === ['14:00','14:00','14:00'], 'past intact, all future edited');
$db->exec('DELETE FROM bookings WHERE id=13');
assertSeries(array_column($future(), 'id') === [12,14], 'individual cancellation not resurrected');
$db->exec('BEGIN IMMEDIATE');
roomieSeriesChangeApply($db, 1, $future(), 'cancel', '', '');
$db->exec('COMMIT');
assertSeries($future() === [] && $db->query('SELECT id FROM bookings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === [11,21,33],
    'only remaining future series rows canceled, not old or unrelated');
echo "Room series management: $count checks passed.\n";
