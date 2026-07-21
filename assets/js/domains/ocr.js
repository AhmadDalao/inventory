import {
  browserOcrTextFromFiles,
  confidenceClass,
  confidenceScore,
  csrfToken,
  escapeHtml,
  formatCount,
  ocrConfidenceMarkup,
  parseNumber,
  postPurchaseOcr,
} from '../core/runtime.js';
import { confirmDialog } from '../ui/dialogs.js';
import { initSupplierTypeOtherFields } from './suppliers.js';

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

export const init = (root = document) => {
  initPurchaseOcrImport(root);
  initPurchaseBulkImport(root);
};
