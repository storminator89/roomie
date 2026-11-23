const imageTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
const imageExtension = /\.(?:jpe?g|png|webp)$/i;
const maxBytes = 5 * 1024 * 1024;
let nextEditorId = 0;

function element(tag, text = '', className = '') {
    const node = document.createElement(tag);
    if (text) node.textContent = text;
    if (className) node.className = className;
    return node;
}

export function roomPhotoFileError(file) {
    if (!file?.size) return 'Wähle ein Foto aus.';
    if (file.size > maxBytes) return 'Das Foto ist größer als 5 MB.';
    if (!imageTypes.has(file.type) && !((!file.type || file.type === 'application/octet-stream') && imageExtension.test(file.name))) {
        return 'Wähle ein Foto im Format JPEG, PNG oder WebP.';
    }
    return '';
}

export function createRoomPhotoEditor({api, onBusy, onChanged}) {
    const controller = new AbortController();
    const listen = (node, event, handler) => node.addEventListener(event, handler, {signal: controller.signal});
    const section = element('fieldset', '', 'rp-photo-editor');
    const body = element('div', '', 'rp-photo-body');
    const preview = element('div', '', 'rp-photo-preview');
    const controls = element('div', '', 'rp-photo-controls');
    const pickerLabel = element('label', 'Datei auswählen');
    const picker = element('input');
    picker.type = 'file';
    picker.accept = '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp';
    const hint = element('p', 'JPEG, PNG oder WebP · bis 5 MB', 'rp-photo-hint');
    hint.id = 'rp-photo-hint-' + (++nextEditorId);
    picker.setAttribute('aria-describedby', hint.id);
    pickerLabel.append(picker);
    const buttons = element('div', '', 'rp-photo-buttons');
    const upload = element('button', 'Foto speichern', 'rp-photo-button');
    const remove = element('button', 'Foto entfernen', 'rp-photo-button rp-photo-remove');
    upload.type = remove.type = 'button';
    buttons.append(upload, remove);
    const status = element('p', '', 'rp-photo-status');
    status.setAttribute('role', 'status');
    const error = element('p', '', 'rp-photo-error');
    error.setAttribute('role', 'alert');
    controls.append(pickerLabel, hint, buttons, status, error);
    body.append(preview, controls);
    section.append(element('legend', 'Raumfoto'), body);

    let room = null;
    let hasPhoto = false;
    let file = null;
    let busy = false;
    let locked = false;
    let version;

    function renderControls() {
        section.hidden = !room;
        picker.disabled = busy || locked;
        upload.disabled = busy || locked || !file;
        remove.hidden = !hasPhoto;
        remove.disabled = busy || locked;
    }

    function renderPreview() {
        preview.replaceChildren();
        if (!room) return;
        const fallback = element('p', hasPhoto ? 'Vorschau wird geladen …' : 'Standardbild aktiv');
        preview.append(fallback);
        if (!hasPhoto) return;
        const image = element('img');
        image.alt = 'Aktuelles Foto: ' + room.name;
        image.hidden = true;
        image.addEventListener('load', () => { image.hidden = false; fallback.hidden = true; }, {once: true});
        image.addEventListener('error', () => { fallback.textContent = 'Vorschau nicht verfügbar'; }, {once: true});
        preview.append(image);
        image.src = api.roomPhotoUrl(room.id, version);
    }

    function clearFile() {
        file = null;
        picker.value = '';
        picker.removeAttribute('aria-invalid');
    }

    function setRoom(next) {
        room = next;
        hasPhoto = room?.hasPhoto === true;
        version = undefined;
        clearFile();
        status.textContent = '';
        error.textContent = '';
        renderPreview();
        renderControls();
    }

    listen(picker, 'change', () => {
        if (!room || busy || locked) return;
        const next = picker.files?.[0];
        error.textContent = next ? roomPhotoFileError(next) : '';
        status.textContent = '';
        if (!next || error.textContent) {
            clearFile();
            if (next) picker.setAttribute('aria-invalid', 'true');
        } else {
            picker.removeAttribute('aria-invalid');
            file = next;
        }
        renderControls();
    });

    async function change(action) {
        if (!room || busy || locked || (action === 'upload' ? !file : !hasPhoto)) return;
        if (action === 'remove' && !window.confirm('Raumfoto entfernen und wieder das Standardbild anzeigen?')) return;
        const current = room;
        const focus = document.activeElement === (action === 'upload' ? upload : remove);
        let changed = false;
        error.textContent = '';
        status.textContent = action === 'upload' ? 'Foto wird gespeichert …' : 'Foto wird entfernt …';
        busy = true;
        renderControls();
        onBusy?.();
        try {
            const savedPhoto = await api.changeRoomPhoto({id: current.id, action, photo: file});
            if (controller.signal.aborted || current !== room) return;
            hasPhoto = savedPhoto;
            version = Date.now();
            clearFile();
            renderPreview();
            changed = true;
            status.textContent = hasPhoto ? 'Raumfoto gespeichert.' : 'Raumfoto entfernt. Das Standardbild wird angezeigt.';
            try { await onChanged?.(current.id, hasPhoto); } catch {
                if (!controller.signal.aborted) status.textContent += ' Die Übersicht konnte nicht aktualisiert werden.';
            }
        } catch (failure) {
            if (controller.signal.aborted || current !== room) return;
            status.textContent = '';
            error.textContent = failure instanceof Error && failure.message ? failure.message : 'Das Raumfoto konnte nicht geändert werden.';
        } finally {
            if (!controller.signal.aborted) {
                busy = false;
                renderControls();
                onBusy?.();
                if (current === room && focus) (changed ? picker : action === 'upload' ? upload : remove).focus();
            }
        }
    }
    listen(upload, 'click', () => void change('upload'));
    listen(remove, 'click', () => void change('remove'));
    setRoom(null);

    return {
        element: section,
        setRoom,
        setLocked(value) { locked = value; renderControls(); },
        isBusy: () => busy,
        destroy() { controller.abort(); setRoom(null); },
    };
}
