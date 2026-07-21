import { escapeHtml } from '../core/runtime.js';

export const initReorderDraftForms = (root = document) => {
  root.querySelectorAll('[data-reorder-draft-form]').forEach((form) => {
    if (form.dataset.jsBound === 'true') {
      return;
    }

    let suppliers = [];

    try {
      suppliers = JSON.parse(form.dataset.reorderSuppliers || '[]');
    } catch (error) {
      suppliers = [];
    }

    const supplierById = new Map(suppliers.map((supplier) => [String(supplier.id), supplier]));
    const supplierIdInput = form.querySelector('[data-reorder-supplier-id]');
    const supplierLabel = form.querySelector('[data-reorder-supplier-label]');
    const supplierSummary = form.querySelector('[data-reorder-supplier-summary]');
    const supplierToggle = form.querySelector('[data-reorder-supplier-toggle]');
    const supplierPanel = form.querySelector('[data-reorder-supplier-panel]');
    const supplierSearch = form.querySelector('[data-reorder-supplier-search]');
    const supplierOptions = form.querySelector('[data-reorder-supplier-options]');
    const newSupplierCard = form.querySelector('[data-reorder-new-supplier]');
    const newSupplierInputs = Array.from(form.querySelectorAll('[data-reorder-new-supplier-input]'));
    const compactText = (value) => String(value || '').trim();
    const searchText = (...values) => values.map((value) => compactText(value).toLowerCase()).join(' ');

    const closeSupplierPanel = () => {
      if (supplierPanel) {
        supplierPanel.hidden = true;
      }

      if (supplierToggle) {
        supplierToggle.setAttribute('aria-expanded', 'false');
      }
    };

    const supplierSummaryMarkup = (supplier) => {
      const meta = [
        supplier.supplier_type_label ? `Type: ${supplier.supplier_type_label}` : '',
        supplier.supplier_type_other && !supplier.supplier_type_label ? `Type: ${supplier.supplier_type_other}` : '',
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

    const setNewSupplierVisible = (visible) => {
      if (newSupplierCard) {
        newSupplierCard.hidden = !visible;

        if (visible && newSupplierCard instanceof HTMLDetailsElement) {
          newSupplierCard.open = true;
        }
      }

      newSupplierInputs.forEach((input) => {
        input.disabled = !visible;
      });
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
          supplier.supplier_type_other
        ).includes(normalized);
      }).slice(0, 60);

      const selectedId = supplierIdInput instanceof HTMLInputElement ? supplierIdInput.value : '';
      supplierOptions.innerHTML = `
        <button class="purchase-picker-option ${selectedId === '' && newSupplierCard && !newSupplierCard.hidden ? 'is-selected' : ''}" type="button" value="__new__" data-reorder-supplier-option>
          <span class="purchase-picker-option-mark">+</span>
          <span><strong>Create new supplier</strong><small>Only then we show the mandatory supplier fields.</small></span>
        </button>
        ${rows.map((supplier) => `
          <button class="purchase-picker-option ${String(supplier.id) === selectedId ? 'is-selected' : ''}" type="button" value="${escapeHtml(supplier.id)}" data-reorder-supplier-option>
            <span class="purchase-picker-option-mark">${escapeHtml(String(supplier.name || 'S').slice(0, 2).toUpperCase())}</span>
            <span>
              <strong>${escapeHtml(supplier.name || 'Supplier')}</strong>
              <small>${escapeHtml([supplier.phone, supplier.email, supplier.tax_number || supplier.commercial_registration, supplier.authorized_person].filter(Boolean).join(' · ') || 'Saved supplier')}</small>
            </span>
          </button>
        `).join('')}
        ${rows.length === 0 ? '<p class="purchase-picker-empty">No saved suppliers match this search.</p>' : ''}
      `;
    };

    const selectSupplier = (id = '') => {
      const selectedId = String(id || '');
      const supplier = supplierById.get(selectedId);

      if (supplierIdInput instanceof HTMLInputElement) {
        supplierIdInput.value = supplier ? selectedId : '';
      }

      if (supplier) {
        if (supplierLabel) {
          supplierLabel.textContent = supplier.name || 'Selected supplier';
        }

        if (supplierSummary) {
          supplierSummary.hidden = false;
          supplierSummary.innerHTML = supplierSummaryMarkup(supplier);
        }

        setNewSupplierVisible(false);
        renderSupplierOptions(supplierSearch instanceof HTMLInputElement ? supplierSearch.value : '');
        return;
      }

      if (selectedId === '__new__') {
        if (supplierLabel) {
          supplierLabel.textContent = 'Create new supplier';
        }

        if (supplierSummary) {
          supplierSummary.hidden = true;
          supplierSummary.innerHTML = '';
        }

        setNewSupplierVisible(true);
        renderSupplierOptions(supplierSearch instanceof HTMLInputElement ? supplierSearch.value : '');
        return;
      }

      if (supplierLabel) {
        supplierLabel.textContent = 'Choose supplier';
      }

      if (supplierSummary) {
        supplierSummary.hidden = true;
        supplierSummary.innerHTML = '';
      }

      setNewSupplierVisible(false);
      renderSupplierOptions(supplierSearch instanceof HTMLInputElement ? supplierSearch.value : '');
    };

    form.dataset.jsBound = 'true';
    selectSupplier(suppliers.length === 0 ? '__new__' : '');

    if (supplierToggle && supplierPanel) {
      supplierToggle.addEventListener('click', () => {
        const willOpen = supplierPanel.hidden;
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

        const option = target.closest('[data-reorder-supplier-option]');

        if (!(option instanceof HTMLButtonElement)) {
          return;
        }

        selectSupplier(option.value);
        closeSupplierPanel();
      });
    }

    document.addEventListener('click', (event) => {
      const target = event.target;

      if (target instanceof Node && !form.contains(target)) {
        closeSupplierPanel();
      }
    });
  });
};

export const init = (root = document) => initReorderDraftForms(root);
