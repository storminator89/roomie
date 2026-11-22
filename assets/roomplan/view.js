import { createRoomplanApi } from './api.js';
import { renderFloorPosition } from './floor-view.js';

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
const amenities = new Map([
  ['desk', 'Schreibtisch'], ['monitor', 'Monitor'], ['dock', 'Dockingstation'],
  ['phone', 'Telefon'], ['whiteboard', 'Whiteboard'], ['projector', 'Beamer'],
  ['display', 'Display'], ['meeting', 'Videokonferenz'], ['wifi', 'WLAN'],
  ['video-conference', 'Videokonferenz'], ['docking-station', 'Dockingstation'], ['webcam', 'Webcam'],
]);

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
  if (!Number.isInteger(capacity) || capacity < 1) return [];
  return periods.filter(([, , start, end]) => {
    const points = new Map();
    for (const booking of bookings) {
      const a = clock(booking.start_time);
      const b = clock(booking.end_time);
      if (!a || !b || a >= b || a >= end || b <= start) continue;
      const from = a < start ? start : a;
      const to = b > end ? end : b;
      points.set(from, (points.get(from) ?? 0) + 1);
      points.set(to, (points.get(to) ?? 0) - 1);
    }
    let used = 0;
    for (const [, change] of [...points].sort(([a], [b]) => a.localeCompare(b))) {
      used += change;
      if (used >= capacity) return false;
    }
    return true;
  });
}

function dateValue(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value) || value.startsWith('0000-')) return false;
  const dt = new Date(`${value}T00:00:00Z`);
  return Number.isFinite(dt.getTime()) && dt.toISOString().slice(0, 10) === value;
}

