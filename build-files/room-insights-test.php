<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../room_insights.php';

$count = 0;
function verifyInsight(bool $condition, string $label): void
{
    global $count;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$count;
}
verifyInsight(roomieInsightsRange('2026-10-23', '2026-11-22') === ['2026-10-23','2026-11-22'], '31 local days including DST');
verifyInsight(roomieInsightsRange('2026-10-23','2026-11-23') === null, '32 days denied');
verifyInsight(roomieInsightsRange('2026-11-03','2026-11-02') === null, 'reversed range denied');
verifyInsight(roomieInsightsRange('2026-11-31','2026-12-01') === null, 'invalid calendar date denied');
verifyInsight(roomieInsightsRange([], '2026-11-01') === null, 'array date denied');

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec(<<<'SQL'
    CREATE TABLE rooms (id INTEGER PRIMARY KEY, name TEXT);
    CREATE TABLE seats (id INTEGER PRIMARY KEY, room_id INTEGER);
    CREATE TABLE bookings (room_id INTEGER, seat_id INTEGER, date TEXT, start_time TEXT, end_time TEXT);
    INSERT INTO rooms VALUES (1,'Alpha'),(2,'Beta'),(3,'Leer'),(4,'Δelta'),(5,'=synthetic');
    INSERT INTO seats VALUES (6,1),(7,2);
    INSERT INTO bookings VALUES
        (1,7,'2026-10-25','09:00','10:00'),
        (1,NULL,'2026-10-25','09:45','10:23:00'),
        (NULL,6,'2026-10-25','10:22','11:00:00'),
        (1,NULL,'2026-10-25','10:30','10:40'),
        (1,NULL,'2026-10-25','10:22','10:22'),
        (1,NULL,'2026-10-25','11:00','11:00:01'),
        (1,NULL,'2026-10-25','12:22','12:22:59'),
        (1,NULL,'2026-10-26','09:50','10:00'),
        (2,NULL,'2026-10-24','09:15','09:30'),
        (NULL,7,'2026-10-25','10:00:00','10:15:00'),
        (2,NULL,'2026-10-25','24:00','24:10'),
        (2,NULL,'2026-11-01','09:00','10:00'),
        (4,NULL,'2026-10-25','00:00','00:00:01'),
        (99,NULL,'2026-10-25','09:00','10:00');
    SQL);
$report = roomieRoomInsights($db, '2026-10-25','2026-10-26');
$rooms = array_column($report['rooms'], null, 'room_name');
verifyInsight(count($rooms) === 5, 'all rooms listed');
verifyInsight($rooms['Alpha'] === ['room_name'=>'Alpha','booked_seconds'=>7860,'peak_hours'=>[9]], 'merged adjacent and overlapping, second precision, per-date sum');
verifyInsight($rooms['Beta'] === ['room_name'=>'Beta','booked_seconds'=>900,'peak_hours'=>[10]], 'legacy seat fallback, explicit room takes precedence, invalid omitted');
verifyInsight($rooms['Leer'] === ['room_name'=>'Leer','booked_seconds'=>0,'peak_hours'=>[]], 'no peak when empty');
verifyInsight($rooms['Δelta'] === ['room_name'=>'Δelta','booked_seconds'=>1,'peak_hours'=>[0]], 'clock midnight exact second');
verifyInsight($report['booked_seconds'] === 8761 && $report['peak_hours'] === [10], 'overall aggregation after room union');
$tie = roomieRoomInsights($db, '2026-10-25','2026-10-25');
verifyInsight(array_column($tie['rooms'], null, 'room_name')['Alpha']['peak_hours'] === [9,10], 'all equal peaks reported');

foreach (['=synthetic', '+synthetic', '-synthetic', '@synthetic', " \t=synthetic",
    "\u{200b}=synthetic", "\u{FEFF}=synthetic", '＝synthetic', '＋synthetic', '−synthetic', '＠synthetic',
    " \u{200f}＝synthetic", "\0=synthetic"] as $label) {
    verifyInsight(str_starts_with(roomieInsightsCsvLabel($label), "'"), 'formula markers escaped');
}
verifyInsight(roomieInsightsCsvLabel('Übung 5, "West"') === 'Übung 5, "West"', 'regular label remains exact');
$utf8 = fopen('php://temp', 'wb+');
roomieInsightsWriteCsv($utf8, $report, '2026-10-25', '2026-10-26');
rewind($utf8);
verifyInsight(fread($utf8, 3) === "\xEF\xBB\xBF", 'UTF-8 BOM');
verifyInsight(fgetcsv($utf8, 0, ',', '"', '') === ['Zeitraum von','Zeitraum bis','Raum','Gebuchte Minuten','Stärkste Stunden (Berlin)'], 'minimal sheet schema');
$escaped = fgetcsv($utf8, 0, ',', '"', '');
verifyInsight($escaped === ['2026-10-25','2026-10-26',"'=synthetic",'0',''], 'formula marker retained as text');
$alpha = fgetcsv($utf8, 0, ',', '"', '');
verifyInsight($alpha === ['2026-10-25','2026-10-26','Alpha','131','09–10 Uhr'], 'numeric CSV and correct peak');
verifyInsight(roomieInsightsDuration(3661) === '1 Std. 1 Min. 1 Sek.', 'UI duration with seconds');
echo "Room insights: $count checks passed.\n";
