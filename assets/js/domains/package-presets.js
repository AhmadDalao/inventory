const initialized = new WeakSet();

function updatePackageFields(form) {
  const type = form.querySelector('[data-package-type]')?.value || 'individual';
  const customField = form.querySelector('[data-custom-package-label]');
  const customInput = customField?.querySelector('input');
  const containsLabel = form.querySelector('[data-package-contains-label]');
  const selectedLabel = form.querySelector(`[data-package-type] option[value="${CSS.escape(type)}"]`)?.textContent?.trim() || 'Package';

  if (customField) customField.hidden = type !== 'other';
  if (customInput) {
    customInput.disabled = type !== 'other';
    customInput.required = type === 'other';
  }
  if (containsLabel) containsLabel.textContent = `One ${selectedLabel} contains`;
}

function initForm(form) {
  if (initialized.has(form)) return;
  initialized.add(form);
  form.addEventListener('change', (event) => {
    if (event.target.matches('[data-package-type]')) updatePackageFields(form);
  });
  updatePackageFields(form);
}

export function init(root = document) {
  root.querySelectorAll('[data-package-preset-form]').forEach(initForm);
}
