const availabilityStates = new Map([
  ['free', { label: 'Verfügbar', symbol: '✓' }],
  ['partial', { label: 'Teilweise belegt', symbol: '◐' }],
  ['busy', { label: 'Belegt', symbol: '●' }],
  ['blocked', { label: 'Gesperrt', symbol: '×' }],
  ['unknown', { label: 'Belegung unbekannt', symbol: '?' }],
]);
const zooms = [100, 150, 200, 300];

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = String(text);
  return node;
}

function button(label, className, ariaLabel) {
  const node = el('button', className, label);
  node.type = 'button';
  if (ariaLabel) node.setAttribute('aria-label', ariaLabel);
  return node;
}

function roomId(value) {
  if (Number.isSafeInteger(value) && value > 0) return value;
  if (typeof value === 'string' && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value))) {
    return Number(value);
  }
  return null;
}

// Resolve markers against authorized room objects on every render. Unrecognized
// IDs have no label, DOM target or shortcut, even if stale layout data lists them.
export function eligibleFloorMarkers(floor, rooms) {
  const byId = new Map();
  const supplied = rooms instanceof Map ? rooms.values() : Array.isArray(rooms) ? rooms : [];
  for (const room of supplied) {
    const id = roomId(room?.id);
    if (id !== null) byId.set(id, room);
  }
  const result = [];
  const seen = new Set();
  for (const marker of Array.isArray(floor?.markers) ? floor.markers : []) {
    const id = roomId(marker?.roomId);
    const room = byId.get(id);
    if (!room || seen.has(id)) continue;
    const { x, y, w, h } = marker;
    if (![x, y, w, h].every(Number.isFinite) || x < 0 || y < 0 || w <= 0 || h <= 0
      || x + w > 1 + 1e-8 || y + h > 1 + 1e-8 || x >= 1 || y >= 1) continue;
    seen.add(id);
    result.push({ room, x, y, w: Math.min(w, 1 - x), h: Math.min(h, 1 - y) });
  }
  // Top-left ordering matches expectations for keyboard traversal.
  return result.sort((a, b) => a.y - b.y || a.x - b.x || roomId(a.room.id) - roomId(b.room.id));
}

function getImageUrl(value) {
  try {
    if (typeof value !== 'string' && !(value instanceof URL)) return null;
    const parsed = new URL(value, document.baseURI);
    return ['https:', 'http:'].includes(parsed.protocol) && parsed.origin === document.location.origin
      && !parsed.username && !parsed.password ? parsed.href : null;
  } catch {
    return null;
  }
}

function markerLabel(room) {
  const name = typeof room.name === 'string' && room.name.trim() ? room.name : 'Raum';
  return typeof room.number === 'string' && room.number.trim() && room.number.trim() !== name.trim()
    ? `${room.number} · ${name}`
    : name;
}

function percentage(num) {
  return (num * 100).toFixed(6) + '%';
}

function normalizeRoomSearch(value) {
  return String(value ?? '').toLocaleLowerCase('de-DE')
    .replaceAll('ß', 'ss').replaceAll('ä', 'ae').replaceAll('ö', 'oe').replaceAll('ü', 'ue')
    .normalize('NFD').replace(/\p{M}/gu, '').trim().split(/\s+/).filter(Boolean);
}

function simplifyGerman(word) {
  return word.replace(/([aou])e/g, '$1');
}

export function matchesRoomSearch(label, query) {
  const words = normalizeRoomSearch(query).map(simplifyGerman);
  const fields = normalizeRoomSearch(label).map(simplifyGerman);
  return words.every(word => fields.some(field => field.includes(word)));
}

