<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../room_access.php';
require_once __DIR__ . '/../booking_approvals.php';
require_once __DIR__ . '/../booking_series_store.php';
require_once __DIR__ . '/../entra_groups.php';

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec(<<<'SQL'
    PRAGMA foreign_keys = ON;
    CREATE TABLE users (id INTEGER PRIMARY KEY, is_admin INTEGER);
    CREATE TABLE rooms (id INTEGER PRIMARY KEY, name TEXT, type TEXT, capacity INTEGER);
    CREATE TABLE seats (id INTEGER PRIMARY KEY, room_id INTEGER);
    CREATE TABLE permissions (user_id INTEGER, room_id INTEGER);
    CREATE TABLE bookings (id INTEGER PRIMARY KEY, user_id INTEGER, room_id INTEGER,
        seat_id INTEGER, date TEXT, start_time TEXT, end_time TEXT);
    INSERT INTO users VALUES (1,0),(2,0),(3,1);
    INSERT INTO rooms VALUES (1,'Freier Raum','meeting',1),(2,'Gruppenraum','meeting',1),(3,'Büro','fk-office',1);
    SQL);
$count = 0;
$check = static function (bool $ok, string $description) use (&$count): void {
    if (!$ok) {
        throw new RuntimeException($description);
    }
    $count++;
};
$book = static fn(int $room, int $user): bool => roomieCanBookRoom($db, $room, $user) !== null;
$check($book(2, 2), 'Absent schema does not change booking.');
$check(!$book(3, 3) && roomieVisibleRoom($db, 3, 3, true) !== null, 'Inspect-only admin retains existing ACL.');
roomieEntraSchema($db);
roomieEntraGroupSchema($db);
$db->exec("INSERT INTO roomie_entra_settings
    (singleton,tenant_id,client_id,group_id,enabled,interval_minutes,revision,secret_box)
    VALUES (1,'a','app','users',1,0,1,'synthetic')");
$now = time();
$db->exec("INSERT INTO roomie_entra_booking_groups (id,object_id,label,tenant_id,synced_at) VALUES
    (1,'group-1','A','a',$now),(2,'group-2','B','a',$now),(3,'group-foreign','Other tenant','b',$now)");
$db->exec("INSERT INTO roomie_entra_room_groups VALUES (2,1),(2,2),(2,3),(3,1)");
$db->exec("INSERT INTO roomie_entra_booking_group_users VALUES (1,1),(2,2),(3,3)");
$check($book(2, 1) && $book(2, 2), 'Fresh same-tenant membership in any assigned group grants the group gate.');
$check(!$book(2, 3) && roomieVisibleRoom($db, 2, 3, true) !== null, 'Foreign group and administrator role cannot bypass group gate.');
$check(!$book(3, 1), 'Group membership alone does not bypass the existing individual grant.');
$db->exec('INSERT INTO permissions VALUES (1,3),(3,3)');
$check($book(3, 1) && !$book(3, 3), 'Existing individual grant and group membership are both required.');
$check(array_column(roomieListBookableRooms($db, 3), 'id') === [1], 'Booking selector excludes group-denied rooms.');
$check($book(1, 3), 'Rooms with no group policies unaffected.');
$db->exec("UPDATE roomie_entra_booking_groups SET synced_at = " . ($now + 600) . " WHERE id=2");
$check(!$book(2, 2), 'Future sync timestamp does not grant booking.');
$db->exec("UPDATE roomie_entra_booking_groups SET synced_at = " . ($now - ROOMIE_ENTRA_GROUP_MAX_AGE - 1) . " WHERE id=2");
$check(!$book(2, 2), 'Stale snapshot does not grant booking.');
$db->exec("UPDATE roomie_entra_booking_groups SET synced_at = NULL WHERE id=2");
$check(!$book(2, 2), 'Unsynced group does not grant booking.');
$db->exec("UPDATE roomie_entra_settings SET enabled=0, secret_box=''");
$check(!$book(2, 1) && $book(1, 1), 'Disconnected integration leaves rules closed, not removed.');
$db->exec("UPDATE roomie_entra_settings SET enabled=1");
$check(!$book(2, 1), 'Enabled configuration without credential is closed.');
$db->exec("UPDATE roomie_entra_settings SET secret_box='synthetic'");
$db->exec('DELETE FROM roomie_entra_booking_group_users WHERE group_id=1 AND user_id=1');
$check(!$book(2, 1), 'Removal from snapshot denies new bookings.');
try {
    roomieAssertBookingRequestSlot($db, 1, 2, '2026-01-01', '12:00', '13:00');
    $check(false, 'Approval check must reject the original requester after membership removal.');
} catch (DomainException $error) {
    $check($error->getCode() === 403, 'Approval path checks membership for original requester.');
}

$calendarNow = new DateTimeImmutable('2026-01-01 09:00:00', new DateTimeZone('Europe/Berlin'));
$future = [['id'=>10, 'room_id'=>2, 'seat_id'=>null, 'date'=>'2026-01-03', 'start_time'=>'10:00', 'end_time'=>'11:00']];
try {
    roomieSeriesChangePreview($db, 1, $future, $calendarNow, 'edit', '11:00', '12:00');
    $check(false, 'Series edit must reject a revoked room group.');
} catch (RoomieSeriesChangeRejected $error) {
    $check($error->getCode() === 403, 'Series edit checks current membership.');
}
[, $cancelError] = roomieSeriesChangePreview($db, 1, $future, $calendarNow, 'cancel', '', '');
$check($cancelError === null, 'Group membership does not prevent cancellation.');
echo "$count Entra room access checks passed.\n";
