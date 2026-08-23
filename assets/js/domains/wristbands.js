const initialized = new WeakSet();

function updateImportMode(form) {
  const mode = form.querySelector('[data-wristband-mapping-mode]:checked')?.value || 'selected_item';
  const field = form.querySelector('[data-wristband-selected-item]');
  if (!field) return;

  const selectedItemMode = mode === 'selected_item';
  field.hidden = !selectedItemMode;
  field.querySelectorAll('select, input').forEach((control) => {
    control.disabled = !selectedItemMode;
    control.required = selectedItemMode;
  });
}

function initImportForm(form) {
  if (initialized.has(form)) return;
  initialized.add(form);
  form.addEventListener('change', (event) => {
    if (event.target.matches('[data-wristband-mapping-mode]')) updateImportMode(form);
  });
  updateImportMode(form);
}

function initCopyButton(button) {
  if (initialized.has(button)) return;
  initialized.add(button);
  button.addEventListener('click', async () => {
    const target = document.querySelector(button.dataset.copyWristbandKey || '');
    if (!target) return;
    try {
      await navigator.clipboard.writeText(target.value || target.textContent || '');
      const label = button.querySelector('span');
      if (label) label.textContent = 'Copied';
    } catch {
      target.select?.();
      document.execCommand('copy');
    }
  });
}

export function init(root = document) {
  root.querySelectorAll('[data-wristband-import-form]').forEach(initImportForm);
  root.querySelectorAll('[data-copy-wristband-key]').forEach(initCopyButton);
}
