export const updateLabelPrintSelection = () => {
  const cards = Array.from(document.querySelectorAll('[data-label-print-card]'));
  const checkboxes = cards
    .map((card) => card.querySelector('[data-label-select-checkbox]'))
    .filter((checkbox) => checkbox instanceof HTMLInputElement);
  const selected = checkboxes.filter((checkbox) => checkbox.checked);
  const printButton = document.querySelector('[data-label-print-button]');
  const printButtonText = document.querySelector('[data-label-print-button-text]');
  const countBadge = document.querySelector('[data-label-selection-count]');
  const selectAll = document.querySelector('[data-label-select-all]');
  const selectedCount = selected.length;
  const totalCount = checkboxes.length;

  cards.forEach((card) => {
    const checkbox = card.querySelector('[data-label-select-checkbox]');
    card.classList.toggle('is-selected-for-print', checkbox instanceof HTMLInputElement && checkbox.checked);
  });

  if (printButton instanceof HTMLButtonElement) {
    printButton.disabled = selectedCount === 0;
    printButton.title = selectedCount === 0 ? 'Select one or more labels first.' : `Print ${selectedCount} selected label${selectedCount === 1 ? '' : 's'}.`;
  }

  if (printButtonText instanceof HTMLElement) {
    printButtonText.textContent = selectedCount === 0
      ? 'Print Selected'
      : `Print ${selectedCount} Selected`;
  }

  if (countBadge instanceof HTMLElement) {
    countBadge.textContent = `${selectedCount} selected`;
  }

  if (selectAll instanceof HTMLInputElement) {
    selectAll.checked = totalCount > 0 && selectedCount === totalCount;
    selectAll.indeterminate = selectedCount > 0 && selectedCount < totalCount;
    selectAll.disabled = totalCount === 0;
  }
};
export const initLabelPrintSelection = () => {
  updateLabelPrintSelection();

  if (document.documentElement.dataset.labelPrintBound === 'true') {
    return;
  }

  document.documentElement.dataset.labelPrintBound = 'true';

  document.addEventListener('change', (event) => {
    const target = event.target;

    if (!(target instanceof HTMLInputElement)) {
      return;
    }

    if (target.matches('[data-label-select-checkbox]')) {
      updateLabelPrintSelection();
      return;
    }

    if (target.matches('[data-label-select-all]')) {
      document.querySelectorAll('[data-label-select-checkbox]').forEach((checkbox) => {
        if (checkbox instanceof HTMLInputElement) {
          checkbox.checked = target.checked;
        }
      });
      updateLabelPrintSelection();
    }
  });

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;

    if (!target) {
      return;
    }

    const clearButton = target.closest('[data-label-clear-selection]');
    if (clearButton) {
      document.querySelectorAll('[data-label-select-checkbox]').forEach((checkbox) => {
        if (checkbox instanceof HTMLInputElement) {
          checkbox.checked = false;
        }
      });
      updateLabelPrintSelection();
      return;
    }

    const printButton = target.closest('[data-label-print-button]');
    if (printButton) {
      const selected = document.querySelectorAll('[data-label-select-checkbox]:checked');

      if (selected.length === 0) {
        updateLabelPrintSelection();
        return;
      }

      document.body.classList.add('label-print-selected');
      updateLabelPrintSelection();
      window.print();
    }
  });

  window.addEventListener('afterprint', () => {
    document.body.classList.remove('label-print-selected');
  });
};

export const init = (root = document) => initLabelPrintSelection(root);
