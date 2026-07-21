import {
  browserOcrTextFromFiles,
  confidenceClass,
  confidenceScore,
  csrfToken,
  escapeHtml,
  formatCount,
  formatMoney,
  formatNumber,
  formatQuantity,
  localDateTimeValue,
  looksLikeScanCode,
  ocrConfidenceMarkup,
  parseNumber,
  postPurchaseOcr,
  showGlobalFlash,
} from '../core/runtime.js';
import { confirmDialog } from '../ui/dialogs.js';

import { initSupplierTypeOtherFields } from './suppliers.js';

export const initHandoverCloseForms = (root = document) => {
  root.querySelectorAll('[data-handover-close-form]').forEach((form) => {
    if (form.dataset.handoverBound === 'true') {
      return;
    }

    const toggleOtherField = (row) => {
      const reason = row.querySelector('[data-handover-usage-reason]');
      const other = row.querySelector('[data-handover-usage-other]');
      const otherWrapper = row.querySelector('[data-handover-usage-other-field]');

      if (!(reason instanceof HTMLSelectElement) || !(other instanceof HTMLInputElement)) {
        return;
      }

      const isOther = reason.value === 'other';
      other.hidden = !isOther;

      if (otherWrapper instanceof HTMLElement) {
        otherWrapper.hidden = !isOther;
      }

      if (!isOther) {
        other.value = '';
      }
    };

    const usageRowHasMeaning = (row) => {
      const reason = row.querySelector('[data-handover-usage-reason]');
      const quantity = row.querySelector('[data-handover-usage-quantity]');
      const other = row.querySelector('[data-handover-usage-other]');
      const note = row.querySelector('input[name^="line_usage_notes"]');
      const reasonValue = reason instanceof HTMLSelectElement ? reason.value : 'unspecified';
      const quantityValue = quantity instanceof HTMLInputElement ? parseNumber(quantity.value) : 0;

      return quantityValue > 0
        || (reasonValue && reasonValue !== 'unspecified')
        || (other instanceof HTMLInputElement && other.value.trim() !== '')
        || (note instanceof HTMLInputElement && note.value.trim() !== '');
    };

    const sumUsageRows = (editor, exceptRow = null) => {
      let total = 0;

      editor.querySelectorAll('[data-handover-usage-row]').forEach((row) => {
        if (exceptRow && row === exceptRow) {
          return;
        }

        const field = row.querySelector('[data-handover-usage-quantity]');

        if (field instanceof HTMLInputElement) {
          total += Math.max(0, parseNumber(field.value));
        }
      });

      return Math.round(total * 100) / 100;
    };

    const syncEditor = (editor) => {
      const usedField = editor.querySelector('[data-handover-used]');
      const closeLine = editor.closest('[data-handover-close-line]') || editor.closest('tr');
      const returnedField = closeLine?.querySelector('[data-handover-returned]');
      const cardUsedLabel = closeLine?.querySelector('[data-handover-card-used]');
      const cardReturnedLabel = closeLine?.querySelector('[data-handover-card-returned]');
      const totalLabel = editor.querySelector('[data-handover-used-total]');
      const warning = editor.querySelector('[data-handover-usage-warning]');

      if (!(usedField instanceof HTMLInputElement)) {
        return;
      }

      const handed = parseNumber(usedField.dataset.handoverHanded || '0');
      const returned = returnedField instanceof HTMLInputElement ? parseNumber(returnedField.value) : 0;
      const hasInvalidReturn = returned < 0 || returned > handed;
      const used = Math.round(Math.max(0, handed - Math.max(0, returned)) * 100) / 100;
      const reasonTotal = sumUsageRows(editor);
      const hasReasonRows = Array.from(editor.querySelectorAll('[data-handover-usage-row]')).some((row) => usageRowHasMeaning(row));
      const hasReasonMismatch = hasReasonRows && Math.abs(reasonTotal - used) >= 0.01;

      usedField.value = formatQuantity(used);

      if (totalLabel instanceof HTMLElement) {
        totalLabel.textContent = formatQuantity(used);
      }

      if (cardUsedLabel instanceof HTMLElement) {
        cardUsedLabel.textContent = formatQuantity(used);
      }

      if (cardReturnedLabel instanceof HTMLElement) {
        cardReturnedLabel.textContent = formatQuantity(returned);
      }

      if (warning instanceof HTMLElement) {
        warning.hidden = !hasInvalidReturn && !hasReasonMismatch;
      }

      if (returnedField instanceof HTMLInputElement) {
        returnedField.classList.toggle('is-invalid', hasInvalidReturn);
      }

      closeLine?.classList?.toggle('has-usage-mismatch', hasReasonMismatch);
      closeLine?.classList?.toggle('has-return-mismatch', hasInvalidReturn);
    };

    const fillCalculatedUsageQuantity = (editor) => {
      const usedField = editor.querySelector('[data-handover-used]');

      if (!(usedField instanceof HTMLInputElement)) {
        return;
      }

      const used = Math.max(0, parseNumber(usedField.value));
      const rows = Array.from(editor.querySelectorAll('[data-handover-usage-row]'));

      if (rows.length === 0) {
        return;
      }

      const meaningfulRows = rows.filter((row) => usageRowHasMeaning(row));

      if (meaningfulRows.length > 1) {
        return;
      }

      const targetRow = meaningfulRows[0] || rows[0];

      if (!(targetRow instanceof HTMLElement)) {
        return;
      }

      const quantity = targetRow.querySelector('[data-handover-usage-quantity]');

      if (quantity instanceof HTMLInputElement) {
        quantity.value = used > 0 ? formatQuantity(used) : '';
      }
    };

    const bindUsageRow = (row, editor) => {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      row.querySelectorAll('input, select').forEach((field) => {
        field.addEventListener('input', () => syncEditor(editor));
        field.addEventListener('change', () => {
          toggleOtherField(row);
          syncEditor(editor);
        });
      });

      const removeButton = row.querySelector('[data-remove-handover-usage]');

      if (removeButton instanceof HTMLButtonElement) {
        removeButton.addEventListener('click', () => {
          const rows = Array.from(editor.querySelectorAll('[data-handover-usage-row]'));

          if (rows.length <= 1) {
            row.querySelectorAll('input').forEach((field) => {
              if (field instanceof HTMLInputElement) {
                field.value = '';
              }
            });
            const reason = row.querySelector('[data-handover-usage-reason]');
            if (reason instanceof HTMLSelectElement) {
              reason.value = 'unspecified';
            }
            toggleOtherField(row);
            syncEditor(editor);
            return;
          }

          row.remove();
          syncEditor(editor);
        });
      }

      toggleOtherField(row);
    };

    const applyQuickReason = (editor, reasonValue) => {
      const rows = Array.from(editor.querySelectorAll('[data-handover-usage-row]'));
      let targetRow = rows.find((row) => {
        const quantity = row.querySelector('[data-handover-usage-quantity]');
        return quantity instanceof HTMLInputElement && quantity.value.trim() === '';
      });

      if (!targetRow) {
        targetRow = rows[rows.length - 1];
      }

      if (!(targetRow instanceof HTMLElement)) {
        return;
      }

      const reason = targetRow.querySelector('[data-handover-usage-reason]');
      const quantity = targetRow.querySelector('[data-handover-usage-quantity]');
      const usedField = editor.querySelector('[data-handover-used]');
      const remainingUsed = Math.max(0, parseNumber(usedField instanceof HTMLInputElement ? usedField.value : '0') - sumUsageRows(editor, targetRow));

      if (reason instanceof HTMLSelectElement) {
        reason.value = reasonValue;
      }

      if (quantity instanceof HTMLInputElement && quantity.value.trim() === '' && remainingUsed > 0) {
        quantity.value = formatQuantity(remainingUsed);
      }

      toggleOtherField(targetRow);
      syncEditor(editor);

      if (quantity instanceof HTMLInputElement) {
        quantity.focus();
        quantity.select();
      }
    };

    form.dataset.handoverBound = 'true';

    form.querySelectorAll('[data-handover-usage-editor]').forEach((editor) => {
      if (!(editor instanceof HTMLElement)) {
        return;
      }

      editor.querySelectorAll('[data-handover-usage-row]').forEach((row) => bindUsageRow(row, editor));

      const closeLine = editor.closest('[data-handover-close-line]') || editor.closest('tr');
      const returnedField = closeLine?.querySelector('[data-handover-returned]');

      if (returnedField instanceof HTMLInputElement) {
        const syncReturnedUsage = () => {
          syncEditor(editor);
          fillCalculatedUsageQuantity(editor);
          syncEditor(editor);
        };

        returnedField.addEventListener('input', syncReturnedUsage);
        returnedField.addEventListener('change', syncReturnedUsage);
      }

      const addButton = editor.querySelector('[data-add-handover-usage]');
      const template = editor.querySelector('[data-handover-usage-template]');
      const list = editor.querySelector('[data-handover-usage-list]');

      if (addButton instanceof HTMLButtonElement && template instanceof HTMLTemplateElement && list instanceof HTMLElement) {
        addButton.addEventListener('click', () => {
          const fragment = template.content.cloneNode(true);
          const row = fragment.querySelector('[data-handover-usage-row]');
          list.appendChild(fragment);

          if (row instanceof HTMLElement) {
            bindUsageRow(row, editor);
            const quantity = row.querySelector('[data-handover-usage-quantity]');
            if (quantity instanceof HTMLInputElement) {
              quantity.focus();
            }
          }

          syncEditor(editor);
        });
      }

      editor.querySelectorAll('[data-handover-usage-quick-reason]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
          return;
        }

        button.addEventListener('click', () => {
          applyQuickReason(editor, button.dataset.handoverUsageQuickReason || 'unspecified');
        });
      });

      syncEditor(editor);
    });

    form.addEventListener('submit', (event) => {
      const invalidLine = Array.from(form.querySelectorAll('[data-handover-close-line]')).find((line) => {
        return line.classList.contains('has-usage-mismatch') || line.classList.contains('has-return-mismatch');
      });

      if (invalidLine) {
        event.preventDefault();
        event.stopPropagation();
        showGlobalFlash('Fix returned quantity or usage reason totals before submitting.', 'danger');
        invalidLine.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  });
};

export const initHandoverApprovalForms = (root = document) => {
  root.querySelectorAll('[data-handover-approval-form]').forEach((form) => {
    if (form.dataset.handoverApprovalBound === 'true') {
      return;
    }

    const toggleOtherField = (row) => {
      const reason = row.querySelector('[data-handover-usage-reason]');
      const other = row.querySelector('[data-handover-usage-other]');
      const otherWrapper = row.querySelector('[data-handover-usage-other-field]');

      if (!(reason instanceof HTMLSelectElement) || !(other instanceof HTMLInputElement)) {
        return;
      }

      const isOther = reason.value === 'other';
      other.hidden = !isOther;

      if (otherWrapper instanceof HTMLElement) {
        otherWrapper.hidden = !isOther;
      }

      if (!isOther) {
        other.value = '';
      }
    };

    const sumUsageRows = (line) => {
      let total = 0;

      line.querySelectorAll('[data-handover-usage-quantity]').forEach((field) => {
        if (field instanceof HTMLInputElement) {
          total += Math.max(0, parseNumber(field.value));
        }
      });

      return Math.round(total * 100) / 100;
    };

    const sumUsageRowsExcept = (line, exceptRow = null) => {
      let total = 0;

      line.querySelectorAll('[data-handover-usage-row]').forEach((row) => {
        if (exceptRow && row === exceptRow) {
          return;
        }

        const field = row.querySelector('[data-handover-usage-quantity]');

        if (field instanceof HTMLInputElement) {
          total += Math.max(0, parseNumber(field.value));
        }
      });

      return Math.round(total * 100) / 100;
    };

    const usageRowHasMeaning = (row) => {
      const reason = row.querySelector('[data-handover-usage-reason]');
      const quantity = row.querySelector('[data-handover-usage-quantity]');
      const other = row.querySelector('[data-handover-usage-other]');
      const note = row.querySelector('input[name^="line_usage_notes"]');
      const reasonValue = reason instanceof HTMLSelectElement ? reason.value : 'unspecified';
      const quantityValue = quantity instanceof HTMLInputElement ? parseNumber(quantity.value) : 0;

      return quantityValue > 0
        || (reasonValue !== '' && reasonValue !== 'unspecified')
        || (other instanceof HTMLInputElement && other.value.trim() !== '')
        || (note instanceof HTMLInputElement && note.value.trim() !== '');
    };

    const fillApprovalCalculatedUsageQuantity = (line, editor) => {
      const targetUsed = Math.max(0, parseNumber(editor.dataset.handoverApprovalTargetUsed || '0'));
      const rows = Array.from(editor.querySelectorAll('[data-handover-usage-row]'));

      if (rows.length === 0) {
        return;
      }

      const meaningfulRows = rows.filter((row) => row instanceof HTMLElement && usageRowHasMeaning(row));

      if (meaningfulRows.length > 1) {
        return;
      }

      const targetRow = meaningfulRows[0] || rows[0];

      if (!(targetRow instanceof HTMLElement)) {
        return;
      }

      const quantity = targetRow.querySelector('[data-handover-usage-quantity]');

      if (quantity instanceof HTMLInputElement) {
        quantity.value = targetUsed > 0 ? formatQuantity(targetUsed) : '';
      }
    };

    const syncLine = (line) => {
      const returnedField = line.querySelector('[data-handover-approval-returned]');
      const usedLabel = line.querySelector('[data-handover-approval-used]');
      const reasonTotalLabel = line.querySelector('[data-handover-approval-reason-total]');
      const warning = line.querySelector('[data-handover-approval-warning]');
      const usageWarning = line.querySelector('[data-handover-approval-usage-warning]');
      const editor = line.querySelector('[data-handover-approval-usage-editor]');

      if (!(returnedField instanceof HTMLInputElement)) {
        return;
      }

      const received = Math.max(0, parseNumber(returnedField.dataset.handoverReceived || '0'));
      const returned = parseNumber(returnedField.value);
      const isInvalid = returned < 0 || returned > received;
      const used = Math.round(Math.max(0, received - Math.max(0, returned)) * 100) / 100;
      const reasonTotal = sumUsageRows(line);
      const reasonMismatch = Math.abs(reasonTotal - used) >= 0.01;

      if (usedLabel instanceof HTMLElement) {
        usedLabel.textContent = formatQuantity(used);
      }

      if (reasonTotalLabel instanceof HTMLElement) {
        reasonTotalLabel.textContent = formatQuantity(reasonTotal);
      }

      if (editor instanceof HTMLElement) {
        editor.dataset.handoverApprovalTargetUsed = formatQuantity(used);
      }

      if (warning instanceof HTMLElement) {
        warning.hidden = !isInvalid;
      }

      if (usageWarning instanceof HTMLElement) {
        usageWarning.hidden = !reasonMismatch;
      }

      returnedField.classList.toggle('is-invalid', isInvalid);
      line.classList.toggle('has-usage-mismatch', reasonMismatch);
      line.classList.toggle('has-return-mismatch', isInvalid);
    };

    const bindUsageRow = (row, line) => {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      row.querySelectorAll('input, select').forEach((field) => {
        field.addEventListener('input', () => syncLine(line));
        field.addEventListener('change', () => {
          toggleOtherField(row);
          syncLine(line);
        });
      });

      const removeButton = row.querySelector('[data-remove-handover-usage]');

      if (removeButton instanceof HTMLButtonElement) {
        removeButton.addEventListener('click', () => {
          const editor = row.closest('[data-handover-usage-editor]');
          const rows = editor ? Array.from(editor.querySelectorAll('[data-handover-usage-row]')) : [];

          if (rows.length <= 1) {
            row.querySelectorAll('input').forEach((field) => {
              if (field instanceof HTMLInputElement) {
                field.value = '';
              }
            });
            const reason = row.querySelector('[data-handover-usage-reason]');
            if (reason instanceof HTMLSelectElement) {
              reason.value = 'unspecified';
            }
            toggleOtherField(row);
            syncLine(line);
            return;
          }

          row.remove();
          syncLine(line);
        });
      }

      toggleOtherField(row);
    };

    const applyQuickReason = (line, editor, reasonValue) => {
      const rows = Array.from(editor.querySelectorAll('[data-handover-usage-row]'));
      let targetRow = rows.find((row) => {
        const quantity = row.querySelector('[data-handover-usage-quantity]');
        return quantity instanceof HTMLInputElement && quantity.value.trim() === '';
      });

      if (!targetRow) {
        targetRow = rows[rows.length - 1];
      }

      if (!(targetRow instanceof HTMLElement)) {
        return;
      }

      const reason = targetRow.querySelector('[data-handover-usage-reason]');
      const quantity = targetRow.querySelector('[data-handover-usage-quantity]');
      const targetUsed = parseNumber(editor.dataset.handoverApprovalTargetUsed || '0');
      const remainingUsed = Math.max(0, targetUsed - sumUsageRowsExcept(line, targetRow));

      if (reason instanceof HTMLSelectElement) {
        reason.value = reasonValue;
      }

      if (quantity instanceof HTMLInputElement && quantity.value.trim() === '' && remainingUsed > 0) {
        quantity.value = formatQuantity(remainingUsed);
      }

      toggleOtherField(targetRow);
      syncLine(line);

      if (quantity instanceof HTMLInputElement) {
        quantity.focus();
        quantity.select();
      }
    };

    form.dataset.handoverApprovalBound = 'true';

    form.querySelectorAll('[data-handover-approval-line]').forEach((line) => {
      if (!(line instanceof HTMLElement)) {
        return;
      }

      const returnedField = line.querySelector('[data-handover-approval-returned]');

      if (returnedField instanceof HTMLInputElement) {
        const syncReturnedApproval = () => {
          syncLine(line);
          const editor = line.querySelector('[data-handover-usage-editor]');

          if (editor instanceof HTMLElement) {
            fillApprovalCalculatedUsageQuantity(line, editor);
            syncLine(line);
          }
        };

        returnedField.addEventListener('input', syncReturnedApproval);
        returnedField.addEventListener('change', syncReturnedApproval);
      }

      const editor = line.querySelector('[data-handover-usage-editor]');

      if (editor instanceof HTMLElement) {
        editor.querySelectorAll('[data-handover-usage-row]').forEach((row) => bindUsageRow(row, line));

        const addButton = editor.querySelector('[data-add-handover-usage]');
        const template = editor.querySelector('[data-handover-usage-template]');
        const list = editor.querySelector('[data-handover-usage-list]');

        if (addButton instanceof HTMLButtonElement && template instanceof HTMLTemplateElement && list instanceof HTMLElement) {
          addButton.addEventListener('click', () => {
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-handover-usage-row]');
            list.appendChild(fragment);

            if (row instanceof HTMLElement) {
              bindUsageRow(row, line);
              const quantity = row.querySelector('[data-handover-usage-quantity]');
              if (quantity instanceof HTMLInputElement) {
                quantity.focus();
              }
            }

            syncLine(line);
          });
        }

        editor.querySelectorAll('[data-handover-usage-quick-reason]').forEach((button) => {
          if (!(button instanceof HTMLButtonElement)) {
            return;
          }

          button.addEventListener('click', () => {
            applyQuickReason(line, editor, button.dataset.handoverUsageQuickReason || 'unspecified');
          });
        });
      }

      syncLine(line);
    });

    form.addEventListener('submit', (event) => {
      const invalidLine = Array.from(form.querySelectorAll('[data-handover-approval-line]')).find((line) => {
        return line.classList.contains('has-usage-mismatch') || line.classList.contains('has-return-mismatch');
      });

      if (invalidLine) {
        event.preventDefault();
        event.stopPropagation();
        showGlobalFlash('Fix returned quantity and usage reason totals before approving.', 'danger');
      }
    }, true);
  });
};

export const initHandoverReceiptReviews = (root = document) => {
  root.querySelectorAll('[data-handover-receipt-review]').forEach((form) => {
    if (!(form instanceof HTMLFormElement) || form.dataset.handoverReceiptReviewBound === 'true') {
      return;
    }

    form.dataset.handoverReceiptReviewBound = 'true';
    form.querySelectorAll('[data-handover-receipt-confirmed]').forEach((field) => {
      if (!(field instanceof HTMLInputElement)) {
        return;
      }

      const row = field.closest('tr');
      const difference = row?.querySelector('[data-handover-receipt-difference]');

      const syncDifference = () => {
        const planned = Math.max(0, parseNumber(field.dataset.handoverPlanned || '0'));
        const confirmed = parseNumber(field.value);
        const invalid = confirmed < 0 || confirmed > planned;
        const shortage = Math.max(0, Math.round((planned - Math.max(0, confirmed)) * 100) / 100);

        field.classList.toggle('is-invalid', invalid);

        if (difference instanceof HTMLElement) {
          difference.textContent = formatQuantity(shortage);
        }
      };

      field.addEventListener('input', syncDifference);
      field.addEventListener('change', syncDifference);
      syncDifference();
    });
  });
};

