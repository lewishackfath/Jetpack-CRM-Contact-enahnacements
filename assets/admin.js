/* global jpccAdmin */
(() => {
  'use strict';
  document.querySelectorAll('[data-jpcc-select-all]').forEach((all) => {
    const form = all.closest('form');
    if (!form) return;
    const boxes = [...form.querySelectorAll('input[name="record_ids[]"]')];
    all.addEventListener('change', () => boxes.forEach((box) => { box.checked = all.checked; }));
    boxes.forEach((box) => box.addEventListener('change', () => {
      all.checked = boxes.every((item) => item.checked);
      all.indeterminate = boxes.some((item) => item.checked) && !all.checked;
    }));
  });
  document.querySelectorAll('[data-jpcc-delete]').forEach((button) => {
    button.closest('form').addEventListener('submit', (event) => {
      if (!window.confirm(jpccAdmin.confirmDelete)) event.preventDefault();
    });
  });
  const panel = document.querySelector('[data-jpcc-audience]');
  if (!panel || panel.dataset.done === '1') return;
  const resume = panel.querySelector('[data-jpcc-resume]');
  const error = panel.querySelector('[data-jpcc-batch-error]');
  let running = false;
  const setItems = (target, items) => {
    target.replaceChildren(...items.map((text) => {
      const li = document.createElement('li');
      li.textContent = text;
      return li;
    }));
  };
  const run = async () => {
    if (running) return;
    running = true;
    resume.hidden = true;
    error.textContent = '';
    try {
      let done = false;
      while (!done) {
        const response = await fetch(jpccAdmin.ajaxUrl, {
          method: 'POST', credentials: 'same-origin',
          body: new URLSearchParams({ action: 'jpcc_audience_batch', _ajax_nonce: jpccAdmin.nonce, token: panel.dataset.jpccAudience }),
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.data?.message || jpccAdmin.error);
        const job = result.data;
        const values = [job.offset, job.total, job.added, job.skipped, job.failed];
        panel.querySelector('[data-jpcc-progress]').textContent = jpccAdmin.progress.replace(/%([1-5])\$s/g, (_, index) => values[Number(index) - 1]);
        panel.querySelector('progress').value = job.offset;
        setItems(panel.querySelector('[data-jpcc-reasons]'), Object.entries(job.reasons).map(([reason, count]) => `${reason}: ${count}`));
        setItems(panel.querySelector('[data-jpcc-details]'), job.details.map((item) => `#${item.contact_id} — ${item.reason}`));
        done = job.done;
        if (done) panel.querySelector('[data-jpcc-finished]').hidden = false;
      }
    } catch (failure) {
      error.textContent = failure.message || jpccAdmin.error;
      resume.hidden = false;
    } finally { running = false; }
  };
  resume.addEventListener('click', run);
  run();
})();
