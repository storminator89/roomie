import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import assert from 'node:assert/strict';

async function sourceUrl(name, imports = {}) {
    let source = await readFile(new URL(`../assets/roomplan/${name}.js`, import.meta.url), 'utf8');
    for (const [from, to] of Object.entries(imports)) source = source.replace(JSON.stringify(from), JSON.stringify(to)).replace(`'${from}'`, JSON.stringify(to));
    return 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
}
const { createRoomplanApi, normalizeRoomplan } = await import(await sourceUrl('api'));
const editorUrl = await sourceUrl('room-photo-editor');
const { roomPhotoFileError } = await import(editorUrl);
const { createFloorRoomManager } = await import(await sourceUrl('floor-rooms', {'./room-photo-editor.js':editorUrl}));

test('room photo picker allows JPEG, PNG and WebP within the file size cap', () => {
    for (const [type, name] of [['image/jpeg', 'Room.jpg'], ['image/png', 'Room.png'], ['image/webp', 'Room.webp'], ['', 'Room.JPG']]) {
        assert.equal(roomPhotoFileError({type, name, size: 5 * 1024 ** 2}), '');
    }
    assert.match(roomPhotoFileError({type:'image/jpeg', name:'Room.jpg', size:5 * 1024 ** 2 + 1}), /größer/);
    assert.match(roomPhotoFileError({type:'image/jpeg', name:'Room.jpg', size:0}), /aus/);
    assert.match(roomPhotoFileError({type:'image/svg+xml', name:'Room.jpg', size:40}), /JPEG, PNG oder WebP/);
});

test('room photos retain typed presence and use CSRF-protected multipart writes', async () => {
    const rooms = normalizeRoomplan({rooms: [{id:'7', has_photo:true}, {id:'8', has_photo:'true'}, {id:9}]}).rooms;
    assert.deepEqual(rooms.map(room => room.hasPhoto), [true, false, false]);
    const requests = [];
    const api = createRoomplanApi({
        csrf: 'qa-token',
        async fetcher(url, options) {
            const {headers, body, method, credentials} = options;
            requests.push({url, headers, body, method, credentials});
            return Response.json({success: true, hasPhoto: body.get('action') === 'upload'});
        },
    });
    const photo = new Blob(['photo fixture'], {type:'image/png'});
    assert.equal(api.roomPhotoUrl(7, 1234), 'room_photo.php?id=7&v=1234');
    assert.equal(await api.changeRoomPhoto({id:7, action:'upload', photo}), true);
    assert.equal(await api.changeRoomPhoto({id:7, action:'remove'}), false);
    for (const {url, headers, body, method, credentials} of requests) {
        assert.equal(url, 'room_photo.php?id=7');
        assert.equal(headers['X-CSRF-Token'], 'qa-token');
        assert.equal(headers['Content-Type'], undefined, 'let fetch set multipart boundary');
        assert.equal(method, 'POST');
        assert.equal(credentials, 'same-origin');
        assert.ok(body instanceof FormData);
    }
    assert.equal(requests[0].body.get('photo').type, 'image/png');
    assert.equal(requests[1].body.has('photo'), false);
});

test('photo writes only report success for an explicit operation-matched server receipt', async () => {
    const receipt = createRoomplanApi({fetcher: async () => Response.json({success:true, hasPhoto:true})});
    await assert.rejects(receipt.changeRoomPhoto({id:7, action:'remove'}), /nicht bestätigt/);
    const stale = createRoomplanApi({fetcher: async () => Response.json({error:'Nicht angemeldet.'}, {status:401})});
    await assert.rejects(stale.changeRoomPhoto({id:7, action:'remove'}), /Sitzung ist abgelaufen/);
});

class DomElement extends EventTarget {
    children = [];
    attributes = new Map();
    value = '';
    files = [];
    isConnected = true;
    constructor(tag) { super(); this.tag = tag; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); }
    focus() { document.activeElement = this; }
    showModal() { this.open = true; }
    close() { this.open = false; this.dispatchEvent(new Event('close')); }
    remove() { this.isConnected = false; }
}
function find(node, selector) {
    if (selector(node)) return node;
    for (const child of node.children) {
        const match = find(child, selector);
        if (match) return match;
    }
}
function dispatch(node, eventName) {
    return node.dispatchEvent(new Event(eventName, {cancelable:true}));
}
const settle = () => new Promise(resolve => setImmediate(resolve));

