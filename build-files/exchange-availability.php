<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/exchange_availability.php';

const ROOMIE_TEST_WINDOW_START = '2026-11-22T10:00:00Z';
const ROOMIE_TEST_WINDOW_END = '2026-11-22T11:00:00Z';
const ROOMIE_TEST_UNKNOWN = ['status' => 'unknown', 'busy' => []];
const ROOMIE_TEST_CLEAR = ['status' => 'known', 'busy' => []];
const ROOMIE_TEST_NEXT_LINK = 'https://graph.example.invalid/calendarView?$skiptoken=offline-fixture-only';

function roomie_fixture_event(string $start, string $end, mixed $showAs = 'busy', mixed $cancelled = false): array
{
    return [
        'start' => ['dateTime' => $start, 'timeZone' => 'UTC'],
        'end' => ['dateTime' => $end, 'timeZone' => 'UTC'],
        'showAs' => $showAs,
        'isCancelled' => $cancelled,
    ];
}

function roomie_fixture_page(array $events, ?string $nextLink = null): array
{
    $page = ['value' => $events];
    if ($nextLink !== null) {
        $page['@odata.nextLink'] = $nextLink;
    }
    return $page;
}

function roomie_fixture_known(array ...$ranges): array
{
    return [
        'status' => 'known',
        'busy' => array_map(
            static fn (array $range): array => ['start' => $range[0], 'end' => $range[1]],
            $ranges,
        ),
    ];
}

$failures = [];
$checks = 0;

function roomie_fixture_check(
    string $label,
    array $expected,
    mixed $pages,
    mixed $start = ROOMIE_TEST_WINDOW_START,
    mixed $end = ROOMIE_TEST_WINDOW_END,
): void {
    global $checks, $failures;
    ++$checks;
    try {
        $actual = roomie_normalize_exchange_availability($pages, $start, $end);
        if ($actual !== $expected) {
            $failures[] = $label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual);
        }
    } catch (Throwable $error) {
        $failures[] = $label . ': threw ' . get_class($error) . ': ' . $error->getMessage();
    }
}

$clearPage = roomie_fixture_page([]);
$busy = roomie_fixture_event('2026-11-22T10:12:00', '2026-11-22T10:34:00');
$busyRange = roomie_fixture_known(['2026-11-22T10:12:00Z', '2026-11-22T10:34:00Z']);

roomie_fixture_check('explicit completed empty response', ROOMIE_TEST_CLEAR, [$clearPage]);
roomie_fixture_check('valid busy event', $busyRange, [roomie_fixture_page([$busy])]);
roomie_fixture_check('no response does not mean free', ROOMIE_TEST_UNKNOWN, null);
roomie_fixture_check('no pages does not mean free', ROOMIE_TEST_UNKNOWN, []);
roomie_fixture_check('page is not a list of pages', ROOMIE_TEST_UNKNOWN, $clearPage);
roomie_fixture_check('missing Graph value', ROOMIE_TEST_UNKNOWN, [['@odata.context' => 'offline']]);
roomie_fixture_check('Graph value is null', ROOMIE_TEST_UNKNOWN, [['value' => null]]);
roomie_fixture_check('Graph value is not a numeric list', ROOMIE_TEST_UNKNOWN, [['value' => ['event' => $busy]]]);
roomie_fixture_check('Graph error envelope', ROOMIE_TEST_UNKNOWN, [['error' => ['code' => 'offline']]]);

// A typical transport uses json_decode(..., false), which preserves the
// distinction between an empty JSON list and an invalid empty JSON object.
$decodeGraphPage = static fn (string $json): mixed => json_decode($json, false, 512, JSON_THROW_ON_ERROR);
roomie_fixture_check('native JSON empty list', ROOMIE_TEST_CLEAR, [$decodeGraphPage('{"value":[]}')]);
roomie_fixture_check('native JSON empty object is not a verified empty list', ROOMIE_TEST_UNKNOWN, [$decodeGraphPage('{"value":{}}')]);
roomie_fixture_check('native JSON object with numeric keys is not an event list', ROOMIE_TEST_UNKNOWN, [
    $decodeGraphPage('{"value":{"0":' . json_encode($busy, JSON_THROW_ON_ERROR) . '}}'),
]);
roomie_fixture_check('native JSON decode returns busy range without event metadata', $busyRange, [
    $decodeGraphPage(json_encode(roomie_fixture_page([$busy + ['subject' => 'synthetic private subject']]), JSON_THROW_ON_ERROR)),
]);
roomie_fixture_check('native JSON error field invalidates even explicit empty list', ROOMIE_TEST_UNKNOWN, [
    $decodeGraphPage('{"value":[],"error":{"code":"offline"}}'),
]);
roomie_fixture_check('outer page list is not a JSON object', ROOMIE_TEST_UNKNOWN, $decodeGraphPage('{"0":{"value":[]}}'));


