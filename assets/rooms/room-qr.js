const form = document.getElementById('roomie-qr-booking');

if (form) {
    const submit = form.querySelector('button[type=submit]');
    const feedback = document.getElementById('roomie-qr-feedback');
    const notice = document.getElementById('roomie-qr-status');
    const links = document.getElementById('roomie-qr-after');
    let attempted = false;

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (attempted) return;
        attempted = true;
        const body = new FormData(form);
        submit.disabled = true;
        feedback.textContent = 'Wird gespeichert …';
        try {
            const response = await fetch(form.action, {
                method: 'POST', body, credentials: 'same-origin', redirect: 'error', cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            const result = await response.json();
            if (response.ok && result.success === true) {
                feedback.textContent = result.pending === true ? 'Anfrage gesendet. Noch nicht gebucht.' : 'Buchung bestätigt.';
            } else if (result.success === false && typeof result.error === 'string') {
                feedback.textContent = result.error;
            } else {
                throw new Error('Unconfirmed booking');
            }
        } catch {
            feedback.textContent = 'Nicht bestätigt. Prüfe zuerst „Meine Buchungen“.';
        } finally {
            // Never offer an automatic retry after a response may have been lost.
            notice.hidden = true;
            form.hidden = true;
            links.hidden = false;
            feedback.focus();
        }
    });
    form.hidden = false;
}