export const initMovementForm = (root = document) => {
  root.querySelectorAll('[data-movement-form]').forEach((movementForm) => {
    if (movementForm.dataset.jsBound === 'true') {
      return;
    }

    const movementType = movementForm.querySelector('[data-movement-type]');
    const quantityInput = movementForm.querySelector('[data-quantity-input]');
    const quantityHint = movementForm.querySelector('[data-quantity-hint]');
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
    let currentUnit = summary.dataset.unit || 'pcs';
    let locationBalances = {};

    try {
      locationBalances = JSON.parse(summary.dataset.balanceMap || '{}');
    } catch (error) {
      locationBalances = {};
    }

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

export const initWorkflowLineBuilders = (root = document) => {
  root.querySelectorAll('[data-workflow-line-builder]').forEach((builder) => {
    if (builder.dataset.jsBound === 'true') {
      return;
    }

    const form = builder.closest('form');
    const storageSelect = form ? form.querySelector('[data-workflow-storage]') : null;
    const ownerSelect = form ? form.querySelector('[data-workflow-owner-select]') : null;
    const body = builder.querySelector('[data-workflow-line-body]');
    const addButton = builder.querySelector('[data-add-workflow-line]');
    const lockedOwnerId = builder.dataset.lockedOwnerId || '';

    if (!form || !storageSelect || !body || !addButton) {
      return;
    }

    let catalog = {};
    let storageMeta = {};
    const hideAvailability = builder.dataset.hideAvailability === 'true';
    const hideItemQuantity = builder.dataset.hideItemQuantity === 'true';
    const ownerName = form.querySelector('[data-request-owner-name]');
    const ownerCopy = form.querySelector('[data-request-owner-copy]');
    const hasExpectedUsage = () => builder.dataset.expectedUsage === 'true';
    let usageReasons = {
      unspecified: 'Unspecified',
      walkin: 'Walk-in',
      online: 'Online',
      event: 'Event',
      damage: 'Damage',
      sport: 'Sport',
      school: 'School',
      complimentary: 'Complimentary',
      noshow: 'No Show',
      other: 'Other'
    };

    try {
      catalog = JSON.parse(builder.dataset.storageCatalog || '{}');
    } catch (error) {
      catalog = {};
    }

    try {
      storageMeta = JSON.parse(builder.dataset.storageMeta || '{}');
    } catch (error) {
      storageMeta = {};
    }

    try {
      usageReasons = JSON.parse(builder.dataset.usageReasons || '{}') || usageReasons;
    } catch (error) {
      usageReasons = {
        unspecified: 'Unspecified',
        walkin: 'Walk-in',
        online: 'Online',
        event: 'Event',
        damage: 'Damage',
        sport: 'Sport',
        school: 'School',
        complimentary: 'Complimentary',
        noshow: 'No Show',
        other: 'Other'
      };
    }

    const currentItems = () => catalog[String(storageSelect.value)] || [];

    const findSelectedItem = (itemId) => currentItems().find((item) => String(item.id) === String(itemId || '')) || null;

    const usageReasonOptionsMarkup = (selected = 'unspecified') => Object.entries(usageReasons).map(([value, label]) => (
      `<option value="${escapeHtml(value)}"${String(value) === String(selected) ? ' selected' : ''}>${escapeHtml(label)}</option>`
    )).join('');

    const expectedUsageRowMarkup = () => `
      <div class="handover-expected-usage-row" data-expected-usage-row>
        <select class="handover-expected-field" data-expected-usage-reason data-expected-usage-name="expected_usage_reason">
          ${usageReasonOptionsMarkup()}
        </select>
        <input class="handover-expected-field" type="number" step="0.01" min="0" placeholder="Expected qty" data-expected-usage-name="expected_usage_quantity">
        <input class="handover-expected-field" type="text" placeholder="Other reason" data-expected-usage-other data-expected-usage-name="expected_usage_other" hidden>
        <input class="handover-expected-field" type="text" placeholder="Optional note" data-expected-usage-name="expected_usage_notes">
        <button class="handover-expected-remove" type="button" data-remove-expected-usage>Remove</button>
      </div>
    `;

    const expectedUsageEditorMarkup = () => {
      if (!hasExpectedUsage()) {
        return '';
      }

      return `
        <details class="handover-expected-usage" data-expected-usage-editor open>
          <summary><span>Expected usage plan</span></summary>
          <p class="tiny-copy">Optional: split what you expect to use before the handover, like Online 250 and Walk-in 30.</p>
          <div class="handover-expected-usage-list" data-expected-usage-list>
            ${expectedUsageRowMarkup()}
          </div>
          <button class="ghost-button compact-button handover-expected-add" type="button" data-add-expected-usage><span>Add Expected Usage</span></button>
        </details>
      `;
    };

    const toggleExpectedOtherField = (row) => {
      const reason = row.querySelector('[data-expected-usage-reason]');
      const other = row.querySelector('[data-expected-usage-other]');

      if (!(reason instanceof HTMLSelectElement) || !(other instanceof HTMLInputElement)) {
        return;
      }

      const isOther = reason.value === 'other';
      other.hidden = !isOther;

      if (!isOther) {
        other.value = '';
      }
    };

    const renumberExpectedUsageFields = () => {
      body.querySelectorAll('[data-workflow-line]').forEach((line, lineIndex) => {
        line.querySelectorAll('[data-expected-usage-name]').forEach((field) => {
          if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement)) {
            return;
          }

          const baseName = field.getAttribute('data-expected-usage-name');

          if (baseName) {
            field.name = `${baseName}[${lineIndex}][]`;
          }
        });
      });
    };

    const closePanels = (exceptPanel = null) => {
      body.querySelectorAll('[data-workflow-picker-panel]').forEach((panel) => {
        if (panel !== exceptPanel) {
          panel.hidden = true;
          const picker = panel.closest('[data-workflow-picker]');
          if (picker instanceof HTMLElement) {
            picker.classList.remove('is-open');
          }
        }
      });
    };

    const selectedOwnerId = () => {
      if (lockedOwnerId !== '') {
        return lockedOwnerId;
      }

      return ownerSelect instanceof HTMLSelectElement ? String(ownerSelect.value || '') : '';
    };

    const filterStorageOptions = () => {
      if (!(storageSelect instanceof HTMLSelectElement)) {
        return;
      }

      const requiredOwnerId = selectedOwnerId();
      let hasVisibleStorage = false;

      Array.from(storageSelect.options).forEach((option) => {
        if (option.value === '') {
          option.hidden = false;
          return;
        }

        const meta = storageMeta[String(option.value)] || null;
        const matchesOwner = requiredOwnerId === '' || String(meta?.owner_user_id || '') === requiredOwnerId;
        option.hidden = !matchesOwner;

        if (!matchesOwner && option.selected) {
          option.selected = false;
        }

        if (matchesOwner) {
          hasVisibleStorage = true;
        }
      });

      const requiresOwnerSelection = ownerSelect instanceof HTMLSelectElement && lockedOwnerId === '' && selectedOwnerId() === '';
      storageSelect.disabled = requiresOwnerSelection || !hasVisibleStorage;

      if (storageSelect.disabled) {
        storageSelect.value = '';
      }
    };

    const updateItemFieldRequirement = (line) => {
      const itemInput = line.querySelector('[data-workflow-item-input]');

      if (!(itemInput instanceof HTMLInputElement)) {
        return;
      }

      const hasStorage = storageSelect.value !== '' && !storageSelect.disabled;
      itemInput.disabled = !hasStorage;
      itemInput.required = hasStorage;
    };

    const renderSelectedLabel = (item) => {
      if (!item) {
        return '<span class="workflow-picker-placeholder">Select source item first</span>';
      }

      const previewImage = item.image_url
        ? `<img class="workflow-picker-thumb" src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}">`
        : `<span class="workflow-picker-thumb workflow-picker-thumb-fallback">${escapeHtml((item.name || '?').charAt(0).toUpperCase())}</span>`;

      return `
        <span class="workflow-picker-selected">
          ${previewImage}
          <span>
            <strong>${escapeHtml(item.name)}</strong>
            <span class="tiny-copy">${escapeHtml(item.sku)}${item.barcode ? ` · ${escapeHtml(item.barcode)}` : ''} · ${escapeHtml(item.unit)}</span>
          </span>
        </span>
      `;
    };

    const renderOptions = (line, query = '') => {
      const optionsWrap = line.querySelector('[data-workflow-picker-options]');
      const itemInput = line.querySelector('[data-workflow-item-input]');

      if (!optionsWrap || !(itemInput instanceof HTMLInputElement)) {
        return;
      }

      if (!storageSelect.value || storageSelect.disabled) {
        optionsWrap.innerHTML = '<div class="workflow-picker-empty">Select a source storage first.</div>';
        return;
      }

      const normalizedQuery = String(query || '').trim().toLowerCase();
      const options = currentItems().filter((item) => {
        if (normalizedQuery === '') {
          return true;
        }

        return [item.name, item.sku, item.barcode, item.unit].join(' ').toLowerCase().includes(normalizedQuery);
      });

      if (options.length === 0) {
        optionsWrap.innerHTML = '<div class="workflow-picker-empty">No matching items in this storage.</div>';
        return;
      }

      optionsWrap.innerHTML = options.map((item) => {
        const selected = String(item.id) === String(itemInput.value || '');
        const image = item.image_url
          ? `<img class="workflow-picker-thumb" src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}">`
          : `<span class="workflow-picker-thumb workflow-picker-thumb-fallback">${escapeHtml((item.name || '?').charAt(0).toUpperCase())}</span>`;
        const quantityCopy = hideItemQuantity ? '' : `<span class="tiny-copy">${escapeHtml(formatQuantity(parseNumber(item.quantity)))} ${escapeHtml(item.unit)} available</span>`;

        return `
          <button class="workflow-picker-option${selected ? ' is-selected' : ''}" type="button" data-workflow-option data-item-id="${escapeHtml(item.id)}">
            ${image}
            <span>
              <strong>${escapeHtml(item.name)}</strong>
              <span class="tiny-copy">${escapeHtml(item.sku)}${item.barcode ? ` · ${escapeHtml(item.barcode)}` : ''}</span>
              ${quantityCopy}
            </span>
          </button>
        `;
      }).join('');
    };

    const syncAvailability = (line, item = null) => {
      const available = line.querySelector('[data-workflow-available]');

      if (!available) {
        return;
      }

      if (!item) {
        available.textContent = '-';
        available.classList.remove('danger-text');
        return;
      }

      const quantity = formatQuantity(parseNumber(item.quantity));
      const unit = item.unit || '';
      available.textContent = `${quantity} ${unit}`.trim();
      available.classList.toggle('danger-text', parseNumber(item.quantity) <= 0);
    };

    const syncLine = (line) => {
      const itemInput = line.querySelector('[data-workflow-item-input]');
      const label = line.querySelector('[data-workflow-picker-label]');
      const search = line.querySelector('[data-workflow-picker-search]');

      if (!(itemInput instanceof HTMLInputElement) || !label) {
        return;
      }

      const selectedItem = findSelectedItem(itemInput.value);

      if (!selectedItem) {
        itemInput.value = '';
      }

      updateItemFieldRequirement(line);
      label.innerHTML = renderSelectedLabel(selectedItem);
      renderOptions(line, search instanceof HTMLInputElement ? search.value : '');
      syncAvailability(line, selectedItem);
    };

    const syncOwnerCard = () => {
      if (!ownerName || !ownerCopy) {
        return;
      }

      const meta = storageMeta[String(storageSelect.value)] || null;

      if (!meta) {
        ownerName.textContent = 'Select a source storage';
        ownerCopy.textContent = 'The storage owner will approve this request.';
        return;
      }

      ownerName.textContent = meta.owner_name || 'Owner not assigned';
      ownerCopy.textContent = meta.owner_name
        ? `${meta.owner_name} owns ${meta.name} and will approve this request.`
        : `${meta.name} needs an owner admin before requests can be approved.`;
    };

    const addLine = (selectedItemId = '', quantity = '') => {
      const row = document.createElement('tr');
      row.setAttribute('data-workflow-line', '');
      row.innerHTML = `
        <td>
          <div class="workflow-picker" data-workflow-picker>
            <input type="hidden" name="${builder.dataset.lineNameItem || 'line_item_id[]'}" value="${escapeHtml(selectedItemId)}" data-workflow-item-input required>
            <button class="workflow-picker-toggle" type="button" data-workflow-picker-toggle>
              <span class="workflow-picker-toggle-copy" data-workflow-picker-label>Select source item first</span>
            </button>
            <div class="workflow-picker-panel" data-workflow-picker-panel hidden>
              <input class="workflow-picker-search" type="search" placeholder="Search item" data-workflow-picker-search>
              <div class="workflow-picker-options" data-workflow-picker-options></div>
            </div>
          </div>
          ${expectedUsageEditorMarkup()}
        </td>
        ${hideAvailability ? '' : '<td><span class="tiny-copy" data-workflow-available>-</span></td>'}
        <td>
          <input type="number" step="0.01" min="0.01" name="${builder.dataset.lineNameQuantity || 'line_quantity[]'}" value="${escapeHtml(quantity)}" required>
        </td>
        <td>
          <button class="text-button danger-link" type="button" data-remove-workflow-line>Remove</button>
        </td>
      `;
      body.appendChild(row);
      renumberExpectedUsageFields();
      syncLine(row);
    };

    const ensureOneLine = () => {
      const rows = body.querySelectorAll('[data-workflow-line]');

      if (rows.length === 0) {
        addLine();
      }
    };

    builder.dataset.jsBound = 'true';

    addButton.addEventListener('click', () => {
      addLine();
    });

    storageSelect.addEventListener('change', () => {
      syncOwnerCard();
      closePanels();
      body.querySelectorAll('[data-workflow-line]').forEach((line) => syncLine(line));
    });

    if (ownerSelect instanceof HTMLSelectElement) {
      ownerSelect.addEventListener('change', () => {
        filterStorageOptions();
        syncOwnerCard();
        closePanels();
        body.querySelectorAll('[data-workflow-line]').forEach((line) => syncLine(line));
      });
    }

    body.addEventListener('input', (event) => {
      const target = event.target;

      if (!(target instanceof HTMLElement)) {
        return;
      }

      if (target.matches('[data-workflow-picker-search]')) {
        const line = target.closest('[data-workflow-line]');

        if (line) {
          renderOptions(line, target.value);
        }
      }
    });

    body.addEventListener('change', (event) => {
      const target = event.target;

      if (!(target instanceof HTMLElement)) {
        return;
      }

      if (target.matches('[data-expected-usage-reason]')) {
        const usageRow = target.closest('[data-expected-usage-row]');

        if (usageRow instanceof HTMLElement) {
          toggleExpectedOtherField(usageRow);
        }
      }
    });

    body.addEventListener('click', (event) => {
      const target = event.target;

      if (!(target instanceof HTMLElement)) {
        return;
      }

      const addExpectedUsageButton = target.closest('[data-add-expected-usage]');

      if (addExpectedUsageButton) {
        const editor = addExpectedUsageButton.closest('[data-expected-usage-editor]');
        const list = editor ? editor.querySelector('[data-expected-usage-list]') : null;

        if (list instanceof HTMLElement) {
          list.insertAdjacentHTML('beforeend', expectedUsageRowMarkup());
          const lastRow = list.querySelector('[data-expected-usage-row]:last-child');
          if (lastRow instanceof HTMLElement) {
            toggleExpectedOtherField(lastRow);
            const qty = lastRow.querySelector('[data-expected-usage-name="expected_usage_quantity"]');
            if (qty instanceof HTMLInputElement) {
              qty.focus();
            }
          }
          renumberExpectedUsageFields();
        }

        return;
      }

      const removeExpectedUsageButton = target.closest('[data-remove-expected-usage]');

      if (removeExpectedUsageButton) {
        const usageRow = removeExpectedUsageButton.closest('[data-expected-usage-row]');
        const list = usageRow?.closest('[data-expected-usage-list]');

        if (usageRow instanceof HTMLElement && list instanceof HTMLElement) {
          const rows = Array.from(list.querySelectorAll('[data-expected-usage-row]'));

          if (rows.length <= 1) {
            usageRow.querySelectorAll('input').forEach((field) => {
              if (field instanceof HTMLInputElement) {
                field.value = '';
              }
            });
            const reason = usageRow.querySelector('[data-expected-usage-reason]');
            if (reason instanceof HTMLSelectElement) {
              reason.value = 'unspecified';
            }
            toggleExpectedOtherField(usageRow);
          } else {
            usageRow.remove();
          }

          renumberExpectedUsageFields();
        }

        return;
      }

      const optionButton = target.closest('[data-workflow-option]');

      if (optionButton) {
        const row = optionButton.closest('[data-workflow-line]');
        const itemInput = row ? row.querySelector('[data-workflow-item-input]') : null;
        const search = row ? row.querySelector('[data-workflow-picker-search]') : null;
        const panel = row ? row.querySelector('[data-workflow-picker-panel]') : null;

        if (row && itemInput instanceof HTMLInputElement) {
          itemInput.value = optionButton.getAttribute('data-item-id') || '';

          if (search instanceof HTMLInputElement) {
            search.value = '';
          }

          if (panel) {
            panel.hidden = true;
            const picker = panel.closest('[data-workflow-picker]');
            if (picker instanceof HTMLElement) {
              picker.classList.remove('is-open');
            }
          }

          syncLine(row);
        }

        return;
      }

      const toggleButton = target.closest('[data-workflow-picker-toggle]');

      if (toggleButton) {
        const row = toggleButton.closest('[data-workflow-line]');
        const panel = row ? row.querySelector('[data-workflow-picker-panel]') : null;
        const search = row ? row.querySelector('[data-workflow-picker-search]') : null;

        closePanels(panel);

        if (panel) {
          panel.hidden = !panel.hidden;
          const picker = panel.closest('[data-workflow-picker]');
          if (picker instanceof HTMLElement) {
            picker.classList.toggle('is-open', !panel.hidden);
          }
        }

        if (!panel?.hidden && search instanceof HTMLInputElement) {
          search.focus({ preventScroll: true });
          renderOptions(row, search.value);
        }

        return;
      }

      const removeButton = target.closest('[data-remove-workflow-line]');

      if (!removeButton) {
        return;
      }

      const row = removeButton.closest('[data-workflow-line]');

      if (row) {
        row.remove();
      }

      ensureOneLine();
      renumberExpectedUsageFields();
    });

    document.addEventListener('click', (event) => {
      const target = event.target;

      if (!(target instanceof Node)) {
        return;
      }

      const activePicker = target instanceof Element ? target.closest('[data-workflow-picker]') : null;

      if (!activePicker || !builder.contains(activePicker)) {
        closePanels();
      }
    });

    ensureOneLine();
    filterStorageOptions();
    syncOwnerCard();
    body.querySelectorAll('[data-expected-usage-row]').forEach((row) => {
      if (row instanceof HTMLElement) {
        toggleExpectedOtherField(row);
      }
    });
    renumberExpectedUsageFields();
    body.querySelectorAll('[data-workflow-line]').forEach((line) => syncLine(line));
  });
};

export const initHandoverTargetSwitchers = (root = document) => {
  root.querySelectorAll('[data-handover-target-switcher]').forEach((switcher) => {
    if (switcher.dataset.jsBound === 'true') {
      return;
    }

    const form = switcher.closest('form');
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    const radios = Array.from(form.querySelectorAll('[data-handover-target-radio]'))
      .filter((radio) => radio instanceof HTMLInputElement);
    const staffFields = Array.from(form.querySelectorAll('[data-handover-staff-fields]'));
    const storageFields = Array.from(form.querySelectorAll('[data-handover-storage-fields]'));
    const destinationSelect = form.querySelector('[data-handover-destination-storage]');
    const destinationCopy = form.querySelector('[data-handover-destination-copy]');
    const destinationSummary = form.querySelector('[data-handover-destination-summary]');
    const destinationSummaryName = form.querySelector('[data-handover-destination-summary-name]');
    const destinationSummaryOwner = form.querySelector('[data-handover-destination-summary-owner]');
    const sourceSelect = form.querySelector('[data-workflow-storage]');
    const lineBuilder = form.querySelector('[data-workflow-line-builder]');
    const formEyebrow = document.querySelector('[data-handover-form-eyebrow]');
    const formTitle = document.querySelector('[data-handover-form-title]');
    const linesTitle = form.querySelector('[data-handover-lines-title]');
    const notesField = form.querySelector('[data-handover-notes]');
    const submitLabel = form.querySelector('[data-handover-submit-label]');
    const modeCopy = {
      staff: {
        eyebrow: 'Temporary Issue',
        title: 'Create Handover',
        linesTitle: 'What You Handed Over',
        notesPlaceholder: 'Where this stock is going and why',
        submit: 'Create Handover'
      },
      storage: {
        eyebrow: 'Storage Transfer',
        title: 'Create Storage Transfer',
        linesTitle: 'What You Are Transferring',
        notesPlaceholder: 'Why this stock is moving to another storage',
        submit: 'Create Storage Transfer'
      }
    };

    const setSectionEnabled = (section, enabled) => {
      if (!(section instanceof HTMLElement)) {
        return;
      }

      section.hidden = !enabled;
      section.querySelectorAll('input, select, textarea, button').forEach((field) => {
        if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement || field instanceof HTMLButtonElement) {
          field.disabled = !enabled;
        }
      });
    };

    const syncDestinationCopy = (isStorage = true) => {
      if (!(destinationSelect instanceof HTMLSelectElement) || !destinationCopy) {
        return;
      }

      const selected = destinationSelect.selectedOptions[0];
      const hasSelection = Boolean(destinationSelect.value);
      const storageName = selected?.dataset?.storageName || selected?.textContent?.trim() || '';
      const storageType = selected?.dataset?.storageType || 'Storage';
      const ownerName = selected?.dataset?.ownerName || '';
      destinationCopy.textContent = ownerName
        ? `${ownerName} will confirm what arrived into this destination storage.`
        : 'Destination owner confirms what arrived. Same source and destination are blocked.';

      if (destinationSummary instanceof HTMLElement) {
        destinationSummary.hidden = !isStorage || !hasSelection;
      }

      if (destinationSummaryName) {
        destinationSummaryName.textContent = hasSelection
          ? `${storageType} · ${storageName}`
          : 'Destination storage';
      }

      if (destinationSummaryOwner) {
        destinationSummaryOwner.textContent = ownerName
          ? `Receipt owner: ${ownerName}`
          : 'Destination owner confirms receipt.';
      }
    };

    const syncExpectedUsage = (enabled) => {
      if (lineBuilder instanceof HTMLElement) {
        lineBuilder.dataset.expectedUsage = enabled ? 'true' : 'false';
      }

      form.querySelectorAll('[data-handover-transfer-sensitive], [data-expected-usage-editor]').forEach((editor) => {
        if (!(editor instanceof HTMLElement)) {
          return;
        }

        editor.hidden = !enabled;
        editor.querySelectorAll('input, select, textarea, button').forEach((field) => {
          if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement || field instanceof HTMLButtonElement) {
            field.disabled = !enabled;
          }
        });
      });
    };

    const sync = () => {
      const targetType = radios.find((radio) => radio.checked)?.value || 'staff';
      const isStorage = targetType === 'storage';
      const copy = isStorage ? modeCopy.storage : modeCopy.staff;

      if (formEyebrow) {
        formEyebrow.textContent = copy.eyebrow;
      }

      if (formTitle) {
        formTitle.textContent = copy.title;
      }

      if (linesTitle) {
        linesTitle.textContent = copy.linesTitle;
      }

      if (notesField instanceof HTMLTextAreaElement) {
        notesField.placeholder = copy.notesPlaceholder;
      }

      if (submitLabel) {
        submitLabel.textContent = copy.submit;
      }

      staffFields.forEach((section) => setSectionEnabled(section, !isStorage));
      storageFields.forEach((section) => setSectionEnabled(section, isStorage));

      if (destinationSelect instanceof HTMLSelectElement) {
        destinationSelect.required = isStorage;
        destinationSelect.disabled = !isStorage;
      }

      syncExpectedUsage(!isStorage);
      syncDestinationCopy(isStorage);

      if (isStorage && sourceSelect instanceof HTMLSelectElement && destinationSelect instanceof HTMLSelectElement && sourceSelect.value !== '' && sourceSelect.value === destinationSelect.value) {
        destinationSelect.setCustomValidity('Destination storage must be different from source storage.');
      } else if (destinationSelect instanceof HTMLSelectElement) {
        destinationSelect.setCustomValidity('');
      }
    };

    radios.forEach((radio) => radio.addEventListener('change', sync));

    if (destinationSelect instanceof HTMLSelectElement) {
      destinationSelect.addEventListener('change', sync);
    }

    if (sourceSelect instanceof HTMLSelectElement) {
      sourceSelect.addEventListener('change', sync);
    }

    switcher.dataset.jsBound = 'true';
    sync();
  });
};

