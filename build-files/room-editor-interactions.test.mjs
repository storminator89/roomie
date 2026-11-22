import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import assert from 'node:assert/strict';

async function load(name) {
    const source = await readFile(new URL(`../assets/roomplan/${name}.js`, import.meta.url));
    return import('data:text/javascript;base64,' + source.toString('base64'));
}
const { createFloorUpload } = await load('floor-upload');
const { mountEditor } = await load('editor');

// Small DOM fixture for form events and rendered draft state; no browser or network.
class Element extends EventTarget {
    children = [];
    attributes = new Map();
    dataset = {};
    style = { setProperty() {} };
    classList = { toggle() {}, remove() {} };
    hidden = false;
    #value = '';
    constructor(tagName) { super(); this.tagName = tagName; }
    get value() { return this.#value; }
    set value(value) { this.#value = String(value); }
    get valueAsNumber() { return Number(this.value); }
    append(...children) { this.children.push(...children); }
    appendChild(child) { this.append(child); }
    replaceChildren(...children) { this.children = children; }
    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); }
    contains(node) { return this === node || this.children.some(child => child.contains(node)); }
    reset() {
        for (const input of findAll(this, child => child.tagName === 'input')) input.value = '';
    }
}
function findAll(node, predicate) {
    return [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => findAll(child, predicate))];
}
const find = (node, predicate) => findAll(node, predicate)[0];
const dispatch = (node, type) => node.dispatchEvent(new Event(type, {cancelable:true}));
globalThis.document = { createElement: tag => new Element(tag) };
globalThis.window = new EventTarget();

test('replacing an image updates its suggested title, but keeps a manually entered title', () => {
    const uploads = [];
    const upload = createFloorUpload({open:true, onSubmit: data => uploads.push(data)});
    const picker = find(upload.element, child => child.type === 'file');
    const title = find(upload.element, child => child.type === 'text');
    const fileName = find(upload.element, child => child.className === 'rp-fu-file');
    const form = find(upload.element, child => child.tagName === 'form');
    const choose = name => {
        // The accepted unknown-MIME filename path needs no thumbnail reader.
        picker.files = [{name, size:1, type:''}];
        dispatch(picker, 'change');
        assert.equal(fileName.textContent, name);
    };
    choose('1.-Stock_Nord.png');
    assert.equal(title.value, '1. Stock Nord');
    choose('2.-Stock_Sued.png');
    assert.equal(title.value, '2. Stock Sued');
    title.value = 'Mein Plan';
    dispatch(title, 'input');
    choose('3.-Stock.jpg');
    assert.equal(title.value, 'Mein Plan');
    dispatch(form, 'submit');
    assert.deepEqual(uploads.map(({title,image}) => [title,image.name]), [['Mein Plan','3.-Stock.jpg']]);
    upload.reset();
    assert.equal(title.value, '');
    choose('Erdgeschoss.png');
    assert.equal(title.value, 'Erdgeschoss');
    upload.destroy();
});

test('shrinking occupied grid slots asks for confirmation and changes only the draft', async () => {
    const root = new Element('main');
    const saves = [];
    let confirm = false;
    const prompts = [];
    window.confirm = text => { prompts.push(text); return confirm; };
    const editor = mountEditor({
        root,
        initial: {rows:2, cols:3, revision:'7', layout:[1,null,null,null,2,null],
            rooms:[{id:1,name:'Bleibt'},{id:2,name:'Randzimmer'}]},
        api: {async saveLayout(payload) { saves.push(payload); return '8'; }},
    });
    try {
        const [rows, cols] = findAll(root, node => node.type === 'number');
        const dimensions = find(root, node => node.className === 'rp-ed-dimensions');
        const save = find(root, node => node.textContent === 'Layout speichern');
        const slots = () => findAll(root, node => node.dataset.slot !== undefined).length;
        assert.equal(slots(), 6);

        rows.value = '1';
        dispatch(dimensions, 'submit');
        assert.equal(prompts.length, 1);
        assert.match(prompts[0], /1 Raum.*Raum bleibt gespeichert/);
        assert.deepEqual([rows.value, cols.value], ['2', '3']);
        assert.equal(slots(), 6);
        assert.equal(save.disabled, true);

        cols.value = '2';
        dispatch(dimensions, 'submit');
        assert.equal(prompts.length, 1, 'empty slots may be removed without confirmation');
        assert.equal(slots(), 4);

        rows.value = '1';
        confirm = true;
        dispatch(dimensions, 'submit');
        assert.equal(prompts.length, 2);
        assert.equal(slots(), 2);
        assert.ok(find(root, node => node.textContent === 'Randzimmer (nicht platziert)'));
        assert.deepEqual(saves, []);
        dispatch(save, 'click');
        assert.deepEqual(saves, [{rows:1, cols:2, layout:[1,null], revision:'7'}]);
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(save.disabled, true);
    } finally { editor.destroy(); }
});
