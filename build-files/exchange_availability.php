<?php

declare(strict_types=1);

/**
 * Normalize one room's already-fetched Graph calendarView pages, without I/O.
 *
 * The caller must supply every successfully fetched page in order for this exact
 * UTC window. This function checks the shape of the paging markers, not whether
 * a page really came from a given link. For JSON, use json_decode in object mode
 * so a malformed object used as "value" is distinguishable from an empty list.
 * Synthetic fixtures may also use associative arrays for response objects.
 *
 * The UTC query window uses YYYY-MM-DDTHH:MM:SS[.fraction]Z; Graph event times
 * use dateTimeTimeZone with timeZone=UTC and a dateTime without an offset.
 * At most 31 days, 32 pages and 10,000 total events are accepted. Known busy
 * ranges are clipped to the half-open window and overlapping/adjacent ranges
 * are merged. Unknown returns no ranges, not a verified empty calendar.
 *
 * @return array{status: 'known'|'unknown', busy: list<array{start: string, end: string}>}
 */
function roomie_normalize_exchange_availability(mixed $pages, mixed $windowStart, mixed $windowEnd): array
{
    $unknown = ['status' => 'unknown', 'busy' => []];
    $start = roomie_exchange_parse_utc($windowStart, true);
    $end = roomie_exchange_parse_utc($windowEnd, true);

    if ($start === null || $end === null || strcmp($start, $end) >= 0
        || !roomie_exchange_window_within_limit($start, $end)) {
        return $unknown;
    }
    if (!is_array($pages) || count($pages) < 1 || count($pages) > 32 || !array_is_list($pages)) {
        return $unknown;
    }

    $ranges = [];
    $seenLinks = [];
    $eventCount = 0;
    $lastPage = count($pages) - 1;
    foreach ($pages as $index => $response) {
        $page = roomie_exchange_object_fields($response);
        if ($page === null || array_key_exists('error', $page) || !is_array($page['value'] ?? null)) {
            return $unknown;
        }

        $hasNextLink = array_key_exists('@odata.nextLink', $page);
        if ($hasNextLink !== ($index < $lastPage)) {
            return $unknown;
        }
        if ($hasNextLink) {
            // An opaque completeness marker only. Never follow it or expose it.
            $link = $page['@odata.nextLink'];
            if (!is_string($link) || $link === '' || strlen($link) > 4096
                || preg_match('/[^\x21-\x7e]/', $link) === 1 || isset($seenLinks[$link])) {
                return $unknown;
            }
            $seenLinks[$link] = true;
        }

        $eventCount += count($page['value']);
        if ($eventCount > 10_000 || !array_is_list($page['value'])) {
            return $unknown;
        }
        foreach ($page['value'] as $entry) {
            $event = roomie_exchange_object_fields($entry);
            if ($event === null || !is_bool($event['isCancelled'] ?? null)) {
                return $unknown;
            }

            $eventStart = roomie_exchange_parse_event_time($event['start'] ?? null);
            $eventEnd = roomie_exchange_parse_event_time($event['end'] ?? null);
            if ($eventStart === null || $eventEnd === null || strcmp($eventStart, $eventEnd) >= 0) {
                return $unknown;
            }
            if ($event['isCancelled'] || strcmp($eventStart, $end) >= 0 || strcmp($eventEnd, $start) <= 0) {
                continue;
            }

            $status = $event['showAs'] ?? null;
            if ($status === 'free') {
                continue;
            }
            if (!in_array($status, ['tentative', 'busy', 'oof', 'workingElsewhere'], true)) {
                return $unknown;
            }

            $ranges[] = [
                'start' => strcmp($eventStart, $start) < 0 ? $start : $eventStart,
                'end' => strcmp($eventEnd, $end) > 0 ? $end : $eventEnd,
            ];
        }
    }

    usort($ranges, static fn(array $a, array $b): int =>
        strcmp($a['start'], $b['start']) ?: strcmp($a['end'], $b['end']));
    $merged = [];
    $last = -1;
    foreach ($ranges as $range) {
        if ($last >= 0 && strcmp($range['start'], $merged[$last]['end']) <= 0) {
            if (strcmp($range['end'], $merged[$last]['end']) > 0) {
                $merged[$last]['end'] = $range['end'];
            }
        } else {
            $merged[++$last] = $range;
        }
    }

    return [
        'status' => 'known',
        'busy' => array_map(static fn(array $range): array => [
            'start' => roomie_exchange_format_utc($range['start']),
            'end' => roomie_exchange_format_utc($range['end']),
        ], $merged),
    ];
}

/** @internal Accept ordinary PHP fixture maps and JSON response objects. */
function roomie_exchange_object_fields(mixed $value): ?array
{
    if ($value instanceof stdClass) {
        return get_object_vars($value);
    }

    return is_array($value) ? $value : null;
}

/** @internal */
function roomie_exchange_parse_event_time(mixed $value): ?string
{
    $time = roomie_exchange_object_fields($value);
    if (($time['timeZone'] ?? null) !== 'UTC') {
        return null;
    }

    return roomie_exchange_parse_utc($time['dateTime'] ?? null, false);
}

/** @internal Fixed-width UTC keys retain Graph's 100 ns precision for comparisons. */
function roomie_exchange_parse_utc(mixed $value, bool $requireZulu): ?string
{
    if (!is_string($value) || strlen($value) < 19 || strlen($value) > 28) {
        return null;
    }
    if ($requireZulu) {
        if (!str_ends_with($value, 'Z')) {
            return null;
        }
        $value = substr($value, 0, -1);
    }

    if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,7}))?\z/', $value, $parts) !== 1
        || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
        || (int)$parts[4] > 23 || (int)$parts[5] > 59 || (int)$parts[6] > 59) {
        return null;
    }

    return substr($value, 0, 19) . '.' . str_pad($parts[7] ?? '', 7, '0');
}

/** @internal */
function roomie_exchange_window_within_limit(string $start, string $end): bool
{
    $wholeStart = new DateTimeImmutable(substr($start, 0, 19) . 'Z');
    $wholeEnd = new DateTimeImmutable(substr($end, 0, 19) . 'Z');
    $wholeDifference = $wholeStart->diff($wholeEnd);

    return $wholeDifference->days < 31
        || ($wholeDifference->days === 31
            && $wholeDifference->h === 0 && $wholeDifference->i === 0 && $wholeDifference->s === 0
            && strcmp(substr($end, 20), substr($start, 20)) <= 0);
}

/** @internal */
function roomie_exchange_format_utc(string $key): string
{
    $fraction = rtrim(substr($key, 20), '0');

    return substr($key, 0, 19) . ($fraction === '' ? '' : '.' . $fraction) . 'Z';
}
