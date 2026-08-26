import { formatMoney, formatQuantity, localDateTimeValue, parseNumber } from '../core/runtime.js';

export const initMovementForm = (root = document) => {
  root.querySelectorAll('[data-movement-form]').forEach((movementForm) => {
    if (movementForm.dataset.jsBound === 'true') {
      return;
    }

    const movementType = movementForm.querySelector('[data-movement-type]');
    const quantityInput = movementForm.querySelector('[data-quantity-input]');
    const quantityHint = movementForm.querySelector('[data-quantity-hint]');
    const packageField = movementForm.querySelector('[data-package-field]');
    const packagePreset = movementForm.querySelector('[data-package-preset]');
    const usageReasonField = movementForm.querySelector('[data-usage-reason-field]');
    const usageReason = movementForm.querySelector('[data-usage-reason]');
    const customReasonField = movementForm.querySelector('[data-custom-reason-field]');
    const customReason = movementForm.querySelector('[data-custom-reason]');
    const proofField = movementForm.querySelector('[data-movement-proof-field]');
    const proofInput = movementForm.querySelector('[data-movement-proof]');
    const proofRequirement = movementForm.querySelector('[data-proof-requirement]');
    const feedback = movementForm.querySelector('[data-movement-feedback]');
    const summary = document.querySelector('[data-item-summary]');

    if (!movementType || !quantityInput || !quantityHint || !feedback || !summary) {
      return;
    }

    movementForm.dataset.jsBound = 'true';

    const submitButton = movementForm.querySelector('[data-movement-submit]');
    const historyBody = document.querySelector('[data-history-body]');
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
    let currentUnit = movementForm.dataset.baseUnit || summary.dataset.unit || 'pcs';
    let locationBalances = {};
    let usageReasonCatalogs = {};

    try {
      locationBalances = JSON.parse(summary.dataset.balanceMap || '{}');
    } catch (error) {
      locationBalances = {};
    }

    try {
      usageReasonCatalogs = JSON.parse(movementForm.dataset.usageReasonCatalogs || '{}');
    } catch (error) {
      usageReasonCatalogs = {};
    }

    const selectedUsageProfile = () => (
      sourceStorage?.selectedOptions[0]?.dataset.usageProfile || 'wristband'
    );

    const activeUsageReasons = () => {
      const reasons = usageReasonCatalogs[selectedUsageProfile()];
      return Array.isArray(reasons) ? reasons : [];
    };

    const syncUsageReasons = () => {
      if (!(usageReason instanceof HTMLSelectElement)) {
        return;
      }

      const profile = selectedUsageProfile();
      if (usageReason.dataset.usageProfile === profile) {
        return;
      }

      const previous = usageReason.value;
      const reasons = activeUsageReasons();
      usageReason.replaceChildren(new Option('Pick reason', ''));
      reasons.forEach((reason) => {
        const option = new Option(String(reason.label || reason.code), String(reason.code || ''));
        option.dataset.requiresCustom = reason.requires_custom_text ? '1' : '0';
        usageReason.add(option);
      });
      usageReason.value = reasons.some((reason) => String(reason.code) === previous) ? previous : '';
      usageReason.dataset.usageProfile = profile;
    };

    const showFeedback = (message, type) => {
      feedback.hidden = false;
      feedback.className = `movement-feedback flash flash-${type}`;
      feedback.textContent = message;
    };

    const clearFeedback = () => {
      feedback.hidden = true;
      feedback.textContent = '';
      feedback.className = 'movement-feedback';
    };

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
      const supportsPackage = type !== 'adjustment';
      const showsReason = type === 'usage';
      const showsProof = type === 'usage' || type === 'restock';
      const proofRequired = type === 'usage'
        ? movementForm.dataset.usageProofRequired === '1'
        : type === 'restock' && movementForm.dataset.refillProofRequired === '1';

      syncUsageReasons();

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

      if (packageField) {
        packageField.hidden = !supportsPackage;
      }
      if (packagePreset) {
        packagePreset.disabled = !supportsPackage;
        if (!supportsPackage) {
          packagePreset.value = '';
        }
      }

      if (usageReasonField) {
        usageReasonField.hidden = !showsReason;
      }
      if (usageReason) {
        usageReason.disabled = !showsReason;
        usageReason.required = showsReason;
      }

      if (proofField) {
        proofField.hidden = !showsProof;
      }
      if (proofInput) {
        proofInput.disabled = !showsProof;
        proofInput.required = showsProof && proofRequired;
      }
      if (proofRequirement) {
        proofRequirement.textContent = proofRequired ? '· Required' : '· Optional';
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

      syncCustomReason();
    };

    const selectedConversion = () => {
      if (!packagePreset || packagePreset.disabled) {
        return 1;
      }

      return Math.max(0, parseNumber(packagePreset.selectedOptions[0]?.dataset.conversion || '1')) || 1;
    };

    const syncCustomReason = () => {
      const option = usageReason?.selectedOptions[0];
      const needsCustom = !usageReason?.disabled && option?.dataset.requiresCustom === '1';
      if (customReasonField) {
        customReasonField.hidden = !needsCustom;
      }
      if (customReason) {
        customReason.disabled = !needsCustom;
        customReason.required = needsCustom;
        if (!needsCustom) {
          customReason.value = '';
        }
      }
    };

    const syncMovementState = () => {
      const type = movementType.value;
      const rawQuantity = parseNumber(quantityInput.value);
      const conversion = type === 'adjustment' ? 1 : selectedConversion();
      const absoluteQuantity = Math.abs(rawQuantity * conversion);
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
        quantityHint.textContent = `${formatQuantity(Math.abs(rawQuantity))} × ${formatQuantity(conversion)} = ${formatQuantity(absoluteQuantity)} ${currentUnit} added.`;
        invalid = !destinationId;
      } else if (type === 'transfer') {
        delta = 0;
        projectedBalance = currentQuantity;
        sourceAfter = sourceId ? sourceCurrent - absoluteQuantity : null;
        destinationAfter = destinationId ? destinationCurrent + absoluteQuantity : null;
        quantityHint.textContent = `${formatQuantity(Math.abs(rawQuantity))} × ${formatQuantity(conversion)} = ${formatQuantity(absoluteQuantity)} ${currentUnit} transferred.`;
        invalid = !sourceId || !destinationId || sourceId === destinationId || sourceAfter === null || sourceAfter < 0;
      } else {
        delta = -absoluteQuantity;
        projectedBalance = currentQuantity + delta;
        sourceAfter = sourceId ? sourceCurrent - absoluteQuantity : null;
        quantityHint.textContent = `${formatQuantity(Math.abs(rawQuantity))} × ${formatQuantity(conversion)} = ${formatQuantity(absoluteQuantity)} ${currentUnit} used.`;
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
    packagePreset?.addEventListener('change', syncMovementState);
    usageReason?.addEventListener('change', () => {
      syncCustomReason();
      syncMovementState();
    });
    sourceStorage?.addEventListener('change', () => {
      syncUsageReasons();
      syncCustomReason();
      syncMovementState();
    });
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
        currentUnit = movementForm.dataset.baseUnit || currentUnit;

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

        const stockPositions = payload.item.stock_positions || {};
        summary.querySelectorAll('[data-stock-position]').forEach((positionElement) => {
          const positionKey = positionElement.dataset.stockPosition;

          if (positionKey && stockPositions[positionKey] !== undefined) {
            positionElement.textContent = `${stockPositions[positionKey]} ${currentUnit}`;
          }
        });

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

        if (packagePreset) {
          packagePreset.value = '';
        }

        if (usageReason) {
          usageReason.selectedIndex = 0;
        }

        if (customReason) {
          customReason.value = '';
        }

        if (proofInput) {
          proofInput.value = '';
        }

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
        document.dispatchEvent(new CustomEvent('inventory:action-complete'));
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
  });
};

export const init = (root = document) => {
  initMovementForm(root);
};