roomie_fixture_check(
    'valid nonterminal page plus empty terminal page',
    $busyRange,
    [roomie_fixture_page([$busy], ROOMIE_TEST_NEXT_LINK), $clearPage],
);
roomie_fixture_check(
    'partial result does not use previously seen busy ranges',
    ROOMIE_TEST_UNKNOWN,
    [roomie_fixture_page([$busy], ROOMIE_TEST_NEXT_LINK)],
);
roomie_fixture_check(
    'invalid terminal page does not use preceding ranges',
    ROOMIE_TEST_UNKNOWN,
    [roomie_fixture_page([$busy], ROOMIE_TEST_NEXT_LINK), ['error' => ['code' => 'offline']]],
);
roomie_fixture_check(
    'extra page after terminal is invalid',
    ROOMIE_TEST_UNKNOWN,
    [$clearPage, roomie_fixture_page([$busy])],
);
roomie_fixture_check('empty continuation is invalid', ROOMIE_TEST_UNKNOWN, [roomie_fixture_page([], ''), $clearPage]);
roomie_fixture_check('null continuation is not a complete response', ROOMIE_TEST_UNKNOWN, [['value' => [], '@odata.nextLink' => null]]);
roomie_fixture_check('nonstring continuation is invalid', ROOMIE_TEST_UNKNOWN, [['value' => [], '@odata.nextLink' => 42], $clearPage]);
roomie_fixture_check('whitespace continuation is invalid', ROOMIE_TEST_UNKNOWN, [roomie_fixture_page([], " \n"), $clearPage]);


$free = roomie_fixture_event('2026-11-22T10:00:00', '2026-11-22T11:00:00', 'free');
$cancelled = roomie_fixture_event('2026-11-22T10:00:00', '2026-11-22T11:00:00', 'busy', true);
$unknown = roomie_fixture_event('2026-11-22T10:30:00', '2026-11-22T10:45:00', 'unknown');
$unknownOutside = roomie_fixture_event('2026-11-22T11:00:00', '2026-11-22T12:00:00', 'unknown');
roomie_fixture_check('free and cancelled events do not block', ROOMIE_TEST_CLEAR, [roomie_fixture_page([$free, $cancelled])]);
roomie_fixture_check('free and cancelled events do not widen busy range', $busyRange, [roomie_fixture_page([$free, $cancelled, $busy])]);
roomie_fixture_check('overlapping unknown status fails closed', ROOMIE_TEST_UNKNOWN, [roomie_fixture_page([$busy, $unknown])]);
roomie_fixture_check('out-of-window unknown status is irrelevant', $busyRange, [roomie_fixture_page([$busy, $unknownOutside])]);

