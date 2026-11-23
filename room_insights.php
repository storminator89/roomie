<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_helpers.php';

const ROOMIE_INSIGHTS_MAX_DAYS = 31;

function roomieInsightsRange(mixed $from, mixed $to): ?array
{
    $start = parseBookingDate($from);
    $end = parseBookingDate($to);
    if ($start === null || $end === null || $start > $end || $start->diff($end)->days >= ROOMIE_INSIGHTS_MAX_DAYS) {
        return null;
    }
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function roomieInsightsClockSeconds(mixed $clock): ?int
{
    $value = roomieBookingClock($clock);
    return $value === null ? null : (int) substr($value, 0, 2) * 3600 +
        (int) substr($value, 3, 2) * 60 + (int) substr($value, 6, 2);
}

/** Split a normalized half-open interval across local clock-hour bins. */
function roomieInsightsAddInterval(array &$hours, int $start, int $end): void
{
    for ($cursor = $start; $cursor < $end;) {
        $hour = intdiv($cursor, 3600);
        $edge = min($end, ($hour + 1) * 3600);
        $hours[$hour] += $edge - $cursor;
        $cursor = $edge;
    }
}

function roomieInsightsPeakHours(array $hours): array
{
    $peak = max($hours);
    return $peak === 0 ? [] : array_keys($hours, $peak, true);
}

/** In one read snapshot; no booking owner, individual record count, or names are read. */
function roomieRoomInsights(PDO $db, string $from, string $to): array
{
    if (roomieInsightsRange($from, $to) === null) {
        throw new InvalidArgumentException('Invalid room report date range');
    }
    $rooms = [];
    foreach ($db->query('SELECT id, name FROM rooms ORDER BY name COLLATE NOCASE, id') as $room) {
        $rooms[(int) $room['id']] = [
            'room_name' => (string) $room['name'],
            'hours' => array_fill(0, 24, 0),
        ];
    }
    $effectiveRoom = ROOMIE_EFFECTIVE_BOOKING_ROOM_SQL;
    $checkDate = '';
    $validDate = false;
    $active = null;
    // For valid HH:MM or HH:MM:SS records, binary lexical start order also
    // orders normalized seconds. At most one merged range is kept in memory.
    $query = $db->prepare("SELECT r.id AS room_id, b.date, b.start_time, b.end_time
        FROM bookings b JOIN rooms r ON r.id = $effectiveRoom
        WHERE b.date BETWEEN ? AND ?
        ORDER BY b.date COLLATE BINARY, r.id, b.start_time COLLATE BINARY");
    $query->execute([$from, $to]);
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        if ($row['date'] !== $checkDate) {
            $checkDate = $row['date'];
            $validDate = parseBookingDate($checkDate) !== null;
        }
        if (!$validDate) {
            continue;
        }
        $start = roomieInsightsClockSeconds($row['start_time']);
        $end = roomieInsightsClockSeconds($row['end_time']);
        if ($start === null || $end === null || $start >= $end) {
            continue;
        }
        $roomId = (int) $row['room_id'];
        if ($active !== null && $active['room'] === $roomId && $active['date'] === $checkDate && $start <= $active['end']) {
            $active['end'] = max($active['end'], $end);
            continue;
        }
        if ($active !== null) {
            roomieInsightsAddInterval($rooms[$active['room']]['hours'], $active['start'], $active['end']);
        }
        $active = ['room' => $roomId, 'date' => $checkDate, 'start' => $start, 'end' => $end];
    }
    if ($active !== null) {
        roomieInsightsAddInterval($rooms[$active['room']]['hours'], $active['start'], $active['end']);
    }

    $rows = [];
    $totalHours = array_fill(0, 24, 0);
    foreach ($rooms as $room) {
        foreach ($room['hours'] as $hour => $seconds) {
            $totalHours[$hour] += $seconds;
        }
        $rows[] = [
            'room_name' => $room['room_name'],
            'booked_seconds' => array_sum($room['hours']),
            'peak_hours' => roomieInsightsPeakHours($room['hours']),
        ];
    }
    return ['rooms' => $rows, 'booked_seconds' => array_sum($totalHours), 'peak_hours' => roomieInsightsPeakHours($totalHours)];
}

function roomieInsightsDuration(int $seconds): string
{
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remainder = $seconds % 60;
    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' Std.';
    }
    if ($minutes > 0 || $seconds === 0) {
        $parts[] = $minutes . ' Min.';
    }
    if ($remainder > 0) {
        $parts[] = $remainder . ' Sek.';
    }
    return implode(' ', $parts);
}

function roomieInsightsHourLabel(int $hour): string
{
    return sprintf('%02d–%02d Uhr', $hour, $hour + 1);
}

/** CSV quotes protect structure, not spreadsheets; escape any non-alphanumeric prefix. */
function roomieInsightsCsvLabel(string $label): string
{
    if (preg_match('//u', $label) !== 1) {
        return 'Raum';
    }
    // Keep letters and plain ASCII decimal digits only. Unicode math/width
    // aliases of formula sigils are symbols/punctuation, not letters.
    $safe = preg_match('/\A(?:\p{L}|[0-9])/u', $label) === 1;
    $label = preg_replace('/\p{C}/u', ' ', $label) ?? 'Raum';
    return $safe ? $label : "'" . $label;
}

function roomieInsightsWriteCsv($stream, array $report, string $from, string $to): void
{
    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, ['Zeitraum von', 'Zeitraum bis', 'Raum', 'Gebuchte Minuten', 'Stärkste Stunden (Berlin)'], ',', '"', '', "\r\n");
    foreach ($report['rooms'] as $room) {
        // Source intervals retain second precision; serialize fractional minutes.
        $mins = rtrim(rtrim(number_format($room['booked_seconds'] / 60, 4, '.', ''), '0'), '.');
        fputcsv($stream, [$from, $to, roomieInsightsCsvLabel($room['room_name']), $mins,
            implode('; ', array_map('roomieInsightsHourLabel', $room['peak_hours']))], ',', '"', '', "\r\n");
    }
}
