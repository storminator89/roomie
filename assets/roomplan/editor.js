const labels = Object.freeze({
    mediaroom: 'Mediaraum',
    office: 'Büro',
    'open-office': 'Großraumbüro',
    meeting: 'Besprechungsraum',
    'shared-desk': 'Shared Desk',
    'spez-abt-buero': 'Spez. Abt. Büro',
    'spez-abt-buro': 'Spez. Abt. Büro (Altbestand)',
    'fk-office': 'FK-Büro',
    'fk-buro': 'FK-Büro (Altbestand)',
});
const features = Object.freeze({
    wifi: 'WLAN',
    'docking-station': 'Dockingstation',
    desk: 'Schreibtisch',
    webcam: 'Kamera',
});
const roomId = value => Number.isSafeInteger(value) && value > 0 ? value : null;
const dimension = value => Number.isInteger(value) && value >= 1 && value <= 20;
const between = (n, a, b) => Math.max(a, Math.min(n, b));
const sameLayout = (a, b) => a.rows === b.rows && a.cols === b.cols && a.layout.every((id, i) => id === b.layout[i]);
const nameOf = room => room.number ? `${room.name} · ${room.number}` : room.name;

export function resizeLayout(layout, oldCols, rows, cols) {
    if (!dimension(rows) || !dimension(cols) || !dimension(oldCols)) throw new RangeError('Ungültiges Raster');
    const next = Array(rows * cols).fill(null);
    layout.forEach((value, index) => {
        const row = Math.floor(index / oldCols);
        const col = index % oldCols;
        if (row < rows && col < cols) next[row * cols + col] = roomId(value);
    });
    return next;
}

export function placeRoom(layout, id, destination) {
    if (roomId(id) === null || !Number.isInteger(destination) || destination < 0 || destination >= layout.length) return layout;
    const source = layout.indexOf(id);
    if (source === destination) return layout;
    const next = layout.slice();
    if (source >= 0) next[source] = layout[destination];
    next[destination] = id;
    return next;
}

function layoutFrom(initial, rooms) {
    const rows = dimension(initial.rows) ? initial.rows : 3;
    const cols = dimension(initial.cols) ? initial.cols : 3;
    const seen = new Set();
    const layout = Array.from({length: rows * cols}, (_, index) => {
        const id = roomId(initial.layout?.[index]);
        if (!rooms.has(id) || seen.has(id)) return null;
        seen.add(id);
        return id;
    });
    return {rows, cols, layout};
}

function node(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text != null) element.textContent = String(text);
    return element;
}

function button(label, className = '') {
    const result = node('button', `rp-ed-btn ${className}`, label);
    result.type = 'button';
    return result;
}

function labeled(label, input) {
    const wrapper = node('label', 'rp-ed-label');
    wrapper.append(node('span', null, label), input);
    return wrapper;
}

function input(type, options = {}) {
    const field = node('input', 'rp-ed-input');
    field.type = type;
    Object.assign(field, options);
    return field;
}

