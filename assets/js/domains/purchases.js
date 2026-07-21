import { escapeHtml, parseNumber } from '../core/runtime.js';

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

export const init = (root = document) => {
  initPurchaseLineBuilders(root);
};
