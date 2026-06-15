document.addEventListener('DOMContentLoaded', () => {
  const shell = document.querySelector('[data-shell]');
  const toggle = document.querySelector('[data-menu-toggle]');
  const unitSelect = document.querySelector('[data-unit-select]');
  const customUnitField = document.querySelector('[data-custom-unit]');
  const movementForm = document.querySelector('[data-movement-form]');
  const movementType = document.querySelector('[data-movement-type]');
  const quantityInput = document.querySelector('[data-quantity-input]');
  const quantityHint = document.querySelector('[data-quantity-hint]');
  const feedback = document.querySelector('[data-movement-feedback]');
  const summary = document.querySelector('[data-item-summary]');
  const submitButton = document.querySelector('[data-movement-submit]');
  const historyBody = document.querySelector('[data-history-body]');

  const parseNumber = (value) => {
    const number = Number.parseFloat(value);
    return Number.isFinite(number) ? number : 0;
  };

  const formatQuantity = (value) => {
    const formatted = new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2,
    }).format(value);

    return formatted.replace(/\.00$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
  };

  const formatMoney = (value) => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(value);

  const localDateTimeValue = () => {
    const now = new Date();
    const offsetMs = now.getTimezoneOffset() * 60000;
    return new Date(now.getTime() - offsetMs).toISOString().slice(0, 16);
  };

  const showFeedback = (message, type) => {
    if (!feedback) {
      return;
    }

    feedback.hidden = false;
    feedback.className = `movement-feedback flash flash-${type}`;
    feedback.textContent = message;
  };

  const clearFeedback = () => {
    if (!feedback) {
      return;
    }

    feedback.hidden = true;
    feedback.textContent = '';
    feedback.className = 'movement-feedback';
  };

  const computeDelta = (type, value) => {
    if (!Number.isFinite(value) || value === 0) {
      return 0;
    }

    if (type === 'usage') {
      return -Math.abs(value);
    }

    if (type === 'restock') {
      return Math.abs(value);
    }

    return value;
  };

  if (toggle && shell) {
    toggle.addEventListener('click', () => {
      shell.classList.toggle('nav-open');
    });
  }

  document.querySelectorAll('[data-confirm]').forEach((button) => {
    button.addEventListener('click', (event) => {
      const message = button.getAttribute('data-confirm') || 'Are you sure?';
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });

  if (unitSelect && customUnitField) {
    const syncCustomUnit = () => {
      const showCustom = unitSelect.value === 'custom';
      customUnitField.hidden = !showCustom;
      customUnitField.required = showCustom;
    };

    unitSelect.addEventListener('change', syncCustomUnit);
    syncCustomUnit();
  }

  if (movementForm && movementType && quantityInput && quantityHint && summary) {
    const stockNumber = summary.querySelector('[data-stock-number]');
    const stockUnit = summary.querySelector('[data-stock-unit]');
    const stockValueLabel = summary.querySelector('[data-stock-value-label]');
    const totalUsed = summary.querySelector('[data-total-used]');
    const totalAdded = summary.querySelector('[data-total-added]');
    const movementCount = summary.querySelector('[data-movement-count]');
    const stockValueMetric = summary.querySelector('[data-stock-value-metric]');
    const previewDelta = movementForm.querySelector('[data-preview-delta]');
    const previewBalance = movementForm.querySelector('[data-preview-balance]');
    const previewValue = movementForm.querySelector('[data-preview-value]');
    const dateInput = movementForm.querySelector('input[name="used_at"]');
    const referenceInput = movementForm.querySelector('input[name="reference_code"]');
    const notesInput = movementForm.querySelector('textarea[name="notes"]');

    let currentQuantity = parseNumber(summary.dataset.currentQuantity);
    let costPerUnit = parseNumber(summary.dataset.costPerUnit);
    let currentUnit = summary.dataset.unit || 'pcs';

    const syncMovementState = () => {
      const quantityValue = parseNumber(quantityInput.value);
      const delta = computeDelta(movementType.value, quantityValue);
      const projectedBalance = currentQuantity + delta;
      const projectedValue = projectedBalance * costPerUnit;

      if (movementType.value === 'adjustment') {
        quantityHint.textContent = 'Adjustments can be positive or negative.';
      } else if (movementType.value === 'restock') {
        quantityHint.textContent = 'Restock adds stock automatically. Type 100 to add 100.';
      } else {
        quantityHint.textContent = 'Usage subtracts stock automatically. Type 100 to use 100.';
      }

      if (previewDelta) {
        previewDelta.textContent = `${formatQuantity(delta)} ${currentUnit}`;
        previewDelta.classList.toggle('danger-text', delta < 0);
      }

      if (previewBalance) {
        previewBalance.textContent = `${formatQuantity(projectedBalance)} ${currentUnit}`;
        previewBalance.classList.toggle('danger-text', projectedBalance < 0);
      }

      if (previewValue) {
        previewValue.textContent = formatMoney(projectedValue);
      }

      if (submitButton) {
        const invalidNegative = projectedBalance < 0;
        submitButton.disabled = invalidNegative;
        submitButton.classList.toggle('is-disabled', invalidNegative);
      }
    };

    movementType.addEventListener('change', syncMovementState);
    quantityInput.addEventListener('input', syncMovementState);
    syncMovementState();

    movementForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearFeedback();

      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Saving...';
      }

      const formData = new FormData(movementForm);

      try {
        const response = await fetch(movementForm.action, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: formData,
        });

        const payload = await response.json();

        if (!response.ok) {
          showFeedback((payload.errors || [payload.message || 'Movement could not be saved.']).join(' '), 'danger');
          return;
        }

        currentQuantity = payload.item.current_quantity_raw;
        costPerUnit = payload.item.cost_per_unit_raw;
        currentUnit = payload.item.unit;

        summary.dataset.currentQuantity = String(currentQuantity);
        summary.dataset.costPerUnit = String(costPerUnit);
        summary.dataset.unit = currentUnit;

        if (stockNumber) {
          stockNumber.textContent = payload.item.current_quantity;
        }

        if (stockUnit) {
          stockUnit.textContent = `${currentUnit} on hand`;
        }

        if (stockValueLabel) {
          stockValueLabel.textContent = `${payload.item.stock_value} stock value`;
        }

        if (totalUsed) {
          totalUsed.textContent = `${payload.item.total_used} ${currentUnit}`;
        }

        if (totalAdded) {
          totalAdded.textContent = `${payload.item.total_added} ${currentUnit}`;
        }

        if (movementCount) {
          movementCount.textContent = String(payload.item.movement_count);
        }

        if (stockValueMetric) {
          stockValueMetric.textContent = payload.item.stock_value;
        }

        if (historyBody && payload.movement && payload.movement.row_html) {
          const emptyStateRow = historyBody.querySelector('.empty-cell');
          if (emptyStateRow) {
            const emptyStateParent = emptyStateRow.parentElement;
            if (emptyStateParent) {
              emptyStateParent.remove();
            }
          }

          historyBody.insertAdjacentHTML('afterbegin', payload.movement.row_html);
        }

        quantityInput.value = '';
        if (referenceInput) {
          referenceInput.value = '';
        }
        if (notesInput) {
          notesInput.value = '';
        }
        if (dateInput) {
          dateInput.value = localDateTimeValue();
        }

        showFeedback(payload.message || 'Movement saved.', 'success');
      } catch (error) {
        showFeedback('Live update failed. Refresh the page and try again.', 'danger');
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = 'Save Movement';
        }

        syncMovementState();
      }
    });
  }
});
