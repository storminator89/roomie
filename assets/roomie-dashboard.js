const form = document.getElementById('bookingForm');
if (form) {
  const start = form.elements.start_date;
  const end = form.elements.end_date;
  const room = form.elements.selectedWorkspace;
  const approvalNote = document.getElementById('rm-range-approval');
  const feedback = document.getElementById('rm-range-feedback');
  const bookingLink = document.getElementById('rm-range-bookings');
  const submit = form.querySelector('button[type="submit"]');
  const inputs = [...form.querySelectorAll('input:not([type="hidden"]), select')];
  let pending = false;
  let uncertain = false;
  let completed = false;
  const needsApproval = () => room.selectedOptions[0]?.dataset.requiresApproval === '1';

  function announce(message, isError = false) {
    feedback.textContent = message;
    feedback.dataset.error = String(isError);
  }

  function syncDates() {
    const approval = needsApproval();
    end.min = start.value || start.min;
    if (approval || (start.value && end.value < start.value)) end.value = start.value;
    end.readOnly = approval;
    if (approvalNote) approvalNote.hidden = !approval;
    submit.textContent = approval ? 'Anfrage senden' : 'Zeitraum buchen';
  }
  room.addEventListener('change', syncDates);
  start.addEventListener('change', syncDates);
  syncDates();
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
    const approval = needsApproval();
    const payload = new FormData(form);
    if (approval) payload.set('approval_request', '1');
    pending = true;
    submit.disabled = true;
    for (const input of inputs) input.disabled = true;
    form.setAttribute('aria-busy', 'true');
    announce(approval ? 'Anfrage wird gesendet …' : 'Wird gebucht …');
    try {
      const response = await fetch(form.action, {
        method: 'POST', body: payload, credentials: 'same-origin',
      });
      const result = await response.json();
      if (result.success === true && response.ok) {
        completed = true;
        const requested = result.pending === true;
        announce(result.message || (requested ? 'Anfrage gesendet. Der Raum ist noch nicht gebucht.' : 'Buchung gespeichert.'));
        bookingLink.textContent = requested ? 'Anfragen ansehen' : 'Buchungen ansehen';
        bookingLink.href = requested ? 'bookings.php#rb-booking-requests' : 'bookings.php';
        bookingLink.hidden = false;
        if (!requested) {
          document.getElementById('rm-next-title').textContent = 'Buchung gespeichert';
          document.getElementById('rm-next-summary').hidden = true;
        }
      } else if (typeof result.error === 'string') {
        announce(result.error, true);
      } else {
        throw new Error('Unconfirmed booking outcome');
      }
    } catch {
      uncertain = true;
      announce(approval ? 'Anfrage nicht bestätigt. Prüfe zuerst deine Anfragen.' : 'Buchung nicht bestätigt. Prüfe zuerst deine Buchungen.', true);
      bookingLink.textContent = approval ? 'Anfragen ansehen' : 'Buchungen ansehen';
      bookingLink.href = approval ? 'bookings.php#rb-booking-requests' : 'bookings.php';
      bookingLink.hidden = false;
    } finally {
      pending = false;
      submit.disabled = uncertain || completed;
      for (const input of inputs) input.disabled = false;
      form.removeAttribute('aria-busy');
    }
  });
}
