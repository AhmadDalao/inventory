export const initUnitSelectors = (root = document) => {
  root.querySelectorAll('[data-unit-select]').forEach((select) => {
    if (select.dataset.jsBound === 'true') {
      return;
    }

    const field = select.closest('.field');
    const customUnitField = field ? field.querySelector('[data-custom-unit]') : null;

    if (!customUnitField) {
      return;
    }

    const syncCustomUnit = () => {
      const showCustom = select.value === 'custom';
      customUnitField.hidden = !showCustom;
      customUnitField.required = showCustom;
    };

    select.dataset.jsBound = 'true';
    select.addEventListener('change', syncCustomUnit);
    syncCustomUnit();
  });
};

export const initStocktakeStorageSelects = (root = document) => {
  root.querySelectorAll('[data-stocktake-storage-select]').forEach((select) => {
    if (select.dataset.jsBound === 'true') {
      return;
    }

    select.dataset.jsBound = 'true';
    select.addEventListener('change', () => {
      const baseUrl = select.getAttribute('data-stocktake-create-base') || '';

      if (!select.value || !baseUrl) {
        return;
      }

      window.location.href = `${baseUrl}${encodeURIComponent(select.value)}`;
    });
  });
};

export const init = (root = document) => {
  initUnitSelectors(root);
  initStocktakeStorageSelects(root);
};
