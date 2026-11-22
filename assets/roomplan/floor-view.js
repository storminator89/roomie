const availabilityStates = new Map([
  ['free', { label: 'Verfügbar', symbol: '✓' }],
  ['partial', { label: 'Teilweise belegt', symbol: '◐' }],
  ['busy', { label: 'Belegt', symbol: '●' }],
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

export function renderFloorPosition({ floor, rooms, availability, onRoomSelect, imageUrl }) {
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

  const shortcuts = el('details', 'rp-floor-shortcuts');
  const shortcutTitle = el('summary', '', 'Räume im Grundriss');
  const shortcutList = el('ul', 'rp-floor-shortcut-list');
  shortcuts.append(shortcutTitle, shortcutList);
  const showFallback = () => {
    const wasFocused = tools.contains(document.activeElement) || viewport.contains(document.activeElement);
    tools.hidden = true;
    viewport.hidden = true;
    hint.textContent = 'Grundrissbild nicht verfügbar.';
    shortcuts.open = markers.length > 0;
    if (wasFocused) (markers.length > 0 ? shortcutTitle : frame).focus();
  };
  for (const item of markers) {
    const li = el('li');
    const link = button(item.label, 'roomplan-action-secondary rp-floor-shortcut',
      `${item.label} · ${item.state.label}`);
    const icon = el('span', 'rp-floor-indicator', item.state.symbol);
    icon.setAttribute('aria-hidden', 'true');
    icon.dataset.availability = item.key;
    link.dataset.floorRoomId = String(item.room.id);
    link.prepend(icon);
    link.addEventListener('click', () => onRoomSelect(item.room));
    li.append(link);
    shortcutList.append(li);
  }

  const tools = el('div', 'rp-floor-controls');
  tools.setAttribute('role', 'group');
  tools.setAttribute('aria-label', 'Grundriss vergrößern');
  const smaller = button('−', 'roomplan-action-secondary rp-floor-zoom', 'Verkleinern');
  const larger = button('+', 'roomplan-action-secondary rp-floor-zoom', 'Vergrößern');
  const level = button('100 %', 'roomplan-action-secondary rp-floor-level', 'Zoom zurücksetzen: 100 Prozent');
  const legend = el('ul', 'rp-floor-legend');
  legend.setAttribute('aria-label', 'Belegungsstatus');
  for (const [key, state] of availabilityStates) {
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
    for (const [key, value] of Object.entries({ left: item.x, top: item.y, width: item.w, height: item.h })) {
      marker.style.setProperty(key, percentage(value));
    }
    const indicator = el('span', 'rp-floor-indicator rp-floor-marker-indicator', item.state.symbol);
    indicator.setAttribute('aria-hidden', 'true');
    const name = el('span', 'rp-floor-marker-name', item.label);
    name.setAttribute('aria-hidden', 'true');
    marker.append(indicator, name);
    marker.addEventListener('click', () => onRoomSelect(item.room));
    overlay.append(marker);
  }
  const hint = el('p', 'rp-floor-hint');
  hint.setAttribute('role', 'status');
  if (markers.length === 0) hint.textContent = 'Keine Räume auf diesem Grundriss markiert.';
  canvas.append(image, overlay);
  viewport.append(canvas);
  frame.append(tools, viewport, hint);
  if (markers.length) frame.append(shortcuts);

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