export function mountEditor({root, api, initial, onChange, onClose}) {
    if (!root || !api || !initial) throw new TypeError('Raumplan-Editor kann nicht gestartet werden.');
    const rooms = new Map(initial.rooms.filter(room => roomId(room.id)).map(room => [room.id, {...room}]));
    let saved = layoutFrom(initial, rooms);
    let revision = initial.revision;
    let draft = {...saved, layout: saved.layout.slice()};
    let selected = null;
    let busy = false;
    let alive = true;
    let activeSlot = 0;
    const dirty = () => !sameLayout(saved, draft);
    const destroySignal = new AbortController();
    const listen = (el, event, handler) => el.addEventListener(event, handler, {signal: destroySignal.signal});

    const panel = node('section', 'rp-ed');
    const title = node('h2', 'rp-ed-title', 'Raumplan bearbeiten');
    const save = button('Layout speichern', 'rp-ed-primary');
    const toolbar = node('div', 'rp-ed-toolbar');
    const toolbarActions = node('div', 'rp-ed-toolbar-actions');
    const finish = button('Fertig');
    if (typeof onClose === 'function') toolbarActions.append(finish);
    toolbarActions.append(save);
    toolbar.append(title, toolbarActions);
    const status = node('div', 'rp-ed-status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    const error = node('div', 'rp-ed-error');
    error.setAttribute('role', 'alert');
    error.hidden = true;

    const dimensions = node('form', 'rp-ed-dimensions');
    const rows = input('number', {min: 1, max: 20, required: true, value: draft.rows});
    const cols = input('number', {min: 1, max: 20, required: true, value: draft.cols});
    const resize = button('Raster ändern');
    resize.type = 'submit';
    dimensions.append(labeled('Zeilen', rows), labeled('Spalten', cols), resize);

    const roomTools = node('div', 'rp-ed-tools');
    const picker = node('select', 'rp-ed-input');
    const edit = button('Bearbeiten');
    const unplace = button('Vom Plan nehmen');
    const add = button('+ Raum');
    roomTools.append(labeled('Raum auswählen', picker), edit, unplace, add);

    const scrollingArea = node('div', 'rp-ed-scroll');
    const grid = node('div', 'rp-ed-grid');
    grid.setAttribute('role', 'group');
    grid.setAttribute('aria-label', 'Rasterplätze');
    scrollingArea.appendChild(grid);
    panel.append(toolbar, status, error, dimensions, roomTools, scrollingArea);

    const dialog = node('dialog', 'rp-ed-dialog');
    const dialogForm = node('form', 'rp-ed-form');
    const dialogTitle = node('h3', 'rp-ed-title');
    dialogTitle.id = 'rp-ed-dialog-title';
    dialog.setAttribute('aria-labelledby', dialogTitle.id);
    const dialogError = node('div', 'rp-ed-error');
    dialogError.setAttribute('role', 'alert');
    dialogError.hidden = true;
    const roomName = input('text', {required: true, maxLength: 160});
    const roomNumber = input('text', {maxLength: 40});
    const roomType = node('select', 'rp-ed-input');
    for (const [value, label] of Object.entries(labels)) {
        const option = node('option', null, label);
        option.value = value;
        roomType.append(option);
    }
    const capacity = input('number', {required: true, min: 1, max: 500});
    const switches = Object.entries(features).map(([key, label]) => [key, label, input('checkbox')]);
    const featuresField = node('fieldset', 'rp-ed-features');
    featuresField.append(node('legend', null, 'Ausstattung'), ...switches.map(([, label, field]) => {
        const wrapper = node('label', 'rp-ed-check');
        wrapper.append(field, node('span', null, label));
        return wrapper;
    }));
    const dialogControls = node('div', 'rp-ed-dialog-actions');
    const remove = button('Raum löschen', 'rp-ed-danger');
    const dismiss = button('Abbrechen');
    const commit = button('Raum speichern', 'rp-ed-primary');
    commit.type = 'submit';
    dialogControls.append(remove, dismiss, commit);
    dialogForm.append(dialogTitle, dialogError, labeled('Raumname', roomName), labeled('Nummer', roomNumber),
        labeled('Typ', roomType), labeled('Plätze', capacity), featuresField, dialogControls);
    dialog.append(dialogForm);
    panel.append(dialog);
    root.replaceChildren(panel);

    let editingId = null;
    let returnFocus = null;
    function setError(element, message = '') {
        element.hidden = !message;
        element.textContent = message;
    }

    function updateControls() {
        const selectedRoom = rooms.get(selected);
        const position = draft.layout.indexOf(selected);
        for (const control of [rows, cols, resize, add, picker]) control.disabled = busy;
        save.disabled = busy || !dirty();
        finish.disabled = busy;
        edit.disabled = busy || !selectedRoom;
        unplace.disabled = busy || !selectedRoom || position < 0;
        dismiss.disabled = busy;
        commit.disabled = busy;
        remove.disabled = busy;
        grid.setAttribute('aria-busy', String(busy));
    }

    function selectRoom(id) {
        selected = rooms.has(id) ? id : null;
        redraw();
    }

    function redraw() {
        const activeWasSlot = grid.contains(document.activeElement);
        const placeholder = node('option', null, rooms.size === 0 ? 'Noch keine Räume' : 'Raum wählen …');
        placeholder.value = '';
        const placed = new Set(draft.layout);
        picker.replaceChildren(placeholder, ...Array.from(rooms.values())
            .sort((a, b) => nameOf(a).localeCompare(nameOf(b), 'de'))
            .map(room => {
                const option = node('option', null, placed.has(room.id) ? nameOf(room) : `${nameOf(room)} (nicht platziert)`);
                option.value = String(room.id);
                return option;
            }));
        picker.value = selected == null ? '' : String(selected);
        const room = rooms.get(selected);
        status.textContent = [
            dirty() ? 'Layout nicht gespeichert.' : '',
            room ? `${room.name}: Rasterplatz wählen.` : 'Raum oder belegten Platz wählen.',
        ].filter(Boolean).join(' ');
        grid.style.setProperty('--rp-ed-cols', String(draft.cols));
        activeSlot = between(activeSlot, 0, draft.layout.length - 1);
        grid.replaceChildren(...draft.layout.map((id, index) => {
            const room = rooms.get(id);
            const row = Math.floor(index / draft.cols) + 1;
            const col = index % draft.cols + 1;
            const slot = node('button', 'rp-ed-slot');
            slot.type = 'button';
            slot.tabIndex = index === activeSlot ? 0 : -1;
            slot.dataset.slot = String(index);
            slot.classList.toggle('rp-ed-selected', Boolean(room) && id === selected);
            if (room) slot.setAttribute('aria-pressed', String(id === selected));
            slot.setAttribute('aria-label', `Zeile ${row}, Spalte ${col}: ${room ? nameOf(room) : 'frei'}`);
            slot.append(node('span', 'rp-ed-slot-number', `${row} · ${col}`), node('span', 'rp-ed-slot-label', room ? nameOf(room) : '+'));
            return slot;
        }));
        if (activeWasSlot) grid.children[activeSlot]?.focus();
        updateControls();
    }

    function lock(value) {
        busy = value;
        updateControls();
    }

    function openRoom(id, opener) {
        if (busy) return;
        const room = rooms.get(id);
        editingId = room ? room.id : null;
        returnFocus = opener;
        dialogTitle.textContent = room ? 'Raum bearbeiten' : 'Neuer Raum';
        roomName.value = room?.name ?? '';
        roomNumber.value = room?.number ?? '';
        roomType.value = room ? (Object.hasOwn(labels, room.type) ? room.type : '') : 'office';
        roomType.required = true;
        capacity.value = room && room.capacity > 0 ? room.capacity : 1;
        for (const [key,, field] of switches) field.checked = room?.amenities?.includes(key) ?? false;
        remove.hidden = !room;
        setError(dialogError);
        dialog.showModal();
        roomName.focus();
    }

    function restoreDialogFocus() {
        if (returnFocus?.isConnected && !returnFocus.disabled) returnFocus.focus();
        else picker.focus();
        returnFocus = null;
    }

    async function mutate(action, errorTarget, apply) {
        if (busy) return;
        setError(errorTarget);
        lock(true);
        try {
            const result = await action();
            if (!alive) return;
            apply(result);
            // A failed viewer refresh does not undo a successful mutation.
            try { await onChange?.(); } catch {
                if (alive) setError(error, 'Gespeichert. Die Raumansicht konnte nicht aktualisiert werden.');
            }
        } catch (err) {
            if (alive) setError(errorTarget, err instanceof Error ? err.message : 'Änderung fehlgeschlagen.');
        } finally {
            if (alive) {
                lock(false);
                if (returnFocus && !dialog.open) restoreDialogFocus();
            }
        }
    }

    listen(finish, 'click', () => onClose?.());
    listen(picker, 'change', () => selectRoom(roomId(Number(picker.value))));
    listen(grid, 'click', event => {
        const slot = event.target.closest?.('[data-slot]');
        if (!slot || !grid.contains(slot) || busy) return;
        activeSlot = Number(slot.dataset.slot);
        slot.focus();
        if (selected == null) {
            selectRoom(draft.layout[activeSlot]);
            return;
        }
        draft.layout = placeRoom(draft.layout, selected, activeSlot);
        selectRoom(null);
    });
    listen(grid, 'keydown', event => {
        const slot = event.target.closest?.('[data-slot]');
        if (!slot || !grid.contains(slot)) return;
        const index = Number(slot.dataset.slot);
        const col = index % draft.cols;
        const row = Math.floor(index / draft.cols);
        const moves = {
            ArrowRight: col + 1 < draft.cols ? index + 1 : index,
            ArrowLeft: col > 0 ? index - 1 : index,
            ArrowUp: row > 0 ? index - draft.cols : index,
            ArrowDown: row + 1 < draft.rows ? index + draft.cols : index,
            Home: index - col,
            End: index - col + draft.cols - 1,
        };
        if (!Object.hasOwn(moves, event.key)) return;
        event.preventDefault();
        activeSlot = moves[event.key];
        slot.tabIndex = -1;
        const target = grid.children[activeSlot];
        target.tabIndex = 0;
        target.focus();
    });

    listen(dimensions, 'submit', event => {
        event.preventDefault();
        if (busy || !dimension(rows.valueAsNumber) || !dimension(cols.valueAsNumber)) return;
        const nextRows = rows.valueAsNumber;
        const nextCols = cols.valueAsNumber;
        if (draft.rows === nextRows && draft.cols === nextCols) return;
        const layout = resizeLayout(draft.layout, draft.cols, nextRows, nextCols);
        draft = {rows: nextRows, cols: nextCols, layout};
        redraw();
    });

    listen(unplace, 'click', () => {
        if (busy || selected == null) return;
        draft.layout = draft.layout.map(id => id === selected ? null : id);
        redraw();
    });
    listen(add, 'click', () => openRoom(null, add));
    listen(edit, 'click', () => openRoom(selected, edit));
    listen(dismiss, 'click', () => dialog.close());
    listen(dialog, 'cancel', event => { if (busy) event.preventDefault(); });
    listen(dialog, 'close', () => {
        if (!busy) restoreDialogFocus();
    });

    listen(dialogForm, 'submit', event => {
        event.preventDefault();
        const payload = {
            name: roomName.value.trim(),
            number: roomNumber.value.trim(),
            type: roomType.value,
            capacity: capacity.valueAsNumber,
            amenities: switches.filter(([, , field]) => field.checked).map(([key]) => key),
        };
        if (!payload.name) {
            setError(dialogError, 'Raumname fehlt.');
            roomName.focus();
            return;
        }
        const id = editingId;
        void mutate(() => id == null ? api.createRoom(payload) : api.updateRoom({id, ...payload}), dialogError, result => {
            const key = id ?? roomId(result);
            if (key == null) throw new Error('Raum gespeichert. Bitte Seite neu laden.');
            rooms.set(key, {...rooms.get(key), id: key, ...payload});
            selected = key;
            dialog.close();
            redraw();
        });
    });

    listen(remove, 'click', () => {
        const id = editingId;
        if (id == null || busy || !window.confirm(`Raum „${rooms.get(id)?.name ?? ''}“ dauerhaft löschen?`)) return;
        void mutate(() => api.deleteRoom(id), dialogError, () => {
            rooms.delete(id);
            draft.layout = draft.layout.map(value => value === id ? null : value);
            saved.layout = saved.layout.map(value => value === id ? null : value);
            if (selected === id) selected = null;
            dialog.close();
            redraw();
        });
    });

    listen(save, 'click', () => {
        if (!dirty() || busy) return;
        const next = {...draft, layout: draft.layout.slice()};
        void mutate(() => api.saveLayout({...next, revision}), error, newRevision => {
            revision = newRevision;
            saved = next;
            redraw();
            status.textContent = 'Layout gespeichert.';
        });
    });

    listen(window, 'beforeunload', event => {
        if (dirty() || busy) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    redraw();

    return {
        canClose: () => !busy && (!dirty() || window.confirm('Ungespeicherte Layoutänderungen verwerfen?')),
        destroy: () => {
            alive = false;
            destroySignal.abort();
            if (dialog.open) dialog.close();
            root.replaceChildren();
        },
    };
}
