import { copyFile, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const source = await readFile(new URL('../assets/roomplan/booking-window.js', import.meta.url));
const { validBookingWindow, bookingWindowInFuture, presetBookingWindowOpen, periodCanBeBooked, firstFreeBookingWindow } = await import(
  'data:text/javascript;base64,' + source.toString('base64')
);

const slot = (start_time, end_time, own = false) => ({ start_time, end_time, own });

test('validates canonical 15-minute intervals within office hours only', () => {
  for (const [start, end] of [['09:00', '17:00'], ['12:15', '12:30'], ['16:45', '17:00']]) {
    assert.equal(validBookingWindow(start, end), true, `${start}–${end}`);
  }
  for (const [start, end] of [['9:00', '10:00'], ['09:00:00', '10:00'], ['09:01', '10:00'],
    ['08:45', '09:15'], ['16:45', '17:15'], ['16:00', '16:00'], ['11:30', '11:15'],
    [null, '10:00'], ['09:00\n', '10:00']]) {
    assert.ok(!validBookingWindow(start, end), `${start}–${end}`);
  }
});

test('uses peak occupancy, normalizes legacy seconds and excludes own overlaps', () => {
  const touching = [slot('10:00:00', '10:30:00'), slot('10:30', '11:00')];
  assert.equal(periodCanBeBooked(2, touching, '10:00', '11:00'), true);
  assert.equal(periodCanBeBooked(1, touching, '10:00', '11:00'), false);
  assert.equal(periodCanBeBooked(1, touching, '11:00', '11:15'), true);
  assert.equal(periodCanBeBooked(3, [slot('10:00', '10:30', true)], '10:15', '10:45'), false);
  assert.equal(periodCanBeBooked(3, [slot('10:00', '10:30', true)], '10:30', '10:45'), true);
  assert.equal(periodCanBeBooked(0, [], '10:00', '10:30'), false);
  assert.equal(periodCanBeBooked(1, [slot('09:59:59', '10:00:01')], '10:00', '10:30'), false);
});

test('finds a bookable custom gap when no preset fits and never offers closed days', () => {
  const allocated = [slot('09:00', '10:00:00'), slot('11:00:00', '17:00')];
  const now = new Date('2026-06-01T07:00:00Z');
  assert.equal(periodCanBeBooked(1, allocated, '09:00', '17:00'), false);
  assert.equal(periodCanBeBooked(1, allocated, '09:00', '12:00'), false);
  assert.equal(periodCanBeBooked(1, allocated, '13:00', '17:00'), false);
  assert.deepEqual(firstFreeBookingWindow(1, allocated, '2026-06-02', now), ['10:00', '10:15']);
  assert.equal(firstFreeBookingWindow(1, [slot('09:00', '17:00')], '2026-06-02', now), null);
  assert.equal(firstFreeBookingWindow(1, [], '2026-05-31', now), null);
  assert.equal(firstFreeBookingWindow(1, [], '2026-06-31', now), null);
});

test('today requires a future start according to Berlin time, including daylight saving', () => {
  const now = new Date('2026-06-01T08:15:00Z'); // 10:15 local, exactly on a grid boundary
  assert.equal(bookingWindowInFuture('2026-06-01', '10:15', now), false);
  assert.equal(bookingWindowInFuture('2026-06-01', '10:30', now), true);
  assert.equal(bookingWindowInFuture('2026-05-31', '16:45', now), false);
  assert.deepEqual(firstFreeBookingWindow(1, [], '2026-06-01', now), ['10:30', '10:45']);
  assert.deepEqual(firstFreeBookingWindow(1, [], '2026-06-01', new Date('2026-06-01T14:44:59Z')), ['16:45', '17:00']);
  assert.equal(firstFreeBookingWindow(1, [], '2026-06-01', new Date('2026-06-01T14:45:00Z')), null);
});


test('planner defaults to all rooms and keeps a selected availability range in history', async t => {
  // Browser JS is ESM without an app-side package manifest. Copy it into an ESM test scope.
  const root = await mkdtemp(join(tmpdir(), 'roomie-planner-test-'));
  t.after(() => rm(root, { recursive: true, force: true }));
  await writeFile(join(root, 'package.json'), '{"type":"module"}');
  for (const file of ['view.js', 'api.js', 'floor-view.js', 'booking-window.js']) {
    await copyFile(new URL(`../assets/roomplan/${file}`, import.meta.url), join(root, file));
  }
  const { readViewLocation, writeViewLocation, sameTimeBookingAlternatives } = await import(pathToFileURL(join(root, 'view.js')).href);
  const today = '2026-11-22';
  const base = 'https://roomie.example.test/raumplan.php';
  const initial = readViewLocation(base, today);
  assert.equal(initial.date, today);
  assert.equal(initial.free, false);
  assert.equal(initial.range, null);
  const next = { ...initial, date: '2026-11-23', floorChoice: 7, favorites: true, free: true, range: ['10:15', '11:45'] };
  const url = writeViewLocation(base, next, today);
  assert.equal(url.searchParams.get('free'), '1');
  assert.deepEqual(readViewLocation(url, today), next);
  const cleared = writeViewLocation(url, initial, today);
  assert.equal(cleared.href, base);
  assert.equal(readViewLocation(`${base}?start_time=11:16&end_time=11:45`, today).range, null);

  const date = '2026-11-23';
  const rooms = Array.from({length: 8}, (_, i) => ({
    id: i + 1, capacity: 1, canBook: i !== 2, requiresApproval: i === 4,
  }));
  const rows = [
    {room_id: 2, date, ...slot('10:15', '10:30')},
    {room_id: 4, date, ...slot('09:45', '10:00:00')},
    {room_id: 5, date, ...slot('08:00', '08:30')},
    {room_id: 6, date, ...slot('10:29:59', '10:31'), blocked: true},
    {room_id: 7, date, ...slot('invalid', '10:30')},
    {room_id: 8, date, ...slot('10:00', '10:30', true)},
    {room_id: 4, date: today, ...slot('10:00', '10:30')},
  ];
  const range = {period: 'individuell', start: '10:00', end: '10:30'};
  const result = sameTimeBookingAlternatives(rooms, rows, {room: rooms[0], date}, range, new Date('2026-11-22T12:00:00Z'));
  assert.deepEqual(result, [{room: rooms[3], date, booked: [rows[1]]}]);
  assert.deepEqual(sameTimeBookingAlternatives(rooms, rows, {room: rooms[0], date}, range,
    new Date('2026-11-23T09:00:00Z')), []);

});

test('first free booking skips all slots inside room maintenance', () => {
  const morningClosure = { ...slot('09:00', '11:00'), blocked: true };
  const now = new Date('2026-11-22T12:00:00Z');
  assert.deepEqual(firstFreeBookingWindow(30, [morningClosure], '2026-11-23', now), ['11:00', '11:15']);
});


test('standard windows stay bookable until their Berlin end; approval needs a future start', () => {
  const date = '2026-06-01';
  const morning = (approval, now) => presetBookingWindowOpen(date, '09:00', '12:00', approval, new Date(now));
  assert.equal(morning(true, '2026-06-01T06:59:59Z'), true);
  assert.equal(morning(true, '2026-06-01T07:00:00Z'), false);
  assert.equal(morning(false, '2026-06-01T09:59:59Z'), true);
  assert.equal(morning(false, '2026-06-01T10:00:00Z'), false);
  assert.equal(presetBookingWindowOpen(date, '12:00', '09:00', false, new Date('2026-06-01T06:00:00Z')), false);
});

async function importRoomplan(t) {
  const root = await mkdtemp(join(tmpdir(), 'roomie-search-alternatives-'));
  t.after(() => rm(root, {recursive: true, force: true}));
  await writeFile(join(root, 'package.json'), '{"type":"module"}');
  for (const file of ['view.js', 'api.js', 'floor-view.js', 'booking-window.js']) {
    await copyFile(new URL(`../assets/roomplan/${file}`, import.meta.url), join(root, file));
  }
  return import(pathToFileURL(join(root, 'view.js')).href);
}

const room = (id, name, overrides = {}) => ({
  id, name, capacity: 4, canBook: true, requiresApproval: false, bookingPolicyKnown: true,
  amenities: ['projector'], ...overrides,
});
const searchDate = '2026-11-23';
const searchNow = new Date('2026-11-22T12:00:00Z');

test('search offers one-dimensional replacements with matching essentials only', async t => {
  const {searchBookingAlternatives} = await importRoomplan(t);
  const rooms = [
    room(1, 'Atlas'), room(2, 'Düne'), room(3, 'Klein', {capacity: 3}),
    room(4, 'Kein Beamer', {amenities: []}), room(5, 'Darf nicht buchen', {canBook: false}),
    room(6, 'Nur Anfrage', {requiresApproval: true}), room(7, 'Unbekannt', {bookingPolicyKnown: false}),
    room(8, 'Gesperrt'), room(9, 'Eigene Buchung'), room(10, 'Defekte Belegung'),
    room(11, 'Standardersatz', {capacity: 6}),
  ];
  const plan = {rooms, favorites: [1, 2]};
  const rows = new Map([
    [1, Array(rooms[0].capacity).fill(slot('10:45', '11:00'))],
    [8, [{...slot('10:00', '10:15'), blocked: true}]],
    [9, [slot('09:45', '10:01', true)]],
    [10, [slot('unknown', '14:00')]],
  ]);
  const filters = {
    date: searchDate, range: ['10:00', '11:00'], equipment: ['projector'], query: 'Atlas',
    scopeRoomIds: new Set([1, 3, 4, 5, 6, 7, 8, 9, 10]),
  };
  const summarize = list => list.map(({room, range, kind, minutes}) => [room.id, ...range, kind, minutes]);
  assert.deepEqual(summarize(searchBookingAlternatives(plan, rows, filters, searchNow)), [
    [2, '10:00', '11:00', 'room', undefined],
    [1, '11:00', '12:00', 'later', 60],
    [1, '10:00', '10:45', 'shorter', 15],
  ]);
  assert.deepEqual(searchBookingAlternatives(plan, new Map(), filters, searchNow), []);
  assert.deepEqual(searchBookingAlternatives(plan, null, filters, searchNow), []);
  assert.deepEqual(searchBookingAlternatives(plan, rows, {...filters, query: 'unbekannte Raumnummer'}, searchNow), []);
  assert.deepEqual(searchBookingAlternatives(plan, rows, {...filters, range: ['10:13', '11:00']}, searchNow), []);
  assert.deepEqual(searchBookingAlternatives(plan, rows, filters, new Date('2026-11-23T11:00:00Z')), []);
  assert.deepEqual(summarize(searchBookingAlternatives(plan, rows, {...filters, onlyFavorites: true}, searchNow)), [
    [2, '10:00', '11:00', 'room', undefined],
    [1, '11:00', '12:00', 'later', 60],
    [1, '10:00', '10:45', 'shorter', 15],
  ]);
  const withoutPreferred = {...plan, favorites: [1]};
  assert.equal(searchBookingAlternatives(withoutPreferred, rows, {...filters, onlyFavorites: true}, searchNow)
    .some(option => option.kind === 'room'), false);
});

test('search caps replacements at three; short slots stay whole and shifts cannot extend past closing', async t => {
  const {searchBookingAlternatives} = await importRoomplan(t);
  const plan = {rooms: [
    room(1, 'Atlas'), room(2, 'Berg'), room(3, 'Canyon'), room(4, 'Düne'), room(5, 'Elbe'),
  ], favorites: [3]};
  const filters = {date: searchDate, range: ['16:45', '17:00'], query: 'Atlas'};
  const rows = new Map([[1, [{...slot('16:45', '17:00'), blocked: true}]]]);
  const options = searchBookingAlternatives(plan, rows, filters, searchNow);
  assert.deepEqual(options.map(({room, kind, range}) => [room.id, kind, ...range]), [
    [3, 'room', '16:45', '17:00'], [2, 'room', '16:45', '17:00'], [4, 'room', '16:45', '17:00'],
  ]);
});

test('search permits an ongoing preset only with explicit range and direct booking policy', async t => {
  const {searchBookingAlternatives} = await importRoomplan(t);
  const plan = {rooms: [room(1, 'Atlas'), room(2, 'Berg'), room(3, 'Canyon', {requiresApproval: true})], favorites: []};
  const rows = new Map([[1, [{...slot('09:00', '12:00'), blocked: true}]]]);
  const filters = {date: searchDate, range: ['09:00', '12:00'], query: 'Atlas'};
  const now = new Date('2026-11-23T09:00:00Z'); // 10:00 Berlin.
  assert.deepEqual(searchBookingAlternatives(plan, rows, filters, now), []);
  assert.deepEqual(searchBookingAlternatives(plan, rows, {...filters, allowInProgressPreset: true}, now)
    .map(({room, kind}) => [room.id, kind]), [[2, 'room']]);
});

test('watch shortcut requires one exact visible writable busy room and a future interval', async t => {
  const {roomForUnavailableWatch} = await importRoomplan(t);
  const now = new Date('2026-11-22T12:00:00Z');
  const date = '2026-11-23';
  const atlas = {id: 1, name: 'Atlas', number: 'B-02', capacity: 1, canBook: true, bookingPolicyKnown: true};
  const rows = new Map([[1, [{start_time: '10:45', end_time: '11:00', blocked: true}]]]);
  const context = {date, range: ['10:00', '11:00'], query: 'Atlas', equipment: [], onlyFavorites: false, favorites: []};
  const find = (options = {}, candidate = atlas, schedule = rows) =>
    roomForUnavailableWatch([candidate], schedule, {...context, ...options}, now);
  assert.equal(find(), atlas);
  assert.equal(find({query: 'b 02'}), atlas);
  assert.equal(find({query: 'Atla'}), null);
  assert.equal(find({onlyFavorites: true}), null);
  assert.equal(find({date: '2026-11-22'}), null);
  assert.equal(find({}, {...atlas, requiresApproval: true}), null);
  assert.equal(find({}, {...atlas, canBook: false}), null);
  assert.equal(find({}, {...atlas, bookingPolicyKnown: false}), null);
  assert.equal(find({}, atlas, new Map([[1, [{start_time: '10:00', end_time: '11:00', own: true}]]])), null);
  assert.equal(find({}, atlas, new Map([[1, [{start_time: 'broken', end_time: '11:00'}]]])), null);
  assert.equal(find({}, atlas, new Map([[1, [{start_time: '11:00', end_time: '12:00'}]]])), null);
  assert.equal(roomForUnavailableWatch([atlas, {...atlas, id: 2}], rows, context, now), null);
});

test('same-day fallback keeps duration, scope and filters and can show approval rooms', async t => {
  const {searchBookingAlternatives} = await importRoomplan(t);
  const plan = {rooms: [
    room(1, 'Atlas', {requiresApproval: true}),
    room(2, 'Atlas ohne Beamer', {amenities: []}),
    room(3, 'Atlas gesperrt', {canBook: false}),
    room(4, 'Berg', {}),
    room(5, 'Atlas ohne Favorit'),
    room(6, 'Atlas anderes Stockwerk'),
  ], favorites: [1, 2, 3, 4, 6]};
  const blocked = to => ({...slot('09:00', to), blocked: true});
  const rows = new Map([
    [1, [blocked('11:45')]], [4, [blocked('11:15')]], [5, [blocked('10:45')]], [6, [blocked('10:30')]],
  ]);
  const filters = {
    date: searchDate, range: ['10:00', '10:30'], equipment: ['projector'], query: 'Atlas', onlyFavorites: true,
    scopeRoomIds: new Set([1, 2, 3, 4, 5]), allowDayFallback: true,
  };
  const next = searchBookingAlternatives(plan, rows, filters, searchNow)
    .filter(option => option.kind === 'later-search');
  assert.deepEqual(next.map(({room, range}) => [room.id, room.requiresApproval, ...range]), [[1, true, '11:45', '12:15']]);
  assert.equal(searchBookingAlternatives(plan, rows, {...filters, allowDayFallback: false}, searchNow)
    .some(option => option.kind === 'later-search'), false);
  assert.equal(searchBookingAlternatives(plan, null, filters, searchNow).length, 0);
  assert.equal(searchBookingAlternatives(plan, new Map([[1, [blocked('16:45')]]]), filters, searchNow)
    .some(option => option.kind === 'later-search'), false);
  const atStart = new Date('2026-11-23T10:45:00Z');
  assert.deepEqual(searchBookingAlternatives(plan, rows, filters, atStart)
    .find(option => option.kind === 'later-search')?.range, ['12:00', '12:30']);
});