export function readViewLocation(href, fallbackDate) {
  const url = new URL(href);
  const supplied = url.searchParams.get('date');
  return {
    date: dateValue(supplied) ? supplied : fallbackDate,
    list: url.searchParams.get('view') === 'list',
    floorChoice: url.searchParams.get('view') === 'grid' ? 'grid'
      : (/^[1-9]\d{0,15}$/.test(url.searchParams.get('floor') ?? '') ? Number(url.searchParams.get('floor')) : null),
    favorites: url.searchParams.get('favorites') === '1',
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

function detailsUrl(room, date) {
  return 'room_details.php?' + new URLSearchParams({ id: String(room.id), date });
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
  let listMode = initialView.list;
  let floorChoice = initialView.floorChoice;
  let floors = [];
  let floorLoadRun = 0;
  let selectedFloorRoom = null;
  let activeFloorView = null;
  let activeFloorViewId = null;
  let floorRoomOpener = null;
  const floorFilters = { query: '', onlyBookable: false };
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
  const dateLabelNode = el('label', 'rp-date-label');
  dateLabelNode.textContent = 'Datum';
  const dateInput = el('input', 'rp-date');
  dateInput.type = 'date';
  dateInput.id = 'rp-selected-date';
  dateInput.value = initialView.date;
  dateLabelNode.htmlFor = dateInput.id;
  const dateControl = el('div', 'rp-date-control');
  dateControl.append(dateLabelNode, dateInput);
  const resetDate = button('Heute', 'roomplan-action-secondary rp-button rp-button-secondary');
  resetDate.disabled = initialView.date === today;
  const filter = button('Nur Favoriten', 'roomplan-action-secondary rp-button rp-button-secondary');
  filter.setAttribute('aria-pressed', String(onlyFavorites));
  filter.disabled = true;
  const mode = button('Listenansicht', 'roomplan-action-secondary rp-button rp-button-secondary');
  mode.setAttribute('aria-pressed', String(listMode));
  mode.hidden = true;
  const floorPick = el('label', 'rp-floor-pick', 'Ansicht');
  const floorSelect = el('select');
  floorPick.append(floorSelect);
  floorPick.hidden = true;
  toolbar.append(dateControl, resetDate, filter, mode, floorPick);

  const notice = el('div', 'rp-status');
  notice.setAttribute('role', 'status');
  notice.setAttribute('aria-live', 'polite');
  notice.setAttribute('aria-atomic', 'true');
  const content = el('div', 'rp-view-content');
  content.id = 'rp-view-content';
  content.tabIndex = -1;
  const retry = button('Erneut laden', 'roomplan-action-secondary rp-button rp-button-secondary rp-retry');
  retry.hidden = true;
  toolbar.setAttribute('role', 'group');
  toolbar.setAttribute('aria-label', 'Raumplan filtern');
  view.replaceChildren(toolbar, notice, retry, content);

  function rememberView() {
    if (!dateValue(dateInput.value)) return;
    const state = { date: dateInput.value, list: listMode, favorites: onlyFavorites, floorChoice };
    const target = writeViewLocation(window.location.href, state, today);
    if (target.href !== window.location.href) window.history.pushState(window.history.state, '', target);
  }

  function activeFloor() {
    if (listMode || floorChoice === 'grid') return null;
    return floors.find(floor => floor.id === floorChoice) ?? floors[0] ?? null;
  }

  function syncFloorControl() {
    floorPick.hidden = !floors.length || onlyFavorites;
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

  async function reloadFloors(intentId = null) {
    const run = ++floorLoadRun;
    try {
      const next = await api.loadFloorplans();
      if (run !== floorLoadRun) return;
      floors = next;
      if (intentId != null && floors.some(floor => floor.id === intentId)) {
        floorChoice = intentId;
        listMode = false;
        selectedFloorRoom = null;
        rememberView();
      }
    } catch (error) {
      if (run !== floorLoadRun) return;
      if (floorEdit) floorEdit.title = error.message;
    }
    syncFloorControl();
    if (plan && dateValue(dateInput.value)) render();
  }

  floorSelect.addEventListener('change', () => {
    const value = floorSelect.value;
    listMode = value === 'list';
    floorChoice = value === 'grid' || value === 'list' ? (value === 'grid' ? value : null) : Number(value);
    selectedFloorRoom = null;
    mode.setAttribute('aria-pressed', String(listMode));
    rememberView();
    render();
  });

  function status(text, isError = false) {
    notice.textContent = text;
    notice.classList.toggle('rp-status-error', isError);
  }

  function drawFavoriteControl(control, favorite) {
    control.textContent = favorite ? '★' : '☆';
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
        filter.focus();
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
  const roomForm = el('form', 'rp-book-form');
  const periodLegend = el('legend', '', 'Zeitraum');
  const choices = el('fieldset', 'rp-periods');
  const feedback = el('div', 'rp-dialog-feedback');
  feedback.setAttribute('role', 'status');
  feedback.setAttribute('aria-live', 'polite');
  const actions = el('div', 'rp-dialog-actions');
  const close = button('Abbrechen', 'roomplan-action-secondary rp-button rp-button-secondary');
  const submit = button('Buchen', 'roomplan-action rp-button rp-button-primary');
  submit.type = 'submit';
  actions.append(close, submit);
  roomForm.append(choices, feedback, actions);
  dialog.append(dialogTitle, dialogDate, roomForm);
  view.append(dialog);
  let selectedBooking = null;
  let bookPending = false;

  function openBooking(room, available, opener) {
    trigger = opener;
    selectedBooking = { room, date: dateInput.value };
    dialogTitle.textContent = room.name + ' buchen';
    dialogDate.textContent = dateLabel(selectedBooking.date);
    feedback.textContent = '';
    choices.replaceChildren(periodLegend);
    for (const [id, name, from, to] of periods) {
      const option = el('label', 'rp-period');
      const radio = el('input');
      radio.type = 'radio';
      radio.name = 'period';
      radio.value = id;
      radio.required = true;
      radio.disabled = !available.some(item => item[0] === id);
      option.append(radio, el('span', '', `${name} · ${from}–${to}`));
      choices.append(option);
    }
    const first = choices.querySelector('input:enabled');
    if (!first) return;
    first.checked = true;
    submit.disabled = false;
    close.disabled = false;
    dialog.showModal();
    first.focus();
  }
  close.addEventListener('click', () => {
    if (!bookPending) dialog.close();
  });
  dialog.addEventListener('cancel', event => {
    if (bookPending) event.preventDefault();
  });
  dialog.addEventListener('close', () => {
    if (trigger?.isConnected) trigger.focus();
    trigger = null;
    selectedBooking = null;
  });
  roomForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (!selectedBooking || bookPending) return;
    const period = new FormData(roomForm).get('period');
    if (!periods.some(item => item[0] === period)) return;
    const attempted = selectedBooking;
    bookPending = true;
    submit.disabled = true;
    close.disabled = true;
    choices.disabled = true;
    feedback.textContent = 'Buchung wird gespeichert …';
    try {
      const result = await api.book({ roomId: attempted.room.id, date: attempted.date, period });
      dialog.close();
      status(result.message || 'Buchung gespeichert.');
      await loadBookings({ announce: false, focusRoom: attempted.room.id });
    } catch (error) {
      feedback.textContent = error.message || 'Buchung war nicht möglich.';
    } finally {
      bookPending = false;
      submit.disabled = false;
      close.disabled = false;
      choices.disabled = false;
    }
  });

  function card(room) {
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
    badges.append(el('span', 'rp-badge', room.capacity === 1 ? '1 Platz' : `${room.capacity} Plätze`));
    if (room.amenities.length) {
      const labels = [...new Set(room.amenities.map(key => amenities.get(key)).filter(Boolean))];
      for (const label of labels.slice(0, 3)) badges.append(el('span', 'rp-badge', label));
    }
    box.append(badges);

    const roomBookings = bookingsByRoom?.get(room.id) ?? [];
    const available = bookingStatus === 'ready' ? availablePeriods(room.capacity, roomBookings) : [];
    const occupancy = el('div', 'rp-bookings');
    if (bookingStatus === 'ready') {
      if (roomBookings.length) {
        const summary = available.length ? 'Teilweise belegt' : 'Belegt';
        const more = el('details', 'rp-booking-details');
        more.append(el('summary', 'rp-availability', summary));
        const list = el('ul', 'rp-booking-list');
        roomBookings.forEach(item => {
          const from = clock(item.start_time);
          const to = clock(item.end_time);
          if (!from || !to) return;
          const name = typeof item.user_name === 'string' ? item.user_name.trim() : '';
          list.append(el('li', '', `${from}–${to} · ${name || 'Belegt'}`));
        });
        more.append(list);
        occupancy.append(more);
      } else {
        occupancy.append(el('p', 'rp-availability', available.length ? 'Verfügbar' : 'Nicht buchbar'));
      }
    } else if (bookingStatus === 'error') {
      occupancy.append(el('p', 'rp-availability', 'Belegung unbekannt'));
    } else {
      occupancy.append(el('p', 'rp-availability', 'Belegung wird geladen …'));
    }
    box.append(occupancy);

    const footer = el('div', 'rp-card-actions');
    const details = el('a', 'roomplan-action-secondary rp-button rp-button-secondary', 'Details');
    details.href = detailsUrl(room, dateInput.value);
    details.setAttribute('aria-label', `Details zu ${room.name}`);
    const book = button('Buchen', 'roomplan-action rp-button rp-button-primary');
    book.dataset.bookRoomId = String(room.id);
    book.setAttribute('aria-label', `${room.name} buchen`);
    book.disabled = !available.length || dateInput.value < today || dateInput.value > bookThrough;
    book.addEventListener('click', () => openBooking(room, available, book));
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
    filter.disabled = !plan.rooms.length;
    syncFloorControl();
    mode.hidden = onlyFavorites || Boolean(floors.length) || !slots.some(Boolean);
    const floor = !onlyFavorites && activeFloor();
    if (floor) {
      const panel = section(floor.title);
      const focus = el('div', 'rp-floor-focus');
      focus.id = 'rp-selected-floor-room';
      focus.hidden = true;
      const previousKey = activeFloorView?.contains(active) ? active.dataset.floorFocusKey : null;
      const previousView = activeFloorViewId === floor.id ? activeFloorView?.snapshot() : null;
      const findControl = key => [...visual.querySelectorAll('[data-floor-focus-key]')]
        .find(control => control.dataset.floorFocusKey === key && !control.closest('[hidden]') && !control.disabled);
      function closeDetails() {
        const opener = findControl(floorRoomOpener);
        selectedFloorRoom = null;
        updateFocus();
        (opener || findControl('search') || visual).focus();
      }
      function updateFocus(moveFocus = false) {
        const room = plan.rooms.find(candidate => candidate.id === selectedFloorRoom);
        if (!room || !visual.isFloorRoomShown(room.id)) {
          focus.replaceChildren();
          focus.hidden = true;
          visual.selectFloorRoom(null);
          return;
        }
        const selectedCard = card(room);
        selectedCard.tabIndex = -1;
        const close = button('×', 'rp-icon-button rp-floor-close-detail');
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
      const visual = renderFloorPosition({
        floor, rooms: plan.rooms, filters: floorFilters, roomDetails: focus,
        availabilityStatus: bookingStatus,
        canBook: (_room, state) => (state === 'free' || state === 'partial')
          && dateInput.value >= today && dateInput.value <= bookThrough,
        availability(room) {
          if (bookingStatus !== 'ready') return 'unknown';
          const booked = bookingsByRoom?.get(room.id) ?? [];
          const times = availablePeriods(room.capacity, booked);
          return !times.length ? 'busy' : booked.length ? 'partial' : 'free';
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
      if (previousKey && !previousId) (findControl(previousKey) || findControl('search') || visual).focus();
      if (focusRoom != null && !visual.isFloorRoomShown(focusRoom)) (findControl('search') || visual).focus();
      return;
    }
    activeFloorView = null;
    activeFloorViewId = null;
    const visible = onlyFavorites ? plan.rooms.filter(room => plan.favorites.includes(room.id)) : plan.rooms;
    if (!visible.length) {
      const text = onlyFavorites ? 'Noch keine Favoriten.' : 'Keine Räume verfügbar.';
      content.replaceChildren(el('p', 'rp-empty', text));
      if (previousCard) (onlyFavorites ? filter : content).focus();
      return;
    }
    if (onlyFavorites || listMode || !slots.some(Boolean)) {
      const list = el('div', 'rp-list');
      list.append(...visible.map(card));
      content.replaceChildren(list);
    } else {
      const floor = section('Raster');
      const scroll = el('div', 'rp-grid-scroll');
      scroll.setAttribute('aria-label', 'Raumplan');
      scroll.tabIndex = 0;
      const grid = el('div', 'rp-grid');
      grid.style.setProperty('--rp-cols', String(plan.cols));
      for (const room of slots) {
        const slot = el('div', room ? 'rp-slot' : 'rp-slot rp-slot-empty');
        if (room) slot.append(card(room));
        else slot.setAttribute('aria-hidden', 'true');
        grid.append(slot);
      }
      scroll.append(grid);
      floor.append(scroll);
      content.replaceChildren(floor);
      if (unplaced.length) {
        const more = section('Weitere Räume');
        const list = el('div', 'rp-list');
        list.append(...unplaced.map(card));
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

  async function loadBookings({ announce = true, focusRoom = null } = {}) {
    const date = dateInput.value;
    if (!plan || !dateValue(date)) return;
    const invocation = ++bookingRun;
    bookingController?.abort();
    bookingController = new AbortController();
    bookingStatus = 'loading';
    bookingsByRoom = null;
    retry.hidden = true;
    content.setAttribute('aria-busy', 'true');
    if (announce) {
      status('Belegung wird geladen …');
      render();
    }
    try {
      const bookings = await api.loadBookings(date, { signal: bookingController.signal });
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
      status(error.message || 'Belegung konnte nicht geladen werden.', true);
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
    try {
      const nextPlan = await api.loadPlan({ signal: planController.signal });
      if (invocation !== planRun) return;
      plan = nextPlan;
      if (edit && isAdmin) edit.disabled = false;
      await loadBookings();
    } catch (error) {
      if (invocation !== planRun || error.name === 'AbortError') return;
      loadPlanError = true;
      retry.hidden = false;
      content.replaceChildren();
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
      content.setAttribute('aria-busy', 'false');
      status('Bitte ein Datum auswählen.', true);
      resetDate.disabled = false;
      return;
    }
    resetDate.disabled = dateInput.value === today;
    rememberView();
    if (plan && !loadPlanError) void loadBookings();
  });
  resetDate.addEventListener('click', () => {
    dateInput.value = today;
    resetDate.disabled = true;
    rememberView();
    dateInput.focus();
    if (plan && !loadPlanError) void loadBookings();
  });
  filter.addEventListener('click', () => {
    if (loadPlanError || !dateValue(dateInput.value)) return;
    onlyFavorites = !onlyFavorites;
    filter.setAttribute('aria-pressed', String(onlyFavorites));
    rememberView();
    render();
  });
  mode.addEventListener('click', () => {
    if (loadPlanError || !dateValue(dateInput.value)) return;
    listMode = !listMode;
    mode.setAttribute('aria-pressed', String(listMode));
    rememberView();
    render();
  });
  retry.addEventListener('click', () => {
    if (!dateValue(dateInput.value)) {
      dateInput.value = today;
      resetDate.disabled = true;
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
    listMode = state.list;
    floorChoice = state.floorChoice;
    selectedFloorRoom = null;
    filter.setAttribute('aria-pressed', String(onlyFavorites));
    mode.setAttribute('aria-pressed', String(listMode));
    resetDate.disabled = state.date === today;
    if (!plan || loadPlanError) return;
    if (dateChanged) void loadBookings();
    else render();
  });

  if (isAdmin && edit && editor) {
    const closeEditor = () => {
      if (editorMounted?.canClose && !editorMounted.canClose()) return;
      editorMounted?.destroy?.();
      editorMounted = null;
      editor.hidden = true;
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
      if (!plan || floorEditor || editorOpening) return;
      if (editorOpened) {
        closeEditor();
        return;
      }
      editorOpening = true;
      edit.disabled = true;
      try {
        const { mountEditor } = await import('./editor.js');
        editor.hidden = false;
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
      if (!plan || editorOpened || editorOpening) return;
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
          onChange: id => reloadFloors(Number.isSafeInteger(id) ? id : null), onClose: closeFloor,
        });
        const heading = floorEditorRoot.querySelector('h2');
        if (heading) { heading.tabIndex = -1; heading.focus(); }
      } catch (error) {
        closeFloor();
        status(error.message || 'Bearbeitung konnte nicht geöffnet werden.', true);
      } finally { editorOpening = false; floorEdit.disabled = false; }
    });
  }
  void loadPlan();
  void reloadFloors();
}

if (typeof document !== 'undefined') {
  const main = document.getElementById('roomplan');
  if (main) mountRoomplan(main);
}