$endsAtStart = roomie_fixture_event('2026-11-22T09:30:00', '2026-11-22T10:00:00');
$startsAtEnd = roomie_fixture_event('2026-11-22T11:00:00', '2026-11-22T12:00:00');
roomie_fixture_check('half-open boundaries exclude adjacent external ranges', ROOMIE_TEST_CLEAR, [roomie_fixture_page([$endsAtStart, $startsAtEnd])]);
roomie_fixture_check(
    'range spanning window is clipped to exact boundaries',
    roomie_fixture_known([ROOMIE_TEST_WINDOW_START, ROOMIE_TEST_WINDOW_END]),
    [roomie_fixture_page([roomie_fixture_event('2026-11-22T09:00:00', '2026-11-22T12:00:00')])],
);
roomie_fixture_check(
    'fractional seconds retained at Graph precision and trimmed canonically',
    roomie_fixture_known(['2026-11-22T10:00:00.0000001Z', '2026-11-22T10:00:00.000001Z']),
    [roomie_fixture_page([
        roomie_fixture_event('2026-11-22T09:59:59.9999999', '2026-11-22T10:00:00.0000010'),
    ])],
    '2026-11-22T10:00:00.0000001Z',
    '2026-11-22T10:00:00.0000020Z',
);
roomie_fixture_check(
    'fractional right boundary excludes exact match',
    ROOMIE_TEST_CLEAR,
    [roomie_fixture_page([
        roomie_fixture_event('2026-11-22T11:00:00.0000001', '2026-11-22T11:00:00.0000002'),
    ])],
    ROOMIE_TEST_WINDOW_START,
    '2026-11-22T11:00:00.0000001Z',
);
roomie_fixture_check(
    'fractional next tick intersects despite equal whole seconds',
    roomie_fixture_known(['2026-11-22T10:00:00.0000001Z', '2026-11-22T10:00:00.0000002Z']),
    [roomie_fixture_page([
        roomie_fixture_event('2026-11-22T10:00:00.0000001', '2026-11-22T10:00:00.0000002'),
    ])],
);
roomie_fixture_check(
    'sorted, merge overlaps and adjacency across pages without dropping disjoint range',
    roomie_fixture_known(
        ['2026-11-22T10:05:00Z', '2026-11-22T10:30:00Z'],
        ['2026-11-22T10:44:00Z', '2026-11-22T10:45:00Z'],
    ),
    [
        roomie_fixture_page([
            roomie_fixture_event('2026-11-22T10:44:00', '2026-11-22T10:45:00'),
            roomie_fixture_event('2026-11-22T10:20:00', '2026-11-22T10:30:00'),
            roomie_fixture_event('2026-11-22T10:08:00', '2026-11-22T10:09:00'),
        ], ROOMIE_TEST_NEXT_LINK),
        roomie_fixture_page([
            roomie_fixture_event('2026-11-22T10:05:00', '2026-11-22T10:15:00'),
            roomie_fixture_event('2026-11-22T10:14:00', '2026-11-22T10:20:00'),
        ]),
    ],
);

$metadata = [
    'id' => 'synthetic-event-id',
    'seriesMasterId' => 'synthetic-recurrence-id',
    'subject' => 'synthetic confidential title',
    'organizer' => ['emailAddress' => ['name' => 'Synthetic Person', 'address' => 'person@example.invalid']],
    'attendees' => [['emailAddress' => ['address' => 'attendee@example.invalid']]],
    'body' => ['content' => 'synthetic confidential body'],
    'location' => ['displayName' => 'synthetic private location'],
    'webLink' => 'https://example.invalid/hidden',
    'recurrence' => ['pattern' => ['type' => 'daily', 'interval' => 1]],
];
$metadataEvent = $busy + $metadata;
roomie_fixture_check('returns only normalized ranges, excluding Graph metadata and continuation', $busyRange, [
    roomie_fixture_page([$metadataEvent], ROOMIE_TEST_NEXT_LINK) + ['@odata.context' => 'synthetic context'],
    $clearPage,
]);

// These are already-expanded calendarView occurrences spanning Berlin's spring transition.
// 09:00 local is 08:00 UTC before the transition and 07:00 UTC afterwards.
$occurrenceBefore = roomie_fixture_event('2026-03-28T08:00:00', '2026-03-28T08:30:00') + ['type' => 'occurrence'] + $metadata;
$occurrenceAfter = roomie_fixture_event('2026-03-29T07:00:00', '2026-03-29T07:30:00') + ['type' => 'occurrence'] + $metadata;
$cancelledOccurrence = roomie_fixture_event('2026-03-30T07:00:00', '2026-03-30T07:30:00', 'busy', true) + ['type' => 'exception'] + $metadata;
roomie_fixture_check(
    'expanded recurrence is not re-expanded or deduplicated by series identity',
    roomie_fixture_known(
        ['2026-03-28T08:00:00Z', '2026-03-28T08:30:00Z'],
        ['2026-03-29T07:00:00Z', '2026-03-29T07:30:00Z'],
    ),
    [roomie_fixture_page([$occurrenceAfter, $cancelledOccurrence, $occurrenceBefore])],
    '2026-03-28T00:00:00Z',
    '2026-03-30T23:59:59Z',
);
roomie_fixture_check(
    'Berlin spring-forward calendar day is a UTC window of 23 hours',
    roomie_fixture_known(
        ['2026-03-28T23:00:00Z', '2026-03-28T23:30:00Z'],
        ['2026-03-29T21:30:00Z', '2026-03-29T22:00:00Z'],
    ),
    [roomie_fixture_page([
        roomie_fixture_event('2026-03-28T22:30:00', '2026-03-28T23:30:00'),
        roomie_fixture_event('2026-03-29T21:30:00', '2026-03-29T22:30:00'),
    ])],
    '2026-03-28T23:00:00Z',
    '2026-03-29T22:00:00Z',
);
roomie_fixture_check(
    'Berlin fall-back calendar day retains both repetitions of 02:15 local',
    roomie_fixture_known(
        ['2026-10-25T00:15:00Z', '2026-10-25T00:30:00Z'],
        ['2026-10-25T01:15:00Z', '2026-10-25T01:30:00Z'],
    ),
    [roomie_fixture_page([
        roomie_fixture_event('2026-10-25T01:15:00', '2026-10-25T01:30:00'),
        roomie_fixture_event('2026-10-25T00:15:00', '2026-10-25T00:30:00'),
        roomie_fixture_event('2026-10-25T23:00:00', '2026-10-25T23:01:00'),
    ])],
    '2026-10-24T22:00:00Z',
    '2026-10-25T23:00:00Z',
);

