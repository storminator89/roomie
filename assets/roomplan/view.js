import { createRoomplanApi, startRoomplanReads } from './api.js';
import { eligibleFloorMarkers, matchesRoomSearch, normalizeRoomSearch, renderFloorPosition } from './floor-view.js';
import { bookingWindowInFuture, firstFreeBookingWindow, periodCanBeBooked, presetBookingWindowOpen, validBookingWindow } from './booking-window.js';

const types = new Map([
  ['mediaroom', 'Mediaraum'], ['office', 'Büro'], ['open-office', 'Großraumbüro'],
  ['spez-abt-buero', 'Abteilungsbüro'], ['spez-abt-buro', 'Abteilungsbüro'],
  ['fk-office', 'FK-Büro'], ['fk-buro', 'FK-Büro'], ['shared-desk', 'Shared Desk'], ['meeting', 'Besprechungsraum'],
]);
const periods = [
  ['ganzerTag', 'Ganztags', '09:00', '17:00'],
  ['vormittags', 'Vormittags', '09:00', '12:00'],
  ['nachmittags', 'Nachmittags', '13:00', '17:00'],
];
const opening = '09:00';
const closing = '17:00';
const customPeriod = 'individuell';
const timeOptions = Array.from({ length: 33 }, (_, index) => {
  const minute = 9 * 60 + 15 * index;
  return `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
});
const amenities = new Map([
  ['monitor', 'Monitor'], ['docking-station', 'Dockingstation'], ['video-conference', 'Videokonferenz'],
  ['whiteboard', 'Whiteboard'], ['projector', 'Beamer'], ['display', 'Display'],
  ['webcam', 'Webcam'], ['desk', 'Schreibtisch'], ['phone', 'Telefon'], ['wifi', 'WLAN'],
]);
const amenityAliases = new Map([['dock', 'docking-station'], ['meeting', 'video-conference']]);

export function normalizeAmenities(values) {
  if (!Array.isArray(values)) return [];
  const keys = new Set(values.map(key => amenityAliases.get(key) ?? key));
  // Return only supported keys in a stable order for filters and shareable URLs.
  return [...amenities.keys()].filter(key => keys.has(key));
}

export function matchesRoomAmenities(room, required) {
  if (!required.length) return true;
  const available = new Set(normalizeAmenities(room.amenities));
  return required.every(key => available.has(key));
}

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = String(text);
  return node;
}
function button(label, className) {
  const node = el('button', className, label);
  node.type = 'button';
  return node;
}

// Decorative outline icons share one 24px coordinate system. Paths are
// internal constants; all room names, messages and labels remain plain text.
const iconPaths = Object.freeze({
  search: 'M20 20l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z',
  sliders: 'M4 7h5m4 0h7M4 17h9m4 0h3M9 4v6m8 4v6',
  chevron: 'm6 9 6 6 6-6',
  calendar: 'M15 3v4M9 3v4M4 10h16M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z',
  calendarPlus: 'M15 3v4M9 3v4M4 10h16M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm6 8v5m-2.5-2.5h5',
  bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 13h4',
  clock: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM12 7v5l3 2',
  check: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-13 0 2.5 2.5 5.5-5',
  star: 'm12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z',
  layers: 'm3 8 9-5 9 5-9 5-9-5Zm0 4.5 9 5 9-5m-18 4.5 9 5 9-5',
  users: 'M15 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2m18 0v-2a4 4 0 0 0-3-3.9M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm4-3.9a4 4 0 0 1 0 7.8',
  shield: 'M12 22s7-3.5 7-10V5l-7-2-7 2v7c0 6.5 7 10 7 10Zm-3-10 2 2 4-4',
  minus: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM8 12h8',
  lock: 'M8 11V7a4 4 0 0 1 8 0v4m-9 0h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2Zm5 4v2',
  help: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-11.5-3a2.5 2.5 0 1 1 3.6 2.25C12.4 11.6 12 12 12 13m0 3h.01',
  warning: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-4v4m0 4h.01',
  info: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-1v5m0-8h.01',
  refresh: 'M20 10a8 8 0 1 0-1.9 7.1M20 4v6h-6',
  arrow: 'M4 12h16m-6-6 6 6-6 6',
  send: 'M21 3 3 10l7 4 4 7 7-18Zm0 0L10 14',
  close: 'm6 6 12 12M18 6 6 18',
});
const svgNamespace = 'http://www.w3.org/2000/svg';

function icon(name, className = '') {
  const svg = document.createElementNS(svgNamespace, 'svg');
  svg.setAttribute('class', className ? `rp-icon ${className}` : 'rp-icon');
  svg.setAttribute('width', '18');
  svg.setAttribute('height', '18');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '1.8');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  const path = document.createElementNS(svgNamespace, 'path');
  path.setAttribute('d', iconPaths[name]);
  svg.append(path);
  return svg;
}

function withIcon(node, name) {
  node.prepend(icon(name));
  return node;
}

const availabilityIcons = Object.freeze({
  free: 'check', partial: 'clock', busy: 'minus', blocked: 'lock', unknown: 'help', loading: 'clock',
});

function availabilityLabel(tag, text, state) {
  const label = el(tag, 'rp-availability');
  const badge = withIcon(el('span', 'rp-availability-badge', text), availabilityIcons[state]);
  label.dataset.state = state;
  label.append(badge);
  return label;
}

function timeRangeControls(id) {
  const root = el('div', 'rp-time-range');
  const inputs = [
    ['Von', 'start_time', timeOptions.slice(0, -1)],
    ['Bis', 'end_time', timeOptions.slice(1)],
  ].map(([label, name, options]) => {
    const field = el('label', 'rp-time-label', label);
    const select = el('select', 'rp-date rp-time');
    select.name = name;
    select.id = id + '-' + name;
    field.htmlFor = select.id;
    select.append(...options.map(time => {
      const option = el('option', '', time);
      option.value = time;
      return option;
    }));
    field.append(select);
    root.append(field);
    return select;
  });
  const [start, end] = inputs;
  function constrainEnd() {
    for (const option of end.options) option.disabled = option.value <= start.value;
    if (end.value <= start.value) end.value = timeOptions[timeOptions.indexOf(start.value) + 1];
  }
  start.addEventListener('change', constrainEnd);
  return {
    root,
    start,
    end,
    get value() { return [start.value, end.value]; },
    set value([from, to]) {
      start.value = from;
      end.value = to;
      constrainEnd();
    },
  };
}

export function groupRooms(plan) {
  const placed = new Set();
  const byId = new Map(plan.rooms.map(room => [room.id, room]));
  const slots = plan.layout.map(id => {
    const room = byId.get(id);
    if (!room || placed.has(id)) return null;
    placed.add(id);
    return room;
  });
  return { slots, unplaced: plan.rooms.filter(room => !placed.has(room.id)) };
}
function clock(value) {
  return typeof value === 'string' && /^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(value)
    ? value.slice(0, 5) : null;
}

export function availablePeriods(capacity, bookings) {
  return periods.filter(([, , start, end]) => periodCanBeBooked(capacity, bookings, start, end));
}

export function preferredBookingPeriod(range, date, room, availability, now = new Date()) {
  if (!range) return null;
  const [start, end] = range;
  const preset = periods.find(([, , from, to]) => start === from && end === to);
  if (preset) {
    return availability.presets.some(([id]) => id === preset[0])
      && presetBookingWindowOpen(date, start, end, room.requiresApproval, now) ? preset[0] : null;
  }
  return availability.custom && validBookingWindow(start, end) && bookingWindowInFuture(date, start, now)
    && periodCanBeBooked(room.capacity, availability.booked, start, end) ? customPeriod : null;
}

export function sameTimeBookingAlternatives(rooms, bookings, attempted, {period, start, end}, now = new Date()) {
  if (!validBookingWindow(start, end)) return [];
  const byRoom = new Map();
  for (const row of bookings) {
    if (row.date !== attempted.date) continue;
    const id = Number(row.room_id);
    if (!byRoom.has(id)) byRoom.set(id, []);
    byRoom.get(id).push(row);
  }
  return rooms.flatMap(room => {
    if (room.id === attempted.room.id || room.canBook !== true || room.requiresApproval !== attempted.room.requiresApproval) return [];
    const timeOpen = period === customPeriod ? bookingWindowInFuture(attempted.date, start, now)
      : presetBookingWindowOpen(attempted.date, start, end, room.requiresApproval, now);
    const booked = byRoom.get(room.id) ?? [];
    return timeOpen && periodCanBeBooked(room.capacity, booked, start, end) ? [{room, date: attempted.date, booked}] : [];
  });
}

// Change only one part of the request. Keep explicit favorites/equipment and
// require a replacement to be at least as large as the named room(s).
export function searchBookingAlternatives(plan, bookingsByRoom, {
  date, range, query = '', equipment = [], onlyFavorites = false,
  scopeRoomIds = null, allowInProgressPreset = false,
}, now = new Date()) {
  if (!(bookingsByRoom instanceof Map) || !range || !validBookingWindow(...range)) return [];
  const [from, to] = range;
  const favoriteIds = new Set(plan.favorites);
  const isFavorite = room => favoriteIds.has(room.id);
  const pool = plan.rooms.filter(room => room.bookingPolicyKnown === true && room.canBook === true
    && Number.isInteger(room.capacity) && room.capacity > 0 && (!onlyFavorites || isFavorite(room))
    && matchesRoomAmenities(room, equipment))
    .sort((a, b) => Number(isFavorite(b)) - Number(isFavorite(a)));
  const hasQuery = normalizeRoomSearch(query).length > 0;
  const named = pool.filter(room => matchesRoomSearch(`${room.number ?? ''} ${room.name ?? ''}`, query));
  const exact = named.filter(room => !scopeRoomIds || scopeRoomIds.has(room.id));
  const fits = (room, start, end, canUsePreset = false) => (
    bookingWindowInFuture(date, start, now)
    || (canUsePreset && allowInProgressPreset && !room.requiresApproval
      && periods.some(([, , a, b]) => a === start && b === end)
      && presetBookingWindowOpen(date, start, end, false, now))
  ) && periodCanBeBooked(room.capacity, bookingsByRoom.get(room.id) ?? [], start, end);
  if (exact.some(room => fits(room, from, to, true))) return [];
  // Don't silently reinterpret an unknown or inaccessible room name.
  if (hasQuery && named.length === 0) return [];
  const minimumCapacity = hasQuery ? named.reduce((max, room) => Math.max(max, room.capacity), 0) : 1;
  const inScope = room => !scopeRoomIds || scopeRoomIds.has(room.id);
  const sameTime = pool.filter(room => !room.requiresApproval && room.capacity >= minimumCapacity
    && fits(room, from, to, true))
    .sort((a, b) => Number(inScope(b)) - Number(inScope(a)))
    .slice(0, 3)
    .map(room => ({room, range: [from, to], kind: 'room'}));

  const fromIndex = timeOptions.indexOf(from);
  const toIndex = timeOptions.indexOf(to);
  const nearby = exact.filter(room => !room.requiresApproval);
  let later, shorter;
  for (let step = 1; step <= 4 && toIndex + step < timeOptions.length && !later; step++) {
    const range = [timeOptions[fromIndex + step], timeOptions[toIndex + step]];
    const room = nearby.find(room => fits(room, ...range));
    if (room) later = {room, range, kind: 'later', minutes: step * 15};
  }
  // Keep at least half the selected duration and never shorten by more than an hour.
  for (let step = 1; step <= Math.min(4, Math.floor((toIndex - fromIndex) / 2)) && !shorter; step++) {
    const range = [from, timeOptions[toIndex - step]];
    const room = nearby.find(room => fits(room, ...range));
    if (room) shorter = {room, range, kind: 'shorter', minutes: step * 15};
  }
  return [sameTime[0], later, shorter, ...sameTime.slice(1)].filter(Boolean).slice(0, 3);
}

// Link to the exact wish only. The detail route rechecks availability and
// rights; following this link never creates a watch or a booking.
export function roomForUnavailableWatch(rooms, rowsByRoom, {date, range, query, equipment, onlyFavorites, favorites}, now = new Date()) {
  const name = value => normalizeRoomSearch(value).join(' ');
  const wish = name(query);
  if (!wish || !(rowsByRoom instanceof Map) || !range || !validBookingWindow(...range)
    || !bookingWindowInFuture(date, range[0], now)) return null;
  const named = rooms.filter(room => name(room.name) === wish || name(room.number) === wish);
  if (named.length !== 1) return null;
  const [room] = named;
  if (room.bookingPolicyKnown !== true || room.canBook !== true || room.requiresApproval
    || !Number.isInteger(room.capacity) || room.capacity < 1
    || (onlyFavorites && !favorites.includes(room.id)) || !matchesRoomAmenities(room, equipment)) return null;
  const rows = rowsByRoom.get(room.id);
  const [from, to] = range.map(time => time + ':00');
  // A broken or incomplete booking response cannot prove this room is busy.
  if (!Array.isArray(rows) || !rows.length || rows.some(entry => {
    const start = entry?.start_time;
    const end = entry?.end_time;
    if (!clock(start) || !clock(end) || ![5, 8].includes(start.length) || ![5, 8].includes(end.length)) return true;
    const a = start.length === 5 ? start + ':00' : start;
    const b = end.length === 5 ? end + ':00' : end;
    return a >= b || (entry.own === true && a < to && b > from);
  })) return null;
  return !periodCanBeBooked(room.capacity, rows, ...range) ? room : null;
}

function dateValue(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value) || value.startsWith('0000-')) return false;
  const dt = new Date(`${value}T00:00:00Z`);
  return Number.isFinite(dt.getTime()) && dt.toISOString().slice(0, 10) === value;
}

export function readViewLocation(href, fallbackDate) {
  const url = new URL(href);
  const supplied = url.searchParams.get('date');
  const range = [url.searchParams.get('start_time'), url.searchParams.get('end_time')];
  return {
    date: dateValue(supplied) ? supplied : fallbackDate,
    list: url.searchParams.get('view') === 'list',
    floorChoice: url.searchParams.get('view') === 'grid' ? 'grid'
      : (/^[1-9]\d{0,15}$/.test(url.searchParams.get('floor') ?? '') ? Number(url.searchParams.get('floor')) : null),
    favorites: url.searchParams.get('favorites') === '1',
    free: url.searchParams.get('free') === '1',
    equipment: normalizeAmenities(url.searchParams.getAll('equipment')),
    range: validBookingWindow(...range) ? range : null,
  };
}

export function writeViewLocation(href, state, fallbackDate) {
  const next = new URL(href);
  if (state.date === fallbackDate) next.searchParams.delete('date');
  else next.searchParams.set('date', state.date);
  if (state.list) next.searchParams.set('view', 'list');
  else if (state.floorChoice === 'grid') next.searchParams.set('view', 'grid');
  else next.searchParams.delete('view');
  if (!state.list && Number.isSafeInteger(state.floorChoice) && state.floorChoice > 0) next.searchParams.set('floor', String(state.floorChoice));
  else next.searchParams.delete('floor');
  if (state.favorites) next.searchParams.set('favorites', '1');
  else next.searchParams.delete('favorites');
  if (state.free) next.searchParams.set('free', '1');
  else next.searchParams.delete('free');
  if (state.range && validBookingWindow(...state.range)) {
    next.searchParams.set('start_time', state.range[0]);
    next.searchParams.set('end_time', state.range[1]);
  } else {
    next.searchParams.delete('start_time');
    next.searchParams.delete('end_time');
  }
  const canonicalEquipment = normalizeAmenities(state.equipment);
  const previousEquipment = next.searchParams.getAll('equipment');
  if (canonicalEquipment.length !== previousEquipment.length
    || canonicalEquipment.some((key, index) => previousEquipment[index] !== key)) {
    next.searchParams.delete('equipment');
    for (const key of canonicalEquipment) next.searchParams.append('equipment', key);
  }
  return next;
}

function addDays(value, days) {
  const dt = new Date(`${value}T00:00:00Z`);
  dt.setUTCDate(dt.getUTCDate() + days);
  return dt.toISOString().slice(0, 10);
}
function dateLabel(value) {
  return new Intl.DateTimeFormat('de-DE', { dateStyle: 'full', timeZone: 'UTC' })
    .format(new Date(`${value}T00:00:00Z`));
}

function detailsUrl(room, date, range, free) {
  const params = new URLSearchParams({ id: String(room.id), date });
  if (range) {
    params.set('start_time', range[0]);
    params.set('end_time', range[1]);
  }
  if (free) params.set('free', '1');
  return 'room_details.php?' + params;
}

export function mountRoomplan(main, api = createRoomplanApi({ csrf: main.dataset.csrf })) {
  const view = main.querySelector('#roomplan-view');
  const edit = main.querySelector('#roomplan-edit') || document.getElementById('roomplan-edit');
  const editor = main.querySelector('#roomplan-editor') || document.getElementById('roomplan-editor');
  if (!view || !dateValue(main.dataset.today)) return;
  const today = main.dataset.today;
  const bookThrough = dateValue(main.dataset.maxDate) ? main.dataset.maxDate : addDays(today, 90);
  const isAdmin = main.dataset.admin === '1' || main.dataset.admin === 'true';

  let plan = null;
  let bookingsByRoom = null;
  let loadPlanError = false;
  let bookingStatus = 'idle';
  const initialView = readViewLocation(window.location.href, today);
  let onlyFavorites = initialView.favorites;
  let onlyFree = initialView.free;
  let selectedEquipment = initialView.equipment;
  let rangeIsExplicit = initialView.range !== null;
  let listMode = initialView.list;
  let floorChoice = initialView.floorChoice;
  let floors = [];
  let floorLoadRun = 0;
  let selectedFloorRoom = null;
  let activeFloorView = null;
  let activeFloorViewId = null;
  let floorRoomOpener = null;
  const floorFilters = { query: '', onlyBookable: false, equipmentActive: false };
  const floorEdit = main.querySelector('#roomplan-floor-edit');
  const floorEditorRoot = main.querySelector('#roomplan-floor-editor');
  let floorEditor = null;
  let bookingRun = 0;
  let bookingController = null;
  let editorOpened = false;
  let editorOpening = false;
  const legacyActions = edit?.closest('.rp-legacy-actions');
  let editorMounted = null;
  let trigger = null;
  const pendingStars = new Set();

  const toolbar = el('div', 'rp-view-toolbar');
  const primary = el('div', 'rp-primary-filters');
  const dateLabelNode = el('label', 'rp-date-label');
  dateLabelNode.textContent = 'Datum';
  const dateInput = el('input', 'rp-date');
  dateInput.type = 'date';
  dateInput.id = 'rp-selected-date';
  dateInput.value = initialView.date;
  dateLabelNode.htmlFor = dateInput.id;
  const dateControl = el('div', 'rp-date-control');
  dateControl.append(dateLabelNode, dateInput);
  const search = el('input', 'rp-date rp-search');
  search.type = 'search';
  search.id = 'rp-room-search';
  search.placeholder = 'Name / Nr.';
  search.maxLength = 100;
  const searchLabel = el('label', 'rp-date-label', 'Raum suchen');
  searchLabel.htmlFor = search.id;
  const searchControl = el('div', 'rp-search-control');
  const searchField = el('div', 'rp-search-field');
  searchField.append(icon('search'), search);
  searchControl.append(searchLabel, searchField);
  const dateRange = timeRangeControls('rp-filter');
  function defaultFilterRange(date, now = new Date()) {
    return [timeOptions.slice(0, -1).find(time => bookingWindowInFuture(date, time, now)) ?? opening, closing];
  }
  dateRange.value = initialView.range ?? defaultFilterRange(initialView.date);
  const secondaryButton = 'roomplan-action roomplan-action-secondary rp-button rp-button-secondary';
  const resetDate = withIcon(button('Heute', secondaryButton), 'calendar');
  resetDate.disabled = initialView.date === today;
  const freeFilter = withIcon(button('Nur frei', secondaryButton + ' rp-free-filter'), 'check');
  freeFilter.setAttribute('aria-pressed', String(onlyFree));
  const filter = withIcon(button('Nur Favoriten', secondaryButton), 'star');
  filter.setAttribute('aria-pressed', String(onlyFavorites));
  filter.disabled = true;
  const moreOptions = el('details', 'rp-more-options');
  const optionsSummary = el('summary', secondaryButton + ' rp-options-summary');
  const optionsTitle = el('span', '', 'Filter und Ansicht');
  const activeOptions = el('span', 'rp-active-options');
  optionsSummary.append(icon('sliders'), optionsTitle, activeOptions, icon('chevron', 'rp-options-chevron'));
  const optionsPanel = el('div', 'rp-options-panel');
  const optionControls = el('div', 'rp-option-controls');
  const rangeSummary = el('p', 'rp-range-summary');
  rangeSummary.id = 'rp-filter-range';
  freeFilter.setAttribute('aria-describedby', rangeSummary.id);
  const floorPick = el('label', 'rp-floor-pick');
  const floorSelect = el('select');
  floorPick.append(withIcon(el('span', 'rp-floor-pick-label', 'Ansicht'), 'layers'), floorSelect);
  floorPick.hidden = true;
  const equipmentGroup = el('fieldset', 'rp-equipment-group');
  const equipmentHint = el('p', 'rp-equipment-hint', 'Räume müssen alle ausgewählten Merkmale haben.');
  equipmentHint.id = 'rp-equipment-hint';
  equipmentGroup.setAttribute('aria-describedby', equipmentHint.id);
  const equipmentOptions = el('div', 'rp-equipment-options');
  const equipmentInputs = new Map();
  for (const [key, label] of amenities) {
    const field = el('label', 'rp-equipment-option');
    const checkbox = el('input');
    checkbox.type = 'checkbox';
    checkbox.value = key;
    equipmentInputs.set(key, checkbox);
    field.append(checkbox, el('span', '', label));
    equipmentOptions.append(field);
  }
  const clearEquipment = button('Auswahl löschen', secondaryButton);
  equipmentGroup.append(el('legend', '', 'Benötigte Ausstattung'), equipmentHint, equipmentOptions, clearEquipment);
  function syncEquipmentFilter() {
    for (const [key, checkbox] of equipmentInputs) checkbox.checked = selectedEquipment.includes(key);
    clearEquipment.disabled = !selectedEquipment.length;
  }
  function syncFilterSummary() {
    const activeCount = Number(onlyFavorites) + selectedEquipment.length;
    activeOptions.textContent = activeCount ? `${activeCount} aktiv` : '';
    const [from, to] = dateRange.value;
    const rangeText = (onlyFree || rangeIsExplicit) && dateValue(dateInput.value) ? `Zeitraum: ${from}–${to} Uhr` : '';
    if (rangeSummary.textContent !== rangeText) {
      rangeSummary.replaceChildren(...(rangeText ? [icon('clock'), rangeText] : []));
    }
  }
  syncEquipmentFilter();
  syncFilterSummary();
  primary.append(dateControl, searchControl, freeFilter);
  optionControls.append(dateRange.root, resetDate, filter, floorPick);
  optionsPanel.append(optionControls, equipmentGroup);
  moreOptions.append(optionsSummary, optionsPanel);
  toolbar.append(primary, rangeSummary, moreOptions);

  const notice = el('div', 'rp-status');
  notice.setAttribute('role', 'status');
  notice.setAttribute('aria-live', 'polite');
  notice.setAttribute('aria-atomic', 'true');
  const filterResult = el('p', 'rp-filter-result');
  filterResult.setAttribute('role', 'status');
  filterResult.setAttribute('aria-atomic', 'true');
  const searchSuggestions = el('section', 'rp-search-alternatives');
  searchSuggestions.hidden = true;
  searchSuggestions.setAttribute('aria-labelledby', 'rp-search-alternatives-title');
  const suggestionTitle = el('h2', '', 'Passt auch …');
  suggestionTitle.id = 'rp-search-alternatives-title';
  const suggestionList = el('ul', 'rp-search-alternative-list');
  searchSuggestions.append(suggestionTitle, suggestionList);
  const watchLink = withIcon(el('a', secondaryButton + ' rp-watch-search', 'Bei Freiwerden melden'), 'bell');
  watchLink.hidden = true;
  const content = el('div', 'rp-view-content');
  content.id = 'rp-view-content';
  content.tabIndex = -1;
  content.setAttribute('role', 'region');
  content.setAttribute('aria-label', 'Raumplan-Ergebnisse');
  const retry = withIcon(button('Erneut laden', secondaryButton + ' rp-retry'), 'refresh');
  retry.hidden = true;
  toolbar.setAttribute('role', 'group');
  toolbar.setAttribute('aria-label', 'Raumplan filtern');
  view.replaceChildren(toolbar, notice, retry, filterResult, searchSuggestions, watchLink, content);

  function preferredRange() {
    return onlyFree || rangeIsExplicit ? dateRange.value : null;
  }

  function rememberView() {
    if (!dateValue(dateInput.value)) return;
    const state = {
      date: dateInput.value, list: listMode, favorites: onlyFavorites, floorChoice,
      free: onlyFree, range: preferredRange(), equipment: selectedEquipment,
    };
    const target = writeViewLocation(window.location.href, state, today);
    if (target.href !== window.location.href) window.history.pushState(window.history.state, '', target);
  }

  function activeFloor() {
    if (listMode || floorChoice === 'grid') return null;
    return floors.find(floor => floor.id === floorChoice) ?? floors[0] ?? null;
  }

  function syncFloorControl(hasGrid = plan?.layout.some(Boolean)) {
    floorPick.hidden = onlyFavorites || (!floors.length && !hasGrid);
    const options = floors.map(floor => [String(floor.id), floor.title]);
    options.push(['grid', 'Raster'], ['list', 'Liste']);
    floorSelect.replaceChildren(...options.map(([id, label]) => {
      const option = el('option', '', label);
      option.value = id;
      return option;
    }));
    floorSelect.value = listMode ? 'list' : String(activeFloor()?.id ?? 'grid');
    if (floorEdit) floorEdit.hidden = !plan;
  }

  async function reloadFloors(intentId = null, reportFailure = false) {
    const run = ++floorLoadRun;
    try {
      const next = await api.loadFloorplans();
      if (run !== floorLoadRun) return;
      floors = next;
      if (floorEdit) floorEdit.removeAttribute('title');
      if (intentId != null && floors.some(floor => floor.id === intentId)) {
        floorChoice = intentId;
        listMode = false;
        selectedFloorRoom = null;
        rememberView();
      }
    } catch (error) {
      if (run !== floorLoadRun) return;
      if (floorEdit) floorEdit.title = error.message;
      if (reportFailure) throw error;
    }
    syncFloorControl();
    if (plan && dateValue(dateInput.value)) render();
  }

  function chooseView(value) {
    if (loadPlanError || !dateValue(dateInput.value)) return;
    listMode = value === 'list';
    floorChoice = value === 'grid' ? 'grid' : value === 'list' ? null : Number(value);
    selectedFloorRoom = null;
    rememberView();
    render();
  }
  floorSelect.addEventListener('change', () => chooseView(floorSelect.value));

  search.addEventListener('input', () => {
    floorFilters.query = search.value;
    if (loadPlanError || !dateValue(dateInput.value)) return;
    if (activeFloorView) activeFloorView.refreshQuery();
    else render();
  });

  function status(text, isError = false) {
    notice.replaceChildren(...(text ? [icon(isError ? 'warning' : 'info'), text] : []));
    notice.classList.toggle('rp-status-error', isError);
  }

  function drawFavoriteControl(control, favorite) {
    if (!control.firstChild) control.append(icon('star'));
    control.setAttribute('aria-pressed', String(favorite));
  }

  async function changeFavorite(room, control) {
    if (!plan || pendingStars.has(room.id)) return;
    pendingStars.add(room.id);
    control.disabled = true;
    try {
      const favorite = await api.toggleFavorite(room.id);
      plan.favorites = favorite
        ? [...new Set([...plan.favorites, room.id])]
        : plan.favorites.filter(id => id !== room.id);
      drawFavoriteControl(control, favorite);
      status(favorite ? `${room.name} zu Favoriten hinzugefügt.` : `${room.name} aus Favoriten entfernt.`);
      if (onlyFavorites && !favorite) {
        render();
        (moreOptions.open ? filter : optionsSummary).focus();
      }
    } catch (error) {
      status(error.message || 'Favoriten konnten nicht aktualisiert werden.', true);
    } finally {
      pendingStars.delete(room.id);
      for (const button of content.querySelectorAll('[data-favorite-room-id]')) {
        if (button.dataset.favoriteRoomId === String(room.id)) {
          button.disabled = false;
          drawFavoriteControl(button, plan.favorites.includes(room.id));
        }
      }
    }
  }

  const dialog = el('dialog', 'rp-dialog');
  dialog.setAttribute('aria-labelledby', 'rp-book-title');
  const dialogTitle = el('h2', '', 'Raum buchen');
  dialogTitle.id = 'rp-book-title';
  const dialogDate = el('p', 'rp-dialog-date');
  const approvalNote = el('p', 'roomplan-muted', 'Der Raum ist erst nach Freigabe gebucht.');
  const roomForm = el('form', 'rp-book-form');
  const periodLegend = el('legend', '', 'Zeitraum');
  const choices = el('fieldset', 'rp-periods');
  const customControls = timeRangeControls('rp-book');
  const feedback = el('div', 'rp-dialog-feedback');
  feedback.setAttribute('role', 'status');
  feedback.setAttribute('aria-live', 'polite');
  const alternatives = el('div', 'rp-book-alternatives');
  alternatives.hidden = true;
  const actions = el('div', 'rp-dialog-actions');
  const close = button('Abbrechen', secondaryButton);
  const submit = withIcon(button('Buchen', 'roomplan-action rp-button rp-button-primary'), 'calendarPlus');
  submit.type = 'submit';
  actions.append(close, submit);
  roomForm.append(choices, feedback, alternatives, actions);
  dialog.append(dialogTitle, dialogDate, approvalNote, roomForm);
  view.append(dialog);
  let selectedBooking = null;
  let bookPending = false;
  let bookingOutcomeUnknown = false;

  function syncCustomControls() {
    const isCustom = choices.querySelector('input:checked')?.value === customPeriod;
    customControls.root.hidden = !isCustom;
    customControls.start.disabled = customControls.end.disabled = !isCustom;
  }
  function clearAlternatives() {
    alternatives.replaceChildren();
    alternatives.hidden = true;
  }

  choices.addEventListener('change', () => {
    syncCustomControls();
    if (bookingOutcomeUnknown) return;
    clearAlternatives();
    feedback.textContent = '';
  });

  function labelBookingRoom(room) {
    dialogTitle.textContent = room.name + (room.requiresApproval ? ' anfragen' : ' buchen');
    submit.replaceChildren(icon(room.requiresApproval ? 'send' : 'calendarPlus'), room.requiresApproval ? 'Anfrage senden' : 'Buchen');
    approvalNote.hidden = !room.requiresApproval;
  }

  async function offerSameTimeAlternatives(attempted, period, start, end) {
    feedback.textContent = 'Raum nicht verfügbar. Alternativen werden geprüft …';
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 5000);
    let freshPlan, freshBookings;
    try {
      const options = {signal: controller.signal};
      [freshPlan, freshBookings] = await Promise.all([api.loadPlan(options), api.loadBookings(attempted.date, options)]);
    } finally {
      clearTimeout(timeout);
      controller.abort();
    }
    if (!dialog.open || selectedBooking !== attempted || dateInput.value !== attempted.date) return;
    const favorite = room => freshPlan.favorites.includes(room.id);
    const candidates = freshPlan.rooms.filter(room => (!onlyFavorites || favorite(room))
      && matchesRoomSearch(`${room.number ?? ''} ${room.name ?? ''}`, floorFilters.query)
      && matchesRoomAmenities(room, selectedEquipment))
      .sort((a, b) => Number(favorite(b)) - Number(favorite(a)));
    const matches = sameTimeBookingAlternatives(candidates, freshBookings, attempted, {period, start, end}).slice(0, 3);
    feedback.textContent = matches.length ? `Raum nicht verfügbar. Frei von ${start}–${end} Uhr:`
      : 'Kein anderer passender Raum für diese Zeit frei.';
    for (const match of matches) {
      const option = button(match.room.name, secondaryButton);
      option.addEventListener('click', () => {
        if (bookPending || selectedBooking !== attempted || dateInput.value !== attempted.date) return;
        selectedBooking = match;
        labelBookingRoom(match.room);
        for (const radio of choices.querySelectorAll('input[type=radio]')) {
          const preset = periods.find(([id]) => id === radio.value);
          const now = new Date();
          radio.disabled = preset ? (!presetBookingWindowOpen(match.date, preset[2], preset[3], match.room.requiresApproval, now)
            || !periodCanBeBooked(match.room.capacity, match.booked, preset[2], preset[3]))
            : !firstFreeBookingWindow(match.room.capacity, match.booked, match.date, now);
        }
        const chosen = choices.querySelector('input:checked');
        submit.disabled = !chosen || chosen.disabled;
        syncCustomControls();
        clearAlternatives();
        feedback.textContent = `${match.room.name} ausgewählt · ${start}–${end} Uhr`;
        (submit.disabled ? close : submit).focus();
      });
      alternatives.append(option);
    }
    alternatives.hidden = matches.length === 0;
  }

  function openBooking(room, opener, preferred = preferredRange()) {
    const now = new Date();
    const availability = roomAvailability(room, now);
    const { booked, presets, custom } = availability;
    trigger = opener;
    selectedBooking = { room, date: dateInput.value, booked };
    bookingOutcomeUnknown = false;
    clearAlternatives();
    labelBookingRoom(room);
    dialogDate.textContent = dateLabel(selectedBooking.date);
    feedback.textContent = '';
    choices.replaceChildren(periodLegend);
    for (const [id, name, from, to] of [...periods, [customPeriod, 'Individuell']]) {
      const option = el('label', 'rp-period');
      const radio = el('input');
      radio.type = 'radio';
      radio.name = 'period';
      radio.value = id;
      radio.required = true;
      radio.disabled = id === customPeriod ? !custom : !presets.some(item => item[0] === id);
      option.append(radio, el('span', '', from ? `${name} · ${from}–${to}` : name));
      choices.append(option);
    }
    const selection = preferredBookingPeriod(preferred, selectedBooking.date, room, availability, now);
    if (custom) customControls.value = selection === customPeriod ? preferred : custom;
    choices.append(customControls.root);
    const first = choices.querySelector('input:enabled');
    const initial = selection ? choices.querySelector(`[value="${selection}"]`) : preferred ? null : first;
    if (initial) initial.checked = true;
    if (preferred && !selection) feedback.textContent = 'Gewählte Zeit nicht verfügbar.';
    else if (!first) return;
    syncCustomControls();
    submit.disabled = !first;
    close.disabled = false;
    dialog.showModal();
    (initial ?? first ?? close).focus();
  }
  close.addEventListener('click', () => {
    if (!bookPending) dialog.close();
  });
  dialog.addEventListener('cancel', event => {
    if (bookPending) event.preventDefault();
  });
  dialog.addEventListener('close', () => {
    if (trigger?.isConnected && !trigger.closest('[hidden]')) trigger.focus();
    else if (trigger) search.focus();
    trigger = null;
    selectedBooking = null;
  });
  roomForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (!selectedBooking || bookPending || bookingOutcomeUnknown) return;
    const period = new FormData(roomForm).get('period');
    const custom = period === customPeriod;
    const preset = periods.find(item => item[0] === period);
    if (!custom && !preset) return;
    const attempted = selectedBooking;
    const [startTime, endTime] = customControls.value;
    if (preset && !presetBookingWindowOpen(attempted.date, preset[2], preset[3], attempted.room.requiresApproval)) {
      feedback.textContent = 'Dieser Zeitraum ist nicht mehr buchbar.';
      return;
    }
    if (custom && (!validBookingWindow(startTime, endTime) || !bookingWindowInFuture(attempted.date, startTime))) {
      feedback.textContent = 'Bitte eine gültige Zeit in der Zukunft wählen.';
      customControls.start.focus();
      return;
    }
    if (custom && !periodCanBeBooked(attempted.room.capacity, attempted.booked, startTime, endTime)) {
      feedback.textContent = 'Dieser Zeitraum ist nicht verfügbar.';
      customControls.start.focus();
      return;
    }
    bookPending = true;
    clearAlternatives();
    submit.disabled = true;
    close.disabled = true;
    choices.disabled = true;
    feedback.textContent = attempted.room.requiresApproval ? 'Anfrage wird gesendet …' : 'Buchung wird gespeichert …';
    try {
      const result = await api.book({
        roomId: attempted.room.id, date: attempted.date, period,
        ...(attempted.room.requiresApproval ? { approvalRequest: true } : {}),
        ...(custom ? { startTime, endTime } : {}),
      });
      dialog.close();
      if (result.pending) {
        status(result.message || 'Anfrage gesendet. Der Raum ist noch nicht gebucht.');
        const requests = el('a', 'rp-request-link', 'Meine Anfragen');
        requests.href = 'bookings.php#rb-booking-requests';
        notice.append(document.createTextNode(' '), requests);
      } else {
        status(`${attempted.room.name} gebucht · ${custom ? startTime : preset[2]}–${custom ? endTime : preset[3]} Uhr.`);
        const bookedLink = el('a', 'rp-request-link', result.bookingId ? 'Zum Kalender (.ics)' : 'Meine Buchungen');
        bookedLink.href = result.bookingId ? 'bookings.php?' + new URLSearchParams({
          download: 'calendar', booking_id: String(result.bookingId),
        }) : 'bookings.php?' + new URLSearchParams({date: attempted.date});
        notice.append(document.createTextNode(' '), bookedLink);
        await loadBookings({ announce: false, focusRoom: attempted.room.id });
      }
    } catch (error) {
      feedback.textContent = error.message || 'Buchung war nicht möglich.';
      if (error.code === 'ROOM_CAPACITY') {
        try {
          await offerSameTimeAlternatives(attempted, period, custom ? startTime : preset[2], custom ? endTime : preset[3]);
        } catch {
          feedback.textContent = 'Raum nicht verfügbar. Alternativen konnten nicht geprüft werden.';
        }
      } else if (error.code === 'BOOKING_OUTCOME_UNKNOWN') {
        bookingOutcomeUnknown = true;
        const check = el('a', 'rp-request-link', attempted.room.requiresApproval ? 'Meine Anfragen' : 'Meine Buchungen');
        check.href = attempted.room.requiresApproval ? 'bookings.php#rb-booking-requests' : 'bookings.php?' + new URLSearchParams({date: attempted.date});
        feedback.append(document.createTextNode(' '), check);
      }
    } finally {
      bookPending = false;
      submit.disabled = bookingOutcomeUnknown;
      close.disabled = false;
      choices.disabled = bookingOutcomeUnknown;
      if (!alternatives.hidden) alternatives.querySelector('button')?.focus();
      else if (bookingOutcomeUnknown) feedback.querySelector('a')?.focus();
    }
  });

  function dateIsBookable() {
    return dateInput.value >= today && dateInput.value <= bookThrough;
  }

  function roomAvailability(room, now) {
    const booked = bookingsByRoom?.get(room.id) ?? [];
    const ready = bookingStatus === 'ready';
    return {
      booked,
      presets: ready && dateIsBookable() ? availablePeriods(room.capacity, booked).filter(([, , from, to]) =>
        presetBookingWindowOpen(dateInput.value, from, to, room.requiresApproval, now)) : [],
      custom: ready && dateIsBookable() ? firstFreeBookingWindow(room.capacity, booked, dateInput.value, now) : null,
    };
  }

  function card(room, availability) {
    const box = el('article', 'rp-card');
    box.dataset.roomId = String(room.id);
    const header = el('div', 'rp-card-header');
    const titleGroup = el('div', 'rp-card-heading');
    const roomName = el('h3', 'rp-room-name', room.name);
    const metadata = [types.get(room.type) ?? 'Raum', room.number].filter(Boolean).join(' · ');
    titleGroup.append(roomName, el('div', 'rp-room-meta', metadata));
    const star = button('', 'rp-icon-button');
    star.dataset.favoriteRoomId = String(room.id);
    star.setAttribute('aria-label', `Favorit: ${room.name}`);
    star.title = 'Favorit';
    star.disabled = pendingStars.has(room.id);
    drawFavoriteControl(star, plan.favorites.includes(room.id));
    star.addEventListener('click', () => changeFavorite(room, star));
    header.append(titleGroup, star);
    box.append(header);
    const badges = el('div', 'rp-room-badges');
    badges.append(withIcon(el('span', 'rp-badge', room.capacity === 1 ? '1 Platz' : `${room.capacity} Plätze`), 'users'));
    if (room.canBook === false) badges.append(withIcon(el('span', 'rp-badge', 'Keine Buchungsberechtigung'), 'lock'));
    if (room.canBook !== false && room.requiresApproval) badges.append(withIcon(el('span', 'rp-badge rp-badge-approval', 'Freigabe nötig'), 'shield'));
    const badgesToShow = new Set([...selectedEquipment, ...normalizeAmenities(room.amenities)]);
    for (const key of [...badgesToShow].slice(0, Math.max(3, selectedEquipment.length))) {
      badges.append(el('span', 'rp-badge', amenities.get(key)));
    }
    box.append(badges);

    const { booked: roomBookings, presets, custom } = availability;
    const available = presets.length > 0 || Boolean(custom);
    const occupancy = el('div', 'rp-bookings');
    if (bookingStatus === 'ready') {
      if (roomBookings.length) {
        const fullyBlocked = roomBookings.some(item => item.blocked === true
          && clock(item.start_time) <= opening && clock(item.end_time) >= closing);
        const summary = onlyFree ? 'Im Zeitraum frei' : fullyBlocked ? 'Gesperrt' : available ? 'Teilweise belegt' : 'Belegt';
        const state = onlyFree ? 'free' : fullyBlocked ? 'blocked' : available ? 'partial' : 'busy';
        const more = el('details', 'rp-booking-details');
        more.append(availabilityLabel('summary', summary, state));
        const list = el('ul', 'rp-booking-list');
        roomBookings.forEach(item => {
          const from = clock(item.start_time);
          const to = clock(item.end_time);
          if (!from || !to) return;
          const name = item.blocked === true ? 'Gesperrt'
            : typeof item.user_name === 'string' ? item.user_name.trim() : '';
          list.append(el('li', '', `${from}–${to} · ${name || 'Belegt'}`));
        });
        more.append(list);
        occupancy.append(more);
      } else {
        const bookable = available && room.canBook !== false;
        occupancy.append(availabilityLabel('p', bookable ? 'Verfügbar' : 'Nicht buchbar', bookable ? 'free' : 'busy'));
      }
    } else if (bookingStatus === 'error') {
      occupancy.append(availabilityLabel('p', 'Belegung unbekannt', 'unknown'));
    } else {
      occupancy.append(availabilityLabel('p', 'Belegung wird geladen …', 'loading'));
    }
    box.append(occupancy);

    const footer = el('div', 'rp-card-actions');
    const details = el('a', secondaryButton, 'Details');
    details.append(icon('arrow'));
    details.href = detailsUrl(room, dateInput.value, preferredRange(), onlyFree);
    details.setAttribute('aria-label', `Details zu ${room.name}`);
    const book = withIcon(
      button(room.requiresApproval ? 'Anfragen' : 'Buchen', 'roomplan-action rp-button rp-button-primary'),
      room.requiresApproval ? 'send' : 'calendarPlus',
    );
    book.dataset.bookRoomId = String(room.id);
    book.setAttribute('aria-label', `${room.name}${room.requiresApproval ? ' anfragen' : ' buchen'}`);
    book.disabled = !available || room.canBook === false || !dateIsBookable();
    book.addEventListener('click', () => openBooking(room, book));
    footer.append(details, book);
    box.append(footer);
    return box;
  }

  function section(title) {
    const element = el('section', 'rp-section');
    const heading = el('h2', 'rp-section-title', title);
    element.append(heading);
    return element;
  }

  function currentSearchAlternatives(floor) {
    if (bookingStatus !== 'ready' || !dateIsBookable()) return [];
    const scopeRoomIds = floor ? new Set(eligibleFloorMarkers(floor, plan.rooms).map(({room}) => room.id)) : null;
    return searchBookingAlternatives(plan, bookingsByRoom, {
      date: dateInput.value, range: preferredRange(), query: floorFilters.query,
      equipment: selectedEquipment, onlyFavorites, scopeRoomIds, allowInProgressPreset: rangeIsExplicit,
    });
  }

  function drawSearchAlternatives(floor) {
    const range = preferredRange();
    const wish = onlyFree && rangeIsExplicit && bookingStatus === 'ready' && dateIsBookable()
      ? roomForUnavailableWatch(plan.rooms, bookingsByRoom, {
        date: dateInput.value, range, query: floorFilters.query,
        equipment: selectedEquipment, onlyFavorites, favorites: plan.favorites,
      })
      : null;
    watchLink.hidden = wish === null;
    if (wish) {
      watchLink.href = detailsUrl(wish, dateInput.value, range, onlyFree) + '#rb-room-watch';
      watchLink.setAttribute('aria-label', `Bei Freiwerden melden: ${wish.name}`);
    } else {
      watchLink.removeAttribute('href');
      if (watchLink === document.activeElement) search.focus();
    }
    const options = currentSearchAlternatives(floor);
    const focused = suggestionList.contains(document.activeElement) ? document.activeElement.dataset.alternative : null;
    const floorTitles = new Map();
    if (floor && options.some(option => option.kind === 'room')) {
      for (const otherFloor of floors) {
        for (const {room} of eligibleFloorMarkers(otherFloor, plan.rooms)) {
          if (!floorTitles.has(room.id)) floorTitles.set(room.id, otherFloor.title);
        }
      }
    }
    const optionKey = option => [option.kind, option.room.id, ...option.range].join('/');
    suggestionList.replaceChildren(...options.map(option => {
      const {room, range, kind, minutes} = option;
      const choice = button('', secondaryButton + ' rp-search-alternative');
      choice.dataset.alternative = optionKey(option);
      const floorLabel = floorTitles.get(room.id);
      const change = kind === 'later' ? `${minutes} Min. später` : kind === 'shorter' ? `${minutes} Min. kürzer`
        : floorLabel && floorLabel !== floor?.title ? `Gleiche Zeit · ${floorLabel}` : 'Gleiche Zeit';
      choice.append(icon(kind === 'room' ? 'layers' : 'clock'), el('span', 'rp-alternative-name', room.name),
        el('span', 'rp-alternative-time', `${range[0]}–${range[1]} Uhr`),
        el('span', 'rp-alternative-change', change));
      choice.addEventListener('click', () => {
        // A boundary may have passed while these controls were visible.
        const current = currentSearchAlternatives(floor).find(item => optionKey(item) === optionKey(option));
        if (!current) {
          drawSearchAlternatives(floor);
          status('Diese Alternative ist nicht mehr verfügbar.', true);
          return;
        }
        openBooking(current.room, choice, current.range);
      });
      const row = el('li');
      row.append(choice);
      return row;
    }));
    searchSuggestions.hidden = options.length === 0;
    if (focused) {
      const previous = [...suggestionList.querySelectorAll('button')].find(item => item.dataset.alternative === focused);
      (previous || suggestionList.querySelector('button') || search).focus();
    }
    return options.length;
  }

  function showFilterResults(count, inFloor = false, suggestions = 0) {
    let text = '';
    const hasSearch = normalizeRoomSearch(floorFilters.query).length > 0;
    const hasFilters = hasSearch || selectedEquipment.length > 0 || onlyFree;
    if (suggestions) text = 'Kein Raum für deine Auswahl.';
    else if (hasFilters && (!onlyFree || bookingStatus === 'ready')) {
      if (count > 0) text = count === 1 ? '1 passender Raum' : `${count} passende Räume`;
      else if (onlyFavorites && !plan.favorites.length) text = 'Noch keine Favoriten.';
      else if (inFloor) text = 'Keine passenden Räume auf diesem Grundriss.';
      else if (onlyFree) text = 'Keine passenden freien Räume für diesen Zeitraum.';
      else if (hasSearch) text = 'Keine passenden Räume für diese Suche.';
      else text = 'Keine passenden Räume mit dieser Ausstattung.';
    }
    // Keep a live region outside the replaced result content so it also announces zero matches.
    filterResult.toggleAttribute('data-empty', Boolean(text) && (count === 0 || suggestions > 0));
    if (filterResult.textContent !== text) filterResult.textContent = text;
    return hasFilters;
  }

  function render(focusRoom) {
    if (!plan) return;
    const active = document.activeElement;
    const previousCard = content.contains(active) ? active.closest('[data-room-id]') : null;
    const previousId = previousCard?.dataset.roomId;
    const previousControl = active?.matches('[data-book-room-id]') ? '[data-book-room-id]:enabled'
      : active?.matches('[data-favorite-room-id]') ? '[data-favorite-room-id]:enabled'
      : active?.matches('summary') ? 'summary'
      : active?.matches('a') ? 'a' : null;
    const expanded = new Set(
      [...content.querySelectorAll('details[open]')].map(node => node.closest('[data-room-id]')?.dataset.roomId),
    );
    const { slots, unplaced } = groupRooms(plan);
    const now = new Date();
    const availabilityCache = new Map();
    const availabilityFor = room => {
      if (!availabilityCache.has(room.id)) availabilityCache.set(room.id, roomAvailability(room, now));
      return availabilityCache.get(room.id);
    };
    const [from, to] = dateRange.value;
    const matchable = bookingStatus === 'ready' && dateIsBookable() && validBookingWindow(from, to);
    const presetWindow = periods.some(([, , start, end]) => start === from && end === to);
    const futureStart = bookingWindowInFuture(dateInput.value, from, now);
    const matchesFreeFilter = room => matchable && room.canBook !== false
      && (futureStart || (rangeIsExplicit && presetWindow && !room.requiresApproval
        && presetBookingWindowOpen(dateInput.value, from, to, false, now)))
      && periodCanBeBooked(room.capacity, bookingsByRoom?.get(room.id) ?? [], from, to);
    const matchesEquipment = room => matchesRoomAmenities(room, selectedEquipment);
    const drawCard = room => card(room, availabilityFor(room));
    filter.disabled = !plan.rooms.length;
    syncFloorControl(slots.some(Boolean));
    const floor = !onlyFavorites && activeFloor();
    if (floor) {
      const panel = section(floor.title);
      const focus = el('div', 'rp-floor-focus');
      focus.id = 'rp-selected-floor-room';
      focus.hidden = true;
      const previousKey = activeFloorView?.contains(active) ? active.dataset.floorFocusKey : null;
      const previousView = activeFloorViewId === floor.id ? activeFloorView?.snapshot() : null;
      const findControl = key => [...visual.querySelectorAll('[data-floor-focus-key]')]
        .find(control => control.dataset.floorFocusKey === key
          && !control.closest('[hidden], details:not([open])') && !control.disabled);
      function closeDetails() {
        const opener = findControl(floorRoomOpener) || findControl(`list-${selectedFloorRoom}`);
        selectedFloorRoom = null;
        updateFocus();
        (opener || search).focus();
      }
      function updateFocus(moveFocus = false) {
        const room = plan.rooms.find(candidate => candidate.id === selectedFloorRoom);
        if (!room || !visual.isFloorRoomShown(room.id)) {
          if (room && !matchesEquipment(room)) selectedFloorRoom = null;
          focus.replaceChildren();
          focus.hidden = true;
          visual.selectFloorRoom(null);
          return;
        }
        const selectedCard = drawCard(room);
        selectedCard.tabIndex = -1;
        const close = withIcon(button('', 'rp-icon-button rp-floor-close-detail'), 'close');
        close.setAttribute('aria-label', `Raumdetails schließen: ${room.name}`);
        close.dataset.floorFocusKey = 'close';
        close.title = 'Schließen';
        close.addEventListener('click', closeDetails);
        selectedCard.querySelector('.rp-card-header')?.append(close);
        focus.replaceChildren(selectedCard);
        focus.hidden = false;
        visual.selectFloorRoom(room.id);
        if (moveFocus || String(room.id) === String(focusRoom)) {
          (selectedCard.querySelector('[data-book-room-id]:enabled') || selectedCard).focus();
        } else if (previousId === String(room.id)) {
          (previousKey === 'close' ? close : selectedCard.querySelector(previousControl || 'a'))?.focus();
        }
      }
      floorFilters.onlyBookable = onlyFree;
      floorFilters.equipmentActive = selectedEquipment.length > 0;
      const visual = renderFloorPosition({
        floor, rooms: plan.rooms, filters: floorFilters, roomDetails: focus,
        availabilityStatus: bookingStatus,
        canBook: matchesFreeFilter, matchesEquipment,
        onResultCount: count => showFilterResults(count, true, drawSearchAlternatives(floor)),
        onOpenList() {
          chooseView('list');
          content.focus();
        },
        availability(room) {
          if (bookingStatus !== 'ready') return 'unknown';
          if (onlyFree) return matchesFreeFilter(room) ? 'free' : 'busy';
          const { booked, presets, custom } = availabilityFor(room);
          const allDayBlocked = booked.some(item => item.blocked === true
            && clock(item.start_time) <= opening && clock(item.end_time) >= closing);
          if (allDayBlocked) return 'blocked';
          return !presets.length && !custom ? 'busy' : booked.length ? 'partial' : 'free';
        },
        onRoomSelect(room, opener) {
          selectedFloorRoom = room.id;
          floorRoomOpener = opener.dataset.floorFocusKey;
          updateFocus(true);
        },
        onFilterChange(selectionHidden) {
          if (!selectionHidden) return;
          selectedFloorRoom = null;
          updateFocus();
        },
        imageUrl: api.floorplanImageUrl(floor.id),
      });
      panel.append(visual);
      content.replaceChildren(panel);
      if (previousView) visual.restore(previousView);
      activeFloorView = visual;
      activeFloorViewId = floor.id;
      updateFocus();
      if (previousKey && !previousId) (findControl(previousKey) || search).focus();
      if (focusRoom != null && !visual.isFloorRoomShown(focusRoom)) search.focus();
      return;
    }
    activeFloorView = null;
    activeFloorViewId = null;
    const visible = plan.rooms.filter(room => (!onlyFavorites || plan.favorites.includes(room.id))
      && matchesRoomSearch(`${room.number ?? ''} ${room.name ?? ''}`, floorFilters.query)
      && matchesEquipment(room) && (!onlyFree || matchesFreeFilter(room)));
    const hasFilters = showFilterResults(visible.length, false, drawSearchAlternatives(null));
    if (!visible.length) {
      const noFavorites = onlyFavorites && !plan.favorites.length;
      const text = hasFilters ? '' : noFavorites ? 'Noch keine Favoriten.' : 'Keine Räume verfügbar.';
      content.replaceChildren(...(text ? [el('p', 'rp-empty', text)] : []));
      if (previousCard || focusRoom != null) {
        (noFavorites || selectedEquipment.length ? optionsSummary : floorFilters.query ? search : onlyFree ? freeFilter : content).focus();
      }
      return;
    }
    const visibleIds = new Set(visible.map(room => room.id));
    const visibleSlots = slots.map(room => room && visibleIds.has(room.id) ? room : null);
    if (onlyFavorites || listMode || !visibleSlots.some(Boolean)) {
      const list = el('div', 'rp-list');
      if (onlyFree) {
        const favorites = new Set(plan.favorites);
        visible.sort((a, b) => Number(favorites.has(b.id)) - Number(favorites.has(a.id)));
      }
      list.append(...visible.map(drawCard));
      content.replaceChildren(list);
    } else {
      const floor = section('Raster');
      const scroll = el('div', 'rp-grid-scroll');
      scroll.setAttribute('aria-label', 'Raumplan');
      scroll.tabIndex = 0;
      const grid = el('div', 'rp-grid');
      grid.style.setProperty('--rp-cols', String(plan.cols));
      for (const room of visibleSlots) {
        const slot = el('div', room ? 'rp-slot' : 'rp-slot rp-slot-empty');
        if (room) slot.append(drawCard(room));
        else slot.setAttribute('aria-hidden', 'true');
        grid.append(slot);
      }
      scroll.append(grid);
      floor.append(scroll);
      content.replaceChildren(floor);
      const visibleUnplaced = unplaced.filter(room => visibleIds.has(room.id));
      if (visibleUnplaced.length) {
        const more = section('Weitere Räume');
        const list = el('div', 'rp-list');
        list.append(...visibleUnplaced.map(drawCard));
        more.append(list);
        content.append(more);
      }
    }
    for (const node of content.querySelectorAll('details')) {
      if (expanded.has(node.closest('[data-room-id]')?.dataset.roomId)) node.open = true;
    }
    const roomToRestore = focusRoom ?? previousId;
    if (roomToRestore != null) {
      const matching = Array.from(content.querySelectorAll('[data-room-id]'))
        .find(item => item.dataset.roomId === String(roomToRestore));
      const control = matching?.querySelector(focusRoom != null ? '[data-book-room-id]:enabled' : previousControl || 'a');
      (control || matching?.querySelector('a') || content).focus();
    }
  }

  async function loadBookings({ announce = true, focusRoom = null, preload = null } = {}) {
    const date = dateInput.value;
    const usePreload = preload?.date === date && !preload.controller.signal.aborted;
    if (!usePreload) preload?.controller.abort();
    if (!plan || !dateValue(date)) return;
    const invocation = ++bookingRun;
    if (!usePreload) {
      bookingController?.abort();
      bookingController = new AbortController();
    }
    bookingStatus = 'loading';
    bookingsByRoom = null;
    retry.hidden = true;
    content.setAttribute('aria-busy', 'true');
    if (announce) {
      status('Belegung wird geladen …');
      render();
    }
    try {
      let bookings;
      if (usePreload) {
        const result = await preload.result;
        if (!result.ok) throw result.error;
        bookings = result.value;
      } else {
        bookings = await api.loadBookings(date, { signal: bookingController.signal });
      }
      if (invocation !== bookingRun) return;
      const next = new Map();
      const ids = new Set(plan.rooms.map(room => room.id));
      for (const item of bookings) {
        const id = Number(item.room_id);
        if (!ids.has(id) || item.date !== date) continue;
        if (!next.has(id)) next.set(id, []);
        next.get(id).push(item);
      }
      bookingsByRoom = next;
      bookingStatus = 'ready';
      if (announce) status('');
    } catch (error) {
      if (invocation !== bookingRun || error.name === 'AbortError') return;
      bookingStatus = 'error';
      retry.hidden = false;
      if (announce) status(error.message || 'Belegung konnte nicht geladen werden.', true);
    } finally {
      if (invocation === bookingRun) {
        content.setAttribute('aria-busy', 'false');
        render(focusRoom);
      }
    }
  }

  let planRun = 0;
  let planController = null;
  async function loadPlan() {
    const invocation = ++planRun;
    planController?.abort();
    planController = new AbortController();
    bookingController?.abort();
    ++bookingRun;
    if (edit) edit.disabled = true;
    loadPlanError = false;
    retry.hidden = true;
    status('Raumplan wird geladen …');
    content.setAttribute('aria-busy', 'true');
    let preload = null;
    try {
      const reads = startRoomplanReads(api, {
        signal: planController.signal,
        bookingDate: !plan && dateValue(dateInput.value) ? dateInput.value : null,
      });
      preload = reads.bookings;
      if (preload) bookingController = preload.controller;
      const nextPlan = await reads.plan;
      if (invocation !== planRun) return;
      plan = nextPlan;
      if (edit && isAdmin) edit.disabled = false;
      await loadBookings({preload});
    } catch (error) {
      preload?.controller.abort();
      if (invocation !== planRun || error.name === 'AbortError') return;
      loadPlanError = true;
      retry.hidden = false;
      content.replaceChildren();
      filterResult.textContent = '';
      searchSuggestions.hidden = true;
      watchLink.hidden = true;
      status(error.message || 'Raumplan konnte nicht geladen werden.', true);
    } finally {
      if (invocation === planRun) content.setAttribute('aria-busy', 'false');
    }
  }

  dateInput.addEventListener('change', () => {
    if (!dateValue(dateInput.value)) {
      ++bookingRun;
      bookingController?.abort();
      bookingsByRoom = null;
      bookingStatus = 'idle';
      content.replaceChildren();
      filterResult.textContent = '';
      searchSuggestions.hidden = true;
      watchLink.hidden = true;
      content.setAttribute('aria-busy', 'false');
      status('Bitte ein Datum auswählen.', true);
      resetDate.disabled = false;
      syncFilterSummary();
      return;
    }
    resetDate.disabled = dateInput.value === today;
    if (!rangeIsExplicit) dateRange.value = defaultFilterRange(dateInput.value);
    syncFilterSummary();
    rememberView();
    if (plan && !loadPlanError) void loadBookings();
  });
  resetDate.addEventListener('click', () => {
    dateInput.value = today;
    resetDate.disabled = true;
    if (!rangeIsExplicit) dateRange.value = defaultFilterRange(today);
    syncFilterSummary();
    rememberView();
    dateInput.focus();
    if (plan && !loadPlanError) void loadBookings();
  });
  function applyViewRange() {
    syncFilterSummary();
    if (loadPlanError || !dateValue(dateInput.value)) return;
    rememberView();
    render();
  }
  equipmentOptions.addEventListener('change', () => {
    selectedEquipment = [...equipmentInputs].filter(([, checkbox]) => checkbox.checked).map(([key]) => key);
    syncEquipmentFilter();
    applyViewRange();
  });
  clearEquipment.addEventListener('click', () => {
    selectedEquipment = [];
    equipmentInputs.values().next().value.focus();
    syncEquipmentFilter();
    applyViewRange();
  });
  dateRange.root.addEventListener('change', () => {
    rangeIsExplicit = true;
    applyViewRange();
  });
  freeFilter.addEventListener('click', () => {
    onlyFree = !onlyFree;
    freeFilter.setAttribute('aria-pressed', String(onlyFree));
    applyViewRange();
  });
  filter.addEventListener('click', () => {
    if (loadPlanError || !dateValue(dateInput.value)) return;
    onlyFavorites = !onlyFavorites;
    filter.setAttribute('aria-pressed', String(onlyFavorites));
    applyViewRange();
  });
  retry.addEventListener('click', () => {
    if (!dateValue(dateInput.value)) {
      dateInput.value = today;
      resetDate.disabled = true;
      syncFilterSummary();
    }
    dateInput.focus();
    if (loadPlanError || !plan) void loadPlan();
    else void loadBookings();
  });

  window.addEventListener('popstate', () => {
    const state = readViewLocation(window.location.href, today);
    const dateChanged = state.date !== dateInput.value;
    dateInput.value = state.date;
    onlyFavorites = state.favorites;
    onlyFree = state.free;
    selectedEquipment = state.equipment;
    syncEquipmentFilter();
    rangeIsExplicit = state.range !== null;
    dateRange.value = state.range ?? defaultFilterRange(state.date);
    listMode = state.list;
    floorChoice = state.floorChoice;
    selectedFloorRoom = null;
    filter.setAttribute('aria-pressed', String(onlyFavorites));
    freeFilter.setAttribute('aria-pressed', String(onlyFree));
    resetDate.disabled = state.date === today;
    syncFilterSummary();
    if (!plan || loadPlanError) return;
    if (dateChanged) void loadBookings();
    else render();
  });

  if (isAdmin && edit && editor) {
    const editorActions = edit.closest('.rp-floor-actions');
    const closeEditor = () => {
      if (editorMounted?.canClose && !editorMounted.canClose()) return;
      editorMounted?.destroy?.();
      editorMounted = null;
      editor.hidden = true;
      view.hidden = false;
      if (editorActions) editorActions.hidden = false;
      editorOpened = false;
      edit.setAttribute('aria-expanded', 'false');
      if (legacyActions) {
        legacyActions.open = false;
        legacyActions.querySelector('summary')?.focus();
      } else edit.focus();
    };
    edit.disabled = true;
    edit.setAttribute('aria-controls', 'roomplan-editor');
    edit.setAttribute('aria-expanded', 'false');
    edit.addEventListener('click', async () => {
      if (!plan || loadPlanError || floorEditor || editorOpening) return;
      if (editorOpened) {
        closeEditor();
        return;
      }
      editorOpening = true;
      edit.disabled = true;
      try {
        const { mountEditor } = await import('./editor.js');
        editor.hidden = false;
        view.hidden = true;
        if (editorActions) editorActions.hidden = true;
        editorOpened = true;
        edit.setAttribute('aria-expanded', 'true');
        editorMounted = await mountEditor({
          root: editor, api, initial: structuredClone(plan),
          onChange: () => loadPlan(), onClose: closeEditor,
        });
        const heading = editor.querySelector('h2');
        if (heading) { heading.tabIndex = -1; heading.focus(); }
      } catch (error) {
        closeEditor();
        status(error.message || 'Bearbeitung konnte nicht geöffnet werden.', true);
      } finally {
        editorOpening = false;
        edit.disabled = false;
      }
    });
  }
  if (isAdmin && floorEdit && floorEditorRoot) {
    const closeFloor = () => {
      if (floorEditor?.canClose && !floorEditor.canClose()) return;
      floorEditor?.destroy?.();
      floorEditor = null;
      floorEditorRoot.hidden = true;
      view.hidden = false;
      floorEdit.setAttribute('aria-expanded', 'false');
      if (legacyActions) legacyActions.hidden = false;
      floorEdit.disabled = false;
      floorEdit.focus();
    };
    floorEdit.addEventListener('click', async () => {
      if (!plan || loadPlanError || editorOpened || editorOpening) return;
      if (floorEditor) { closeFloor(); return; }
      editorOpening = true;
      floorEdit.disabled = true;
      try {
        const { mountFloorEditor } = await import('./floor-editor.js');
        floorEditorRoot.hidden = false;
        view.hidden = true;
        if (legacyActions) { legacyActions.open = false; legacyActions.hidden = true; }
        floorEdit.setAttribute('aria-expanded', 'true');
        floorEditor = await mountFloorEditor({
          root: floorEditorRoot, api, plans: structuredClone(floors),
          initialId: activeFloor()?.id ?? floors[0]?.id, rooms: structuredClone(plan.rooms),
          onChange: id => reloadFloors(Number.isSafeInteger(id) ? id : null, true),
          onRoomsChanged: async () => {
            await loadPlan();
            if (loadPlanError) throw new Error('Raumansicht nicht verfügbar.');
          },
          onClose: closeFloor,
        });
        const heading = floorEditorRoot.querySelector('h2');
        if (heading) { heading.tabIndex = -1; heading.focus(); }
      } catch (error) {
        closeFloor();
        status(error.message || 'Bearbeitung konnte nicht geöffnet werden.', true);
      } finally { editorOpening = false; floorEdit.disabled = false; }
    });
  }
  const startup = Promise.all([loadPlan(), reloadFloors()]);
  if (isAdmin && floorEdit && new URL(window.location.href).searchParams.get('manage') === '1') {
    void startup.then(() => {
      if (!plan || loadPlanError || floorEditor || editorOpened || editorOpening) return;
      const url = new URL(window.location.href);
      if (url.searchParams.get('manage') !== '1') return;
      url.searchParams.delete('manage');
      window.history.replaceState(window.history.state, '', url);
      floorEdit.click();
    });
  }
}

if (typeof document !== 'undefined') {
  const main = document.getElementById('roomplan');
  if (main) mountRoomplan(main);
}
