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
  const lightbox = document.querySelector('[data-image-lightbox]');
  const lightboxImage = document.querySelector('[data-image-lightbox-image]');
  const lightboxCaption = document.querySelector('[data-image-lightbox-caption]');

  const openLightbox = (image) => {
    if (!lightbox || !lightboxImage || !image) {
      return;
    }

    const src = image.getAttribute('src');
    const alt = image.getAttribute('alt') || '';

    if (!src) {
      return;
    }

    lightboxImage.src = src;
    lightboxImage.alt = alt;

    if (lightboxCaption) {
      lightboxCaption.textContent = alt;
      lightboxCaption.hidden = alt === '';
    }

    lightbox.hidden = false;
    document.body.style.overflow = 'hidden';
  };

  const closeLightbox = () => {
    if (!lightbox || !lightboxImage) {
      return;
    }

    lightbox.hidden = true;
    lightboxImage.src = '';
    lightboxImage.alt = '';

    if (lightboxCaption) {
      lightboxCaption.textContent = '';
      lightboxCaption.hidden = true;
    }

    document.body.style.overflow = '';
  };

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

  document.querySelectorAll('[data-expand-image]').forEach((image) => {
    image.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      openLightbox(image);
    });

    image.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openLightbox(image);
      }
    });
  });

  document.querySelectorAll('[data-image-lightbox-close]').forEach((element) => {
    element.addEventListener('click', closeLightbox);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && lightbox && !lightbox.hidden) {
      closeLightbox();
    }
  });

  if (movementForm && movementType && quantityInput && quantityHint && summary) {
    const sourceField = movementForm.querySelector('[data-source-field]');
    const destinationField = movementForm.querySelector('[data-destination-field]');
    const sourceLabel = movementForm.querySelector('[data-source-label]');
    const destinationLabel = movementForm.querySelector('[data-destination-label]');
    const sourceStorage = movementForm.querySelector('[data-source-storage]');
    const destinationStorage = movementForm.querySelector('[data-destination-storage]');
    const stockNumber = summary.querySelector('[data-stock-number]');
    const stockUnit = summary.querySelector('[data-stock-unit]');
    const stockValueLabel = summary.querySelector('[data-stock-value-label]');
    const totalUsed = summary.querySelector('[data-total-used]');
    const totalAdded = summary.querySelector('[data-total-added]');
    const totalTransferred = summary.querySelector('[data-total-transferred]');
    const movementCount = summary.querySelector('[data-movement-count]');
    const stockValueMetric = summary.querySelector('[data-stock-value-metric]');
    const previewDelta = movementForm.querySelector('[data-preview-delta]');
    const previewBalance = movementForm.querySelector('[data-preview-balance]');
    const previewSource = movementForm.querySelector('[data-preview-source]');
    const previewDestination = movementForm.querySelector('[data-preview-destination]');
    const previewSourceLabel = movementForm.querySelector('[data-preview-source-label]');
    const previewDestinationLabel = movementForm.querySelector('[data-preview-destination-label]');
    const previewValue = movementForm.querySelector('[data-preview-value]');
    const dateInput = movementForm.querySelector('input[name="used_at"]');
    const referenceInput = movementForm.querySelector('input[name="reference_code"]');
    const notesInput = movementForm.querySelector('textarea[name="notes"]');

    let currentQuantity = parseNumber(summary.dataset.currentQuantity);
    let costPerUnit = parseNumber(summary.dataset.costPerUnit);
    let currentUnit = summary.dataset.unit || 'pcs';
    let locationBalances = {};

    try {
      locationBalances = JSON.parse(summary.dataset.balanceMap || '{}');
    } catch (error) {
      locationBalances = {};
    }

    const getLocationBalance = (storageId) => {
      if (!storageId) {
        return 0;
      }

      return parseNumber(locationBalances[String(storageId)]);
    };

    const setPreviewValue = (element, value, unit, negative = false) => {
      if (!element) {
        return;
      }

      element.textContent = value === null ? '-' : `${formatQuantity(value)} ${unit}`;
      element.classList.toggle('danger-text', negative);
    };

    const syncMovementLayout = () => {
      const type = movementType.value;
      const needsSource = type === 'usage' || type === 'transfer' || type === 'adjustment';
      const needsDestination = type === 'restock' || type === 'transfer';

      if (sourceField) {
        sourceField.hidden = !needsSource;
      }

      if (destinationField) {
        destinationField.hidden = !needsDestination;
      }

      if (sourceStorage) {
        sourceStorage.required = needsSource;
      }

      if (destinationStorage) {
        destinationStorage.required = needsDestination;
      }

      if (sourceLabel) {
        sourceLabel.textContent = type === 'adjustment' ? 'Adjust Location' : 'From Location';
      }

      if (destinationLabel) {
        destinationLabel.textContent = type === 'restock' ? 'To Location' : 'Destination';
      }

      if (previewSourceLabel) {
        previewSourceLabel.textContent = type === 'adjustment' ? 'Adjusted Location After' : 'Source After';
      }

      if (previewDestinationLabel) {
        previewDestinationLabel.textContent = type === 'restock' ? 'Restock Location After' : 'Destination After';
      }
    };

    const syncMovementState = () => {
      const type = movementType.value;
      const rawQuantity = parseNumber(quantityInput.value);
      const absoluteQuantity = Math.abs(rawQuantity);
      const sourceId = sourceStorage ? sourceStorage.value : '';
      const destinationId = destinationStorage ? destinationStorage.value : '';
      const sourceCurrent = getLocationBalance(sourceId);
      const destinationCurrent = getLocationBalance(destinationId);

      let delta = 0;
      let projectedBalance = currentQuantity;
      let projectedValue = currentQuantity * costPerUnit;
      let sourceAfter = null;
      let destinationAfter = null;
      let invalid = false;

      if (type === 'adjustment') {
        delta = rawQuantity;
        projectedBalance = currentQuantity + delta;
        sourceAfter = sourceId ? sourceCurrent + rawQuantity : null;
        quantityHint.textContent = 'Adjustments can be positive or negative, but the location cannot go below zero.';
        invalid = !sourceId || sourceAfter === null || sourceAfter < 0;
      } else if (type === 'restock') {
        delta = absoluteQuantity;
        projectedBalance = currentQuantity + delta;
        destinationAfter = destinationId ? destinationCurrent + absoluteQuantity : null;
        quantityHint.textContent = 'Restock adds stock to the selected location.';
        invalid = !destinationId;
      } else if (type === 'transfer') {
        delta = 0;
        projectedBalance = currentQuantity;
        sourceAfter = sourceId ? sourceCurrent - absoluteQuantity : null;
        destinationAfter = destinationId ? destinationCurrent + absoluteQuantity : null;
        quantityHint.textContent = 'Transfer moves stock between locations without changing the total on hand.';
        invalid = !sourceId || !destinationId || sourceId === destinationId || sourceAfter === null || sourceAfter < 0;
      } else {
        delta = -absoluteQuantity;
        projectedBalance = currentQuantity + delta;
        sourceAfter = sourceId ? sourceCurrent - absoluteQuantity : null;
        quantityHint.textContent = 'Usage subtracts stock from the selected location. Type 100 to use 100.';
        invalid = !sourceId || sourceAfter === null || sourceAfter < 0;
      }

      projectedValue = projectedBalance * costPerUnit;

      if (previewDelta) {
        previewDelta.textContent = `${formatQuantity(delta)} ${currentUnit}`;
        previewDelta.classList.toggle('danger-text', delta < 0);
      }

      if (previewBalance) {
        previewBalance.textContent = `${formatQuantity(projectedBalance)} ${currentUnit}`;
        previewBalance.classList.toggle('danger-text', projectedBalance < 0);
      }

      setPreviewValue(previewSource, sourceAfter, currentUnit, sourceAfter !== null && sourceAfter < 0);
      setPreviewValue(previewDestination, destinationAfter, currentUnit, false);

      if (previewValue) {
        previewValue.textContent = formatMoney(projectedValue);
      }

      if (submitButton) {
        const hasQuantity = quantityInput.value !== '';
        const invalidNegative = projectedBalance < 0;
        submitButton.disabled = invalid || invalidNegative || !hasQuantity;
        submitButton.classList.toggle('is-disabled', submitButton.disabled);
      }
    };

    movementType.addEventListener('change', () => {
      syncMovementLayout();
      syncMovementState();
    });

    quantityInput.addEventListener('input', syncMovementState);
    sourceStorage?.addEventListener('change', syncMovementState);
    destinationStorage?.addEventListener('change', syncMovementState);

    syncMovementLayout();
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
            Accept: 'application/json',
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

        if (payload.item.balance_map_json) {
          summary.dataset.balanceMap = payload.item.balance_map_json;

          try {
            locationBalances = JSON.parse(payload.item.balance_map_json);
          } catch (error) {
            locationBalances = {};
          }
        }

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

        if (totalTransferred) {
          totalTransferred.textContent = `${payload.item.total_transferred} ${currentUnit}`;
        }

        if (movementCount) {
          movementCount.textContent = String(payload.item.movement_count);
        }

        if (stockValueMetric) {
          stockValueMetric.textContent = payload.item.stock_value;
        }

        const locationBalancesSection = document.querySelector('[data-location-balances]');
        if (locationBalancesSection && payload.item.location_balances_html) {
          locationBalancesSection.outerHTML = payload.item.location_balances_html;
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
        if (sourceStorage) {
          sourceStorage.value = '';
        }
        if (destinationStorage) {
          destinationStorage.value = '';
        }
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

        syncMovementLayout();
        syncMovementState();
      }
    });
  }
});