export const initPurchaseLineBuilders = (root = document) => {
  root.querySelectorAll('[data-purchase-line-builder]').forEach((builder) => {
    if (builder.dataset.jsBound === 'true') {
      return;
    }

    const body = builder.querySelector('[data-purchase-line-body]');
    const addButton = builder.querySelector('[data-add-purchase-line]');

    if (!body || !addButton) {
      return;
    }

    let catalog = [];
    let suppliers = [];

    try {
      catalog = JSON.parse(builder.dataset.purchaseCatalog || '[]');
    } catch (error) {
      catalog = [];
    }

    try {
      suppliers = JSON.parse(builder.dataset.purchaseSuppliers || '[]');
    } catch (error) {
      suppliers = [];
    }

    const catalogById = new Map(catalog.map((item) => [String(item.id), item]));
    const supplierById = new Map(suppliers.map((supplier) => [String(supplier.id), supplier]));
    const supplierIdInput = builder.querySelector('[data-purchase-supplier-id]');
    const supplierLabel = builder.querySelector('[data-purchase-supplier-label]');
    const supplierSummary = builder.querySelector('[data-purchase-supplier-summary]');
    const supplierPanel = builder.querySelector('[data-purchase-supplier-panel]');
    const supplierToggle = builder.querySelector('[data-purchase-supplier-toggle]');
    const supplierSearch = builder.querySelector('[data-purchase-supplier-search]');
    const supplierOptions = builder.querySelector('[data-purchase-supplier-options]');
    const newSupplierFields = builder.querySelector('[data-new-supplier-fields]');
    const newSupplierInputs = Array.from(builder.querySelectorAll('[data-new-supplier-input]'));
    const totalTarget = builder.querySelector('[data-purchase-total]');

    const compactText = (value) => String(value || '').trim();
    const searchText = (...values) => values.map((value) => compactText(value).toLowerCase()).join(' ');
    const currencyValue = () => compactText(builder.querySelector('[name="currency"]')?.value || 'SAR') || 'SAR';
    const formatLineMoney = (value) => `${currencyValue()} ${new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(Number.isFinite(value) ? value : 0)}`;

    const closePanels = (except = null) => {
      builder.querySelectorAll('[data-purchase-supplier-panel], [data-purchase-item-panel]').forEach((panel) => {
        if (panel !== except) {
          panel.hidden = true;
        }
      });

      builder.querySelectorAll('[data-purchase-supplier-toggle], [data-purchase-item-toggle]').forEach((toggle) => {
        const ownsOpenPanel = except && toggle.parentElement?.contains(except);
        toggle.setAttribute('aria-expanded', ownsOpenPanel ? 'true' : 'false');
      });
    };

    const clearFileInput = (input) => {
      if (input instanceof HTMLInputElement && input.type === 'file') {
        input.value = '';
      }
    };

    const setInputValue = (field, value, overwrite = true) => {
      if (!(field instanceof HTMLInputElement) && !(field instanceof HTMLTextAreaElement)) {
        return;
      }

      if (!overwrite && field.value.trim() !== '') {
        return;
      }

      field.value = String(value || '');
    };

    const selectedSupplierSummaryMarkup = (supplier) => {
      if (!supplier) {
        return '';
      }

      const meta = [
        supplier.supplier_type_label ? `Type: ${supplier.supplier_type_label}` : (supplier.supplier_type ? `Type: ${supplier.supplier_type}` : ''),
        supplier.phone ? `Phone: ${supplier.phone}` : '',
        supplier.authorized_person ? `Authorized: ${supplier.authorized_person}` : '',
        supplier.tax_number ? `VAT: ${supplier.tax_number}` : '',
        supplier.commercial_registration ? `CR: ${supplier.commercial_registration}` : '',
      ].filter(Boolean);

      return `
        <strong>${escapeHtml(supplier.name || 'Selected supplier')}</strong>
        <span>${escapeHtml(meta.join(' · ') || 'Supplier details are already saved.')}</span>
      `;
    };

    const renderSupplierOptions = (query = '') => {
      if (!supplierOptions) {
        return;
      }

      const normalized = compactText(query).toLowerCase();
      const rows = suppliers.filter((supplier) => {
        if (normalized === '') {
          return true;
        }

        return searchText(
          supplier.name,
          supplier.phone,
          supplier.email,
          supplier.tax_number,
          supplier.commercial_registration,
          supplier.national_address,
          supplier.authorized_person,
          supplier.supplier_type_label,
          supplier.supplier_type,
          supplier.supplier_type_other
        ).includes(normalized);
      }).slice(0, 80);

      const selectedId = supplierIdInput instanceof HTMLInputElement ? supplierIdInput.value : '';
      supplierOptions.innerHTML = `
        <button class="purchase-picker-option ${selectedId === '' ? 'is-selected' : ''}" type="button" data-purchase-supplier-option value="">
          <span class="purchase-picker-option-mark">+</span>
          <span><strong>Create new supplier</strong><small>Show supplier details and save this supplier with the purchase.</small></span>
        </button>
        ${rows.map((supplier) => `
          <button class="purchase-picker-option ${String(supplier.id) === selectedId ? 'is-selected' : ''}" type="button" data-purchase-supplier-option value="${escapeHtml(supplier.id)}">
            <span class="purchase-picker-option-mark">${escapeHtml(String(supplier.name || 'S').slice(0, 2).toUpperCase())}</span>
            <span>
              <strong>${escapeHtml(supplier.name || 'Supplier')}</strong>
              <small>${escapeHtml([supplier.phone, supplier.email, supplier.tax_number || supplier.commercial_registration, supplier.authorized_person].filter(Boolean).join(' · ') || 'No extra details')}</small>
            </span>
          </button>
        `).join('')}
      `;
    };

    const selectSupplier = (id = '') => {
      const supplierId = String(id || '');
      const supplier = supplierById.get(supplierId);

      if (supplierIdInput instanceof HTMLInputElement) {
        supplierIdInput.value = supplier ? supplierId : '';
      }

      if (supplierLabel) {
        supplierLabel.textContent = supplier ? (supplier.name || 'Selected supplier') : 'Create new supplier';
      }

      if (supplierSummary) {
        supplierSummary.hidden = !supplier;
        supplierSummary.innerHTML = selectedSupplierSummaryMarkup(supplier);
      }

      if (newSupplierFields) {
        newSupplierFields.hidden = Boolean(supplier);
      }

      newSupplierInputs.forEach((field) => {
        field.disabled = Boolean(supplier);
      });

      renderSupplierOptions(supplierSearch instanceof HTMLInputElement ? supplierSearch.value : '');
    };

    const findSupplierByName = (name) => suppliers.find((supplier) => compactText(supplier.name).toLowerCase() === compactText(name).toLowerCase()) || null;

    builder.addEventListener('purchase:supplier-select', (event) => {
      selectSupplier(event.detail?.id || '');
      closePanels();
    });

    builder.addEventListener('purchase:supplier-create', () => {
      selectSupplier('');
      closePanels();
    });

    builder.purchaseFindSupplierByName = findSupplierByName;
    builder.purchaseSelectSupplier = selectSupplier;

    const rowFields = (row) => ({
      id: row.querySelector('[data-purchase-item-id]'),
      label: row.querySelector('[data-purchase-item-label]'),
      previewName: row.querySelector('[data-purchase-item-name-preview]'),
      preview: row.querySelector('[data-purchase-item-preview]'),
      thumb: row.querySelector('[data-purchase-item-thumb]'),
      details: row.querySelector('[data-purchase-line-details]'),
      lineTotal: row.querySelector('[data-purchase-line-total]'),
      name: row.querySelector('input[name="line_item_name[]"]'),
      sku: row.querySelector('input[name="line_item_sku[]"]'),
      barcode: row.querySelector('input[name="line_item_barcode[]"]'),
      category: row.querySelector('input[name="line_item_category[]"]'),
      unit: row.querySelector('select[name="line_unit[]"]'),
      customUnit: row.querySelector('input[name="line_custom_unit[]"]'),
      quantity: row.querySelector('input[name="line_quantity_requested[]"]'),
      cost: row.querySelector('input[name="line_unit_cost_quoted[]"]'),
      notes: row.querySelector('textarea[name="line_item_notes[]"]'),
    });

    const fillUnit = (row, unitValue) => {
      const fields = rowFields(row);
      const normalized = compactText(unitValue || 'pcs') || 'pcs';

      if (!(fields.unit instanceof HTMLSelectElement)) {
        return;
      }

      const matchingOption = Array.from(fields.unit.options).find((option) => option.value === normalized);
      fields.unit.value = matchingOption ? normalized : 'custom';

      if (fields.customUnit instanceof HTMLInputElement) {
        fields.customUnit.value = matchingOption ? '' : normalized;
      }

      fields.unit.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const thumbMarkup = (item) => {
      if (item?.image_url) {
        return `<img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name || 'Item')}">`;
      }

      return '<span class="purchase-line-thumb-fallback">IT</span>';
    };

    const updateTotals = () => {
      let total = 0;

      body.querySelectorAll('[data-purchase-line]').forEach((row) => {
        const fields = rowFields(row);
        const quantity = fields.quantity instanceof HTMLInputElement ? parseNumber(fields.quantity.value) : 0;
        const cost = fields.cost instanceof HTMLInputElement ? parseNumber(fields.cost.value) : 0;
        const lineTotal = quantity * cost;
        total += lineTotal;

        if (fields.lineTotal) {
          fields.lineTotal.textContent = formatLineMoney(lineTotal);
        }
      });

      if (totalTarget) {
        totalTarget.textContent = formatLineMoney(total);
      }
    };

    const updateLineIndexes = () => {
      body.querySelectorAll('[data-purchase-line]').forEach((row, index) => {
        const indexTarget = row.querySelector('[data-purchase-line-index]');

        if (indexTarget) {
          indexTarget.textContent = String(index + 1);
        }
      });
    };

    const renderItemOptions = (row, query = '') => {
      const optionsTarget = row.querySelector('[data-purchase-item-options]');

      if (!optionsTarget) {
        return;
      }

      const fields = rowFields(row);
      const selectedId = fields.id instanceof HTMLInputElement ? fields.id.value : '';
      const normalized = compactText(query).toLowerCase();
      const rows = catalog.filter((item) => {
        if (normalized === '') {
          return true;
        }

        return searchText(item.name, item.sku, item.barcode, item.category, item.unit).includes(normalized);
      }).slice(0, 80);

      optionsTarget.innerHTML = `
        <button class="purchase-picker-option ${selectedId === '' ? 'is-selected' : ''}" type="button" data-purchase-item-option value="">
          <span class="purchase-picker-option-mark">+</span>
          <span><strong>Quick-create new item</strong><small>Add name, SKU, unit, barcode, image, and notes below.</small></span>
        </button>
        ${rows.map((item) => `
          <button class="purchase-picker-option ${String(item.id) === selectedId ? 'is-selected' : ''}" type="button" data-purchase-item-option value="${escapeHtml(item.id)}">
            <span class="purchase-picker-option-thumb">${thumbMarkup(item)}</span>
            <span>
              <strong>${escapeHtml(item.name || 'Item')}</strong>
              <small>${escapeHtml([item.sku, item.barcode, item.unit, Number(item.cost_per_unit) > 0 ? formatLineMoney(Number(item.cost_per_unit)) : ''].filter(Boolean).join(' · ') || 'No SKU')}</small>
            </span>
          </button>
        `).join('')}
      `;
    };

    const clearSnapshot = (row) => {
      const fields = rowFields(row);
      [fields.name, fields.sku, fields.barcode, fields.category, fields.customUnit, fields.notes].forEach((field) => {
        if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
          field.value = '';
        }
      });

      if (fields.unit instanceof HTMLSelectElement) {
        fields.unit.value = 'pcs';
      }
    };

    const setRowItem = (row, itemId = '', options = {}) => {
      const fields = rowFields(row);
      const selectedId = String(itemId || '');
      const item = catalogById.get(selectedId);
      const overwrite = options.overwrite === true;

      if (fields.id instanceof HTMLInputElement) {
        fields.id.value = item ? selectedId : '';
      }

      if (!item) {
        if (overwrite) {
          clearSnapshot(row);
        }

        if (fields.label) {
          fields.label.textContent = 'Quick-create new item';
        }

        if (fields.previewName) {
          fields.previewName.textContent = compactText(fields.name?.value) || 'Quick-create new item';
        }

        if (fields.preview) {
          fields.preview.textContent = 'Fill the snapshot details below.';
        }

        if (fields.thumb) {
          fields.thumb.innerHTML = '<span class="purchase-line-thumb-fallback">IT</span>';
        }

        if (fields.details instanceof HTMLDetailsElement) {
          fields.details.open = true;
        }

        renderItemOptions(row, row.querySelector('[data-purchase-item-search]')?.value || '');
        updateTotals();
        return;
      }

      setInputValue(fields.name, item.name, overwrite);
      setInputValue(fields.sku, item.sku, overwrite);
      setInputValue(fields.barcode, item.barcode, overwrite);
      setInputValue(fields.category, item.category, overwrite);

      if (fields.notes instanceof HTMLTextAreaElement && (overwrite || fields.notes.value.trim() === '')) {
        fields.notes.value = item.notes || '';
      }

      fillUnit(row, item.unit || 'pcs');

      if (fields.cost instanceof HTMLInputElement && Number(item.cost_per_unit) > 0 && (overwrite || fields.cost.value.trim() === '')) {
        fields.cost.value = Number(item.cost_per_unit).toFixed(2);
      }

      if (fields.label) {
        fields.label.textContent = item.name || 'Selected item';
      }

      if (fields.previewName) {
        fields.previewName.textContent = item.name || 'Selected item';
      }

      if (fields.preview) {
        fields.preview.textContent = [item.sku || 'SKU', item.barcode, item.unit || 'pcs'].filter(Boolean).join(' · ');
      }

      if (fields.thumb) {
        fields.thumb.innerHTML = thumbMarkup(item);
      }

      if (fields.details instanceof HTMLDetailsElement && !options.keepDetailsOpen) {
        fields.details.open = false;
      }

      renderItemOptions(row, row.querySelector('[data-purchase-item-search]')?.value || '');
      updateTotals();
    };

    builder.purchaseSetRowItem = setRowItem;
    builder.purchaseUpdateTotals = updateTotals;

    const ensureOneLine = () => {
      if (body.querySelectorAll('[data-purchase-line]').length > 0) {
        return;
      }

      addLine();
    };

    const addLine = () => {
      const firstRow = body.querySelector('[data-purchase-line]');

      if (!firstRow) {
        return;
      }

      const row = firstRow.cloneNode(true);

      row.querySelectorAll('input, select, textarea').forEach((field) => {
        if (field instanceof HTMLSelectElement) {
          field.selectedIndex = 0;
        } else if (field instanceof HTMLTextAreaElement || field instanceof HTMLInputElement) {
          field.value = '';
          clearFileInput(field);
        }
      });

      row.querySelectorAll('[data-purchase-item-search]').forEach((input) => {
        if (input instanceof HTMLInputElement) {
          input.value = '';
        }
      });

      row.querySelectorAll('[data-purchase-item-panel]').forEach((panel) => {
        panel.hidden = true;
      });

      const details = row.querySelector('[data-purchase-line-details]');

      if (details instanceof HTMLDetailsElement) {
        details.open = true;
      }

      body.appendChild(row);
      setRowItem(row, '', { overwrite: true });
      updateLineIndexes();
      updateTotals();
    };

    builder.dataset.jsBound = 'true';

    selectSupplier(supplierIdInput instanceof HTMLInputElement ? supplierIdInput.value : '');
    renderSupplierOptions();

    if (supplierToggle && supplierPanel) {
      supplierToggle.addEventListener('click', () => {
        const willOpen = supplierPanel.hidden;
        closePanels(willOpen ? supplierPanel : null);
        supplierPanel.hidden = !willOpen;
        supplierToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

        if (willOpen && supplierSearch instanceof HTMLInputElement) {
          supplierSearch.focus();
          supplierSearch.select();
        }
      });
    }

    if (supplierSearch instanceof HTMLInputElement) {
      supplierSearch.addEventListener('input', () => {
        renderSupplierOptions(supplierSearch.value);
      });
    }

    if (supplierOptions) {
      supplierOptions.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Element)) {
          return;
        }

        const option = target.closest('[data-purchase-supplier-option]');

        if (!(option instanceof HTMLButtonElement)) {
          return;
        }

        selectSupplier(option.value);
        closePanels();
      });
    }

    addButton.addEventListener('click', () => {
      addLine();
    });

    body.addEventListener('click', (event) => {
      const target = event.target;

      if (!(target instanceof Element)) {
        return;
      }

      const removeButton = target.closest('[data-remove-purchase-line]');

      if (removeButton) {
        const row = removeButton.closest('[data-purchase-line]');
        const lineCount = body.querySelectorAll('[data-purchase-line]').length;

        if (row && lineCount > 1) {
          row.remove();
        } else if (row) {
          row.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field instanceof HTMLSelectElement) {
              field.selectedIndex = 0;
            } else if (field instanceof HTMLTextAreaElement || field instanceof HTMLInputElement) {
              field.value = '';
              clearFileInput(field);
            }
          });
          setRowItem(row, '', { overwrite: true });
        }

        ensureOneLine();
        updateLineIndexes();
        updateTotals();
        return;
      }

      const toggle = target.closest('[data-purchase-item-toggle]');

      if (toggle instanceof HTMLButtonElement) {
        const row = toggle.closest('[data-purchase-line]');
        const panel = row?.querySelector('[data-purchase-item-panel]');
        const search = row?.querySelector('[data-purchase-item-search]');

        if (!row || !panel) {
          return;
        }

        const willOpen = panel.hidden;
        closePanels(willOpen ? panel : null);
        panel.hidden = !willOpen;
        toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        renderItemOptions(row, search instanceof HTMLInputElement ? search.value : '');

        if (willOpen && search instanceof HTMLInputElement) {
          search.focus();
          search.select();
        }

        return;
      }

      const option = target.closest('[data-purchase-item-option]');

      if (option instanceof HTMLButtonElement) {
        const row = option.closest('[data-purchase-line]');

        if (row) {
          setRowItem(row, option.value, { overwrite: true });
          closePanels();
        }
      }
    });

    body.addEventListener('input', (event) => {
      const target = event.target;

      if (!(target instanceof Element)) {
        return;
      }

      if (target.matches('[data-purchase-item-search]')) {
        const row = target.closest('[data-purchase-line]');

        if (row && target instanceof HTMLInputElement) {
          renderItemOptions(row, target.value);
        }

        return;
      }

      if (target.matches('input[name="line_item_name[]"], input[name="line_item_sku[]"], input[name="line_item_barcode[]"], input[name="line_item_category[]"]')) {
        const row = target.closest('[data-purchase-line]');

        if (row) {
          setRowItem(row, row.querySelector('[data-purchase-item-id]')?.value || '', { keepDetailsOpen: true });
        }

        return;
      }

      if (target.matches('[data-purchase-quantity], [data-purchase-unit-cost]')) {
        updateTotals();
      }
    });

    body.addEventListener('change', (event) => {
      const target = event.target;

      if (!(target instanceof Element) || !target.matches('input[name="line_item_name[]"], input[name="line_item_sku[]"], input[name="line_item_barcode[]"], input[name="line_item_category[]"], select[name="line_unit[]"]')) {
        return;
      }

      const row = target.closest('[data-purchase-line]');

      if (row) {
        setRowItem(row, row.querySelector('[data-purchase-item-id]')?.value || '', { keepDetailsOpen: true });
      }
    });

    builder.querySelector('[name="currency"]')?.addEventListener('input', updateTotals);

    document.addEventListener('click', (event) => {
      const target = event.target;

      if (target instanceof Node && !builder.contains(target)) {
        closePanels();
      }
    });

    ensureOneLine();
    body.querySelectorAll('[data-purchase-line]').forEach((row) => {
      setRowItem(row, row.querySelector('[data-purchase-item-id]')?.value || '', { keepDetailsOpen: true });
      renderItemOptions(row);
    });
    updateLineIndexes();
    updateTotals();
  });
};

