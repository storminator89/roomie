const types = Object.freeze({
    office:'Büro', meeting:'Besprechungsraum', 'open-office':'Großraumbüro',
    mediaroom:'Mediaraum', 'shared-desk':'Shared Desk', 'spez-abt-buero':'Abteilungsbüro',
    'spez-abt-buro':'Abteilungsbüro (Altbestand)', 'fk-office':'FK-Büro', 'fk-buro':'FK-Büro (Altbestand)',
});
const amenities = Object.freeze({
    desk:'Schreibtisch', monitor:'Monitor', dock:'Dock', phone:'Telefon', whiteboard:'Whiteboard',
    projector:'Beamer', display:'Display', meeting:'Meeting-Ausstattung', wifi:'WLAN',
    'video-conference':'Videokonferenz', 'docking-station':'Dockingstation', webcam:'Kamera',
});
const validId = id => Number.isSafeInteger(id) && id > 0;
const byteLength = text => new TextEncoder().encode(text).length;
const invalidText = /[\x00-\x1f\x7f]/;

function el(tag, text, className) {
    const node = document.createElement(tag);
    if (text) node.textContent = text;
    if (className) node.className = className;
    return node;
}
function button(text, className) {
    const node = el('button', text, className);
    node.type = 'button';
    return node;
}
function label(text, control) {
    const node = el('label', text);
    node.append(control);
    return node;
}
function input(name, type, options = {}) {
    return Object.assign(el('input'), { name, type }, options);
}

