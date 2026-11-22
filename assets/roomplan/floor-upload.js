const maxSize = 5 * 1024 * 1024;
const extension = /\.(?:png|jpe?g)$/i;
const knownTypes = new Set(['image/png', 'image/jpeg']);

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
}

export function floorUploadFileError(file) {
    if (!file?.size) return 'Wähle ein Bild aus.';
    if (file.size > maxSize) return 'Das Bild ist größer als 5 MB.';
    if (!knownTypes.has(file.type) && !((!file.type || file.type === 'application/octet-stream') && extension.test(file.name))) {
        return 'Wähle ein PNG- oder JPG-Bild.';
    }
    return '';
}

export function suggestedFloorTitle(filename) {
    return filename.replace(extension, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120).replace(/[\uD800-\uDBFF]$/, '').trimEnd();
}

function supportedImageHeader(buffer, type) {
    const start = new Uint8Array(buffer);
    if (type === 'image/png') {
        const expected = [137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 13, 73, 72, 68, 82];
        if (start.length !== 24 || !expected.every((byte, i) => start[i] === byte)) return false;
        const view = new DataView(buffer);
        const width = view.getUint32(16);
        const height = view.getUint32(20);
        return width > 0 && height > 0 && width <= 2400 && height <= 2400 && width * height <= 3_000_000;
    }
    return type === 'image/jpeg' && start[0] === 0xff && start[1] === 0xd8 && start[2] === 0xff;
}

export function createFloorUpload({open = false, onSubmit}) {
    let file = null;
    let pending = false;
    let generation = 0;
    let reader = null;
    let dragDepth = 0;
    let hasSuggestedTitle = false;

    const element = el('details', 'rp-fu');
    element.open = open;
    const summary = el('summary', '', '+ Grundriss');
    const form = el('form', 'rp-fu-form');
    const pickerLabel = el('label', 'rp-fu-drop');
    const picker = el('input', 'rp-fu-picker');
    picker.type = 'file';
    picker.accept = '.png,.jpg,.jpeg,image/png,image/jpeg';
    const pickerText = el('span', 'rp-fu-choose', 'Bild auswählen');
    const format = el('span', 'rp-fu-format', 'PNG oder JPG · bis 5 MB');
    const dragText = el('span', 'rp-fu-drag-text', 'oder hierher ziehen');
    dragText.setAttribute('aria-hidden', 'true');
    pickerLabel.append(picker, pickerText, dragText, format);
    const chosen = el('div', 'rp-fu-chosen');
    chosen.hidden = true;
    chosen.setAttribute('role', 'status');
    const preview = el('img', 'rp-fu-preview');
    preview.alt = '';
    preview.hidden = true;
    const filename = el('span', 'rp-fu-file');
    chosen.append(preview, filename);
    const titleLabel = el('label', 'rp-fu-name', 'Name');
    const title = el('input');
    title.type = 'text';
    title.placeholder = 'z. B. 1. Stock';
    title.maxLength = 120;
    title.required = true;
    titleLabel.append(title);
    const warning = el('p', 'rp-fu-warning', 'Das Bild sehen alle angemeldeten Nutzer.');
    const error = el('p', 'rp-fu-error');
    error.setAttribute('role', 'alert');
    error.hidden = true;
    const submit = el('button', 'rp-fe-primary', 'Hochladen');
    submit.type = 'submit';
    form.append(pickerLabel, chosen, titleLabel, warning, error, submit);
    element.append(summary, form);

    function render() {
        picker.disabled = pending;
        title.disabled = pending;
        submit.disabled = pending || !file || !title.value.trim();
        submit.textContent = pending ? 'Bitte warten …' : 'Hochladen';
        if (pending) form.setAttribute('aria-busy', 'true');
        else form.removeAttribute('aria-busy');
    }

    function setError(message) {
        error.textContent = message;
        error.hidden = !message;
    }

    function clearImage() {
        generation++;
        reader?.abort();
        reader = null;
        preview.hidden = true;
        preview.removeAttribute('src');
    }

    function clearSelection() {
        clearImage();
        file = null;
        filename.textContent = '';
        chosen.hidden = true;
        pickerText.textContent = 'Bild auswählen';
    }

    function select(files) {
        if (pending || !files?.length) return;
        clearSelection();
        setError('');
        if (files.length !== 1) {
            setError('Wähle genau ein Bild.');
            render();
            return;
        }
        const next = files[0];
        const message = floorUploadFileError(next);
        if (message) {
            setError(message);
            render();
            return;
        }
        file = next;
        if (!title.value.trim() || hasSuggestedTitle) {
            title.value = suggestedFloorTitle(file.name);
            hasSuggestedTitle = Boolean(title.value);
        }
        filename.textContent = file.name;
        chosen.hidden = false;
        pickerText.textContent = 'Anderes Bild auswählen';
        render();
        // The file stays local until the submit action. Match the header before showing a thumbnail.
        if (!knownTypes.has(next.type)) return;
        const selectedGeneration = generation;
        const header = new FileReader();
        reader = header;
        header.addEventListener('load', () => {
            if (selectedGeneration !== generation || !supportedImageHeader(header.result, next.type)) return;
            const image = new FileReader();
            reader = image;
            image.addEventListener('load', () => {
                if (selectedGeneration !== generation) return;
                preview.src = image.result;
            });
            image.readAsDataURL(next);
        });
        header.readAsArrayBuffer(next.slice(0, 24));
    }

    function isFileDrag(event) {
        return Array.from(event.dataTransfer?.types ?? []).includes('Files');
    }

    picker.addEventListener('change', () => {
        select(picker.files);
        picker.value = '';
    });
    preview.addEventListener('load', () => { preview.hidden = !preview.hasAttribute('src'); });
    preview.addEventListener('error', () => { preview.hidden = true; });
    title.addEventListener('input', () => {
        hasSuggestedTitle = false;
        render();
    });
    pickerLabel.addEventListener('dragenter', event => {
        if (!isFileDrag(event)) return;
        event.preventDefault();
        if (pending) return;
        dragDepth++;
        pickerLabel.classList.add('rp-fu-drag');
    });
    pickerLabel.addEventListener('dragover', event => {
        if (!isFileDrag(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = pending ? 'none' : 'copy';
    });
    pickerLabel.addEventListener('dragleave', () => {
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0) pickerLabel.classList.remove('rp-fu-drag');
    });
    pickerLabel.addEventListener('drop', event => {
        if (!isFileDrag(event)) return;
        event.preventDefault();
        dragDepth = 0;
        pickerLabel.classList.remove('rp-fu-drag');
        select(event.dataTransfer.files);
    });
    form.addEventListener('submit', event => {
        event.preventDefault();
        const name = title.value.trim();
        if (!pending && file && name) onSubmit({title: name, image: file});
    });
    render();

    return {
        element,
        setPending(value) {
            pending = Boolean(value);
            if (pending) {
                dragDepth = 0;
                pickerLabel.classList.remove('rp-fu-drag');
            }
            render();
        },
        reset() {
            clearSelection();
            form.reset();
            hasSuggestedTitle = false;
            setError('');
            element.open = false;
            render();
        },
        destroy: clearImage,
    };
}
