import { bookingWindowInFuture, periodCanBeBooked, validBookingWindow } from '../roomplan/booking-window.js';

(() => {
  const form = document.getElementById('rb-room-book');
  if (!form) return;
  const feedback = document.getElementById('rb-room-feedback');
  const bookings = document.getElementById('rb-room-bookings');
  const submit = form.querySelector('button[type=submit]');
  const periods = form.querySelector('fieldset');
  const customControls = form.querySelector('[data-custom-time]');
  const blocks = JSON.parse(customControls?.dataset.roomBlocks ?? '[]').map(block => ({ ...block, blocked: true }));
  const startTime = form.querySelector('[name=start_time]');
  const endTime = form.querySelector('[name=end_time]');
  const date = form.querySelector('[name=start_date]').value;
  let pending = false;
  let booked = false;
  function tell(text, error = false) {
    feedback.textContent = text;
    feedback.classList.toggle('rb-notice-error', error);
    feedback.hidden = false;
  }
  function syncTimeControls() {
    if (!customControls) return;
    const custom = form.querySelector('[name=time_period]:checked')?.value === 'individuell';
    customControls.hidden = !custom;
    startTime.disabled = endTime.disabled = !custom;
    for (const option of endTime.options) {
      option.disabled = !periodCanBeBooked(1, blocks, startTime.value, option.value);
    }
    if (endTime.selectedOptions[0]?.disabled || !endTime.value) {
      endTime.value = [...endTime.options].find(option => !option.disabled)?.value ?? '';
    }
  }
  syncTimeControls();
  periods.addEventListener('change', syncTimeControls);
  form.hidden = false;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (pending || booked) return;
    const body = new FormData(form);
    if (body.get('time_period') === 'individuell'
      && (!validBookingWindow(startTime.value, endTime.value) || !bookingWindowInFuture(date, startTime.value))) {
      tell('Bitte eine gültige Zeit in der Zukunft wählen.', true);
      startTime.focus();
      return;
    }
    if (body.get('time_period') === 'individuell' && !periodCanBeBooked(1, blocks, startTime.value, endTime.value)) {
      tell('Der Raum ist in diesem Zeitraum gesperrt.', true);
      startTime.focus();
      return;
    }
    pending = true;
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
