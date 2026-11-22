import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const source = await readFile(new URL('../assets/roomplan/api.js', import.meta.url));
const { normalizeRoomplan, createRoomplanApi } = await import(
    'data:text/javascript;base64,' + source.toString('base64')
);

test('saved layouts preserve slots without exposing invisible or removed rooms', () => {
    const rooms = [{id: '1', name: 'Saal'}, {id: 2, name: 'Büro'}, {id: 3}];
    const model = normalizeRoomplan({
        rooms, revision: '42', gridConfig: {rows: 1, cols: 5, room_layout: '[2,null,"1",2,999]'}
    }, [1, 999, '1']);
    assert.deepEqual(model.layout, [2, null, 1, null, null]);
    assert.deepEqual(model.rooms.map(room => room.position), [2, 0, null]);
    assert.deepEqual(model.favorites, [1]);
    assert.equal(model.revision, '42');
});

test('legacy positions preserve zero, discard collisions and retain unplaced rooms', () => {
    const model = normalizeRoomplan({rooms: [
        {id: '1', grid_position: '0', equipment: '["wifi"]'},
        {id: '2', grid_position: 0},
        {id: '3', grid_position: 121},
        {id: '4', grid_position: null}
    ]});
    assert.equal(model.layout[0], 1);
    assert.equal(model.layout[121], 3);
    assert.equal(model.rooms[1].position, null);
    assert.equal(model.rooms[3].position, null);
    assert.deepEqual(model.rooms[0].amenities, ['wifi']);
});

test('writes use existing endpoints, with CSRF and session credentials', async () => {
    const calls = [];
    const api = createRoomplanApi({csrf: 'fixture-token', fetcher: async (path, opts) => {
        calls.push([path, opts]);
        return {ok: true, status: 200, json: async () => ({success: true, roomId: '7', revision: '43', message: 'Buchung erstellt.'})};
    }});
    const id = await api.createRoom({name: 'Workshop'});
    assert.equal(await api.saveLayout({rows: 1, cols: 1, layout: [id], revision: '42'}), '43');
    assert.equal(id, 7);
    assert.equal(calls[0][0], 'add_room.php');
    assert.equal(calls[1][0], 'save_grid.php');
    assert.equal(calls[1][1].headers['X-CSRF-Token'], 'fixture-token');
    assert.equal(calls[1][1].credentials, 'same-origin');
    assert.deepEqual(JSON.parse(calls[1][1].body), {rows: 1, cols: 1, layout: [7], revision: '42'});
    const booking = await api.book({roomId: id, date: '2026-11-22', period: 'vormittags'});
    assert.equal(calls[2][0], 'process_booking.php');
    assert.equal(calls[2][1].body.get('end_date'), '2026-11-22');
    assert.equal(booking.message, 'Buchung erstellt.');
});

test('backend failures are not treated as empty availability or success', async () => {
    const api = createRoomplanApi({fetcher: async () => ({
        ok: false, status: 409, json: async () => ({error: 'Raum nicht verfügbar.'})
    })});
    await assert.rejects(api.loadBookings('2026-11-22'), /Raum nicht verfügbar/);
});

test('new editor never sends an unlocked layout write', async () => {
    const api = createRoomplanApi({fetcher: async () => assert.fail('fetch should not run')});
    await assert.rejects(api.saveLayout({rows: 1, cols: 1, layout: [null]}), /Layout-Version fehlt/);
});
