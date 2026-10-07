/* global jpccAdmin */
(() => {
  'use strict';
  if (new URLSearchParams(window.location.search).get('jpcc_tab') === 'courses') {
    window.jQuery(() => {
      const tabs = window.jQuery('#zbs-vitals-box .tabular.menu .item');
      if (tabs.length && typeof tabs.tab === 'function') tabs.tab('change tab', 'jpcc-courses');
    });
  }
  document.querySelectorAll('[data-jpcc-dropzone]').forEach((zone) => {
    const input = zone.querySelector('input[type="file"]');
    const status = zone.querySelector('[data-jpcc-file-status]');
    const error = zone.querySelector('[data-jpcc-file-error]');
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'button';
    clear.textContent = jpccAdmin.clearFile;
    clear.hidden = true;
    zone.append(clear);
    let depth = 0;
    const showSelection = () => {
      const file = input.files[0];
      input.setCustomValidity('');
      error.textContent = '';
      status.textContent = file ? `${file.name} (${(file.size / 1024).toFixed(0)} KB)` : jpccAdmin.noFile;
      clear.hidden = !file;
    };
    const reject = (message) => {
      input.value = '';
      showSelection();
      input.setCustomValidity(message);
      error.textContent = message;
      clear.hidden = false;
    };
    const valid = (files) => files.length === 1 && /\.(pdf|jpe?g|png)$/i.test(files[0].name)
      && files[0].size > 0 && files[0].size <= 5 * 1024 * 1024;
    input.addEventListener('change', () => {
      if (input.files.length && !valid(input.files)) reject(jpccAdmin.fileError);
      else showSelection();
    });
    clear.addEventListener('click', () => { input.value = ''; showSelection(); input.focus(); });
    zone.addEventListener('dragenter', (event) => {
      event.preventDefault();
      depth += 1;
      zone.classList.add('jpcc-dragover');
    });
    zone.addEventListener('dragover', (event) => {
      event.preventDefault();
      event.dataTransfer.dropEffect = 'copy';
    });
    zone.addEventListener('dragleave', (event) => {
      event.preventDefault();
      depth = Math.max(0, depth - 1);
      if (!depth) zone.classList.remove('jpcc-dragover');
    });
    zone.addEventListener('drop', (event) => {
      event.preventDefault();
      depth = 0;
      zone.classList.remove('jpcc-dragover');
      const files = event.dataTransfer.files;
      if (!valid(files)) { reject(jpccAdmin.fileError); return; }
      try {
        input.files = files;
        showSelection();
      } catch (_) { reject(jpccAdmin.dropUnavailable); }
    });
    showSelection();
  });
  document.querySelectorAll('.jpcc-form').forEach((form) => {
    if (form.querySelector('input[name="action"]')?.value !== 'jpcc_save_record') return;
    const buttons = [...form.querySelectorAll('button[type="submit"], input[type="submit"]')];
    const labels = buttons.map((button) => button.tagName === 'INPUT' ? button.value : button.textContent);
    const reset = () => {
      form.removeAttribute('aria-busy');
      delete form.dataset.submitting;
      buttons.forEach((button, index) => {
        button.disabled = false;
        if (button.tagName === 'INPUT') button.value = labels[index];
        else button.textContent = labels[index];
      });
    };
    form.addEventListener('submit', (event) => {
      if (event.defaultPrevented) return;
      if (form.dataset.submitting) { event.preventDefault(); return; }
      form.dataset.submitting = '1';
      form.setAttribute('aria-busy', 'true');
      buttons.forEach((button) => {
        button.disabled = true;
        if (button.tagName === 'INPUT') button.value = jpccAdmin.saving;
        else button.textContent = jpccAdmin.saving;
      });
    });
    // A failed submission's Back button can restore a page from the browser cache.
    window.addEventListener('pageshow', reset);
  });
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
