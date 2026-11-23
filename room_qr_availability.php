<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_helpers.php';

/**
 * Calculate free capacity and the first bookable 30-minute slot without returning
 * booking rows. Boundaries retain legacy second precision and are half-open.
 */
function roomieQrAvailability(DateTimeImmutable $now, int $capacity, int $userId, array $bookings, array $blocks): array
{
    $now = $now->setTimezone(new DateTimeZone('Europe/Berlin'));
    $open = ROOMIE_BOOKING_OPEN_TIME . ':00';
    $close = ROOMIE_BOOKING_CLOSE_TIME . ':00';
    $clock = $now->format('H:i:s');
    $result = [
        'status' => 'closed',
        'until' => $clock < $open ? $open : null,
        'slot' => null,
    ];
    if ($clock >= $close) {
        return $result;
    }
    $changes = [$open => [0, 0, 0], $close => [0, 0, 0]];
    foreach ([[$bookings, false], [$blocks, true]] as [$rows, $block]) {
        foreach ($rows as $row) {
            $from = roomieBookingClock($row['start_time'] ?? null);
            $to = roomieBookingClock($row['end_time'] ?? null);
            if ($from === null || $to === null || $from >= $to) {
                throw new UnexpectedValueException('Invalid Roomie interval');
            }
            $from = max($from, $open);
            $to = min($to, $close);
            if ($from >= $to) {
                continue;
            }
            $dimensions = $block ? [2] : ((int) ($row['user_id'] ?? 0) === $userId ? [0, 1] : [0]);
            foreach ([[$from, 1], [$to, -1]] as [$time, $difference]) {
                $changes[$time] ??= [0, 0, 0];
                foreach ($dimensions as $dimension) {
                    $changes[$time][$dimension] += $difference;
                }
            }
        }
    }
    ksort($changes, SORT_STRING);
    $segments = [];
    $segmentsCount = 0;
    $filled = $own = $blocked = 0;
    foreach ($changes as $start => [$bookedDelta, $ownDelta, $blockedDelta]) {
        if ($segmentsCount > 0) {
            $segments[$segmentsCount - 1]['end'] = $start;
        }
        if ($start === $close) {
            break;
        }
        $filled += $bookedDelta;
        $own += $ownDelta;
        $blocked += $blockedDelta;
        $status = $blocked > 0 ? 'blocked' : ($filled >= $capacity ? 'busy' : 'free');
        $segments[] = ['start' => $start, 'end' => $close, 'status' => $status, 'own' => $own > 0];
        ++$segmentsCount;
    }

    if ($clock >= $open) {
        foreach ($segments as $segment) {
            if ($segment['end'] <= $clock) {
                continue;
            }
            if ($segment['start'] <= $clock) {
                $result['status'] = $segment['status'];
            } elseif ($segment['status'] !== $result['status']) {
                break;
            }
            $result['until'] = $segment['end'];
        }
    }

    // The booking endpoint requires a strictly future start on the 15-minute grid.
    $day = $now->setTime(0, 0);
    $start = $day->modify(ROOMIE_BOOKING_OPEN_TIME);
    $step = '+' . ROOMIE_BOOKING_STEP_MINUTES . ' minutes';
    $duration = '+30 minutes';
    $endOfDay = $day->modify(ROOMIE_BOOKING_CLOSE_TIME);
    $segmentIndex = 0;
    for (; $start->modify($duration) <= $endOfDay; $start = $start->modify($step)) {
        if ($start <= $now) {
            continue;
        }
        $end = $start->modify($duration);
        $startClock = $start->format('H:i:s');
        $endClock = $end->format('H:i:s');
        while ($segmentIndex < $segmentsCount && $segments[$segmentIndex]['end'] <= $startClock) {
            ++$segmentIndex;
        }
        for ($i = $segmentIndex; $i < $segmentsCount && $segments[$i]['start'] < $endClock; ++$i) {
            if ($segments[$i]['status'] !== 'free' || $segments[$i]['own']) {
                continue 2;
            }
        }
        $result['slot'] = ['start' => $start->format('H:i'), 'end' => $end->format('H:i')];
        break;
    }
    return $result;
}
