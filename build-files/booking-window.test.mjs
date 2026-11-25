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