test('existing room dialog keeps photo changes separate, relocks during writes and restores focus on remove', async () => {
    globalThis.document = {
        createElement: tag => new DomElement(tag),
        createTextNode: textContent => Object.assign(new DomElement('#text'), {textContent}),
    };
    let confirm = false;
    globalThis.window = {confirm: () => confirm};
    const room = {id:7, name:'Raum Nord', type:'meeting', capacity:8, hasPhoto:false};
    const photoCalls = [];
    let finishPhoto;
    let rejectPhoto;
    const manager = createFloorRoomManager({
        rooms:[room],
        getSelectedRoom: () => room.id,
        onSave: () => assert.fail('photo writes must not submit room details'),
        api:{
            roomPhotoUrl: (id, version) => `photo?id=${id}${version ? '&v=' + version : ''}`,
            changeRoomPhoto: payload => {
                photoCalls.push(payload);
                return new Promise((resolve, reject) => { finishPhoto = resolve; rejectPhoto = reject; });
            },
        },
        onPhotoChanged(id, hasPhoto) { assert.equal(id, room.id); manager.setRooms([{...room, hasPhoto}]); },
    });
    try {
        const named = name => find(manager.element, node => node.tag === 'button' && node.textContent === name);
        const picker = find(manager.element, node => node.type === 'file');
        const section = find(manager.element, node => node.className === 'rp-photo-editor');
        const preview = find(section, node => node.className === 'rp-photo-preview');
        const status = find(section, node => node.attributes.get('role') === 'status');
        const alert = find(section, node => node.attributes.get('role') === 'alert');
        const dialog = find(manager.element, node => node.tag === 'dialog');
        const roomName = find(dialog, node => node.tag === 'input' && node.name === 'name');
        const add = named('+ Raum');
        const edit = named('Raum bearbeiten');
        const upload = named('Foto speichern');
        const remove = named('Foto entfernen');
        const cancel = named('Abbrechen');
        const file = {name:'Nord.png', type:'image/png', size:30};
        const select = file => {picker.files = [file]; dispatch(picker, 'change');};

        dispatch(add, 'click');
        assert.equal(section.hidden, true, 'the room must exist before uploading a photo');
        dispatch(cancel, 'click');
        dispatch(edit, 'click');
        assert.equal(preview.children[0].textContent, 'Standardbild aktiv');
        assert.equal(remove.hidden, true);

        select({name:'Nord.gif', type:'image/gif', size:30});
        assert.match(alert.textContent, /JPEG, PNG oder WebP/);
        assert.equal(upload.disabled, true);
        select(file);
        assert.equal(upload.disabled, false);
        dispatch(cancel, 'click');
        assert.equal(document.activeElement, edit);
        dispatch(edit, 'click');
        assert.equal(upload.disabled, true);
        assert.equal(photoCalls.length, 0);

        select(file);
        roomName.value = 'Ungespeicherter Name';
        upload.focus();
        dispatch(upload, 'click');
        dispatch(upload, 'click');
        assert.equal(photoCalls.length, 1);
        assert.deepEqual(photoCalls[0], {id:7, action:'upload', photo:file});
        assert.equal(named('Raum speichern').disabled, true);
        assert.equal(cancel.disabled, true);
        assert.equal(dispatch(dialog, 'cancel'), false, 'Escape should not dismiss an in-flight write');
        finishPhoto(true);
        await settle();
        assert.equal(roomName.value, 'Ungespeicherter Name');
        assert.equal(status.textContent, 'Raumfoto gespeichert.');
        const photo = preview.children[1];
        assert.match(photo.src, /photo\?id=7&v=[0-9]+/);
        assert.equal(photo.alt, 'Aktuelles Foto: Raum Nord');
        dispatch(photo, 'load');
        assert.equal(photo.hidden, false);
        assert.equal(document.activeElement, picker);

        dispatch(cancel, 'click');
        dispatch(edit, 'click');
        assert.equal(roomName.value, room.name);
        assert.match(preview.children[1].src, /photo\?id=7/);
        assert.equal(remove.hidden, false, 'reopening reads the acknowledged photo state');

        select(file);
        dispatch(upload, 'click');
        rejectPhoto(new Error('Datei beschädigt.'));
        await settle();
        assert.equal(alert.textContent, 'Datei beschädigt.');
        assert.equal(upload.disabled, false, 'a failed attempt can be retried');
        assert.equal(remove.hidden, false, 'a failed replacement does not hide the current photo');
        dispatch(remove, 'click');
        assert.equal(photoCalls.length, 2, 'dismissed confirmation does not write');
        confirm = true;
        remove.focus();
        dispatch(remove, 'click');
        assert.equal(photoCalls[2].action, 'remove');
        finishPhoto(false);
        await settle();
        assert.equal(remove.hidden, true);
        assert.equal(preview.children[0].textContent, 'Standardbild aktiv');
        assert.equal(document.activeElement, picker, 'focus moves to the picker when the remove button disappears');
    } finally { manager.destroy(); }
});
