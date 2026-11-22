const MIN = 0.01;
const limit = n => Math.min(1, Math.max(0, n));
const rounded = n => Number(n.toFixed(5));
const fields = [['x','Links %',0], ['y','Oben %',0], ['w','Breite %',100], ['h','Höhe %',100]];

function el(tag, label, cls) {
    const element = document.createElement(tag);
    if (label != null) element.textContent = label;
    if (cls) element.className = cls;
    return element;
}

function btn(label) {
    const element = el('button', label);
    element.type = 'button';
    return element;
}

function option(value, label) {
    const element = el('option', label);
    element.value = String(value);
    return element;
}

function label(text, control) {
    const wrapper = el('label');
    wrapper.append(el('span', text), control);
    return wrapper;
}

function marker(roomId, values) {
    const [x, y, w, h] = values.map(rounded);
    if (![x, y, w, h].every(Number.isFinite) || x < 0 || y < 0 || w < MIN || h < MIN ||
        rounded(x + w) > 1 || rounded(y + h) > 1 || !Number.isSafeInteger(roomId)) return null;
    return {roomId, x, y, w, h};
}

export function rectangleBetween(a, b) {
    const [x, y] = [Math.min(a.x, b.x), Math.min(a.y, b.y)];
    const [w, h] = [Math.abs(a.x - b.x), Math.abs(a.y - b.y)];
    return [x, y, w, h];
}

const same = (a, b) => a.length === b.length && a.every((m, i) =>
    m.roomId === b[i].roomId && ['x','y','w','h'].every(k => m[k] === b[i][k]));