export function renderFloorPosition({
  floor, rooms, availability, onRoomSelect, imageUrl, roomDetails,
  filters = { query: '', onlyBookable: false }, canBook = () => true, availabilityStatus = 'ready',
  onFilterChange = () => {}, matchesEquipment = () => true, onResultCount = () => {},
}) {
  const frame = el('section', 'rp-floor-position');
  const title = (typeof floor?.title === 'string' && floor.title.trim()) || 'Grundriss';
  frame.setAttribute('aria-label', title);
  frame.tabIndex = -1;
  const matches = eligibleFloorMarkers(floor, rooms);
  const markers = matches.map(marker => {
    const state = availability(marker.room);
    const key = availabilityStates.has(state) ? state : 'unknown';
    return { ...marker, key, state: availabilityStates.get(key), label: markerLabel(marker.room) };
  });

  const filtersRow = el('div', 'rp-floor-filters');
  const search = el('input', 'rp-floor-search');
  search.type = 'search';
  search.placeholder = 'Raum suchen';
  search.setAttribute('aria-label', 'Raum suchen');
  search.dataset.floorFocusKey = 'search';
  search.maxLength = 100;
  search.value = filters.query;
  const resultCount = el('span', 'rp-floor-result-count');
  resultCount.setAttribute('role', 'status');
  filtersRow.append(search, resultCount);

  const shortcuts = el('details', 'rp-floor-shortcuts');
  const shortcutTitle = el('summary', '', 'Räume im Grundriss');
  const shortcutList = el('ul', 'rp-floor-shortcut-list');
  shortcuts.append(shortcutTitle, shortcutList);
  const showFallback = () => {
    const wasFocused = tools.contains(document.activeElement) || viewport.contains(document.activeElement);
    tools.hidden = true;
    viewport.hidden = true;
    hint.textContent = 'Grundrissbild nicht verfügbar.';
    shortcuts.open = !shortcuts.hidden;
    if (wasFocused) (!shortcuts.hidden ? shortcutTitle : frame).focus();
  };
  const entries = new Map();
  let selectedId = null;
  for (const item of markers) {
    const li = el('li');
    const link = button(item.label, 'roomplan-action roomplan-action-secondary rp-floor-shortcut',
      `${item.label} · ${item.state.label}`);
    link.dataset.floorFocusKey = `list-${item.room.id}`;
    if (roomDetails?.id) link.setAttribute('aria-controls', roomDetails.id);
    const icon = el('span', 'rp-floor-indicator', item.state.symbol);
    icon.setAttribute('aria-hidden', 'true');
    icon.dataset.availability = item.key;
    link.dataset.floorRoomId = String(item.room.id);
    link.prepend(icon);
    link.addEventListener('click', () => onRoomSelect(item.room, link));
    entries.set(item.room.id, { item, li, link });
    li.append(link);
    shortcutList.append(li);
  }

  const tools = el('div', 'rp-floor-controls');
  tools.setAttribute('role', 'group');
  tools.setAttribute('aria-label', 'Grundriss vergrößern');
  const smaller = button('−', 'roomplan-action roomplan-action-secondary rp-floor-zoom', 'Verkleinern');
  const larger = button('+', 'roomplan-action roomplan-action-secondary rp-floor-zoom', 'Vergrößern');
  smaller.dataset.floorFocusKey = 'minus';
  larger.dataset.floorFocusKey = 'plus';
  const level = button('100 %', 'roomplan-action roomplan-action-secondary rp-floor-level', 'Zoom zurücksetzen: 100 Prozent');
  level.dataset.floorFocusKey = 'zoom';
  const legend = el('ul', 'rp-floor-legend');
  legend.setAttribute('aria-label', 'Belegungsstatus');
  for (const [key, state] of availabilityStates) {
    if (key === 'blocked' && !markers.some(marker => marker.key === key)) continue;
    const item = el('li');
    const icon = el('span', 'rp-floor-indicator', state.symbol);
    icon.setAttribute('aria-hidden', 'true');
    icon.dataset.availability = key;
    item.append(icon, el('span', '', state.label));
    legend.append(item);
  }
  tools.append(smaller, level, larger, legend);

  const viewport = el('div', 'rp-floor-viewport');
  viewport.setAttribute('role', 'region');
  viewport.setAttribute('aria-label', 'Grundriss mit Raumpositionen');
  viewport.tabIndex = 0;
  const canvas = el('div', 'rp-floor-canvas');
  canvas.dataset.zoom = '100';
  const image = el('img', 'rp-floor-image');
  image.alt = 'Grundriss: ' + title;
  image.decoding = 'async';
  image.referrerPolicy = 'no-referrer';
  image.draggable = false;

  const overlay = el('div', 'rp-floor-marker-layer');
  for (const item of markers) {
    const marker = button('', 'rp-floor-marker', `${item.label} · ${item.state.label}`);
    marker.dataset.floorRoomId = String(item.room.id);
    marker.dataset.availability = item.key;
    marker.title = `${item.label} · ${item.state.label}`;
    marker.dataset.floorFocusKey = `map-${item.room.id}`;
    if (roomDetails?.id) marker.setAttribute('aria-controls', roomDetails.id);
    entries.get(item.room.id).marker = marker;
    for (const [key, value] of Object.entries({ left: item.x, top: item.y, width: item.w, height: item.h })) {
      marker.style.setProperty(key, percentage(value));
    }
    const indicator = el('span', 'rp-floor-indicator rp-floor-marker-indicator', item.state.symbol);
    indicator.setAttribute('aria-hidden', 'true');
    const name = el('span', 'rp-floor-marker-name', item.label);
    name.setAttribute('aria-hidden', 'true');
    marker.append(indicator, name);
    marker.addEventListener('click', () => onRoomSelect(item.room, marker));
    overlay.append(marker);
  }
  const hint = el('p', 'rp-floor-hint');
  hint.setAttribute('role', 'status');
  if (markers.length === 0) hint.textContent = 'Keine Räume auf diesem Grundriss markiert.';
  canvas.append(image, overlay);
  viewport.append(canvas);
  if (markers.length) frame.append(filtersRow, shortcuts);
  if (roomDetails) frame.append(roomDetails);
  frame.append(tools, viewport, hint);

  function updateSelection(id) {
    selectedId = id;
    for (const [roomId, entry] of entries) {
      if (roomDetails?.id) for (const control of [entry.link, entry.marker]) {
        control.setAttribute('aria-expanded', String(roomId === id));
      }
    }
  }

  function applyFilters(resetOpen = false) {
    const required = normalizeRoomSearch(filters.query);
    let count = 0;
    for (const { item, li, marker } of entries.values()) {
      const matches = matchesRoomSearch(item.label, filters.query);
      const bookable = !filters.onlyBookable || (availabilityStatus === 'ready' && canBook(item.room, item.key));
      li.hidden = marker.hidden = !(matches && matchesEquipment(item.room) && bookable);
      if (!li.hidden) count++;
    }
    const active = required.length > 0 || filters.onlyBookable || filters.equipmentActive;
    shortcuts.hidden = count === 0;
    shortcutTitle.textContent = required.length ? 'Suchergebnisse' : filters.onlyBookable ? 'Freie Räume'
      : filters.equipmentActive ? 'Passende Räume' : 'Räume im Grundriss';
    if (active) shortcuts.open = true;
    else if (resetOpen && !viewport.hidden) shortcuts.open = false;
    resultCount.textContent = !active || filters.equipmentActive ? '' : filters.onlyBookable && availabilityStatus !== 'ready'
      ? (availabilityStatus === 'error' ? 'Belegung unbekannt' : 'Belegung wird geladen …')
      : count === 0 ? 'Keine Treffer' : count === 1 ? '1 Raum' : `${count} Räume`;
    onResultCount(count);
  }
  const notifyFilters = () => {
    applyFilters(true);
    onFilterChange(selectedId != null && entries.get(selectedId)?.marker.hidden);
  };
  search.addEventListener('input', () => { filters.query = search.value; notifyFilters(); });
  applyFilters();
  updateSelection(null);
  // Keep the renderer's DOM return contract; expose view state without a re-render.
  frame.selectFloorRoom = updateSelection;
  frame.isFloorRoomShown = id => entries.has(id) && !entries.get(id).marker.hidden;
  frame.snapshot = () => ({ zoomIndex, x: viewport.scrollLeft, y: viewport.scrollTop, open: shortcuts.open });
  frame.restore = position => {
    setZoom(position.zoomIndex);
    viewport.scrollLeft = position.x;
    viewport.scrollTop = position.y;
    if (!viewport.hidden && !filters.onlyBookable && !filters.equipmentActive && !normalizeRoomSearch(filters.query).length) {
      shortcuts.open = position.open;
    }
  };

  let zoomIndex = 0;
  function setZoom(index) {
    const currentWidth = canvas.offsetWidth;
    const active = document.activeElement;
    const x = currentWidth ? (viewport.scrollLeft + viewport.clientWidth / 2) / currentWidth : 0.5;
    const currentHeight = canvas.offsetHeight;
    const y = currentHeight ? (viewport.scrollTop + viewport.clientHeight / 2) / currentHeight : 0.5;
    zoomIndex = Math.max(0, Math.min(zooms.length - 1, index));
    const zoom = zooms[zoomIndex];
    canvas.dataset.zoom = String(zoom);
    viewport.scrollLeft = x * canvas.offsetWidth - viewport.clientWidth / 2;
    viewport.scrollTop = y * canvas.offsetHeight - viewport.clientHeight / 2;
    level.textContent = `${zoom} %`;
    level.setAttribute('aria-label', `Zoom zurücksetzen: ${zoom} Prozent`);
    smaller.disabled = zoomIndex === 0;
    larger.disabled = zoomIndex === zooms.length - 1;
    // Avoid dropping keyboard focus when an end of the zoom range is reached.
    if ((active === smaller && smaller.disabled) || (active === larger && larger.disabled)) level.focus();
  }
  smaller.addEventListener('click', () => setZoom(zoomIndex - 1));
  larger.addEventListener('click', () => setZoom(zoomIndex + 1));
  level.addEventListener('click', () => setZoom(0));
  setZoom(0);

  const source = getImageUrl(imageUrl);
  const { width, height } = floor ?? {};
  if (source && Number.isSafeInteger(width) && Number.isSafeInteger(height) && width > 0 && height > 0) {
    image.width = width;
    image.height = height;
    image.addEventListener('error', showFallback, { once: true });
    image.src = source;
  } else {
    showFallback();
  }
  return frame;
}
