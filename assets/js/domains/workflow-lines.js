import { escapeHtml, formatQuantity, parseNumber } from '../core/runtime.js';

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

export const init = (root = document) => {
  initWorkflowLineBuilders(root);
};