foreach ([
    'invalid event type' => 'offline malformed',
    'missing cancellation flag' => array_diff_key($busy, ['isCancelled' => true]),
    'string cancellation flag' => array_replace($busy, ['isCancelled' => 'false']),
    'integer cancellation flag' => array_replace($cancelled, ['isCancelled' => 1]),
    'null cancellation flag' => array_replace($free, ['isCancelled' => null]),
    'missing start' => array_diff_key($busy, ['start' => true]),
    'missing end' => array_diff_key($busy, ['end' => true]),
    'missing timezone' => array_replace($busy, ['start' => ['dateTime' => '2026-11-22T10:12:00']]),
    'invalid timezone' => array_replace($busy, ['start' => ['dateTime' => '2026-11-22T10:12:00', 'timeZone' => 'offline-zone']]),
    'non-UTC timezone' => array_replace($busy, ['start' => ['dateTime' => '2026-11-22T10:12:00', 'timeZone' => 'Europe/Berlin']]),
    'impossible date' => roomie_fixture_event('2026-11-31T10:00:00', '2026-12-01T11:00:00'),
    'normalized-by-PHP hour is invalid' => roomie_fixture_event('2026-11-21T24:00:00', '2026-11-22T11:00:00'),
    'leap second is invalid' => roomie_fixture_event('2026-11-22T10:15:60', '2026-11-22T10:20:00'),
    'more than seven fraction digits' => roomie_fixture_event('2026-11-22T10:12:00.00000001', '2026-11-22T10:34:00'),
    'zero-length event' => roomie_fixture_event('2026-11-22T10:12:00', '2026-11-22T10:12:00'),
    'inverted event' => roomie_fixture_event('2026-11-22T10:34:00', '2026-11-22T10:12:00'),
] as $label => $bad) {
    roomie_fixture_check($label . ' invalidates a mixed sample', ROOMIE_TEST_UNKNOWN, [roomie_fixture_page([$busy, $bad])]);
}

foreach ([
    'missing window start' => [null, ROOMIE_TEST_WINDOW_END],
    'relative window start' => ['today', ROOMIE_TEST_WINDOW_END],
    'non-UTC window offset' => ['2026-11-22T10:00:00+01:00', ROOMIE_TEST_WINDOW_END],
    'zero-length window' => [ROOMIE_TEST_WINDOW_START, ROOMIE_TEST_WINDOW_START],
    'inverted window' => [ROOMIE_TEST_WINDOW_END, ROOMIE_TEST_WINDOW_START],
    'impossible leap date' => ['2026-02-29T10:00:00Z', '2026-03-01T11:00:00Z'],
    'too many window fraction digits' => ['2026-11-22T10:00:00.00000001Z', ROOMIE_TEST_WINDOW_END],
    'trailing junk in window' => ["2026-11-22T10:00:00Z\n", ROOMIE_TEST_WINDOW_END],
] as $label => [$start, $end]) {
    roomie_fixture_check($label, ROOMIE_TEST_UNKNOWN, [$clearPage], $start, $end);
}

