import { copyFile, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { after, test } from 'node:test';
import assert from 'node:assert/strict';

const moduleRoot = await mkdtemp(join(tmpdir(), 'roomie-amenities-test-'));
after(() => rm(moduleRoot, { recursive: true, force: true }));
await writeFile(join(moduleRoot, 'package.json'), '{"type":"module"}');
for (const file of ['view.js', 'api.js', 'floor-view.js', 'booking-window.js']) {
  await copyFile(new URL(`../assets/roomplan/${file}`, import.meta.url), join(moduleRoot, file));
}
const { matchesRoomAmenities, normalizeAmenities, readViewLocation, writeViewLocation } =
  await import(pathToFileURL(join(moduleRoot, 'view.js')).href);

test('equipment accepts only known keys and folds legacy and current aliases together', () => {
  assert.deepEqual(normalizeAmenities(['meeting', 'docking-station', 'monitor', 'dock', 'video-conference',
    'unrecognized', 'capacity', 'min-seats', 'constructor', '__proto__', null, { key: 'monitor' }]),
  ['monitor', 'docking-station', 'video-conference']);
  assert.deepEqual(normalizeAmenities('monitor'), []);
  assert.deepEqual(normalizeAmenities(undefined), []);
});

test('matching requires all selected equipment regardless of capacity or legacy spelling', () => {
  const required = normalizeAmenities(['monitor', 'dock', 'meeting']);
  assert.equal(matchesRoomAmenities({ amenities: ['monitor', 'docking-station', 'video-conference'], capacity: 1 }, required), true);
  assert.equal(matchesRoomAmenities({ amenities: ['monitor', 'dock', 'meeting'], capacity: 2 }, required), true);
  assert.equal(matchesRoomAmenities({ amenities: ['monitor', 'dock'], capacity: 500 }, required), false);
  assert.equal(matchesRoomAmenities({ amenities: ['display', 'dock', 'meeting'], capacity: 500 }, required), false);
  assert.equal(matchesRoomAmenities({ capacity: 1 }, required), false);
  assert.equal(matchesRoomAmenities({ capacity: 1 }, []), true);
});

test('equipment deep links round trip with date, floor, favorites, availability, and unknown URL parameters', () => {
  const today = '2099-07-06';
  const legacy = 'https://roomie.example.test/raumplan.php?from=dashboard&date=2099-07-07&floor=8'
    + '&favorites=1&free=1&start_time=10%3A15&end_time=11%3A15'
    + '&equipment=meeting&equipment=unknown&equipment=dock&equipment=monitor&equipment=docking-station#plan';
  const parsed = readViewLocation(legacy, today);
  assert.deepEqual(parsed, {
    date: '2099-07-07', list: false, floorChoice: 8, favorites: true, free: true,
    equipment: ['monitor', 'docking-station', 'video-conference'], range: ['10:15', '11:15'],
  });
  const saved = writeViewLocation(legacy, parsed, today);
  assert.equal(saved.searchParams.get('from'), 'dashboard');
  assert.equal(saved.hash, '#plan');
  assert.deepEqual(saved.searchParams.getAll('equipment'), parsed.equipment);
  assert.deepEqual(readViewLocation(saved, today), parsed);
  assert.equal(writeViewLocation(saved, parsed, today).href, saved.href);
  const cleared = writeViewLocation(saved, { ...parsed, equipment: [] }, today);
  assert.equal(cleared.searchParams.has('equipment'), false);
  assert.deepEqual(readViewLocation(cleared, today), { ...parsed, equipment: [] });
});