export function createFloorRoomManager({ rooms = [], getSelectedRoom, onSave, onSaved }) {
    let records = new Map();
    let pending = false;
    let submitting = false;
    let alive = true;
    let editing = null;
    let opener = null;
    let hasUnsupported = false;
    let closeAfterSave = false;
    const controller = new AbortController();
    const listen = (node, event, handler) => node.addEventListener(event, handler, { signal: controller.signal });

    const element = el('div', null, 'rp-fr-tools');
    const add = button('+ Raum');
    const edit = button('Raum bearbeiten');
    const dialog = el('dialog', null, 'rp-fr-dialog');
    const heading = el('h2');
    heading.id = 'rp-fr-title';
    dialog.setAttribute('aria-labelledby', heading.id);
    const form = el('form', null, 'rp-fr-form');
    const inputs = el('div', null, 'rp-fr-fields');
    const name = input('name', 'text', { required:true, maxLength:160 });
    const number = input('number', 'text', { maxLength:40 });
    const type = el('select');
    type.name = 'type';
    for (const [key, text] of Object.entries(types)) {
        const option = el('option', text);
        option.value = key;
        type.append(option);
    }
    const capacity = input('capacity', 'number', { required:true, min:'1', max:'500', step:'1' });
    inputs.append(label('Name', name), label('Raumnummer', number), label('Raumtyp', type), label('Plätze', capacity));
    const extras = el('fieldset', null, 'rp-fr-extras');
    extras.setAttribute('aria-label', 'Ausstattung');
    const equipment = el('details', null, 'rp-fr-equipment');
    equipment.append(el('summary', 'Ausstattung'), extras);
    const checkboxes = new Map();
    for (const [key, text] of Object.entries(amenities)) {
        const field = input('amenities', 'checkbox', { value:key });
        const item = el('label');
        item.append(field, document.createTextNode(text));
        checkboxes.set(key, field);
        extras.append(item);
    }
    const error = el('p', null, 'rp-fr-error');
    error.hidden = true;
    error.setAttribute('role', 'alert');
    const caution = el('p', null, 'rp-fr-help');
    caution.hidden = true;
    const actions = el('div', null, 'rp-fr-actions');
    const cancel = button('Abbrechen');
    const submit = button('Raum speichern', 'rp-fe-primary');
    submit.type = 'submit';
    actions.append(cancel, submit);
    form.append(inputs, equipment, caution, error, actions);
    dialog.append(heading, form);
    element.append(add, edit, dialog);

    const selected = () => records.get(getSelectedRoom()) ?? null;
    function refresh() {
        const busy = pending || submitting;
        const room = selected();
        add.disabled = busy;
        edit.disabled = busy || !room;
        edit.setAttribute('aria-label', room ? `Raum bearbeiten: ${room.name}` : 'Raum bearbeiten');
        for (const field of [name, number, type, capacity, ...checkboxes.values()]) field.disabled = busy || hasUnsupported;
        submit.disabled = busy || hasUnsupported;
        submit.textContent = busy ? 'Wird gespeichert …' : 'Raum speichern';
        cancel.disabled = busy;
        if (busy) form.setAttribute('aria-busy', 'true');
        else form.removeAttribute('aria-busy');
    }

    function setRooms(next) {
        records = new Map(next.filter(room => validId(room?.id)).map(room => [room.id, room]));
        refresh();
    }

    function showError(message) {
        error.textContent = message;
        error.hidden = !message;
    }

    function open(room, trigger) {
        if (pending || submitting || dialog.open) return;
        editing = room?.id ?? null;
        opener = trigger;
        equipment.open = false;
        heading.textContent = room ? 'Raum bearbeiten' : 'Neuer Raum';
        showError('');
        name.value = room?.name ?? '';
        number.value = room?.number ?? '';
        type.value = Object.hasOwn(types, room?.type) ? room.type : 'office';
        capacity.value = room ? (room.capacity > 0 ? String(room.capacity) : '') : '1';
        const known = room?.amenities ?? [];
        const unsupportedType = room && !Object.hasOwn(types, room.type);
        const unsupportedAmenity = known.some(key => !Object.hasOwn(amenities, key));
        hasUnsupported = Boolean(unsupportedType || unsupportedAmenity);
        caution.textContent = hasUnsupported ? 'Dieser Raum enthält alte Typ- oder Ausstattungsdaten. Bearbeiten ist hier gesperrt, damit sie erhalten bleiben.' : '';
        caution.hidden = !hasUnsupported;
        for (const [key, field] of checkboxes) field.checked = known.includes(key);
        refresh();
        dialog.showModal();
        (hasUnsupported ? cancel : name).focus();
    }

    listen(add, 'click', () => open(null, add));
    listen(edit, 'click', () => { const room = selected(); if (room) open(room, edit); });
    listen(cancel, 'click', () => dialog.close());
    listen(dialog, 'cancel', event => { if (pending || submitting) event.preventDefault(); });
    listen(dialog, 'close', () => {
        if (!alive) return;
        hasUnsupported = false;
        refresh();
        (opener?.isConnected && !opener.disabled ? opener : add).focus();
        opener = null;
        if (closeAfterSave) { closeAfterSave = false; onSaved?.(); }
    });
    listen(form, 'submit', async event => {
        event.preventDefault();
        if (pending || submitting || hasUnsupported || !dialog.open) return;
        showError('');
        const title = name.value.trim(), idNumber = number.value.trim();
        for (const [value, field, max, message] of [
            [title, name, 160, 'Bitte einen gültigen Raumnamen eingeben.'],
            [idNumber, number, 40, 'Die Raumnummer ist zu lang oder ungültig.'],
        ]) {
            if ((field === name && !value) || byteLength(value) > max || invalidText.test(value)) {
                showError(message);
                field.focus();
                return;
            }
        }
        const seats = capacity.valueAsNumber;
        if (!Number.isInteger(seats) || seats < 1 || seats > 500 || !Object.hasOwn(types, type.value)) {
            showError('Raumtyp und Anzahl der Plätze prüfen.');
            return;
        }
        const room = { name:title, number:idNumber, type:type.value, capacity:seats,
            amenities:[...checkboxes].filter(([, field]) => field.checked).map(([key]) => key) };
        submitting = true;
        refresh();
        let saved = false;
        try { saved = Boolean(await onSave({ id:editing, room })); }
        catch (failure) {
            if (alive) showError(failure instanceof Error && failure.message ? failure.message : 'Der Raum konnte nicht gespeichert werden.');
        } finally {
            if (alive) {
                submitting = false;
                refresh();
                if (saved) { closeAfterSave = true; dialog.close(); }
                else {
                    if (error.hidden) showError('Der Raum konnte nicht gespeichert werden.');
                    submit.focus();
                }
            }
        }
    });
    setRooms(rooms);
    return { element, setRooms, refresh, setPending(value) { pending = Boolean(value); refresh(); }, destroy() {
        alive = false;
        controller.abort();
        if (dialog.open) dialog.close();
        element.remove();
    } };
}