export const initPurchaseOcrImport = (root = document) => {
  root.querySelectorAll('[data-purchase-ocr-url]').forEach((form) => {
    if (form.dataset.ocrBound === 'true') {
      return;
    }

    const ocrUrl = form.dataset.purchaseOcrUrl;
    const fileInput = form.querySelector('[data-purchase-ocr-files]');
    const button = form.querySelector('[data-purchase-ocr-button]');
    const aiButton = form.querySelector('[data-purchase-ocr-ai-button]');
    const status = form.querySelector('[data-purchase-ocr-status]');
    const review = form.querySelector('[data-purchase-ocr-review]');
    const preview = form.querySelector('[data-purchase-ocr-preview]');
    const runHolder = form.querySelector('[data-purchase-ocr-run-holder]');
    const textWrap = form.querySelector('[data-purchase-ocr-text-wrap]');
    const textPreview = form.querySelector('[data-purchase-ocr-text]');
    const body = form.querySelector('[data-purchase-line-body]');
    const addButton = form.querySelector('[data-add-purchase-line]');
    const canRunAi = form.dataset.purchaseOcrCanAi === '1';
    const maxPagesPerPdf = Number.parseInt(form.dataset.purchaseOcrMaxPages || '8', 10);
    const minConfidence = confidenceScore(form.dataset.purchaseOcrMinConfidence, 0.7);

    if (!ocrUrl || !(fileInput instanceof HTMLInputElement) || !(button instanceof HTMLButtonElement) || !body || !addButton) {
      return;
    }

    const setStatus = (message, type = '') => {
      if (!status) {
        return;
      }

      status.textContent = message;
      status.classList.toggle('danger-text', type === 'danger');
      status.classList.toggle('success-text', type === 'success');
    };

    const renderDocumentPreview = (files) => {
      if (!(preview instanceof HTMLElement)) {
        return;
      }

      if (!files.length) {
        preview.hidden = true;
        preview.innerHTML = '';
        return;
      }

      preview.innerHTML = files.map((file, index) => {
        const isImage = /^image\/(jpeg|png|webp)$/i.test(file.type);
        const thumb = isImage
          ? `<img src="${escapeHtml(URL.createObjectURL(file))}" alt="">`
          : escapeHtml((file.name.split('.').pop() || 'file').slice(0, 4).toUpperCase());

        return `
          <article class="purchase-document-preview-card">
            <span class="purchase-document-preview-thumb">${thumb}</span>
            <span>
              <strong>${escapeHtml(file.name || `Document ${index + 1}`)}</strong>
              <small class="tiny-copy">${escapeHtml(file.type || 'document')} · ${formatCount(Math.ceil((file.size || 0) / 1024))} KB</small>
            </span>
          </article>
        `;
      }).join('');
      preview.hidden = false;
    };

    const syncOcrRunIds = (ids) => {
      if (!(runHolder instanceof HTMLElement) || !Array.isArray(ids)) {
        return;
      }

      const existing = new Set(Array.from(runHolder.querySelectorAll('input[name="ocr_run_ids[]"]')).map((input) => input.value));

      ids.forEach((id) => {
        const normalized = String(id || '').trim();

        if (!normalized || existing.has(normalized)) {
          return;
        }

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ocr_run_ids[]';
        input.value = normalized;
        runHolder.appendChild(input);
        existing.add(normalized);
      });
    };

    const showAiButton = (show) => {
      if (aiButton instanceof HTMLButtonElement) {
        aiButton.hidden = !(show && canRunAi);
      }
    };

    const setFieldValue = (name, value, overwrite = false) => {
      if (value === undefined || value === null || String(value).trim() === '') {
        return;
      }

      const field = form.querySelector(`[name="${name}"]`);

      if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
        if (overwrite || field.value.trim() === '') {
          field.value = String(value);
          field.dispatchEvent(new Event('input', { bubbles: true }));
        }
      } else if (field instanceof HTMLSelectElement) {
        if (overwrite || field.value === '') {
          field.value = String(value);
          field.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
    };

    const rowFields = (row) => ({
      itemSelect: row.querySelector('input[name="line_item_id[]"]'),
      name: row.querySelector('input[name="line_item_name[]"]'),
      sku: row.querySelector('input[name="line_item_sku[]"]'),
      barcode: row.querySelector('input[name="line_item_barcode[]"]'),
      category: row.querySelector('input[name="line_item_category[]"]'),
      unit: row.querySelector('select[name="line_unit[]"]'),
      customUnit: row.querySelector('input[name="line_custom_unit[]"]'),
      quantity: row.querySelector('input[name="line_quantity_requested[]"]'),
      cost: row.querySelector('input[name="line_unit_cost_quoted[]"]'),
      notes: row.querySelector('textarea[name="line_item_notes[]"]'),
    });

    const isRowEmpty = (row) => {
      const fields = rowFields(row);

      return Object.values(fields).every((field) => {
        if (field instanceof HTMLSelectElement) {
          return field.value === '' || field.name === 'line_unit[]';
        }

        if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
          return field.type === 'file' || field.value.trim() === '';
        }

        return true;
      });
    };

    const fillUnit = (row, unitValue) => {
      const fields = rowFields(row);
      const normalized = String(unitValue || 'pcs').trim() || 'pcs';

      if (!(fields.unit instanceof HTMLSelectElement)) {
        return;
      }

      const matchingOption = Array.from(fields.unit.options).find((option) => option.value === normalized);
      fields.unit.value = matchingOption ? normalized : 'custom';

      if (fields.customUnit instanceof HTMLInputElement) {
        fields.customUnit.value = matchingOption ? '' : normalized;
      }

      fields.unit.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const fillLines = async (lines) => {
      if (!Array.isArray(lines) || lines.length === 0) {
        setStatus('No item rows were detected. You can still review the extracted text and add lines manually.', 'danger');
        return;
      }

      const existingRows = Array.from(body.querySelectorAll('[data-purchase-line]'));
      const hasExistingData = existingRows.some((row) => !isRowEmpty(row));

      if (hasExistingData && !(await confirmDialog('Replace the current purchase lines with OCR extracted lines?'))) {
        return;
      }

      while (body.querySelectorAll('[data-purchase-line]').length < lines.length) {
        addButton.click();
      }

      Array.from(body.querySelectorAll('[data-purchase-line]')).forEach((row, index) => {
        if (index >= lines.length) {
          if (body.querySelectorAll('[data-purchase-line]').length > 1) {
            row.remove();
          }
          return;
        }

        const line = lines[index] || {};
        const fields = rowFields(row);

        if (fields.itemSelect instanceof HTMLInputElement) {
          fields.itemSelect.value = line.item_id ? String(line.item_id) : '';
        }

        if (fields.name instanceof HTMLInputElement) {
          fields.name.value = line.item_name || '';
        }

        if (fields.sku instanceof HTMLInputElement) {
          fields.sku.value = line.item_sku || '';
        }

        if (fields.barcode instanceof HTMLInputElement) {
          fields.barcode.value = line.item_barcode || '';
        }

        if (fields.category instanceof HTMLInputElement) {
          fields.category.value = line.item_category || '';
        }

        fillUnit(row, line.unit || 'pcs');

        if (fields.quantity instanceof HTMLInputElement) {
          fields.quantity.value = line.quantity_requested || '';
        }

        if (fields.cost instanceof HTMLInputElement) {
          fields.cost.value = line.unit_cost_quoted || '';
        }

        if (fields.notes instanceof HTMLTextAreaElement) {
          fields.notes.value = line.item_notes || '';
        }

        if (typeof form.purchaseSetRowItem === 'function') {
          form.purchaseSetRowItem(row, line.item_id ? String(line.item_id) : '', {
            keepDetailsOpen: !line.item_id,
            overwrite: false,
          });
        }
      });

      if (typeof form.purchaseUpdateTotals === 'function') {
        form.purchaseUpdateTotals();
      }
    };

    const applyParsedPayload = async (payload) => {
      const parsed = payload.parsed || {};
      const supplier = parsed.supplier || {};
      const purchase = parsed.purchase || {};

      if (supplier.name) {
        const existingSupplier = typeof form.purchaseFindSupplierByName === 'function'
          ? form.purchaseFindSupplierByName(supplier.name)
          : null;

        if (existingSupplier) {
          form.dispatchEvent(new CustomEvent('purchase:supplier-select', {
            detail: { id: existingSupplier.id },
          }));
        } else {
          form.dispatchEvent(new CustomEvent('purchase:supplier-create'));
          setFieldValue('supplier_name', supplier.name, false);
        }
      }

      setFieldValue('supplier_phone', supplier.phone, false);
      setFieldValue('supplier_email', supplier.email, false);
      setFieldValue('supplier_type', supplier.supplier_type || supplier.type, true);
      setFieldValue('supplier_type_other', supplier.supplier_type_other || supplier.type_other, true);
      setFieldValue('supplier_tax_number', supplier.tax_number, false);
      setFieldValue('supplier_commercial_registration', supplier.commercial_registration, false);
      setFieldValue('supplier_national_address', supplier.national_address, false);
      setFieldValue('supplier_authorized_person', supplier.authorized_person, false);
      setFieldValue('expected_date', purchase.expected_date, false);
      setFieldValue('currency', purchase.currency, false);
      await fillLines(parsed.lines || []);

      if (textPreview && parsed.text_excerpt) {
        textPreview.textContent = parsed.text_excerpt;

        if (textWrap) {
          textWrap.hidden = false;
        }
      }

      if (review instanceof HTMLElement) {
        review.innerHTML = ocrConfidenceMarkup(parsed);
        review.hidden = false;
      }

      syncOcrRunIds(payload.ocr_run_ids || []);
      const overall = confidenceScore(parsed.confidence?.overall, 0);
      showAiButton(overall > 0 && overall < minConfidence);

      const warnings = Array.isArray(payload.warnings) && payload.warnings.length > 0
        ? ` ${payload.warnings.join(' ')}`
        : '';
      setStatus(`${payload.message || 'OCR import finished.'}${warnings}`, 'success');
    };

    const postToServer = async (formData) => {
      const response = await fetch(ocrUrl, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: formData,
      });
      const payload = await response.json();

      if (!response.ok || !payload.ok) {
        const error = new Error(payload.message || 'OCR failed.');
        error.payload = payload;
        throw error;
      }

      return payload;
    };

    form.dataset.ocrBound = 'true';

    fileInput.addEventListener('change', () => {
      renderDocumentPreview(Array.from(fileInput.files || []));
      showAiButton(false);
    });

    const runFileExtraction = async (engine = 'auto') => {
      const files = Array.from(fileInput.files || []);

      if (files.length === 0) {
        setStatus('Select quote, price list, or receipt files first.', 'danger');
        return;
      }

      renderDocumentPreview(files);
      button.disabled = true;

      if (aiButton instanceof HTMLButtonElement) {
        aiButton.disabled = true;
      }

      setStatus(engine === 'openai' ? 'Running AI extraction...' : 'Extracting document text...');

      try {
        const formData = new FormData();
        formData.append('_token', csrfToken(form));
        formData.append('ocr_engine', engine);
        files.forEach((file) => formData.append('documents[]', file));

        const payload = await postToServer(formData);
        await applyParsedPayload(payload);
      } catch (error) {
        const needsBrowserOcr = error.payload?.needs_browser_ocr || files.some((file) => /^image\//i.test(file.type) || file.type === 'application/pdf' || /\.pdf$/i.test(file.name));

        if (!needsBrowserOcr) {
          setStatus(error.message || 'OCR failed.', 'danger');
          button.disabled = false;
          if (aiButton instanceof HTMLButtonElement) {
            aiButton.disabled = false;
          }
          return;
        }

        if (engine === 'openai') {
          setStatus(error.message || 'AI OCR failed. Review manually or try browser OCR.', 'danger');
          showAiButton(canRunAi);
          button.disabled = false;
          if (aiButton instanceof HTMLButtonElement) {
            aiButton.disabled = false;
          }
          return;
        }

        try {
          const text = await browserOcrTextFromFiles(files, setStatus, { maxPagesPerPdf });
          const textFormData = new FormData();
          textFormData.append('_token', csrfToken(form));
          textFormData.append('ocr_text', text);
          textFormData.append('ocr_source_name', files.map((file) => file.name).join(', ') || 'Browser OCR text');
          const payload = await postToServer(textFormData);
          await applyParsedPayload(payload);
        } catch (browserError) {
          setStatus(browserError.message || 'Browser OCR failed.', 'danger');
          showAiButton(canRunAi);
        }
      } finally {
        button.disabled = false;
        if (aiButton instanceof HTMLButtonElement) {
          aiButton.disabled = false;
        }
      }
    };

    button.addEventListener('click', () => {
      runFileExtraction('auto');
    });

    if (aiButton instanceof HTMLButtonElement) {
      aiButton.addEventListener('click', () => {
        runFileExtraction('openai');
      });
    }
  });
};

export const initPurchaseBulkImport = (root = document) => {
  root.querySelectorAll('[data-purchase-bulk-import]').forEach((form) => {
    if (form.dataset.bulkImportBound === 'true') {
      return;
    }

    const ocrUrl = form.dataset.purchaseOcrUrl;
    const fileInput = form.querySelector('[data-purchase-bulk-files]');
    const processButton = form.querySelector('[data-purchase-bulk-process]');
    const aiProcessButton = form.querySelector('[data-purchase-bulk-ai-process]');
    const status = form.querySelector('[data-purchase-bulk-status]');
    const review = form.querySelector('[data-purchase-bulk-review]');
    const submitButton = form.querySelector('[data-purchase-bulk-submit]');
    const canRunAi = form.dataset.purchaseOcrCanAi === '1';
    const maxPagesPerPdf = Number.parseInt(form.dataset.purchaseOcrMaxPages || '8', 10);
    const minConfidence = confidenceScore(form.dataset.purchaseOcrMinConfidence, 0.7);

    if (!ocrUrl || !(fileInput instanceof HTMLInputElement) || !(processButton instanceof HTMLButtonElement) || !review) {
      return;
    }

    let catalog = [];
    let unitOptions = {};
    let documentTypes = {};
    let supplierTypeOptions = {};

    try {
      catalog = JSON.parse(form.dataset.purchaseCatalog || '[]');
    } catch (error) {
      catalog = [];
    }

    try {
      unitOptions = JSON.parse(form.dataset.purchaseUnitOptions || '{}');
    } catch (error) {
      unitOptions = {};
    }

    try {
      documentTypes = JSON.parse(form.dataset.purchaseDocumentTypes || '{}');
    } catch (error) {
      documentTypes = {};
    }

    try {
      supplierTypeOptions = JSON.parse(form.dataset.purchaseSupplierTypeOptions || '{}');
    } catch (error) {
      supplierTypeOptions = {};
    }

    if (Object.keys(supplierTypeOptions).length === 0) {
      supplierTypeOptions = { product: 'Product', service: 'Service', other: 'Other' };
    }

    const catalogById = new Map(catalog.map((item) => [String(item.id), item]));

    const setStatus = (message, type = '') => {
      if (!status) {
        return;
      }

      status.textContent = message;
      status.classList.toggle('danger-text', type === 'danger');
      status.classList.toggle('success-text', type === 'success');
    };

    const selectedValue = (selector, fallback = '') => {
      const field = form.querySelector(selector);

      return field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement
        ? field.value
        : fallback;
    };

    const selectOptionsMarkup = (options, selected) => Object.entries(options).map(([value, label]) => (
      `<option value="${escapeHtml(value)}"${String(value) === String(selected) ? ' selected' : ''}>${escapeHtml(label)}</option>`
    )).join('');

    const catalogOptionsMarkup = (selected) => [
      '<option value="">Quick-create new item</option>',
      ...catalog.map((item) => (
        `<option value="${escapeHtml(item.id)}"${String(item.id) === String(selected || '') ? ' selected' : ''}>${escapeHtml(item.name)} · ${escapeHtml(item.sku || 'No SKU')}</option>`
      )),
    ].join('');

    const unitState = (unit) => {
      const normalized = String(unit || 'pcs').trim() || 'pcs';

      if (Object.prototype.hasOwnProperty.call(unitOptions, normalized) && normalized !== 'custom') {
        return { selected: normalized, custom: '' };
      }

      return { selected: 'custom', custom: normalized };
    };

    const itemPreviewMarkup = (item) => {
      if (!item) {
        return '<span class="tiny-copy">Quick-create from document details.</span>';
      }

      const image = item.image_url
        ? `<img class="workflow-picker-thumb" src="${escapeHtml(item.image_url)}" alt="">`
        : '<span class="workflow-picker-thumb workflow-picker-thumb-fallback">I</span>';

      return `${image}<span><strong>${escapeHtml(item.name || 'Item')}</strong><span class="tiny-copy">${escapeHtml(item.sku || 'SKU')}${item.barcode ? ` · ${escapeHtml(item.barcode)}` : ''} · ${escapeHtml(item.unit || 'pcs')}</span></span>`;
    };

    const lineMarkup = (documentIndex, line = {}) => {
      const itemId = line.item_id ? String(line.item_id) : '';
      const selectedItem = itemId ? catalogById.get(itemId) : null;
      const unit = unitState(line.unit || selectedItem?.unit || 'pcs');
      const lineConfidence = confidenceScore(line.confidence, selectedItem ? 0.88 : 0.62);
      const lineFlags = Array.isArray(line.review_flags) ? line.review_flags : [];

      return `
        <tr data-import-line>
          <td data-label="Catalog">
            <select name="line_item_id[${documentIndex}][]" data-import-item-select>
              ${catalogOptionsMarkup(itemId)}
            </select>
            <div class="purchase-import-item-preview" data-import-item-preview>${itemPreviewMarkup(selectedItem)}</div>
            <div class="ocr-confidence-panel">
              <span class="ocr-confidence-chip ${confidenceClass(lineConfidence)}">Line ${Math.round(lineConfidence * 100)}%</span>
              ${lineFlags.length ? `<ul class="ocr-review-flags">${lineFlags.slice(0, 3).map((flag) => `<li>${escapeHtml(flag)}</li>`).join('')}</ul>` : ''}
            </div>
          </td>
          <td data-label="Details">
            <div class="field-stack compact-field-stack">
              <input type="text" name="line_item_name[${documentIndex}][]" value="${escapeHtml(line.item_name || selectedItem?.name || '')}" placeholder="Item name" data-import-line-name>
              <input type="text" name="line_item_sku[${documentIndex}][]" value="${escapeHtml(line.item_sku || selectedItem?.sku || '')}" placeholder="SKU" data-import-line-sku>
              <input type="text" name="line_item_barcode[${documentIndex}][]" value="${escapeHtml(line.item_barcode || selectedItem?.barcode || '')}" placeholder="Barcode optional" autocomplete="off" inputmode="text" data-import-line-barcode>
              <input type="text" name="line_item_category[${documentIndex}][]" value="${escapeHtml(line.item_category || selectedItem?.category || '')}" placeholder="Category" data-import-line-category>
              <div class="field-row inline-field-row">
                <select name="line_unit[${documentIndex}][]" data-import-line-unit>
                  ${selectOptionsMarkup(unitOptions, unit.selected)}
                </select>
                <input type="text" name="line_custom_unit[${documentIndex}][]" value="${escapeHtml(unit.custom)}" placeholder="Custom unit" data-import-line-custom-unit>
              </div>
              <textarea name="line_item_notes[${documentIndex}][]" rows="2" placeholder="Item notes" data-import-line-notes>${escapeHtml(line.item_notes || selectedItem?.notes || '')}</textarea>
            </div>
          </td>
          <td data-label="Qty">
            <input type="number" step="0.01" min="0.01" name="line_quantity_requested[${documentIndex}][]" value="${escapeHtml(line.quantity_requested || '')}" required data-import-line-quantity>
          </td>
          <td data-label="Unit Price">
            <input type="number" step="0.01" min="0" name="line_unit_cost_quoted[${documentIndex}][]" value="${escapeHtml(line.unit_cost_quoted || selectedItem?.cost_per_unit || '')}" required data-import-line-cost>
          </td>
          <td data-label="Total"><strong data-import-line-total>0.00</strong></td>
          <td data-label="Actions">
            <button class="text-button danger-link" type="button" data-import-remove-line>Remove</button>
          </td>
        </tr>
      `;
    };

    const cardMarkup = (file, documentIndex, payload, warning = '') => {
      const parsed = payload?.parsed || {};
      const supplier = parsed.supplier || {};
      const purchase = parsed.purchase || {};
      const lines = Array.isArray(parsed.lines) && parsed.lines.length > 0 ? parsed.lines : [{}];
      const currency = purchase.currency || selectedValue('[name="default_currency"]', 'SAR') || 'SAR';
      const documentType = selectedValue('[name="default_document_type"]', 'quote') || 'quote';
      const textExcerpt = parsed.text_excerpt || '';
      const runIds = Array.isArray(payload?.ocr_run_ids) ? payload.ocr_run_ids : [];
      const confidence = confidenceScore(parsed.confidence?.overall, 0);
      const showAiRerun = canRunAi && (warning || (confidence > 0 && confidence < minConfidence));

      return `
        <article class="purchase-import-card" data-import-document="${documentIndex}">
          <input type="hidden" name="document_index[]" value="${documentIndex}">
          ${runIds[0] ? `<input type="hidden" name="ocr_run_id[${documentIndex}]" value="${escapeHtml(runIds[0])}">` : ''}
          <div class="purchase-import-card-head">
            <label class="choice-field purchase-import-include">
              <input type="checkbox" name="document_include[${documentIndex}]" value="1" checked>
              <div>
                <strong>${escapeHtml(file.name)}</strong>
                <span>${escapeHtml(lines.length)} detected line${lines.length === 1 ? '' : 's'}. Review before creating the draft.</span>
              </div>
            </label>
            <div class="purchase-import-card-total">
              <span class="tiny-copy">Draft total</span>
              <strong data-import-document-total>0.00</strong>
            </div>
          </div>

          ${warning ? `<div class="copy-context-card danger-text">${escapeHtml(warning)}</div>` : ''}
          ${showAiRerun ? `<button class="ghost-button" type="button" data-import-run-ai>Run AI Extraction For This File</button>` : ''}
          <div class="ocr-confidence-panel purchase-import-confidence">${ocrConfidenceMarkup(parsed)}</div>

          <div class="field-row">
            <label class="field">
              <span>Supplier Name</span>
              <input type="text" name="supplier_name[${documentIndex}]" value="${escapeHtml(supplier.name || '')}" placeholder="Supplier name" required>
            </label>
            <label class="field">
              <span>Supplier Type</span>
              <select name="supplier_type[${documentIndex}]" required data-supplier-type-select>
                ${selectOptionsMarkup(supplierTypeOptions, supplier.supplier_type || supplier.type || 'product')}
              </select>
            </label>
            <label class="field">
              <span>Phone</span>
              <input type="text" name="supplier_phone[${documentIndex}]" value="${escapeHtml(supplier.phone || '')}" required>
            </label>
          </div>

          <label class="field" data-supplier-type-other-field hidden>
            <span>Custom supplier type</span>
            <input type="text" name="supplier_type_other[${documentIndex}]" value="${escapeHtml(supplier.supplier_type_other || supplier.type_other || '')}" placeholder="Example: Maintenance, contractor, logistics" data-supplier-type-other-input>
            <small class="tiny-copy">Required only when supplier type is Other.</small>
          </label>

          <div class="field-row">
            <label class="field">
              <span>Authorized Person / اسم المفوض</span>
              <input type="text" name="supplier_authorized_person[${documentIndex}]" value="${escapeHtml(supplier.authorized_person || supplier.name || '')}" required>
            </label>
            <label class="field">
              <span>National Address / العنوان الوطني</span>
              <input type="text" name="supplier_national_address[${documentIndex}]" value="${escapeHtml(supplier.national_address || '')}" required>
            </label>
          </div>

          <div class="field-row">
            <label class="field">
              <span>Email</span>
              <input type="email" name="supplier_email[${documentIndex}]" value="${escapeHtml(supplier.email || '')}">
            </label>
            <label class="field">
              <span>Commercial Registration (CR)</span>
              <input type="text" name="supplier_commercial_registration[${documentIndex}]" value="${escapeHtml(supplier.commercial_registration || '')}">
            </label>
          </div>

          <div class="field-row">
            <label class="field">
              <span>VAT / Tax Number</span>
              <input type="text" name="supplier_tax_number[${documentIndex}]" value="${escapeHtml(supplier.tax_number || '')}">
            </label>
            <label class="field">
              <span>Expected Date</span>
              <input type="date" name="expected_date[${documentIndex}]" value="${escapeHtml(purchase.expected_date || '')}">
            </label>
            <label class="field">
              <span>Currency</span>
              <input type="text" name="currency[${documentIndex}]" value="${escapeHtml(currency)}" maxlength="8" required>
            </label>
            <label class="field">
              <span>Document Type</span>
              <select name="document_type[${documentIndex}]">
                ${selectOptionsMarkup(documentTypes, documentType)}
              </select>
            </label>
          </div>

          <label class="field">
            <span>Supplier Notes</span>
            <textarea name="supplier_notes[${documentIndex}]" rows="2" placeholder="Optional supplier notes"></textarea>
          </label>

          <div class="purchase-import-line-tools">
            <strong>Imported item rows</strong>
            <button class="ghost-button" type="button" data-import-add-line><span>+</span><span>Add Line</span></button>
          </div>

          <div class="table-wrap">
            <table class="data-table data-table-mobile purchase-import-line-table">
              <thead>
              <tr>
                <th>Catalog</th>
                <th>Details</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Total</th>
                <th></th>
              </tr>
              </thead>
              <tbody data-import-line-body>
                ${lines.map((line) => lineMarkup(documentIndex, line)).join('')}
              </tbody>
            </table>
          </div>

          ${textExcerpt ? `
            <details class="purchase-ocr-text">
              <summary>Extracted text preview</summary>
              <pre>${escapeHtml(textExcerpt)}</pre>
            </details>
          ` : ''}
        </article>
      `;
    };

    const rowFields = (row) => ({
      itemSelect: row.querySelector('[data-import-item-select]'),
      preview: row.querySelector('[data-import-item-preview]'),
      name: row.querySelector('[data-import-line-name]'),
      sku: row.querySelector('[data-import-line-sku]'),
      barcode: row.querySelector('[data-import-line-barcode]'),
      category: row.querySelector('[data-import-line-category]'),
      unit: row.querySelector('[data-import-line-unit]'),
      customUnit: row.querySelector('[data-import-line-custom-unit]'),
      quantity: row.querySelector('[data-import-line-quantity]'),
      cost: row.querySelector('[data-import-line-cost]'),
      notes: row.querySelector('[data-import-line-notes]'),
      total: row.querySelector('[data-import-line-total]'),
    });

    const fillUnit = (row, unitValue) => {
      const fields = rowFields(row);
      const state = unitState(unitValue);

      if (fields.unit instanceof HTMLSelectElement) {
        fields.unit.value = state.selected;
      }

      if (fields.customUnit instanceof HTMLInputElement) {
        fields.customUnit.value = state.custom;
      }
    };

    const updateTotals = (card) => {
      let total = 0;

      card.querySelectorAll('[data-import-line]').forEach((row) => {
        const fields = rowFields(row);
        const lineTotal = parseNumber(fields.quantity?.value || '0') * parseNumber(fields.cost?.value || '0');
        total += lineTotal;

        if (fields.total) {
          fields.total.textContent = lineTotal.toFixed(2);
        }
      });

      const cardTotal = card.querySelector('[data-import-document-total]');

      if (cardTotal) {
        cardTotal.textContent = total.toFixed(2);
      }
    };

    const syncRowFromCatalog = (row) => {
      const fields = rowFields(row);

      if (!(fields.itemSelect instanceof HTMLSelectElement)) {
        return;
      }

      const item = catalogById.get(String(fields.itemSelect.value));

      if (!item) {
        if (fields.preview) {
          fields.preview.innerHTML = itemPreviewMarkup(null);
        }

        updateTotals(row.closest('[data-import-document]') || form);
        return;
      }

      if (fields.name instanceof HTMLInputElement) {
        fields.name.value = item.name || '';
      }

      if (fields.sku instanceof HTMLInputElement) {
        fields.sku.value = item.sku || '';
      }

      if (fields.barcode instanceof HTMLInputElement) {
        fields.barcode.value = item.barcode || '';
      }

      if (fields.category instanceof HTMLInputElement) {
        fields.category.value = item.category || '';
      }

      fillUnit(row, item.unit || 'pcs');

      if (fields.cost instanceof HTMLInputElement && Number(item.cost_per_unit) > 0 && !fields.cost.value) {
        fields.cost.value = Number(item.cost_per_unit).toFixed(2);
      }

      if (fields.notes instanceof HTMLTextAreaElement && !fields.notes.value) {
        fields.notes.value = item.notes || '';
      }

      if (fields.preview) {
        fields.preview.innerHTML = itemPreviewMarkup(item);
      }

      updateTotals(row.closest('[data-import-document]') || form);
    };

    const extractPayloadForFile = async (file, engine = 'auto') => {
      const formData = new FormData();
      formData.append('_token', csrfToken(form));
      formData.append('ocr_engine', engine);
      formData.append('documents[]', file);

      try {
        return await postPurchaseOcr(ocrUrl, formData);
      } catch (error) {
        const needsBrowserOcr = error.payload?.needs_browser_ocr || /^image\//i.test(file.type) || file.type === 'application/pdf' || /\.pdf$/i.test(file.name);

        if (!needsBrowserOcr || engine === 'openai') {
          throw error;
        }

        const text = await browserOcrTextFromFiles([file], setStatus, { maxPagesPerPdf });
        const textFormData = new FormData();
        textFormData.append('_token', csrfToken(form));
        textFormData.append('ocr_text', text);
        textFormData.append('ocr_source_name', file.name || 'Browser OCR text');

        return postPurchaseOcr(ocrUrl, textFormData);
      }
    };

    const resetReview = () => {
      review.innerHTML = `
        <div class="empty-state-card">
          <strong>No documents processed yet.</strong>
          <p>OCR is helpful, not magic. Expect to correct names, quantities, and prices on old scans.</p>
        </div>
      `;

      if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = true;
      }
    };

    form.dataset.bulkImportBound = 'true';

    fileInput.addEventListener('change', resetReview);

    if (aiProcessButton instanceof HTMLButtonElement) {
      aiProcessButton.hidden = !canRunAi;
    }

    const processFiles = async (engine = 'auto') => {
      const files = Array.from(fileInput.files || []);

      if (files.length === 0) {
        setStatus('Upload at least one quote, price list, receipt, or scanned PDF.', 'danger');
        return;
      }

      processButton.disabled = true;
      if (aiProcessButton instanceof HTMLButtonElement) {
        aiProcessButton.disabled = true;
      }
      review.innerHTML = '';

      try {
        for (const [index, file] of files.entries()) {
          setStatus(`${engine === 'openai' ? 'AI processing' : 'Processing'} ${file.name} (${index + 1} of ${files.length})...`);

          try {
            const payload = await extractPayloadForFile(file, engine);
            review.insertAdjacentHTML('beforeend', cardMarkup(file, index, payload));
          } catch (error) {
            review.insertAdjacentHTML('beforeend', cardMarkup(file, index, { parsed: { lines: [{}] } }, error.message || 'OCR failed. Fill this document manually.'));
          }

          const card = review.querySelector(`[data-import-document="${index}"]`);

          if (card) {
            initSupplierTypeOtherFields(card);
            card.querySelectorAll('[data-import-line]').forEach((row) => {
              syncRowFromCatalog(row);
            });
            updateTotals(card);
          }
        }

        if (submitButton instanceof HTMLButtonElement) {
          submitButton.disabled = false;
        }

        setStatus(`Processed ${files.length} document${files.length === 1 ? '' : 's'}. Review and create drafts when ready.`, 'success');
      } finally {
        processButton.disabled = false;
        if (aiProcessButton instanceof HTMLButtonElement) {
          aiProcessButton.disabled = false;
        }
      }
    };

    processButton.addEventListener('click', () => {
      processFiles('auto');
    });

    if (aiProcessButton instanceof HTMLButtonElement) {
      aiProcessButton.addEventListener('click', () => {
        processFiles('openai');
      });
    }

    review.addEventListener('click', (event) => {
      const target = event.target;

      if (!(target instanceof Element)) {
        return;
      }

      const addButton = target.closest('[data-import-add-line]');
      const removeButton = target.closest('[data-import-remove-line]');
      const aiButton = target.closest('[data-import-run-ai]');

      if (aiButton) {
        const card = aiButton.closest('[data-import-document]');
        const documentIndex = Number.parseInt(card?.getAttribute('data-import-document') || '', 10);
        const file = Number.isFinite(documentIndex) ? Array.from(fileInput.files || [])[documentIndex] : null;

        if (!card || !file) {
          setStatus('Could not find the selected document for AI rerun.', 'danger');
          return;
        }

        aiButton.disabled = true;
        setStatus(`Running AI extraction for ${file.name}...`);

        extractPayloadForFile(file, 'openai')
          .then((payload) => {
            card.outerHTML = cardMarkup(file, documentIndex, payload);
            const nextCard = review.querySelector(`[data-import-document="${documentIndex}"]`);

            if (nextCard) {
              initSupplierTypeOtherFields(nextCard);
              nextCard.querySelectorAll('[data-import-line]').forEach((row) => {
                syncRowFromCatalog(row);
              });
              updateTotals(nextCard);
            }

            setStatus(`AI extraction finished for ${file.name}. Review before creating drafts.`, 'success');
          })
          .catch((error) => {
            setStatus(error.message || 'AI extraction failed for this file.', 'danger');
            aiButton.disabled = false;
          });

        return;
      }

      if (addButton) {
        const card = addButton.closest('[data-import-document]');
        const body = card?.querySelector('[data-import-line-body]');
        const documentIndex = card?.getAttribute('data-import-document');

        if (body && documentIndex !== null) {
          body.insertAdjacentHTML('beforeend', lineMarkup(documentIndex, {}));
          updateTotals(card);
        }

        return;
      }

      if (removeButton) {
        const row = removeButton.closest('[data-import-line]');
        const card = removeButton.closest('[data-import-document]');
        const body = card?.querySelector('[data-import-line-body]');
        const documentIndex = card?.getAttribute('data-import-document');

        if (row && body && documentIndex !== null) {
          row.remove();

          if (body.querySelectorAll('[data-import-line]').length === 0) {
            body.insertAdjacentHTML('beforeend', lineMarkup(documentIndex, {}));
          }

          updateTotals(card);
        }
      }
    });

    review.addEventListener('change', (event) => {
      const target = event.target;

      if (!(target instanceof Element) || !target.matches('[data-import-item-select]')) {
        return;
      }

      const row = target.closest('[data-import-line]');

      if (row) {
        syncRowFromCatalog(row);
      }
    });

    review.addEventListener('input', (event) => {
      const target = event.target;

      if (!(target instanceof Element) || !target.matches('[data-import-line-quantity], [data-import-line-cost]')) {
        return;
      }

      const card = target.closest('[data-import-document]');

      if (card) {
        updateTotals(card);
      }
    });
  });
};

