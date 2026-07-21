import { formatQuantity, parseNumber, showGlobalFlash } from '../core/runtime.js';

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

export const init = (root = document) => {
  initHandoverCloseForms(root);
  initHandoverApprovalForms(root);
  initHandoverReceiptReviews(root);
  initHandoverTargetSwitchers(root);
};
