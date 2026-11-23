<?php
declare(strict_types=1);
require_once __DIR__ . '/../room_qr_availability.php';

$tz = new DateTimeZone('Europe/Berlin');
$assertions = 0;

function row(string $start, string $end, int $userId = 2): array
{
    return ['start_time' => $start, 'end_time' => $end, 'user_id' => $userId];
}

function check(string $label, string $status, ?string $until, ?array $slot, DateTimeImmutable $now,
    int $capacity = 1, array $bookings = [], array $blocks = []): void
{
    global $assertions;
    $expected = compact('status', 'until', 'slot');
    $actual = roomieQrAvailability($now, $capacity, 1, $bookings, $blocks);
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . json_encode(['expected' => $expected, 'actual' => $actual]));
    }
    if ($slot !== null) {
        roomieParseCustomBookingTimes($now->setTimezone(new DateTimeZone('Europe/Berlin'))->setTime(0, 0), $slot['start'], $slot['end'], $now);
        if (roomieBookingConflict($bookings, $capacity, 1, $slot['start'], $slot['end']) !== null) {
            throw new RuntimeException($label . ': engine disagrees with offered slot');
        }
    }
    ++$assertions;
}

function slot(string $start, string $end): array
{
    return compact('start', 'end');
}

$at = static fn(string $clock): DateTimeImmutable => new DateTimeImmutable("2026-11-23 $clock", $tz);
check('open and strictly future', 'free', '17:00:00', slot('10:15', '10:45'), $at('10:00:00'));
check('last slot', 'free', '17:00:00', slot('16:30', '17:00'), $at('16:29:59'));
check('last start already reached', 'free', '17:00:00', null, $at('16:30:00'));
check('closed before opening', 'closed', '09:00:00', slot('09:00', '09:30'), $at('08:59:00'));
check('closed after opening hours', 'closed', null, null, $at('17:00:00'));
check('short legacy overlap is not lost', 'free', '10:30:05', slot('10:45', '11:15'), $at('10:10'), 1,
    [row('10:30:05', '10:30:15')]);
check('legacy ends at precisely future grid start', 'busy', '10:15:00', slot('10:15', '10:45'), $at('10:14:59'), 1,
    [row('09:00:00', '10:15:00')]);
check('continuous saturation from successive users', 'busy', '10:45:00', slot('10:45', '11:15'), $at('10:14'), 1,
    [row('09:00', '10:15'), row('10:15', '10:45', 3)]);
check('successive users do not double-count at a shared boundary', 'free', '17:00:00', slot('10:15', '10:45'), $at('10:05'), 2,
    [row('10:00', '10:30'), row('10:30', '10:45', 3)]);
check('actual peak across overlaps', 'free', '10:40:00', slot('11:00', '11:30'), $at('10:10'), 2,
    [row('10:00', '11:00'), row('10:40', '11:15', 3)]);
check('viewer sees free capacity but cannot overlap own booking', 'free', '17:00:00', slot('10:45', '11:15'), $at('10:05'), 2,
    [row('10:15', '10:45', 1)]);
check('block closes a room independently of capacity', 'blocked', '11:00:00', slot('11:00', '11:30'), $at('10:15'), 40,
    [], [row('10:00', '11:00')]);
check('blocked start not offered', 'free', '10:30:00', slot('11:00', '11:30'), $at('10:05'), 1,
    [], [row('10:30', '11:00')]);
check('off-hours legacy row cropped to schedule', 'busy', '09:15:00', slot('09:15', '09:45'), $at('09:00'), 1,
    [row('08:15', '09:15')]);
check('UTC caller normalized to Berlin', 'free', '17:00:00', slot('10:15', '10:45'), new DateTimeImmutable('2026-11-23 09:08:00Z'));

try {
    roomieQrAvailability($at('10:00'), 1, 1, [row('10:00', 'bad')], []);
    throw new RuntimeException('Malformed schedule must fail closed');
} catch (UnexpectedValueException) {
    ++$assertions;
}
echo "Room QR: $assertions checks passed.\n";
