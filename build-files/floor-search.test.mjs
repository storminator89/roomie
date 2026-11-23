import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const source = await readFile(new URL('../assets/roomplan/floor-view.js', import.meta.url));
const { matchesRoomSearch, eligibleFloorMarkers } = await import(
  'data:text/javascript;base64,' + source.toString('base64')
);

test('office search accepts room numbers, accents and words in any order', () => {
  const label = 'A 23 · Großes Büro Süd';
  for (const query of ['', '   ', 'a 23', 'BURO', 'Buero Sued', 'süd grosses A 23', '23 Süd']) {
    assert.equal(matchesRoomSearch(label, query), true, query);
  }
  for (const query of ['23 nord', '24', 'ok 23']) {
    assert.equal(matchesRoomSearch(label, query), false, query);
  }
});

test('room search treats composed and decomposed accents, transliteration and number separators alike', () => {
  const labels = ['A-23 · Großes Büro Süd · Café', 'A-23 · Grosses Buero Sued · Cafe\u0301'];
  const queries = ['a 23 BURO SUED CAFE', 'GROẞES', 'su\u0308d Büro', 'sued A.23', 'büro a-23 café'];
  for (const label of labels) {
    for (const query of queries) {
      assert.equal(matchesRoomSearch(label, query), true, `${label}: ${query}`);
    }
    assert.equal(matchesRoomSearch(label, '24 Süd'), false);
    assert.equal(matchesRoomSearch(label, '23 Nord'), false);
  }
  assert.equal(matchesRoomSearch('Straße', 'STRAẞE'), true);
});

test('a search can only find the authorized valid and deduplicated floor markers', () => {
  const rooms = [{ id: 3, name: 'Nord' }, { id: 4, name: 'Unmarkiert' }];
  const marker = { roomId: 3, x: .1, y: .2, w: .3, h: .4 };
  const floor = { markers: [{ ...marker, roomId: 8 }, marker, { ...marker, x: .4 }, { ...marker, roomId: 4, w: 2 }] };
  assert.deepEqual(eligibleFloorMarkers(floor, rooms).map(m => m.room.id), [3]);
});
