(() => {
  const form = document.getElementById('rb-room-book');
  if (!form) return;
  const feedback = document.getElementById('rb-room-feedback');
  const bookings = document.getElementById('rb-room-bookings');
  const submit = form.querySelector('button[type=submit]');
  const periods = form.querySelector('fieldset');
  let pending = false;
  let booked = false;
  function tell(text, error = false) {
    feedback.textContent = text;
    feedback.classList.toggle('rb-notice-error', error);
    feedback.hidden = false;
  }
  form.hidden = false;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (pending || booked) return;
    pending = true;
    const body = new FormData(form);
    submit.disabled = true;
    periods.disabled = true;
    tell('Buchung wird gespeichert …');
    try {
      const response = await fetch(form.action, {
        method: 'POST', body, credentials: 'same-origin', redirect: 'error', cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      const result = await response.json();
      if (!response.ok || result.success !== true) {
        const message = typeof result.error === 'string' ? result.error : result.message;
        throw new Error(typeof message === 'string' ? message : 'Buchung fehlgeschlagen.');
      }
      booked = true;
      tell(typeof result.message === 'string' ? result.message : 'Buchung gespeichert.');
      bookings.hidden = false;
      form.hidden = true;
      feedback.focus();
    } catch (error) {
      tell(error instanceof SyntaxError ? 'Buchung konnte nicht bestätigt werden.' : error.message || 'Buchung konnte nicht bestätigt werden.', true);
      feedback.focus();
    } finally {
      pending = false;
      if (!booked) {
        submit.disabled = false;
        periods.disabled = false;
      }
    }
  });
})();
