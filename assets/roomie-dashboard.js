const form = document.getElementById('bookingForm');
if (form) {
  const start = form.elements.start_date;
  const end = form.elements.end_date;
  const feedback = document.getElementById('rm-range-feedback');
  const bookingLink = document.getElementById('rm-range-bookings');
  const submit = form.querySelector('button[type="submit"]');
  const inputs = [...form.querySelectorAll('input:not([type="hidden"]), select')];
  let pending = false;
  let uncertain = false;
  let completed = false;

  function announce(message, isError = false) {
    feedback.textContent = message;
    feedback.dataset.error = String(isError);
  }

  start.addEventListener('change', () => {
    end.min = start.value || start.min;
    if (start.value && end.value < start.value) end.value = start.value;
  });
  form.addEventListener('input', () => {
    if (!pending && !uncertain) {
      completed = false;
      submit.disabled = false;
      announce('');
      bookingLink.hidden = true;
    }
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (pending || uncertain || completed) return;
    const payload = new FormData(form);
    pending = true;
    submit.disabled = true;
    for (const input of inputs) input.disabled = true;
    form.setAttribute('aria-busy', 'true');
    announce('Wird gebucht …');
    try {
      const response = await fetch(form.action, {
        method: 'POST', body: payload, credentials: 'same-origin',
      });
      const result = await response.json();
      if (result.success === true && response.ok) {
        completed = true;
        announce(result.message || 'Buchung gespeichert.');
        bookingLink.hidden = false;
        document.getElementById('rm-next-title').textContent = 'Buchung gespeichert';
        document.getElementById('rm-next-summary').hidden = true;
      } else if (typeof result.error === 'string') {
        announce(result.error, true);
      } else {
        throw new Error('Unconfirmed booking outcome');
      }
    } catch {
      uncertain = true;
      announce('Buchung nicht bestätigt. Prüfe zuerst deine Buchungen.', true);
      bookingLink.hidden = false;
    } finally {
      pending = false;
      submit.disabled = uncertain || completed;
      for (const input of inputs) input.disabled = false;
      form.removeAttribute('aria-busy');
    }
  });
}
