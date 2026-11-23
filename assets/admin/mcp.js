'use strict';

const keyField = document.getElementById('mcp-issued-key');
const copyKey = document.getElementById('mcp-copy-key');
const copyStatus = document.getElementById('mcp-copy-status');
if (keyField && copyKey && copyStatus) {
    copyKey.hidden = !navigator.clipboard?.writeText;
    copyKey.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(keyField.value);
            copyStatus.textContent = 'Kopiert.';
        } catch {
            keyField.focus();
            keyField.select();
            copyStatus.textContent = 'Bitte manuell kopieren.';
        }
    });
    window.addEventListener('pagehide', () => {
        keyField.value = '';
        keyField.removeAttribute('value');
        keyField.closest('#mcp-issued-panel')?.remove();
    }, {once: true});
}
