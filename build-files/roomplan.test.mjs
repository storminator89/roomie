import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const source = await readFile(new URL('../assets/roomplan/api.js', import.meta.url));
const { normalizeRoomplan, createRoomplanApi, startRoomplanReads } = await import(
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

test('planner keeps inspect-only rooms visible without treating them as bookable', () => {
    const {rooms} = normalizeRoomplan({rooms: [
        {id: 1, type: 'fk-office', can_book: false}, {id: 2, type: 'fk-office', can_book: true},
    ]});
    assert.deepEqual(rooms.map(room => [room.id, room.canBook]), [[1, false], [2, true]]);
});

test('planner sends only custom time fields for same-day individual bookings', async () => {
    const calls = [];
    const api = createRoomplanApi({csrf: 'fixture-token', fetcher: async (path, opts) => {
        calls.push([path, opts]);
        return {ok: true, status: 200, json: async () => ({success: true, message: 'Gebucht.'})};
    }});
    const common = {roomId: 7, date: '2026-11-22', startTime: '10:15', endTime: '11:45'};
    assert.deepEqual(await api.book({...common, period: 'individuell'}), {message: 'Gebucht.'});
    assert.equal(calls[0][0], 'process_booking.php');
    assert.equal(calls[0][1].headers['X-CSRF-Token'], 'fixture-token');
    assert.deepEqual(Object.fromEntries(calls[0][1].body), {
        selectedWorkspace: '7', start_date: common.date, end_date: common.date, time_period: 'individuell',
        start_time: common.startTime, end_time: common.endTime,
    });
    await api.book({...common, period: 'vormittags'});
    assert.equal(calls[1][1].body.has('start_time'), false);
    assert.equal(calls[1][1].body.has('end_time'), false);
});

test('planner requests maintenance rows explicitly without changing the legacy booking API', async () => {
    const closure = {room_id: 7, date: '2026-11-22', start_time: '10:00', end_time: '11:00', blocked: true};
    const api = createRoomplanApi({fetcher: async path => {
        const request = new URL(path, 'https://roomie.example.test/');
        assert.equal(request.pathname, '/get_bookings.php');
        assert.deepEqual(Object.fromEntries(request.searchParams), {
            start_date: closure.date, end_date: closure.date, include_blocks: '1',
        });
        return {ok: true, status: 200, json: async () => [closure]};
    }});
    assert.deepEqual(await api.loadBookings(closure.date), [closure]);
});


test('planner preserves request-only policy, explicit intent, and pending receipt', async () => {
    const {rooms} = normalizeRoomplan({rooms: [
        {id: 2, name: 'Request only', requires_approval: true}, {id: 3, name: 'Direct'},
    ]});
    assert.deepEqual(rooms.map(room => room.requiresApproval), [true, false]);
    const api = createRoomplanApi({fetcher: async (_path, {body}) => {
        assert.equal(body.get('approval_request'), '1');
        assert.equal(body.get('start_date'), body.get('end_date'));
        return {ok: true, status: 200, json: async () => ({
            success: true, pending: true, status: 'pending', message: 'Anfrage gesendet.', request_id: 7,
        })};
    }});
    assert.deepEqual(await api.book({roomId: 2, date: '2026-11-23', period: 'vormittags', approvalRequest: true}), {
        message: 'Anfrage gesendet.', pending: true,
    });
});

test('initial availability request starts while both plan reads remain pending', async () => {
    const requests = new Map();
    const api = createRoomplanApi({fetcher: (url, {signal}) => new Promise(resolve => {
        requests.set(url, {
            signal, finish: body => resolve({ok: true, status: 200, json: async () => body}),
        });
    })});
    const signal = new AbortController().signal;
    const date = '2026-11-24';
    const {plan, bookings} = startRoomplanReads(api, {signal, bookingDate: date});
    const url = 'get_bookings.php?start_date=2026-11-24&end_date=2026-11-24&include_blocks=1';
    assert.deepEqual([...requests.keys()], ['load_grid.php', 'get_user_favorites.php', url]);
    assert.equal(requests.get(url).signal, bookings.controller.signal);
    requests.get(url).finish([{room_id: 2, date, blocked: true}]);
    assert.deepEqual(await bookings.result, {ok: true, value: [{room_id: 2, date, blocked: true}]});
    requests.get('load_grid.php').finish({data: {rooms: [{id: 2}]}});
    requests.get('get_user_favorites.php').finish({favorites: [2]});
    assert.deepEqual((await plan).favorites, [2]);
});

test('failed speculative availability stays isolated and can be aborted', async () => {
    const controller = new AbortController();
    let finishPlan;
    const api = {
        loadPlan: () => new Promise(resolve => { finishPlan = resolve; }),
        loadBookings: (_date, {signal}) => new Promise((resolve, reject) => {
            signal.addEventListener('abort', () => reject(signal.reason), {once: true});
        }),
    };
    const {plan, bookings} = startRoomplanReads(api, {signal: controller.signal, bookingDate: '2026-11-24'});
    bookings.controller.abort();
    const result = await bookings.result;
    assert.equal(result.ok, false);
    assert.equal(result.error.name, 'AbortError');
    assert.equal(controller.signal.aborted, false);
    finishPlan({rooms: []});
    assert.deepEqual(await plan, {rooms: []});

    const reload = startRoomplanReads({loadPlan: async () => ({rooms: []})});
    assert.equal(reload.bookings, null);
    assert.deepEqual(await reload.plan, {rooms: []});
});