export function mountFloorEditor({root, api, plans, initialId, rooms, onChange, onClose}) {
    let floors = new Map(plans.map(floor => [floor.id, floor]));
    const roomNames = new Map(rooms.map(r => [r.id, r.number ? `${r.name} · ${r.number}` : r.name]));
    let floor = floors.get(initialId) ?? floors.values().next().value ?? null;
    let draft = floor?.markers.map(m => ({...m})) ?? [];
    let selectedRoom = null;
    let start = null;
    let pending = false;
    let alive = true;
    const controller = new AbortController();
    const listen = (node, event, fn) => node.addEventListener(event, fn, {signal: controller.signal});
    const dirty = () => Boolean(floor) && !same(draft, floor.markers);

    const panel = el('section', null, 'rp-floor-editor');
    const title = el('h2', 'Grundrisse bearbeiten');
    const finish = btn('Fertig');
    const save = btn('Zonen speichern');
    save.className = 'rp-fe-primary';
    const toolbar = el('div', null, 'rp-fe-toolbar');
    toolbar.append(title, finish, save);
    const picker = el('select');
    const planTools = el('div', null, 'rp-fe-tools');
    planTools.append(label('Grundriss', picker));
    const status = el('p', '', 'rp-fe-status');
    status.setAttribute('role','status');
    const error = el('p', '', 'rp-fe-error');
    error.setAttribute('role','alert');
    error.hidden = true;

    const uploads = el('details');
    uploads.append(el('summary', '+ Grundriss'));
    uploads.open = floors.size === 0;
    const uploadForm = el('form', null, 'rp-fe-tools');
    const uploadTitle = el('input');
    uploadTitle.type = 'text';
    uploadTitle.maxLength = 120;
    uploadTitle.required = true;
    const imageFile = el('input');
    imageFile.type = 'file';
    imageFile.accept = 'image/png,image/jpeg';
    imageFile.required = true;
    const uploadBtn = btn('Hochladen');
    uploadBtn.type = 'submit';
    uploadForm.append(label('Name', uploadTitle), label('Bild', imageFile), uploadBtn);
    uploads.append(uploadForm, el('p', 'PNG/JPEG bis 5 MiB. Das Bild ist für angemeldete Nutzer sichtbar.', 'rp-fe-help'));

    const workspace = el('div', null, 'rp-fe-workspace');
    const zoneRoom = el('select');
    const deleteZone = btn('Zone entfernen');
    const cancelDraw = btn('Zeichnen abbrechen');
    const zoneTools = el('div', null, 'rp-fe-tools');
    zoneTools.append(label('Raum', zoneRoom), deleteZone, cancelDraw);
    const imageWrap = el('div', null, 'rp-fe-image');
    const image = el('img');
    image.alt = 'Grundriss';
    image.draggable = false;
    const overlay = el('div', null, 'rp-fe-overlay');
    imageWrap.append(image, overlay);
    const coordinateForm = el('form', null, 'rp-fe-tools rp-fe-coords');
    const coords = fields.map(([, display]) => {
        const control = el('input');
        control.type = 'number';
        control.step = 'any';
        control.min = '0';
        control.max = '100';
        control.required = true;
        coordinateForm.append(label(display, control));
        return control;
    });
    const apply = btn('Zone setzen');
    apply.type = 'submit';
    coordinateForm.append(apply);
    workspace.append(zoneTools, imageWrap, coordinateForm);
    panel.append(toolbar, planTools, uploads, error, status, workspace);
    root.replaceChildren(panel);

    function showError(message) {
        error.hidden = !message;
        error.textContent = message || '';
    }

    function currentMarker() {
        return draft.find(zone => zone.roomId === selectedRoom);
    }

    function drawOverlay() {
        overlay.replaceChildren(...draft.map(m => {
            const zone = el('span', roomNames.get(m.roomId) ?? 'Raum', 'rp-fe-zone');
            zone.style.left = `${m.x*100}%`;
            zone.style.top = `${m.y*100}%`;
            zone.style.width = `${m.w*100}%`;
            zone.style.height = `${m.h*100}%`;
            zone.classList.toggle('rp-fe-active', m.roomId === selectedRoom);
            return zone;
        }));
        if (start) {
            const point = el('span', null, 'rp-fe-point');
            point.style.left = `${start.x*100}%`;
            point.style.top = `${start.y*100}%`;
            overlay.append(point);
        }
    }

    function render({refreshCoords = true} = {}) {
        picker.replaceChildren(...(floors.size ? [...floors.values()].map(f => option(f.id, f.title)) : [option('', 'Noch kein Grundriss')]));
        picker.value = floor ? String(floor.id) : '';
        zoneRoom.replaceChildren(option('', 'Raum auswählen …'), ...[...roomNames].sort((a,b)=>a[1].localeCompare(b[1],'de'))
            .map(([id, name]) => option(id, draft.some(m => m.roomId === id) ? `${name} ✓` : name)));
        zoneRoom.value = selectedRoom == null ? '' : String(selectedRoom);
        workspace.hidden = !floor;
        imageWrap.classList.toggle('rp-fe-draw', selectedRoom != null && !pending);
        if (floor) {
            const imageUrl = api.floorplanImageUrl(floor.id);
            if (image.getAttribute('src') !== imageUrl) {
                image.width = floor.width;
                image.height = floor.height;
                image.setAttribute('src', imageUrl);
            }
            image.alt = floor.title;
        }
        const item = currentMarker();
        if (refreshCoords) fields.forEach(([key,,fallback], i) => {
            const value = item ? rounded(item[key]*100) : (key === 'w' || key === 'h' ? 20 : fallback);
            coords[i].value = String(value);
        });
        drawOverlay();
        status.textContent = !floor ? 'Lade zuerst einen Grundriss hoch.' : [
            dirty() ? 'Zonen nicht gespeichert.' : '',
            selectedRoom == null ? 'Raum auswählen.' : start ? 'Zweite Ecke wählen.' : 'Zwei Ecken wählen oder Maße eingeben.',
        ].filter(Boolean).join(' ');
        save.disabled = pending || !dirty();
        finish.disabled = pending;
        picker.disabled = pending || !floors.size;
        zoneRoom.disabled = pending || !floor;
        deleteZone.disabled = pending || !item;
        cancelDraw.hidden = !start;
        cancelDraw.disabled = pending;
        apply.disabled = pending || selectedRoom == null;
        coords.forEach(c => c.disabled = pending || selectedRoom == null);
        uploadBtn.disabled = pending;
        imageFile.disabled = pending;
        uploadTitle.disabled = pending;
    }

    function choosePlan(next) {
        floor = next;
        draft = next.markers.map(m => ({...m}));
        selectedRoom = null;
        start = null;
        showError('');
        render();
    }

    function setZone(values) {
        const next = marker(selectedRoom, values);
        if (!next) {
            showError('Die Zone muss im Bild liegen und mindestens 1 % breit und hoch sein.');
            return;
        }
        showError('');
        const i = draft.findIndex(m => m.roomId === selectedRoom);
        if (i === -1) draft.push(next);
        else draft[i] = next;
        render();
    }

    function canClose() {
        return !pending && (!dirty() || window.confirm('Ungespeicherte Zonen verwerfen?'));
    }

    async function perform(action, applyResult) {
        if (pending) return;
        pending = true;
        showError('');
        render({refreshCoords:false});
        try {
            const result = await action();
            if (!alive) return;
            applyResult(result);
            try { await onChange?.(floor?.id); } catch {
                if (alive) showError('Gespeichert, aber die Ansicht konnte nicht aktualisiert werden.');
            }
        } catch (err) {
            if (alive) showError(err instanceof Error ? err.message : 'Änderung nicht möglich.');
        } finally {
            if (alive) {
                pending = false;
                render({refreshCoords:false});
            }
        }
    }

    listen(finish,'click', () => onClose?.());
    listen(picker,'change', () => {
        const next = floors.get(Number(picker.value));
        if (next && canClose()) choosePlan(next);
        else picker.value = String(floor?.id ?? '');
    });
    listen(zoneRoom,'change', () => {
        selectedRoom = roomNames.has(Number(zoneRoom.value)) ? Number(zoneRoom.value) : null;
        start = null;
        showError('');
        render();
    });
    listen(imageWrap,'click', event => {
        if (pending || selectedRoom == null || !floor || !image.complete || !image.naturalWidth) return;
        const bounds = image.getBoundingClientRect();
        if (bounds.width <= 0 || bounds.height <= 0) return;
        const point = {x:limit((event.clientX-bounds.left)/bounds.width), y:limit((event.clientY-bounds.top)/bounds.height)};
        if (!start) {
            start = point;
            render({refreshCoords:false});
        } else {
            const values = rectangleBetween(start, point);
            start = null;
            render({refreshCoords:false});
            setZone(values);
        }
    });
    listen(cancelDraw,'click', () => { start = null; render({refreshCoords:false}); });
    listen(coordinateForm,'submit', event => {
        event.preventDefault();
        if (pending || selectedRoom == null) return;
        start = null;
        setZone(coords.map(control => control.valueAsNumber / 100));
        drawOverlay();
    });
    listen(deleteZone,'click', () => {
        if (pending) return;
        draft = draft.filter(m => m.roomId !== selectedRoom);
        start = null;
        render();
    });
    listen(save,'click', () => {
        if (pending || !dirty()) return;
        const markers = draft.map(m => ({...m}));
        const id = floor.id;
        const revision = floor.revision;
        void perform(() => api.saveFloorMarkers({id, revision, markers}), newRevision => {
            floor = {...floor, revision:newRevision, markers};
            floors.set(id, floor);
        });
    });
    listen(uploadForm,'submit', event => {
        event.preventDefault();
        const name = uploadTitle.value.trim();
        const file = imageFile.files?.[0];
        if (!name || !file || !canClose()) return;
        if (file.size > 5 * 1024 * 1024) {
            showError('Bitte ein Bild bis 5 MiB auswählen.');
            return;
        }
        void perform(async () => {
            const id = await api.uploadFloorplan({title:name,image:file});
            let refreshed;
            try { refreshed = await api.loadFloorplans(); }
            catch { throw new Error('Grundriss hochgeladen. Aktueller Stand konnte nicht geladen werden. Bitte Editor neu öffnen.'); }
            return {id, refreshed};
        }, ({id, refreshed}) => {
            const next = refreshed.find(plan => plan.id === id);
            if (!next) throw new Error('Grundriss hochgeladen. Bitte Editor neu öffnen.');
            floors = new Map(refreshed.map(f => [f.id,f]));
            uploadForm.reset();
            uploads.open = false;
            choosePlan(next);
        });
    });
    listen(window,'beforeunload', event => {
        if (!dirty() && !pending) return;
        event.preventDefault();
        event.returnValue = '';
    });
    render();
    return {
        canClose,
        destroy() {
            alive = false;
            controller.abort();
            root.replaceChildren();
        },
    };
}
