export const initSupplierTypeOtherFields = (root = document) => {
  root.querySelectorAll('[data-supplier-type-select]').forEach((select) => {
    if (select.dataset.supplierTypeBound === 'true') {
      return;
    }

    select.dataset.supplierTypeBound = 'true';

    const scope = select.closest('.purchase-import-card, .purchase-new-supplier, .reorder-new-supplier-grid, .stack-form, form') || select.parentElement;
    const field = scope?.querySelector('[data-supplier-type-other-field]');
    const input = field?.querySelector('[data-supplier-type-other-input]');

    const sync = () => {
      const shouldShow = select instanceof HTMLSelectElement && select.value === 'other';

      if (field instanceof HTMLElement) {
        field.hidden = !shouldShow;
      }

      if (input instanceof HTMLInputElement) {
        input.required = shouldShow;
        input.disabled = !shouldShow || select.disabled;

        if (!shouldShow) {
          input.value = '';
        }
      }
    };

    select.addEventListener('change', sync);

    const observer = new MutationObserver(sync);
    observer.observe(select, { attributes: true, attributeFilter: ['disabled'] });

    sync();
  });
};

export const init = (root = document) => initSupplierTypeOtherFields(root);
