import {readFile} from 'node:fs/promises';
import test from 'node:test';
import assert from 'node:assert/strict';

const path = new URL('../assets/roomplan/booking-window.js', import.meta.url);
const {periodCanBeBooked,firstFreeBookingWindow} = await import(
    'data:text/javascript;base64,' + (await readFile(path)).toString('base64')
);

test('a whole-room block excludes every place, but not touching intervals', () => {
    const rows = [{blocked: true, start_time: '09:00', end_time: '10:30'}];
    assert.equal(periodCanBeBooked(500, rows, '10:15', '10:45'), false);
    assert.equal(periodCanBeBooked(500, rows, '10:30', '10:45'), true);
    assert.deepEqual(firstFreeBookingWindow(500, rows, '2027-02-10', new Date('2027-02-09T12:00:00Z')), ['10:30', '10:45']);
});

test('an invalid whole-room block remains unavailable', () => {
    assert.equal(periodCanBeBooked(500, [{blocked: true, start_time: 'invalid', end_time: '10:30'}], '12:00', '13:00'), false);
});
