const copy = document.getElementById('rw-copy-link');
const link = document.getElementById('rw-guest-link');
const status = document.getElementById('rw-share-status');
if (copy && link && status && navigator.clipboard?.writeText) {
  copy.hidden = false;
  copy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(link.value);
      status.textContent = 'Gästelink kopiert.';
    } catch {
      link.focus();
      link.select();
    }
  });
}