export const initManualStockAdd = (root = document) => {
  root.querySelectorAll('[data-manual-stock-add]').forEach((page) => {
    if (page.dataset.manualStockBound === 'true') {
      return;
    }

    const lookupUrl = page.dataset.manualLookupUrl || '';
    const submitUrl = page.dataset.manualSubmitUrl || '';
    const searchInput = page.querySelector('[data-manual-stock-search]');
    const results = page.querySelector('[data-manual-stock-results]');
    const selectedWrap = page.querySelector('[data-manual-stock-selected]');
    const lineForm = page.querySelector('[data-manual-stock-line-form]');
    const storageSelect = page.querySelector('[data-manual-stock-storage]');
    const quantityInput = page.querySelector('[data-manual-stock-quantity]');
    const referenceInput = page.querySelector('[data-manual-stock-reference]');
    const notesInput = page.querySelector('[data-manual-stock-notes]');
    const status = page.querySelector('[data-manual-stock-status]');
    const draftWrap = page.querySelector('[data-manual-stock-draft]');
    const summary = page.querySelector('[data-manual-stock-summary]');
    const count = page.querySelector('[data-manual-stock-count]');
    const clearButton = page.querySelector('[data-manual-stock-clear]');
    const confirmButton = page.querySelector('[data-manual-stock-confirm]');

    if (!lookupUrl || !submitUrl || !(searchInput instanceof HTMLInputElement) || !(lineForm instanceof HTMLFormElement) || !(results instanceof HTMLElement) || !(draftWrap instanceof HTMLElement)) {
      return;
    }

    let storages = [];
    let lookupItems = [];
    let selectedItem = null;
    let lookupTimer = null;
    let lookupSequence = 0;
    let draftLines = [];

    try {
      storages = JSON.parse(page.dataset.manualStorages || '[]');
    } catch (error) {
      storages = [];
    }

    const setStatus = (message, type = '') => {
      if (!status) {
        return;
      }

      status.textContent = message;
      status.classList.toggle('danger-text', type === 'danger');
      status.classList.toggle('success-text', type === 'success');
    };

    const itemImageMarkup = (item, className = 'scan-item-thumb') => (
      item.image_url
        ? `<img class="${className}" src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}">`
        : `<span class="${className} scan-item-thumb-fallback">${escapeHtml(String(item.name || 'I').slice(0, 1).toUpperCase())}</span>`
    );

    const storageLabel = (storageId) => {
      const storage = storages.find((entry) => String(entry.id) === String(storageId));

      return storage ? `${storage.type} · ${storage.name}` : 'Selected storage';
    };

    const exactScanMatch = (items, query) => items.find((item) => String(item.scan_code || '').toLowerCase() === String(query || '').toLowerCase())
      || items.find((item) => String(item.barcode || '').toLowerCase() === String(query || '').toLowerCase())
      || items.find((item) => String(item.sku || '').toLowerCase() === String(query || '').toLowerCase())
      || (items.length === 1 ? items[0] : null);

    const setSelectedItem = (item) => {
      selectedItem = item || null;

      if (selectedWrap instanceof HTMLElement) {
        if (!selectedItem) {
          selectedWrap.hidden = true;
          selectedWrap.innerHTML = '';
        } else {
          selectedWrap.hidden = false;
          selectedWrap.innerHTML = `
            <div class="manual-stock-selected-card">
              ${itemImageMarkup(selectedItem)}
              <span>
                <strong>${escapeHtml(selectedItem.name)}</strong>
                <small>${escapeHtml([selectedItem.sku, selectedItem.barcode || 'No barcode', selectedItem.unit].filter(Boolean).join(' · '))}</small>
                <small>${escapeHtml(selectedItem.location_summary || 'No assigned locations')}</small>
              </span>
              <em>${escapeHtml(selectedItem.quantity)} ${escapeHtml(selectedItem.unit)}</em>
            </div>
          `;
        }
      }

      if (results instanceof HTMLElement) {
        results.querySelectorAll('[data-manual-stock-result-index]').forEach((card) => {
          const index = Number.parseInt(card.getAttribute('data-manual-stock-result-index') || '-1', 10);
          card.classList.toggle('is-selected', Boolean(selectedItem && lookupItems[index] && String(lookupItems[index].id) === String(selectedItem.id)));
        });
      }

      setStatus(selectedItem ? `Selected ${selectedItem.name}. Add storage and quantity, then add it to the draft.` : 'Pick an existing item.');
    };

    const renderResults = (items, query) => {
      lookupItems = items;
      selectedItem = null;

      if (selectedWrap instanceof HTMLElement) {
        selectedWrap.hidden = true;
        selectedWrap.innerHTML = '';
      }

      if (!items.length) {
        results.innerHTML = `<p class="empty-state">No existing item found for "${escapeHtml(query)}". Create it from Items first, then come back here.</p>`;
        return;
      }

      results.innerHTML = items.map((item, index) => `
        <button class="scan-manual-result-card" type="button" data-manual-stock-result-index="${index}">
          ${itemImageMarkup(item)}
          <span>
            <strong>${escapeHtml(item.name)}</strong>
            <small>${escapeHtml([item.sku, item.barcode || 'No barcode', item.unit].filter(Boolean).join(' · '))}</small>
            <small>${escapeHtml(item.location_summary || 'No assigned locations')}</small>
          </span>
          <em>${escapeHtml(item.quantity)} ${escapeHtml(item.unit)}</em>
        </button>
      `).join('');

      const exact = exactScanMatch(items, query);

      if (exact) {
        setSelectedItem(exact);
      }
    };

    const lookup = async (query) => {
      const normalized = String(query || '').trim();

      if (normalized === '') {
        lookupSequence += 1;
        lookupItems = [];
        setSelectedItem(null);
        results.innerHTML = '<p class="empty-state">Search and pick an existing catalog item.</p>';
        setStatus('Draft is empty. Add one or more items, review, then confirm.');
        return;
      }

      if (normalized.length < 2) {
        setStatus('Keep typing the item name, SKU, or barcode.');
        return;
      }

      const requestId = ++lookupSequence;
      setStatus(`Searching ${normalized}...`);

      const response = await fetch(`${lookupUrl}?q=${encodeURIComponent(normalized)}`, {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const payload = await response.json();

      if (requestId !== lookupSequence) {
        return;
      }

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'Manual item search failed.');
      }

      if (payload.open_url) {
        lookupItems = [];
        setSelectedItem(null);
        results.innerHTML = '<p class="empty-state">That looks like a workflow reference. Use the main Scan Center lookup to open it directly.</p>';
        setStatus('Manual add only accepts existing inventory items.', 'danger');
        return;
      }

      renderResults(payload.items || [], normalized);
      setStatus(payload.count > 0 ? `Found ${payload.count} existing item${payload.count === 1 ? '' : 's'}.` : 'No existing item found.', payload.count > 0 ? 'success' : 'danger');
    };

    const scheduleLookup = () => {
      const value = searchInput.value.trim();
      window.clearTimeout(lookupTimer);
      lookupTimer = window.setTimeout(async () => {
        try {
          await lookup(value);
        } catch (error) {
          setStatus(error.message || 'Manual item search failed.', 'danger');
        }
      }, looksLikeScanCode(value) ? 40 : 240);
    };

    const draftTotal = () => draftLines.reduce((sum, line) => sum + parseNumber(line.quantity), 0);

    const renderDraft = () => {
      const lineCount = draftLines.length;

      if (count instanceof HTMLElement) {
        count.textContent = `${lineCount} line${lineCount === 1 ? '' : 's'}`;
      }

      if (lineCount === 0) {
        draftWrap.innerHTML = '<p class="empty-state">No pending additions yet.</p>';

        if (summary instanceof HTMLElement) {
          summary.hidden = true;
          summary.innerHTML = '';
        }

        return;
      }

      draftWrap.innerHTML = draftLines.map((line, index) => `
        <div class="manual-stock-draft-row" data-manual-stock-draft-index="${index}">
          ${itemImageMarkup(line.item)}
          <span>
            <strong>${escapeHtml(line.item.name)}</strong>
            <small>${escapeHtml([line.item.sku, line.item.barcode || 'No barcode', line.item.unit].filter(Boolean).join(' · '))}</small>
            <small>${escapeHtml(line.storage_label)}</small>
            ${line.reference_code ? `<small>Ref: ${escapeHtml(line.reference_code)}</small>` : ''}
            ${line.notes ? `<small>Notes: ${escapeHtml(line.notes)}</small>` : ''}
          </span>
          <em>${escapeHtml(formatNumber(line.quantity))} ${escapeHtml(line.item.unit)}</em>
          <button class="ghost-button danger-link" type="button" data-manual-stock-remove>Remove</button>
        </div>
      `).join('');

      if (summary instanceof HTMLElement) {
        summary.hidden = false;
        summary.innerHTML = `
          <strong>${lineCount} pending line${lineCount === 1 ? '' : 's'}</strong>
          <span>${escapeHtml(formatNumber(draftTotal()))} total units across selected items</span>
        `;
      }
    };

    page.dataset.manualStockBound = 'true';

    searchInput.addEventListener('input', scheduleLookup);

    results.addEventListener('click', (event) => {
      const target = event.target;

      if (!(target instanceof Element)) {
        return;
      }

      const card = target.closest('[data-manual-stock-result-index]');
      const index = Number.parseInt(card?.getAttribute('data-manual-stock-result-index') || '-1', 10);

      if (index >= 0 && lookupItems[index]) {
        setSelectedItem(lookupItems[index]);
      }
    });

    lineForm.addEventListener('submit', (event) => {
      event.preventDefault();

      const storageId = storageSelect instanceof HTMLSelectElement ? storageSelect.value : '';
      const quantity = quantityInput instanceof HTMLInputElement ? parseNumber(quantityInput.value) : 0;

      if (!selectedItem) {
        setStatus('Pick an existing item first.', 'danger');
        return;
      }

      if (!storageId) {
        setStatus('Pick the storage receiving this stock.', 'danger');
        return;
      }

      if (quantity <= 0) {
        setStatus('Quantity must be greater than zero.', 'danger');
        return;
      }

      draftLines.push({
        item: selectedItem,
        item_id: String(selectedItem.id || ''),
        storage_id: storageId,
        storage_label: storageLabel(storageId),
        quantity: formatNumber(quantity),
        reference_code: referenceInput instanceof HTMLInputElement ? referenceInput.value.trim() : '',
        notes: notesInput instanceof HTMLInputElement ? notesInput.value.trim() : '',
      });

      if (quantityInput instanceof HTMLInputElement) {
        quantityInput.value = '';
      }

      if (referenceInput instanceof HTMLInputElement) {
        referenceInput.value = '';
      }

      if (notesInput instanceof HTMLInputElement) {
        notesInput.value = '';
      }

      searchInput.value = '';
      lookupItems = [];
      setSelectedItem(null);
      results.innerHTML = '<p class="empty-state">Search and pick another item, or confirm the draft below.</p>';
      renderDraft();
      setStatus('Added to draft. Review the pending list before confirming.', 'success');
    });

    draftWrap.addEventListener('click', (event) => {
      const target = event.target;

      if (!(target instanceof Element) || !target.closest('[data-manual-stock-remove]')) {
        return;
      }

      const row = target.closest('[data-manual-stock-draft-index]');
      const index = Number.parseInt(row?.getAttribute('data-manual-stock-draft-index') || '-1', 10);

      if (index >= 0) {
        draftLines.splice(index, 1);
        renderDraft();
        setStatus('Draft line removed.');
      }
    });

    if (clearButton instanceof HTMLButtonElement) {
      clearButton.addEventListener('click', () => {
        draftLines = [];
        renderDraft();
        setStatus('Draft cleared.');
      });
    }

    if (confirmButton instanceof HTMLButtonElement) {
      confirmButton.addEventListener('click', async () => {
        if (draftLines.length === 0) {
          setStatus('Add at least one item to the draft before confirming.', 'danger');
          return;
        }

        confirmButton.disabled = true;
        setStatus('Confirming manual stock additions...');

        const formData = new FormData();
        formData.append('_token', csrfToken(page));
        formData.append('lines', JSON.stringify(draftLines.map((line) => ({
          item_id: line.item_id,
          storage_id: line.storage_id,
          quantity: line.quantity,
          reference_code: line.reference_code,
          notes: line.notes,
        }))));

        try {
          const response = await fetch(submitUrl, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
          });
          const payload = await response.json();

          if (!response.ok || !payload.ok) {
            throw new Error(payload.errors?.join(' ') || payload.message || 'Manual stock add failed.');
          }

          draftLines = [];
          renderDraft();
          setStatus(payload.message || 'Manual stock additions saved.', 'success');
        } catch (error) {
          setStatus(error.message || 'Manual stock add failed.', 'danger');
        } finally {
          confirmButton.disabled = false;
        }
      });
    }

    renderDraft();
  });
};