foreach (['tentative', 'oof', 'workingElsewhere'] as $blocking) {
    roomie_fixture_check(
        $blocking . ' blocks when overlapping',
        $busyRange,
        [roomie_fixture_page([array_replace($busy, ['showAs' => $blocking])])],
    );
}
foreach ([
    'missing showAs' => array_diff_key($busy, ['showAs' => true]),
    'unexpected showAs' => array_replace($busy, ['showAs' => 'unexpected']),
    'nonstring showAs' => array_replace($busy, ['showAs' => ['free']]),
] as $label => $bad) {
    roomie_fixture_check($label . ' on overlapping event', ROOMIE_TEST_UNKNOWN, [roomie_fixture_page([$busy, $bad])]);
}
roomie_fixture_check(
    'valid cancelled event does not need showAs',
    ROOMIE_TEST_CLEAR,
    [roomie_fixture_page([array_diff_key($cancelled, ['showAs' => true])])],
);
roomie_fixture_check(
    'invalid dates on cancelled event are not silently ignored',
    ROOMIE_TEST_UNKNOWN,
    [roomie_fixture_page([array_diff_key($cancelled, ['end' => true])])],
);
roomie_fixture_check(
    'invalid dates on free event are not silently ignored',
    ROOMIE_TEST_UNKNOWN,
    [roomie_fixture_page([array_diff_key($free, ['end' => true])])],
);
roomie_fixture_check(
    'Graph timeZone+dateTime split rejects timezone suffix',
    ROOMIE_TEST_UNKNOWN,
    [roomie_fixture_page([roomie_fixture_event('2026-11-22T10:12:00Z', '2026-11-22T10:34:00')])],
);

roomie_fixture_check(
    '31-day window accepted exactly at cap',
    ROOMIE_TEST_CLEAR,
    [$clearPage],
    '2026-11-01T11:00:00Z',
    '2026-12-02T11:00:00Z',
);
roomie_fixture_check(
    'window greater than 31 days by one Graph tick rejected',
    ROOMIE_TEST_UNKNOWN,
    [$clearPage],
    '2026-11-01T11:00:00Z',
    '2026-12-02T11:00:00.0000001Z',
);
$maxLink = str_pad(ROOMIE_TEST_NEXT_LINK, 4096, 'a');
roomie_fixture_check(
    'continuation at byte cap',
    ROOMIE_TEST_CLEAR,
    [roomie_fixture_page([], $maxLink), $clearPage],
);
roomie_fixture_check(
    'continuation over byte cap',
    ROOMIE_TEST_UNKNOWN,
    [roomie_fixture_page([], $maxLink . 'a'), $clearPage],
);
roomie_fixture_check(
    'duplicate continuation rejected even with a terminal page',
    ROOMIE_TEST_UNKNOWN,
    [roomie_fixture_page([], ROOMIE_TEST_NEXT_LINK), roomie_fixture_page([], ROOMIE_TEST_NEXT_LINK), $clearPage],
);
$pages = [];
for ($index = 0; $index < 31; ++$index) {
    $pages[] = roomie_fixture_page([], ROOMIE_TEST_NEXT_LINK . $index);
}
roomie_fixture_check('32 pages accepted', ROOMIE_TEST_CLEAR, [...$pages, $clearPage]);
roomie_fixture_check(
    '33 pages rejected despite completed envelope',
    ROOMIE_TEST_UNKNOWN,
    [...$pages, roomie_fixture_page([], ROOMIE_TEST_NEXT_LINK . '32'), $clearPage],
);
$largePage = roomie_fixture_page([...array_fill(0, 8999, $free), $busy], ROOMIE_TEST_NEXT_LINK);
roomie_fixture_check(
    'exactly 10,000 samples across pages accepted',
    $busyRange,
    [$largePage, roomie_fixture_page(array_fill(0, 1000, $free))],
);
roomie_fixture_check(
    '10,001 samples across pages rejected even when event 10,001 is free',
    ROOMIE_TEST_UNKNOWN,
    [$largePage, roomie_fixture_page(array_fill(0, 1001, $free))],
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    fwrite(STDERR, count($failures) . ' of ' . $checks . ' offline exchange availability checks failed.' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, $checks . ' offline exchange availability checks passed.' . PHP_EOL);
