const STEP = 15 * 60;
const OPEN = 9 * 3600;
const CLOSE = 17 * 3600;
const berlinClock = new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit',
  hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
});

function clock(value) {
  if (typeof value !== 'string') return null;
  const match = /^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/.exec(value);
  return match && match[0] === value ? Number(match[1]) * 3600 + Number(match[2]) * 60 + Number(match[3] ?? 0) : null;
}

function label(seconds) {
  return [Math.floor(seconds / 3600), (seconds / 60) % 60].map(n => String(n).padStart(2, '0')).join(':');
}

function berlinNow(now) {
  const p = Object.fromEntries(berlinClock.formatToParts(now).map(({type, value}) => [type, value]));
  return {date: `${p.year}-${p.month}-${p.day}`, seconds: clock(`${p.hour}:${p.minute}:${p.second}`)};
}

function validDate(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value) || value.startsWith('0000-')) return false;
  const date = new Date(`${value}T00:00:00Z`);
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value;
}

export function validBookingWindow(start, end) {
  const a = clock(start);
  const b = clock(end);
  return start?.length === 5 && end?.length === 5 && a !== null && b !== null
    && a >= OPEN && b <= CLOSE && a < b && a % STEP === 0 && b % STEP === 0;
}

export function bookingWindowInFuture(date, start, now = new Date()) {
  const a = clock(start);
  if (!validDate(date) || a === null) return false;
  const current = berlinNow(now);
  return date > current.date || (date === current.date && a > current.seconds);
}

function available(capacity, bookings, a, b) {
  const deltas = new Map();
  for (const row of bookings) {
    const start = clock(row.start_time);
    const end = clock(row.end_time);
    if (start === null || end === null || start >= end || start >= b || end <= a) continue;
    if (row.own === true) return false;
    const from = Math.max(a, start);
    const to = Math.min(b, end);
    deltas.set(from, (deltas.get(from) ?? 0) + 1);
    deltas.set(to, (deltas.get(to) ?? 0) - 1);
  }
  let used = 0;
  for (const [, delta] of [...deltas].sort(([x], [y]) => x - y)) {
    used += delta;
    if (used >= capacity) return false;
  }
  return true;
}

export function periodCanBeBooked(capacity, bookings, start, end) {
  const a = clock(start);
  const b = clock(end);
  return Number.isInteger(capacity) && capacity > 0 && a !== null && b !== null && a < b
    && available(capacity, bookings, a, b);
}

export function firstFreeBookingWindow(capacity, bookings, date, now = new Date()) {
  if (!Number.isInteger(capacity) || capacity < 1 || !validDate(date)) return null;
  const current = berlinNow(now);
  if (date < current.date) return null;
  let start = date === current.date ? Math.max(OPEN, (Math.floor(current.seconds / STEP) + 1) * STEP) : OPEN;
  for (; start + STEP <= CLOSE; start += STEP) {
    if (available(capacity, bookings, start, start + STEP)) return [label(start), label(start + STEP)];
  }
  return null;
}
