(() => {
  'use strict';

  // Confirmation dialog for destructive actions
  const dialog = document.getElementById('confirm');
  const dialogText = document.getElementById('confirm-text');
  document.addEventListener('submit', event => {
    const form = event.target.closest('form[data-confirm]');
    if (!form || form.dataset.confirmed === '1') return;
    event.preventDefault();
    dialogText.textContent = form.dataset.confirm;
    dialog.addEventListener('close', () => {
      if (dialog.returnValue === 'ok') { form.dataset.confirmed = '1'; form.requestSubmit(); }
    }, { once: true });
    dialog.showModal();
  });

  // Password visibility
  document.querySelectorAll('[data-toggle-password]').forEach(button => {
    button.addEventListener('click', () => {
      const input = button.parentElement.querySelector('input');
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.setAttribute('aria-label', show ? 'Скрыть пароль' : 'Показать пароль');
    });
  });

  // Textareas grow with their content
  const grow = el => { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight + 2, 520) + 'px'; };
  document.querySelectorAll('textarea[data-autosize]').forEach(el => {
    el.addEventListener('input', () => grow(el));
    const details = el.closest('details');
    if (details) details.addEventListener('toggle', () => grow(el)); else grow(el);
    requestAnimationFrame(() => grow(el));
  });

  // Text editor: change markers, counters, reset-to-default, unsaved-changes guard
  document.querySelectorAll('form[data-dirty-guard]').forEach(form => {
    const note = form.querySelector('[data-dirty-note]');
    const fields = [...form.querySelectorAll('input[name]:not([type=hidden]), textarea[name]')];
    const initial = new Map(fields.map(f => [f, f.value]));
    let submitting = false;

    const refresh = () => {
      const dirty = fields.filter(f => f.value !== initial.get(f)).length;
      fields.forEach(f => {
        const wrap = f.closest('.field');
        if (!wrap) return;
        wrap.classList.toggle('is-changed', f.value !== initial.get(f));
        const reset = wrap.querySelector('[data-reset]');
        if (reset && f.dataset.default !== undefined) reset.hidden = f.value === f.dataset.default;
        const counter = wrap.querySelector('.char-count');
        if (counter && f.maxLength > 0) counter.textContent = f.value.length + ' / ' + f.maxLength;
      });
      if (note) note.textContent = dirty ? 'Не сохранено: ' + dirty : 'Изменений нет';
      form.dataset.dirty = dirty ? '1' : '';
    };

    fields.forEach(f => {
      if (f.hasAttribute('data-count') && f.maxLength > 0) {
        const c = document.createElement('span'); c.className = 'char-count'; c.setAttribute('aria-hidden', 'true');
        f.after(c);
      }
      f.addEventListener('input', refresh);
    });
    form.querySelectorAll('[data-reset]').forEach(btn => btn.addEventListener('click', () => {
      const f = btn.closest('.field').querySelector('[data-default]');
      f.value = f.dataset.default; f.dispatchEvent(new Event('input', { bubbles: true })); f.focus();
    }));
    form.addEventListener('submit', () => { submitting = true; });
    window.addEventListener('beforeunload', event => {
      if (form.dataset.dirty && !submitting) { event.preventDefault(); event.returnValue = ''; }
    });
    refresh();
  });

  // Choosing a file uploads it immediately
  document.querySelectorAll('input[type=file][data-autosubmit]').forEach(input => {
    input.addEventListener('change', () => {
      if (!input.files.length) return;
      const form = input.closest('form');
      form.classList.add('is-busy');
      const label = input.closest('label');
      const text = label && label.querySelector('span:last-of-type');
      if (text) text.textContent = 'Загружаю…';
      form.submit();
    });
  });

  // Drag & drop onto the gallery dropzone
  document.querySelectorAll('.dropzone').forEach(zone => {
    ['dragenter', 'dragover'].forEach(n => zone.addEventListener(n, e => { e.preventDefault(); zone.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach(n => zone.addEventListener(n, () => zone.classList.remove('is-over')));
  });

  // Close photo-description popovers when clicking elsewhere
  document.addEventListener('click', event => {
    document.querySelectorAll('.gedit[open]').forEach(d => { if (!d.contains(event.target)) d.removeAttribute('open'); });
  });
})();
