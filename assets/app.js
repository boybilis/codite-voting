'use strict';
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', event => {
    if (form.id === 'ballot-form') {
      const count = form.querySelectorAll('.candidate-check:checked').length;
      const limit = Number(form.dataset.limit);
      if (count < 1 || count > limit) {
        event.preventDefault();
        const error = document.getElementById('ballot-error');
        error.textContent = `Select between 1 and ${limit} members before submitting.`;
        error.classList.remove('d-none');
        return;
      }
    }
    const message = event.submitter?.dataset.confirm;
    if (message && !window.confirm(message)) event.preventDefault();
  });
});
document.querySelectorAll('[data-copy]').forEach(button => {
  button.addEventListener('click', async () => {
    const input = document.getElementById(button.dataset.copy);
    try { await navigator.clipboard.writeText(input.value); button.textContent = 'Copied!'; }
    catch { input.focus(); input.select(); button.textContent = 'Select & copy'; }
    setTimeout(() => { button.textContent = 'Copy link'; }, 2500);
  });
});
const ballot = document.getElementById('ballot-form');
if (ballot) {
  const checks = [...ballot.querySelectorAll('.candidate-check')];
  const limit = Number(ballot.dataset.limit);
  function updateSelection() {
    const count = checks.filter(box => box.checked).length;
    document.getElementById('selection-count').textContent = `${count} of ${limit} selected`;
    checks.forEach(box => { box.disabled = !box.checked && count >= limit; });
    document.getElementById('ballot-error').classList.add('d-none');
  }
  checks.forEach(box => box.addEventListener('change', updateSelection));
  updateSelection();
  document.getElementById('ballot-search').addEventListener('input', event => {
    const search = event.target.value.toLocaleLowerCase().trim();
    let visible = 0;
    ballot.querySelectorAll('[data-search]').forEach(row => {
      const match = row.dataset.search.includes(search);
      row.hidden = !match;
      if (match) visible++;
    });
    document.getElementById('no-search-results').classList.toggle('d-none', visible > 0);
  });
}

const invitationQueue = document.querySelector('[data-invitation-queue]');
if (invitationQueue && Number(invitationQueue.dataset.pending) > 0) {
  const status = invitationQueue.querySelector('[data-queue-status]');
  const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
  (async () => {
    while (invitationQueue.isConnected) {
      try {
        const response = await fetch(invitationQueue.dataset.endpoint, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf: invitationQueue.dataset.csrf })
        });
        if (!response.ok) throw new Error('Request failed');
        const result = await response.json();
        if (!result.queue) throw new Error('Session expired');
        const remaining = result.queue.queued + result.queue.sending;
        const failed = result.queue.failed;
        status.textContent = remaining
          ? `Sending invitations: ${remaining} remaining${failed ? `, ${failed} failed` : ''}. Keep this page open.`
          : (failed ? `${failed} invitation(s) could not be sent. Check your email settings, then retry.` : 'Invitation sending is complete. Refresh the nomination tally to see the latest responses.');
        if (remaining === 0) break;
        await pause(result.outcome === 'idle' ? 5000 : 300);
      } catch {
        status.textContent = 'Invitation sending paused. Refresh this page to resume. Pending emails remain queued.';
        break;
      }
    }
  })();
}