export const initScanCenter = (root = document) => {
  root.querySelectorAll('[data-scan-center]').forEach((scanner) => {
    if (scanner.dataset.scanBound === 'true') {
      return;
    }

    const lookupUrl = scanner.dataset.scanLookupUrl;
    const manualRestockUrl = scanner.dataset.scanManualRestockUrl || '';
    const canCreateMovement = scanner.dataset.canCreateMovement === '1';
    const form = scanner.querySelector('[data-scan-form]');
    const input = scanner.querySelector('[data-scan-input]');
    const status = scanner.querySelector('[data-scan-status]');
    const results = scanner.querySelector('[data-scan-results]');
    const workspace = scanner.querySelector('[data-scan-workspace]');
    const selectedPanel = scanner.querySelector('[data-scan-selected]');
    const selectedBody = scanner.querySelector('[data-scan-selected-body]');
    const cameraToggle = scanner.querySelector('[data-scan-camera-toggle]');
    const cameraWrap = scanner.querySelector('[data-scan-camera]');
    const entryCameraSlot = scanner.querySelector('[data-scan-camera-slot="entry"]');
    const batchCameraSlot = scanner.querySelector('[data-scan-camera-slot="batch"]');
    const cameraStatus = scanner.querySelector('[data-scan-camera-status]');
    const video = scanner.querySelector('[data-scan-video]');
    const batchToggle = scanner.querySelector('[data-scan-batch-toggle]');
    const batchPanel = scanner.querySelector('[data-scan-batch-panel]');
    const batchList = scanner.querySelector('[data-scan-batch-list]');
    const batchStatus = scanner.querySelector('[data-scan-batch-status]');
    const batchForm = scanner.querySelector('[data-scan-batch-form]');
    const batchInput = scanner.querySelector('[data-scan-batch-input]');
    const batchType = scanner.querySelector('[data-scan-batch-type]');
    const batchStorage = scanner.querySelector('[data-scan-batch-storage]');
    const batchStorageLabel = scanner.querySelector('[data-scan-batch-storage-label]');
    const batchReference = scanner.querySelector('[data-scan-batch-reference]');
    const batchNotes = scanner.querySelector('[data-scan-batch-notes]');
    const batchSubmit = scanner.querySelector('[data-scan-batch-submit]');
    const batchClear = scanner.querySelector('[data-scan-batch-clear]');
    const batchCameraToggle = scanner.querySelector('[data-scan-batch-camera-toggle]');
    const manualForm = scanner.querySelector('[data-scan-manual-form]');
    const manualSearch = scanner.querySelector('[data-scan-manual-search]');
    const manualResults = scanner.querySelector('[data-scan-manual-results]');
    const manualItemId = scanner.querySelector('[data-scan-manual-item-id]');
    const manualStorage = scanner.querySelector('[data-scan-manual-storage]');
    const manualQuantity = scanner.querySelector('[data-scan-manual-quantity]');
    const manualStatus = scanner.querySelector('[data-scan-manual-status]');
    let storages = [];
    let movementTypes = [];
    let currentItems = [];
    let manualItems = [];
    let manualSelectedItem = null;
    let selectedItem = null;
    let batchMode = false;
    const batchItems = new Map();
    let cameraStream = null;
    let cameraScanning = false;
    let entryLookupTimer = null;
    let batchLookupTimer = null;
    let manualLookupTimer = null;
    let manualLookupSequence = 0;
    let lookupSequence = 0;
    let cameraLookupInFlight = false;
    let lastCameraCode = '';
    let lastCameraCodeAt = 0;

    try {
      storages = JSON.parse(scanner.dataset.scanStorages || '[]');
    } catch (error) {
      storages = [];
    }

    try {
      movementTypes = JSON.parse(scanner.dataset.scanMovementTypes || '[]');
    } catch (error) {
      movementTypes = [];
    }

    if (!lookupUrl || !(form instanceof HTMLFormElement) || !(input instanceof HTMLInputElement) || !results || !selectedPanel || !selectedBody) {
      return;
    }

    const setStatus = (message, type = '') => {
      if (!status) {
        return;
      }

      status.textContent = message;
      status.classList.toggle('danger-text', type === 'danger');
      status.classList.toggle('success-text', type === 'success');
    };

    const setManualStatus = (message, type = '') => {
      if (!manualStatus) {
        return;
      }

      manualStatus.textContent = message;
      manualStatus.classList.toggle('danger-text', type === 'danger');
      manualStatus.classList.toggle('success-text', type === 'success');
    };

    const setCameraStatus = (message, type = '') => {
      if (!cameraStatus) {
        return;
      }

      cameraStatus.textContent = message;
      cameraStatus.classList.toggle('danger-text', type === 'danger');
      cameraStatus.classList.toggle('success-text', type === 'success');
    };

    const placeCamera = (target = 'entry') => {
      if (!(cameraWrap instanceof HTMLElement)) {
        return;
      }

      const slot = target === 'batch' ? batchCameraSlot : entryCameraSlot;

      if (slot instanceof HTMLElement && cameraWrap.parentElement !== slot) {
        slot.appendChild(cameraWrap);
      }
    };

    const setWorkspaceEmpty = (isEmpty) => {
      if (workspace instanceof HTMLElement) {
        workspace.classList.toggle('scan-workspace-empty', isEmpty);
      }
    };

    const nowDateTimeLocal = () => {
      const now = new Date();
      now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
      return now.toISOString().slice(0, 16);
    };

    const itemImageMarkup = (item, className = 'scan-item-thumb') => (
      item.image_url
        ? `<img class="${className}" src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}">`
        : `<span class="${className} scan-item-thumb-fallback">${escapeHtml(String(item.name || 'I').slice(0, 1).toUpperCase())}</span>`
    );

    const exactScanMatch = (items, query) => items.find((item) => String(item.scan_code || '').toLowerCase() === String(query || '').toLowerCase())
      || items.find((item) => String(item.barcode || '').toLowerCase() === String(query || '').toLowerCase())
      || items.find((item) => String(item.sku || '').toLowerCase() === String(query || '').toLowerCase())
      || (items.length === 1 ? items[0] : null);

    const renderResults = (items, query, options = {}) => {
      currentItems = items;

      if (!items.length) {
        results.innerHTML = `<p class="empty-state">No item found for "${escapeHtml(query)}". Try barcode, SKU, or item name.</p>`;
        selectedPanel.hidden = true;
        selectedItem = null;
        setWorkspaceEmpty(true);
        return;
      }

      results.innerHTML = items.map((item, index) => `
        <button class="scan-result-card" type="button" data-scan-result-index="${index}">
          ${itemImageMarkup(item)}
          <span>
            <strong>${escapeHtml(item.name)}</strong>
            <small>${escapeHtml([item.sku, item.barcode || 'No barcode', item.unit].filter(Boolean).join(' · '))}</small>
            <small>${escapeHtml(item.location_summary || 'No assigned locations')}</small>
          </span>
          <em>${escapeHtml(item.quantity)} ${escapeHtml(item.unit)}</em>
        </button>
      `).join('');

      const exact = exactScanMatch(items, query);

      if (exact && !options.suppressAutoSelect) {
        selectItem(exact);
      }

      return exact;
    };

    const setManualSelectedItem = (item) => {
      manualSelectedItem = item;

      if (manualItemId instanceof HTMLInputElement) {
        manualItemId.value = item ? String(item.id || '') : '';
      }

      if (manualResults instanceof HTMLElement) {
        manualResults.querySelectorAll('[data-scan-manual-result-index]').forEach((card) => {
          const index = Number.parseInt(card.getAttribute('data-scan-manual-result-index') || '-1', 10);
          card.classList.toggle('is-selected', Boolean(item && manualItems[index] && String(manualItems[index].id) === String(item.id)));
        });
      }

      if (item) {
        setManualStatus(`Selected ${item.name}. Pick storage and quantity to add.`, 'success');
      }
    };

    const renderManualResults = (items, query) => {
      manualItems = items;
      manualSelectedItem = null;

      if (manualItemId instanceof HTMLInputElement) {
        manualItemId.value = '';
      }

      if (!(manualResults instanceof HTMLElement)) {
        return;
      }

      if (!items.length) {
        manualResults.innerHTML = `<p class="empty-state">No existing item found for "${escapeHtml(query)}". Create the item first, then add stock here.</p>`;
        return;
      }

      manualResults.innerHTML = items.map((item, index) => `
        <button class="scan-manual-result-card" type="button" data-scan-manual-result-index="${index}">
          ${itemImageMarkup(item)}
          <span>
            <strong>${escapeHtml(item.name)}</strong>
            <small>${escapeHtml([item.sku, item.barcode || 'No barcode', item.unit].filter(Boolean).join(' · '))}</small>
            <small>${escapeHtml(item.location_summary || 'No assigned locations')}</small>
          </span>
          <em>${escapeHtml(item.quantity)} ${escapeHtml(item.unit)}</em>
        </button>
      `).join('');

      const exact = exactScanMatch(items, query);

      if (exact) {
        setManualSelectedItem(exact);
      }
    };

    const manualLookup = async (query) => {
      const normalized = String(query || '').trim();

      if (normalized === '') {
        manualLookupSequence += 1;
        manualItems = [];
        manualSelectedItem = null;
        if (manualItemId instanceof HTMLInputElement) {
          manualItemId.value = '';
        }
        if (manualResults instanceof HTMLElement) {
          manualResults.innerHTML = '<p class="empty-state">Search and pick an existing catalog item.</p>';
        }
        setManualStatus('Manual add creates a restock movement and updates the selected storage balance.');
        return;
      }

      if (normalized.length < 2) {
        setManualStatus('Keep typing the item name, SKU, or barcode.');
        return;
      }

      const requestId = ++manualLookupSequence;
      setManualStatus(`Searching ${normalized}...`);

      const response = await fetch(`${lookupUrl}?q=${encodeURIComponent(normalized)}`, {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const payload = await response.json();

      if (requestId !== manualLookupSequence) {
        return;
      }

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'Manual item search failed.');
      }

      if (payload.open_url) {
        manualItems = [];
        manualSelectedItem = null;
        if (manualResults instanceof HTMLElement) {
          manualResults.innerHTML = '<p class="empty-state">That looks like a workflow reference. Use the main Scan Center lookup to open it directly.</p>';
        }
        setManualStatus('Manual add only accepts existing inventory items.', 'danger');
        return;
      }

      renderManualResults(payload.items || [], normalized);
      setManualStatus(payload.count > 0 ? `Found ${payload.count} existing item${payload.count === 1 ? '' : 's'}.` : 'No existing item found.', payload.count > 0 ? 'success' : 'danger');
    };

    const scheduleManualLookup = () => {
      if (!(manualSearch instanceof HTMLInputElement)) {
        return;
      }

      const value = manualSearch.value.trim();
      window.clearTimeout(manualLookupTimer);

      manualLookupTimer = window.setTimeout(async () => {
        try {
          await manualLookup(value);
        } catch (error) {
          setManualStatus(error.message || 'Manual item search failed.', 'danger');
        }
      }, looksLikeScanCode(value) ? 40 : 240);
    };

    const resetLookupState = () => {
      lookupSequence += 1;
      currentItems = [];
      selectedItem = null;
      selectedPanel.hidden = true;
      results.innerHTML = '<p class="empty-state">Scan or search to see item matches.</p>';
      setWorkspaceEmpty(true);
      setStatus('Ready for scan.');
    };

    const storageOptions = (selectedStorageId = '') => storages.map((storage) => (
      `<option value="${escapeHtml(storage.id)}"${String(storage.id) === String(selectedStorageId) ? ' selected' : ''}>${escapeHtml(storage.type)} · ${escapeHtml(storage.name)}</option>`
    )).join('');

    const movementTypeOptionsMarkup = () => movementTypes.map((type) => (
      `<option value="${escapeHtml(type.value)}">${escapeHtml(type.label)}</option>`
    )).join('');

    const movementStorageLabel = (type) => (type === 'restock' ? 'To Location' : 'From Location');

    const balanceRows = (item) => {
      if (!Array.isArray(item.balances) || item.balances.length === 0) {
        return '<p class="empty-state">No assigned locations yet.</p>';
      }

      return `
        <div class="scan-balance-list">
          ${item.balances.map((balance) => `
            <div class="scan-balance-row">
              <span>
                <strong>${escapeHtml(balance.name)}</strong>
                <small>${escapeHtml(balance.type)} · Used ${escapeHtml(balance.used)} · In ${escapeHtml(balance.transferred_in)} · Out ${escapeHtml(balance.transferred_out)}</small>
              </span>
              <em>${escapeHtml(balance.quantity)} ${escapeHtml(item.unit)}</em>
            </div>
          `).join('')}
        </div>
      `;
    };

    const packagePresetsForItem = (item) => (
      Array.isArray(item.package_presets)
        ? item.package_presets.filter((preset) => parseNumber(preset.pieces_per_unit_raw ?? preset.pieces_per_unit) > 0)
        : []
    );

    const packagePresetById = (item, presetId) => packagePresetsForItem(item).find((preset) => String(preset.id) === String(presetId)) || null;

    const defaultPackagePreset = (item) => packagePresetsForItem(item).find((preset) => Number(preset.is_default) === 1) || packagePresetsForItem(item)[0] || null;

    const packageOptionMarkup = (item, selectedPresetId = '') => {
      const presets = packagePresetsForItem(item);
      const selected = selectedPresetId || defaultPackagePreset(item)?.id || 'custom';

      return [
        ...presets.map((preset) => `<option value="${escapeHtml(preset.id)}"${String(selected) === String(preset.id) ? ' selected' : ''}>${escapeHtml(preset.label)} · ${escapeHtml(preset.pieces_per_unit)} ${escapeHtml(item.unit)}</option>`),
        `<option value="custom"${String(selected) === 'custom' ? ' selected' : ''}>Custom package</option>`,
      ].join('');
    };

    const packageControlsMarkup = (item, namespace, quantityLabel = 'Quantity') => `
      <label class="field scan-quantity-field">
        <span>${escapeHtml(quantityLabel)}</span>
        <input type="number" step="0.01" min="0.01" name="${namespace === 'scan' ? 'scan_quantity' : ''}" placeholder="Type 1, 10, 100" data-${namespace}-quantity-input ${namespace === 'scan' ? 'required' : ''}>
      </label>
      <label class="field">
        <span>Count as</span>
        <select data-${namespace}-quantity-mode>
          <option value="pieces">Pieces / direct quantity</option>
          <option value="container">Package / box / bag</option>
        </select>
      </label>
      <div class="scan-package-controls" data-${namespace}-package-controls hidden>
        <label class="field">
          <span>Package type</span>
          <select data-${namespace}-package-preset>
            ${packageOptionMarkup(item)}
          </select>
        </label>
        <div class="scan-custom-package-fields" data-${namespace}-custom-package-fields hidden>
          <label class="field">
            <span>Custom label</span>
            <input type="text" placeholder="Box, bag, pack" value="Custom" data-${namespace}-package-custom-label>
          </label>
          <label class="field">
            <span>Contains</span>
            <input type="number" step="0.01" min="0.01" value="1" data-${namespace}-package-custom-pieces>
            <small>${escapeHtml(item.unit)} per package.</small>
          </label>
        </div>
      </div>
      <p class="scan-conversion-card tiny-copy" data-${namespace}-conversion>Direct pieces. Saved as ${escapeHtml(item.unit)}.</p>
    `;

    const quantityDetails = (scope, item, namespace) => {
      const quantityInput = scope.querySelector(`[data-${namespace}-quantity-input]`);
      const modeSelect = scope.querySelector(`[data-${namespace}-quantity-mode]`);
      const presetSelect = scope.querySelector(`[data-${namespace}-package-preset]`);
      const customLabelInput = scope.querySelector(`[data-${namespace}-package-custom-label]`);
      const customPiecesInput = scope.querySelector(`[data-${namespace}-package-custom-pieces]`);
      const count = parseNumber(quantityInput instanceof HTMLInputElement ? quantityInput.value : '');
      const mode = modeSelect instanceof HTMLSelectElement ? modeSelect.value : 'pieces';
      let piecesPerUnit = 1;
      let label = item.unit || 'pcs';

      if (mode === 'container') {
        const selectedPresetId = presetSelect instanceof HTMLSelectElement ? presetSelect.value : '';
        const preset = selectedPresetId !== 'custom' ? packagePresetById(item, selectedPresetId) : null;

        if (preset) {
          piecesPerUnit = parseNumber(preset.pieces_per_unit_raw ?? preset.pieces_per_unit);
          label = preset.label || 'Package';
        } else {
          piecesPerUnit = parseNumber(customPiecesInput instanceof HTMLInputElement ? customPiecesInput.value : '');
          label = (customLabelInput instanceof HTMLInputElement && customLabelInput.value.trim() !== '') ? customLabelInput.value.trim() : 'Custom package';
        }
      }

      const baseQuantity = mode === 'container' ? count * piecesPerUnit : count;
      const note = mode === 'container'
        ? `Scan conversion: ${formatNumber(count)} ${label} x ${formatNumber(piecesPerUnit)} ${item.unit} = ${formatNumber(baseQuantity)} ${item.unit}.`
        : '';

      return {
        mode,
        count,
        label,
        piecesPerUnit,
        baseQuantity,
        note,
        ok: count > 0 && (mode !== 'container' || piecesPerUnit > 0),
      };
    };

    const syncPackageControls = (scope, item, namespace) => {
      const modeSelect = scope.querySelector(`[data-${namespace}-quantity-mode]`);
      const presetSelect = scope.querySelector(`[data-${namespace}-package-preset]`);
      const controls = scope.querySelector(`[data-${namespace}-package-controls]`);
      const customFields = scope.querySelector(`[data-${namespace}-custom-package-fields]`);
      const conversion = scope.querySelector(`[data-${namespace}-conversion]`);
      const mode = modeSelect instanceof HTMLSelectElement ? modeSelect.value : 'pieces';
      const useContainer = mode === 'container';
      const useCustom = useContainer && presetSelect instanceof HTMLSelectElement && presetSelect.value === 'custom';

      if (controls instanceof HTMLElement) {
        controls.hidden = !useContainer;
      }

      if (customFields instanceof HTMLElement) {
        customFields.hidden = !useCustom;
      }

      const details = quantityDetails(scope, item, namespace);

      if (conversion instanceof HTMLElement) {
        if (details.mode === 'container') {
          conversion.textContent = details.ok
            ? `${formatNumber(details.count)} ${details.label} x ${formatNumber(details.piecesPerUnit)} ${item.unit} = ${formatNumber(details.baseQuantity)} ${item.unit}`
            : 'Enter package count and pieces per package.';
        } else {
          conversion.textContent = `Direct quantity. Saved as ${item.unit}.`;
        }
      }
    };

    const setBatchStatus = (message, type = '') => {
      if (!batchStatus) {
        return;
      }

      batchStatus.textContent = message;
      batchStatus.classList.toggle('danger-text', type === 'danger');
      batchStatus.classList.toggle('success-text', type === 'success');
    };

    const entryBaseQuantity = (entry) => {
      const count = parseNumber(entry.quantity);

      if (entry.quantityMode !== 'container') {
        return count;
      }

      let piecesPerUnit = parseNumber(entry.customPiecesPerUnit || 0);

      if (entry.packagePresetId && entry.packagePresetId !== 'custom') {
        const preset = packagePresetById(entry.item, entry.packagePresetId);
        piecesPerUnit = parseNumber(preset?.pieces_per_unit_raw ?? preset?.pieces_per_unit ?? 0);
      }

      return count * piecesPerUnit;
    };

    const entryConversionNote = (entry) => {
      if (entry.quantityMode !== 'container') {
        return '';
      }

      let label = entry.customPackageLabel || 'Custom package';
      let piecesPerUnit = parseNumber(entry.customPiecesPerUnit || 0);

      if (entry.packagePresetId && entry.packagePresetId !== 'custom') {
        const preset = packagePresetById(entry.item, entry.packagePresetId);
        label = preset?.label || label;
        piecesPerUnit = parseNumber(preset?.pieces_per_unit_raw ?? preset?.pieces_per_unit ?? piecesPerUnit);
      }

      return `Scan conversion: ${formatNumber(entry.quantity)} ${label} x ${formatNumber(piecesPerUnit)} ${entry.item.unit} = ${formatNumber(entryBaseQuantity(entry))} ${entry.item.unit}.`;
    };

    const batchTotalQuantity = () => Array.from(batchItems.values()).reduce((total, entry) => total + entryBaseQuantity(entry), 0);

    const selectedBatchMovementType = () => (batchType instanceof HTMLSelectElement ? batchType.value : (movementTypes[0]?.value || 'usage'));

    const selectedBatchStorageId = () => (batchStorage instanceof HTMLSelectElement ? batchStorage.value : '');

    const itemStorageBalance = (item, storageId) => {
      if (!Array.isArray(item.balances)) {
        return null;
      }

      return item.balances.find((balance) => String(balance.storage_id) === String(storageId)) || null;
    };

    const renderBatch = () => {
      if (!batchList) {
        return;
      }

      const entries = Array.from(batchItems.values());

      if (!entries.length) {
        batchList.innerHTML = '<p class="empty-state">Turn on Batch Mode, then scan items. Repeated scans add quantity automatically.</p>';
        setBatchStatus('Batch is empty.');
        return;
      }

      batchList.innerHTML = `
        <div class="scan-batch-table">
          ${entries.map((entry) => `
            <div class="scan-batch-row" data-scan-batch-item="${escapeHtml(entry.item.id)}">
              <div class="scan-batch-row-main">
                ${itemImageMarkup(entry.item, 'scan-item-thumb')}
                <span>
                  <strong>${escapeHtml(entry.item.name)}</strong>
                  <small>${escapeHtml([entry.item.sku, entry.item.barcode || 'No barcode', entry.item.unit].filter(Boolean).join(' · '))}</small>
                </span>
                <div class="scan-batch-qty">
                  <button type="button" data-scan-batch-dec aria-label="Decrease ${escapeHtml(entry.item.name)}">-</button>
                  <input type="number" min="0.01" step="0.01" value="${escapeHtml(entry.quantity)}" data-scan-batch-qty data-scan-batch-quantity-input>
                  <button type="button" data-scan-batch-inc aria-label="Increase ${escapeHtml(entry.item.name)}">+</button>
                </div>
                <button class="ghost-button danger-link" type="button" data-scan-batch-remove>Remove</button>
              </div>
              <div class="scan-batch-packaging">
                <label class="field compact-field">
                  <span>Count as</span>
                  <select data-scan-batch-quantity-mode>
                    <option value="pieces"${entry.quantityMode !== 'container' ? ' selected' : ''}>Pieces</option>
                    <option value="container"${entry.quantityMode === 'container' ? ' selected' : ''}>Package / box / bag</option>
                  </select>
                </label>
                <div class="scan-package-controls" data-scan-batch-package-controls${entry.quantityMode === 'container' ? '' : ' hidden'}>
                  <label class="field compact-field">
                    <span>Package type</span>
                    <select data-scan-batch-package-preset>
                      ${packageOptionMarkup(entry.item, entry.packagePresetId || '')}
                    </select>
                  </label>
                  <div class="scan-custom-package-fields" data-scan-batch-custom-package-fields${entry.packagePresetId === 'custom' && entry.quantityMode === 'container' ? '' : ' hidden'}>
                    <label class="field compact-field">
                      <span>Label</span>
                      <input type="text" value="${escapeHtml(entry.customPackageLabel || 'Custom')}" data-scan-batch-package-custom-label>
                    </label>
                    <label class="field compact-field">
                      <span>Contains</span>
                      <input type="number" min="0.01" step="0.01" value="${escapeHtml(entry.customPiecesPerUnit || '1')}" data-scan-batch-package-custom-pieces>
                    </label>
                  </div>
                </div>
                <p class="scan-conversion-card tiny-copy" data-scan-batch-conversion>
                  ${entry.quantityMode === 'container' ? escapeHtml(entryConversionNote(entry)) : `Direct quantity. Saved as ${escapeHtml(entry.item.unit)}.`}
                </p>
              </div>
            </div>
          `).join('')}
        </div>
      `;

      setBatchStatus(`${entries.length} item${entries.length === 1 ? '' : 's'} · ${formatNumber(batchTotalQuantity())} total base units`, 'success');
    };

    const addItemToBatch = (item, quantity = 1) => {
      if (!canCreateMovement) {
        return;
      }

      const key = String(item.id);
      const existing = batchItems.get(key);

      if (existing) {
        existing.quantity = formatNumber(parseNumber(existing.quantity) + quantity);
      } else {
        const defaultPreset = defaultPackagePreset(item);
        batchItems.set(key, {
          item,
          quantity: formatNumber(quantity),
          quantityMode: 'pieces',
          packagePresetId: defaultPreset ? String(defaultPreset.id) : 'custom',
          customPackageLabel: 'Custom',
          customPiecesPerUnit: '1',
        });
      }

      renderBatch();
      input.value = '';
      if (batchInput instanceof HTMLInputElement) {
        batchInput.value = '';
      }
      (batchMode && batchInput instanceof HTMLInputElement ? batchInput : input).focus();
    };

    const clearBatch = () => {
      batchItems.clear();
      renderBatch();
    };

    const updateBatchEntryFromRow = (row) => {
      if (!(row instanceof Element)) {
        return null;
      }

      const itemId = row.getAttribute('data-scan-batch-item') || '';
      const entry = batchItems.get(itemId);

      if (!entry) {
        return null;
      }

      const quantityInput = row.querySelector('[data-scan-batch-qty]');
      const modeSelect = row.querySelector('[data-scan-batch-quantity-mode]');
      const presetSelect = row.querySelector('[data-scan-batch-package-preset]');
      const customLabelInput = row.querySelector('[data-scan-batch-package-custom-label]');
      const customPiecesInput = row.querySelector('[data-scan-batch-package-custom-pieces]');

      if (quantityInput instanceof HTMLInputElement) {
        entry.quantity = quantityInput.value;
      }

      if (modeSelect instanceof HTMLSelectElement) {
        entry.quantityMode = modeSelect.value;
      }

      if (presetSelect instanceof HTMLSelectElement) {
        entry.packagePresetId = presetSelect.value;
      }

      if (customLabelInput instanceof HTMLInputElement) {
        entry.customPackageLabel = customLabelInput.value;
      }

      if (customPiecesInput instanceof HTMLInputElement) {
        entry.customPiecesPerUnit = customPiecesInput.value;
      }

      syncPackageControls(row, entry.item, 'scan-batch');

      return entry;
    };

    const setBatchMode = (enabled) => {
      batchMode = enabled && canCreateMovement;

      if (!batchMode && cameraScanning) {
        stopCamera();
      }

      if (!batchMode) {
        placeCamera('entry');
      }

      if (batchPanel instanceof HTMLElement) {
        batchPanel.hidden = !batchMode;
      }

      if (batchToggle instanceof HTMLButtonElement) {
        batchToggle.setAttribute('aria-pressed', batchMode ? 'true' : 'false');
        batchToggle.classList.toggle('is-active', batchMode);
        const label = batchToggle.querySelector('span:last-child');

        if (label) {
          label.textContent = batchMode ? 'Batch On' : 'Batch Mode';
        }
      }

      setStatus(batchMode ? 'Batch Mode is on. Scan the same item again to increase quantity.' : 'Ready for scan.', batchMode ? 'success' : '');
      setCameraButtonLabels();

      if (batchMode && batchInput instanceof HTMLInputElement) {
        window.setTimeout(() => {
          batchPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          batchInput.focus();
        }, 0);
      }
    };

    const setCameraButtonLabels = () => {
      if (cameraToggle instanceof HTMLButtonElement) {
        const label = cameraToggle.querySelector('span:last-child');

        if (label) {
          label.textContent = cameraScanning ? 'Stop Camera Scan' : 'Start Camera Scan';
        }
      }

      if (batchCameraToggle instanceof HTMLButtonElement) {
        const label = batchCameraToggle.querySelector('span:last-child');

        if (label) {
          label.textContent = cameraScanning && batchMode ? 'Stop Batch Camera' : 'Start Batch Camera Scan';
        }
      }
    };

    const selectItem = (item) => {
      selectedItem = item;
      setWorkspaceEmpty(false);
      const defaultStorage = Array.isArray(item.balances)
        ? (item.balances.find((balance) => parseNumber(balance.quantity_raw) > 0) || item.balances[0])
        : null;

      selectedPanel.hidden = false;
      selectedBody.innerHTML = `
        <div class="scan-selected-head">
          ${itemImageMarkup(item, 'scan-selected-image')}
          <div>
            <p class="eyebrow">Selected Item</p>
            <h3>${escapeHtml(item.name)}</h3>
            <p>${escapeHtml([item.sku, item.barcode || 'No barcode', item.category || 'No category'].filter(Boolean).join(' · '))}</p>
          </div>
          <div class="scan-selected-stock">
            <strong>${escapeHtml(item.quantity)}</strong>
            <span>${escapeHtml(item.unit)} on hand</span>
            <small>${escapeHtml(item.stock_value)} stock value</small>
          </div>
        </div>

        ${balanceRows(item)}

        <div class="scan-selected-actions">
          <a class="ghost-button" href="${escapeHtml(item.item_url)}">Open Item</a>
          <a class="ghost-button" href="${escapeHtml(item.label_url)}">${uiIconFallback('labels')}<span>Print Label</span></a>
        </div>

        ${canCreateMovement ? `
          <form class="scan-quick-form" data-scan-movement-form>
            <div class="scan-quick-grid">
              <label class="field">
                <span>Action</span>
                <select name="scan_movement_type" data-scan-movement-type>
                  ${movementTypeOptionsMarkup()}
                </select>
              </label>
              <label class="field">
                <span data-scan-storage-label>${movementStorageLabel(movementTypes[0]?.value || 'usage')}</span>
                <select name="scan_storage_id" required>
                  <option value="">Pick location</option>
                  ${storageOptions(defaultStorage?.storage_id || '')}
                </select>
              </label>
              ${packageControlsMarkup(item, 'scan', 'Quantity')}
              <label class="field">
                <span>Reference</span>
                <input type="text" name="scan_reference" placeholder="Scan, event, note">
              </label>
            </div>
            <label class="field">
              <span>Notes</span>
              <input type="text" name="scan_notes" placeholder="Optional quick movement note">
            </label>
            <button class="primary-button" type="submit">Save Quick Movement</button>
            <p class="tiny-copy" data-scan-movement-status>Usage subtracts automatically. Restock adds to the selected location.</p>
          </form>
        ` : '<p class="empty-state">You can scan and open items, but you do not have permission to create movements.</p>'}
      `;

      const quickForm = selectedBody.querySelector('[data-scan-movement-form]');
      if (quickForm instanceof HTMLElement) {
        syncPackageControls(quickForm, item, 'scan');
      }
    };

    const uiIconFallback = (name) => `<span class="ui-icon ui-icon-${escapeHtml(name)}" aria-hidden="true"></span>`;

    const lookup = async (query, options = {}) => {
      const normalized = String(query || '').trim();

      if (normalized === '') {
        resetLookupState();
        return;
      }

      const requestId = ++lookupSequence;
      setStatus(`Looking up ${normalized}...`);

      const response = await fetch(`${lookupUrl}?q=${encodeURIComponent(normalized)}`, {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const payload = await response.json();

      if (requestId !== lookupSequence) {
        return;
      }

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'Lookup failed.');
      }

      if (payload.open_url) {
        setStatus(payload.message || `Opening ${payload.open_reference || normalized}...`, 'success');
        window.location.href = payload.open_url;
        return;
      }

      const items = payload.items || [];
      const exact = renderResults(items, normalized, {
        suppressAutoSelect: batchMode && options.addToBatch,
      });

      if (batchMode && options.addToBatch) {
        if (exact) {
          addItemToBatch(exact);
          const addedMessage = `Added ${exact.name}. Quantity is now ${batchItems.get(String(exact.id))?.quantity || '1'}.`;
          setStatus(addedMessage, 'success');
          setBatchStatus(addedMessage, 'success');
          return;
        }

        const batchMessage = items.length > 1 ? 'Multiple matches found. Tap the correct item to add it to the batch.' : 'No matching item found.';
        const batchMessageType = items.length > 1 ? '' : 'danger';
        setStatus(batchMessage, batchMessageType);
        setBatchStatus(batchMessage, batchMessageType);
        return;
      }

      setStatus(payload.count > 0 ? `Found ${payload.count} match${payload.count === 1 ? '' : 'es'}.` : 'No matching item found.', payload.count > 0 ? 'success' : 'danger');
    };

    const scheduleLookup = () => {
      const value = input.value.trim();
      window.clearTimeout(entryLookupTimer);

      if (value === '') {
        resetLookupState();
        return;
      }

      if (value.length < 2) {
        lookupSequence += 1;
        currentItems = [];
        selectedItem = null;
        selectedPanel.hidden = true;
        results.innerHTML = '<p class="empty-state">Scan or search to see item matches.</p>';
        setWorkspaceEmpty(true);
        setStatus('Keep typing or scan a full code.');
        return;
      }

      entryLookupTimer = window.setTimeout(async () => {
        try {
          await lookup(value, { addToBatch: batchMode });
        } catch (error) {
          setStatus(error.message || 'Lookup failed.', 'danger');
        }
      }, looksLikeScanCode(value) ? 40 : 260);
    };

    const scheduleBatchLookup = () => {
      if (!(batchInput instanceof HTMLInputElement)) {
        return;
      }

      const value = batchInput.value.trim();
      window.clearTimeout(batchLookupTimer);

      if (value === '') {
        setBatchStatus('Batch scan field is ready.');
        return;
      }

      if (value.length < 2) {
        setBatchStatus('Keep typing or scan the full barcode.');
        return;
      }

      batchLookupTimer = window.setTimeout(async () => {
        try {
          await lookup(value, { addToBatch: true });
        } catch (error) {
          setBatchStatus(error.message || 'Batch lookup failed.', 'danger');
        }
      }, looksLikeScanCode(value) ? 40 : 220);
    };

    const stopCamera = () => {
      cameraScanning = false;

      if (cameraStream) {
        cameraStream.getTracks().forEach((track) => track.stop());
        cameraStream = null;
      }

      if (video instanceof HTMLVideoElement) {
        video.srcObject = null;
      }

      if (cameraWrap instanceof HTMLElement) {
        cameraWrap.hidden = true;
      }

      setCameraButtonLabels();
    };

    const startCamera = async () => {
      placeCamera(batchMode ? 'batch' : 'entry');

      if (!('BarcodeDetector' in window)) {
        setCameraStatus('This browser does not support camera barcode scanning. Use a hardware scanner or type the barcode.', 'danger');
        setStatus('Camera barcode scanning is not supported here. Type or scan with a hardware barcode scanner.', 'danger');
        return;
      }

      if (!navigator.mediaDevices?.getUserMedia || !(video instanceof HTMLVideoElement)) {
        setCameraStatus('Camera access is not available in this browser.', 'danger');
        setStatus('Camera access is not available. Type or scan with a hardware barcode scanner.', 'danger');
        return;
      }

      cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
      video.srcObject = cameraStream;
      await video.play();

      if (cameraWrap instanceof HTMLElement) {
        cameraWrap.hidden = false;

        if (batchMode) {
          cameraWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }

      const detector = new window.BarcodeDetector({
        formats: ['code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code'],
      });
      cameraScanning = true;
      setCameraButtonLabels();
      setCameraStatus('Scanning...');

      const tick = async () => {
        if (!cameraScanning) {
          return;
        }

        try {
          const codes = await detector.detect(video);

          if (codes.length > 0 && codes[0].rawValue) {
            const scannedCode = String(codes[0].rawValue).trim();
            if (batchMode && batchInput instanceof HTMLInputElement) {
              batchInput.value = scannedCode;
            } else {
              input.value = scannedCode;
            }
            const now = Date.now();

            if (batchMode) {
              if (!cameraLookupInFlight && (scannedCode !== lastCameraCode || now - lastCameraCodeAt > 1400)) {
                lastCameraCode = scannedCode;
                lastCameraCodeAt = now;
                cameraLookupInFlight = true;
                setCameraStatus(`Detected ${scannedCode}. Added to batch.`, 'success');

                try {
                  await lookup(scannedCode, { addToBatch: true });
                } finally {
                  cameraLookupInFlight = false;
                }
              }

              window.setTimeout(() => window.requestAnimationFrame(tick), 260);
              return;
            }

            setCameraStatus(`Detected ${scannedCode}.`, 'success');
            stopCamera();
            await lookup(scannedCode);
            return;
          }
        } catch (error) {
          setCameraStatus(error.message || 'Camera scan failed.', 'danger');
          stopCamera();
          return;
        }

        window.requestAnimationFrame(tick);
      };

      window.requestAnimationFrame(tick);
    };

    scanner.dataset.scanBound = 'true';

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      window.clearTimeout(entryLookupTimer);

      try {
        await lookup(input.value, { addToBatch: batchMode });
      } catch (error) {
        setStatus(error.message || 'Lookup failed.', 'danger');
      }
    });

    input.addEventListener('input', scheduleLookup);

    results.addEventListener('click', (event) => {
      const target = event.target;

      if (!(target instanceof Element)) {
        return;
      }

      const card = target.closest('[data-scan-result-index]');
      const index = Number.parseInt(card?.getAttribute('data-scan-result-index') || '-1', 10);

      if (index >= 0 && currentItems[index]) {
        if (batchMode) {
          addItemToBatch(currentItems[index]);
          setStatus(`Added ${currentItems[index].name}.`, 'success');
          return;
        }

        selectItem(currentItems[index]);
      }
    });

    if (manualSearch instanceof HTMLInputElement) {
      manualSearch.addEventListener('input', scheduleManualLookup);
    }

    if (manualResults instanceof HTMLElement) {
      manualResults.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Element)) {
          return;
        }

        const card = target.closest('[data-scan-manual-result-index]');
        const index = Number.parseInt(card?.getAttribute('data-scan-manual-result-index') || '-1', 10);

        if (index >= 0 && manualItems[index]) {
          setManualSelectedItem(manualItems[index]);
        }
      });
    }

    if (manualForm instanceof HTMLFormElement) {
      manualForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const selectedId = manualItemId instanceof HTMLInputElement ? manualItemId.value : '';
        const storageId = manualStorage instanceof HTMLSelectElement ? manualStorage.value : '';
        const quantity = manualQuantity instanceof HTMLInputElement ? parseNumber(manualQuantity.value) : 0;

        if (!manualRestockUrl) {
          setManualStatus('Manual stock add is disabled in Website Control.', 'danger');
          return;
        }

        if (!selectedId || !manualSelectedItem) {
          setManualStatus('Pick an existing item first.', 'danger');
          return;
        }

        if (!storageId) {
          setManualStatus('Pick the storage receiving this stock.', 'danger');
          return;
        }

        if (quantity <= 0) {
          setManualStatus('Quantity must be greater than zero.', 'danger');
          return;
        }

        const submitButton = manualForm.querySelector('button[type="submit"]');
        const formData = new FormData(manualForm);
        formData.append('_token', csrfToken(scanner));

        if (submitButton instanceof HTMLButtonElement) {
          submitButton.disabled = true;
        }

        setManualStatus('Saving manual stock add...');

        try {
          const response = await fetch(manualRestockUrl, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
          });
          const payload = await response.json();

          if (!response.ok || !payload.ok) {
            throw new Error(payload.errors?.join(' ') || payload.message || 'Manual stock add failed.');
          }

          if (payload.item) {
            manualItems = manualItems.map((item) => String(item.id) === String(payload.item.id) ? payload.item : item);
            setManualSelectedItem(payload.item);

            if (selectedItem && String(selectedItem.id) === String(payload.item.id)) {
              selectItem(payload.item);
            }
          }

          if (manualQuantity instanceof HTMLInputElement) {
            manualQuantity.value = '';
          }

          manualForm.querySelectorAll('[name="reference_code"], [name="notes"]').forEach((inputElement) => {
            if (inputElement instanceof HTMLInputElement) {
              inputElement.value = '';
            }
          });

          setManualStatus(payload.message || 'Manual stock add saved.', 'success');
        } catch (error) {
          setManualStatus(error.message || 'Manual stock add failed.', 'danger');
        } finally {
          if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = false;
          }
        }
      });
    }

    selectedBody.addEventListener('change', (event) => {
      const target = event.target;

      if (!(target instanceof HTMLSelectElement)) {
        return;
      }

      if (target.matches('[data-scan-movement-type]')) {
        const label = selectedBody.querySelector('[data-scan-storage-label]');

        if (label) {
          label.textContent = target.value === 'restock' ? 'To Location' : 'From Location';
        }
      }

      if (selectedItem && (target.matches('[data-scan-quantity-mode]') || target.matches('[data-scan-package-preset]'))) {
        const movementForm = target.closest('[data-scan-movement-form]');

        if (movementForm instanceof HTMLElement) {
          syncPackageControls(movementForm, selectedItem, 'scan');
        }
      }
    });

    selectedBody.addEventListener('input', (event) => {
      const target = event.target;

      if (!selectedItem || !(target instanceof HTMLInputElement)) {
        return;
      }

      if (!target.matches('[data-scan-quantity-input], [data-scan-package-custom-label], [data-scan-package-custom-pieces]')) {
        return;
      }

      const movementForm = target.closest('[data-scan-movement-form]');

      if (movementForm instanceof HTMLElement) {
        syncPackageControls(movementForm, selectedItem, 'scan');
      }
    });

    selectedBody.addEventListener('submit', async (event) => {
      const movementForm = event.target;

      if (!(movementForm instanceof HTMLFormElement) || !movementForm.matches('[data-scan-movement-form]') || !selectedItem) {
        return;
      }

      event.preventDefault();

      const movementStatus = movementForm.querySelector('[data-scan-movement-status]');
      const movementType = movementForm.querySelector('[name="scan_movement_type"]')?.value || 'usage';
      const storageId = movementForm.querySelector('[name="scan_storage_id"]')?.value || '';
      const reference = movementForm.querySelector('[name="scan_reference"]')?.value || '';
      const notes = movementForm.querySelector('[name="scan_notes"]')?.value || '';
      const quantityInfo = quantityDetails(movementForm, selectedItem, 'scan');
      const formData = new FormData();

      if (!quantityInfo.ok) {
        if (movementStatus) {
          movementStatus.textContent = 'Enter a valid quantity and package size.';
          movementStatus.classList.add('danger-text');
        }
        return;
      }

      formData.append('_token', csrfToken(scanner));
      formData.append('movement_type', movementType);
      formData.append('quantity', formatNumber(quantityInfo.baseQuantity));
      formData.append('used_at', nowDateTimeLocal());
      formData.append('reference_code', reference);
      formData.append('notes', [notes, quantityInfo.note].filter(Boolean).join(' '));
      formData.append('source_storage_id', movementType === 'usage' ? storageId : '');
      formData.append('destination_storage_id', movementType === 'restock' ? storageId : '');

      if (movementStatus) {
        movementStatus.textContent = 'Saving movement...';
        movementStatus.classList.remove('danger-text', 'success-text');
      }

      try {
        const response = await fetch(selectedItem.movement_url, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: formData,
        });
        const payload = await response.json();

        if (!response.ok) {
          throw new Error(payload.errors?.join(' ') || payload.message || 'Movement failed.');
        }

        if (movementStatus) {
          movementStatus.textContent = payload.message || 'Movement saved.';
          movementStatus.classList.add('success-text');
        }

        movementForm.reset();
        await lookup(selectedItem.scan_code || selectedItem.sku);
      } catch (error) {
        if (movementStatus) {
          movementStatus.textContent = error.message || 'Movement failed.';
          movementStatus.classList.add('danger-text');
        }
      }
    });

    if (batchToggle instanceof HTMLButtonElement) {
      batchToggle.addEventListener('click', () => {
        setBatchMode(!batchMode);
      });
    }

    if (batchType instanceof HTMLSelectElement) {
      batchType.addEventListener('change', () => {
        if (batchStorageLabel) {
          batchStorageLabel.textContent = batchType.value === 'restock' ? 'To Location' : 'From Location';
        }
      });
    }

    if (batchClear instanceof HTMLButtonElement) {
      batchClear.addEventListener('click', clearBatch);
    }

    if (batchForm instanceof HTMLFormElement && batchInput instanceof HTMLInputElement) {
      batchForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        window.clearTimeout(batchLookupTimer);

        try {
          await lookup(batchInput.value, { addToBatch: true });
        } catch (error) {
          setBatchStatus(error.message || 'Batch lookup failed.', 'danger');
        }
      });

      batchInput.addEventListener('input', scheduleBatchLookup);
    }

    if (batchCameraToggle instanceof HTMLButtonElement) {
      batchCameraToggle.addEventListener('click', async () => {
        if (cameraScanning) {
          stopCamera();
          return;
        }

        try {
          setBatchMode(true);
          placeCamera('batch');
          await startCamera();
        } catch (error) {
          setCameraStatus(error.message || 'Could not start batch camera scanner.', 'danger');
        }
      });
    }

    if (batchList) {
      batchList.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Element)) {
          return;
        }

        const row = target.closest('[data-scan-batch-item]');
        const itemId = row?.getAttribute('data-scan-batch-item') || '';
        const entry = batchItems.get(itemId);

        if (!entry) {
          return;
        }

        if (target.closest('[data-scan-batch-remove]')) {
          batchItems.delete(itemId);
          renderBatch();
          return;
        }

        if (target.closest('[data-scan-batch-inc]')) {
          entry.quantity = formatNumber(parseNumber(entry.quantity) + 1);
          renderBatch();
          return;
        }

        if (target.closest('[data-scan-batch-dec]')) {
          const nextQuantity = parseNumber(entry.quantity) - 1;

          if (nextQuantity <= 0) {
            batchItems.delete(itemId);
          } else {
            entry.quantity = formatNumber(nextQuantity);
          }

          renderBatch();
        }
      });

      batchList.addEventListener('input', (event) => {
        const target = event.target;

        if (!(target instanceof HTMLInputElement) || !target.matches('[data-scan-batch-qty], [data-scan-batch-package-custom-label], [data-scan-batch-package-custom-pieces]')) {
          return;
        }

        const row = target.closest('[data-scan-batch-item]');
        const entry = updateBatchEntryFromRow(row);

        if (!entry) {
          return;
        }

        setBatchStatus(`${batchItems.size} item${batchItems.size === 1 ? '' : 's'} · ${formatNumber(batchTotalQuantity())} total base units`, 'success');
      });

      batchList.addEventListener('change', (event) => {
        const target = event.target;

        if (!(target instanceof HTMLSelectElement) || !target.matches('[data-scan-batch-quantity-mode], [data-scan-batch-package-preset]')) {
          return;
        }

        const row = target.closest('[data-scan-batch-item]');
        const entry = updateBatchEntryFromRow(row);

        if (!entry) {
          return;
        }

        setBatchStatus(`${batchItems.size} item${batchItems.size === 1 ? '' : 's'} · ${formatNumber(batchTotalQuantity())} total base units`, 'success');
      });
    }

    if (batchSubmit instanceof HTMLButtonElement) {
      batchSubmit.addEventListener('click', async () => {
        const entries = Array.from(batchItems.values());
        const movementType = selectedBatchMovementType();
        const storageId = selectedBatchStorageId();

        if (!entries.length) {
          setBatchStatus('Scan at least one item before saving.', 'danger');
          return;
        }

        if (storageId === '') {
          setBatchStatus('Pick the location for this batch.', 'danger');
          return;
        }

        for (const entry of entries) {
          const count = parseNumber(entry.quantity);
          const quantity = entryBaseQuantity(entry);

          if (count <= 0 || quantity <= 0) {
            setBatchStatus(`Quantity must be greater than zero for ${entry.item.name}.`, 'danger');
            return;
          }

          if (movementType === 'usage') {
            const balance = itemStorageBalance(entry.item, storageId);
            const available = parseNumber(balance?.quantity_raw);

            if (!balance) {
              setBatchStatus(`${entry.item.name} is not assigned to the selected location.`, 'danger');
              return;
            }

            if (quantity > available) {
              setBatchStatus(`${entry.item.name} only has ${formatNumber(available)} ${entry.item.unit} in that location.`, 'danger');
              return;
            }
          }
        }

        batchSubmit.disabled = true;
        setBatchStatus('Saving batch movements...');

        try {
          let saved = 0;

          for (const entry of entries) {
            const conversionNote = entryConversionNote(entry);
            const batchNoteText = batchNotes instanceof HTMLInputElement ? batchNotes.value : '';
            const formData = new FormData();
            formData.append('_token', csrfToken(scanner));
            formData.append('movement_type', movementType);
            formData.append('quantity', formatNumber(entryBaseQuantity(entry)));
            formData.append('used_at', nowDateTimeLocal());
            formData.append('reference_code', batchReference instanceof HTMLInputElement ? batchReference.value : '');
            formData.append('notes', [batchNoteText, conversionNote].filter(Boolean).join(' '));
            formData.append('source_storage_id', movementType === 'usage' ? storageId : '');
            formData.append('destination_storage_id', movementType === 'restock' ? storageId : '');

            const response = await fetch(entry.item.movement_url, {
              method: 'POST',
              headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
              body: formData,
            });
            const payload = await response.json();

            if (!response.ok) {
              throw new Error(payload.errors?.join(' ') || payload.message || `Could not save ${entry.item.name}.`);
            }

            saved++;
          }

          clearBatch();
          resetLookupState();
          setBatchStatus(`Saved ${saved} movement${saved === 1 ? '' : 's'}.`, 'success');
        } catch (error) {
          setBatchStatus(error.message || 'Batch save failed.', 'danger');
        } finally {
          batchSubmit.disabled = false;
        }
      });
    }

    if (cameraToggle instanceof HTMLButtonElement) {
      cameraToggle.addEventListener('click', async () => {
        if (cameraScanning) {
          stopCamera();
          return;
        }

        try {
          if (batchMode) {
            setBatchMode(false);
          }

          placeCamera('entry');
          await startCamera();
        } catch (error) {
          setCameraStatus(error.message || 'Could not start camera scanner.', 'danger');
        }
      });
    }
  });
};

export const init = (root = document) => {
  initHandoverCloseForms(root);
  initHandoverApprovalForms(root);
  initHandoverReceiptReviews(root);
  initMovementForm(root);
  initHandoverTargetSwitchers(root);
  initWorkflowLineBuilders(root);
  initPurchaseLineBuilders(root);
  initPurchaseOcrImport(root);
  initPurchaseBulkImport(root);
  initManualStockAdd(root);
  initScanCenter(root);
};
