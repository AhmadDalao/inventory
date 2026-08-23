import { csrfToken, escapeHtml, formatNumber, looksLikeScanCode, parseNumber } from '../core/runtime.js';

export const initManualStockAdd = (root = document) => {
  root.querySelectorAll('[data-manual-stock-add]').forEach((page) => {
    if (page.dataset.manualStockBound === 'true') {
      return;
    }

    const lookupUrl = page.dataset.manualLookupUrl || '';
    const submitUrl = page.dataset.manualSubmitUrl || '';
    const wristbandPreflightUrl = page.dataset.wristbandPreflightUrl || '';
    const wristbandSampleCsvUrl = page.dataset.wristbandSampleCsvUrl || '';
    const wristbandSampleXlsxUrl = page.dataset.wristbandSampleXlsxUrl || '';
    const canImportWristbands = page.dataset.canImportWristbands === 'true';
    const canEnableWristbandTracking = page.dataset.canEnableWristbandTracking === 'true';
    const searchInput = page.querySelector('[data-manual-stock-search]');
    const results = page.querySelector('[data-manual-stock-results]');
    const selectedWrap = page.querySelector('[data-manual-stock-selected]');
    const lineForm = page.querySelector('[data-manual-stock-line-form]');
    const storageSelect = page.querySelector('[data-manual-stock-storage]');
    const packageSelect = page.querySelector('[data-manual-stock-package]');
    const packageHelp = page.querySelector('[data-manual-stock-package-help]');
    const quantityInput = page.querySelector('[data-manual-stock-quantity]');
    const conversionPreview = page.querySelector('[data-manual-stock-conversion]');
    const referenceInput = page.querySelector('[data-manual-stock-reference]');
    const notesInput = page.querySelector('[data-manual-stock-notes]');
    const status = page.querySelector('[data-manual-stock-status]');
    const draftWrap = page.querySelector('[data-manual-stock-draft]');
    const summary = page.querySelector('[data-manual-stock-summary]');
    const count = page.querySelector('[data-manual-stock-count]');
    const clearButton = page.querySelector('[data-manual-stock-clear]');
    const confirmButton = page.querySelector('[data-manual-stock-confirm]');
    const proofInput = page.querySelector('[data-manual-stock-proof]');
    const proofLabel = page.querySelector('[data-manual-stock-proof-label]');

    if (!lookupUrl || !submitUrl || !(searchInput instanceof HTMLInputElement) || !(lineForm instanceof HTMLFormElement) || !(results instanceof HTMLElement) || !(draftWrap instanceof HTMLElement)) {
      return;
    }

    let storages = [];
    let lookupItems = [];
    let selectedItem = null;
    let lookupTimer = null;
    let lookupSequence = 0;
    let wristbandSequence = 0;
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

    const canonicalUnit = (item = selectedItem) => String(item?.canonical_unit || item?.unit || 'unit');

    const activePresets = (item = selectedItem) => Array.isArray(item?.package_presets)
      ? item.package_presets.filter((preset) => Number(preset.is_active ?? 1) === 1)
      : [];

    const selectedPreset = () => {
      if (!(packageSelect instanceof HTMLSelectElement) || !selectedItem || packageSelect.value === '') {
        return null;
      }

      return activePresets().find((preset) => String(preset.id) === packageSelect.value) || null;
    };

    const populatePackageOptions = (item) => {
      if (!(packageSelect instanceof HTMLSelectElement)) {
        return;
      }

      if (!item) {
        packageSelect.disabled = true;
        packageSelect.innerHTML = '<option value="">Select an item first</option>';

        if (packageHelp instanceof HTMLElement) {
          packageHelp.textContent = 'Stock is always saved in the item\'s canonical unit.';
        }
        return;
      }

      const unit = canonicalUnit(item);
      const presets = activePresets(item);
      packageSelect.disabled = false;
      packageSelect.innerHTML = [
        `<option value="">${escapeHtml(unit)} (base unit)</option>`,
        ...presets.map((preset) => (
          `<option value="${escapeHtml(preset.id)}">${escapeHtml(preset.label)} = ${escapeHtml(formatNumber(preset.pieces_per_unit_raw ?? preset.pieces_per_unit))} ${escapeHtml(unit)}</option>`
        )),
      ].join('');

      if (packageHelp instanceof HTMLElement) {
        packageHelp.textContent = presets.length > 0
          ? `Choose how the stock arrived. It will be stored as ${unit}.`
          : `No package presets are configured. Enter the amount in ${unit}.`;
      }
    };

    const conversionForSelection = () => {
      const preset = selectedPreset();

      return preset ? parseNumber(preset.pieces_per_unit_raw ?? preset.pieces_per_unit) : 1;
    };

    const updateConversionPreview = () => {
      if (!(conversionPreview instanceof HTMLElement)) {
        return;
      }

      if (!selectedItem) {
        conversionPreview.textContent = 'Pick an item and package to preview the converted quantity.';
        return;
      }

      const inputQuantity = quantityInput instanceof HTMLInputElement ? parseNumber(quantityInput.value) : 0;
      const preset = selectedPreset();
      const unit = canonicalUnit();

      if (inputQuantity <= 0) {
        conversionPreview.textContent = preset
          ? `1 ${preset.label} = ${formatNumber(conversionForSelection())} ${unit}.`
          : `Enter the amount in ${unit}.`;
        return;
      }

      const baseQuantity = inputQuantity * conversionForSelection();
      const enteredLabel = preset ? preset.label : unit;
      conversionPreview.textContent = `${formatNumber(inputQuantity)} ${enteredLabel} = ${formatNumber(baseQuantity)} ${unit}.`;
    };

    const updateProofRequirement = () => {
      const required = draftLines.some((line) => line.requires_refill_proof === true);

      if (proofInput instanceof HTMLInputElement) {
        proofInput.required = required;
      }

      if (proofLabel instanceof HTMLElement) {
        proofLabel.textContent = required ? 'required' : 'optional';
        proofLabel.classList.toggle('danger-text', required);
      }
    };

    const setSelectedItem = (item) => {
      selectedItem = item || null;
      populatePackageOptions(selectedItem);
      updateConversionPreview();

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

    const draftTotals = () => draftLines.reduce((totals, line) => {
      const unit = String(line.base_unit || line.item.unit || 'unit');
      totals[unit] = (totals[unit] || 0) + parseNumber(line.base_quantity);
      return totals;
    }, {});

    const wristbandMarkup = (line) => {
      if (!canImportWristbands || line.item?.wristband_eligible !== true) {
        return '';
      }

      const trackingEnabled = line.item?.external_qr_tracking_enabled === true;
      const trackingControl = trackingEnabled
        ? '<span class="status-badge success">QR tracking enabled</span>'
        : (canEnableWristbandTracking
          ? `<label class="checkbox-field">
              <input type="checkbox" data-manual-wristband-enable ${line.enable_external_qr_tracking ? 'checked' : ''}>
              <span>Enable external QR tracking for this item</span>
            </label>`
          : '<span class="danger-text">QR tracking is disabled. An item editor must enable it before codes can be attached.</span>');
      const preflight = line.wristband_preflight || null;
      let statusClass = '';
      let statusText = 'Optional. Attach one code per canonical unit being added.';

      if (line.wristband_file && preflight?.state === 'checking') {
        statusText = `Checking ${line.wristband_file.name}...`;
      } else if (line.wristband_file && preflight?.state === 'ready') {
        statusClass = 'is-ready';
        statusText = `${preflight.valid_count} unique codes match this ${formatNumber(line.base_quantity)} ${line.base_unit} restock.`;
      } else if (line.wristband_file && preflight?.state === 'error') {
        statusClass = 'is-error';
        statusText = preflight.message || 'This code file is not ready.';
      } else if (line.wristband_file) {
        statusText = `${line.wristband_file.name} is waiting for validation.`;
      }

      return `
        <details class="manual-stock-wristband" ${line.wristband_open ? 'open' : ''} data-manual-wristband-details>
          <summary>
            <span>Wristband Codes <small>(Optional)</small></span>
            <span>${escapeHtml(String(line.item.wristband_registered_codes || 0))} already registered</span>
          </summary>
          <div class="manual-stock-wristband-body">
            <p class="tiny-copy">Use this only when the added stock is a wristband batch. The valid unique code count must exactly match the converted restock quantity.</p>
            ${trackingControl}
            <label class="field manual-stock-wristband-file">
              <span>CSV or XLSX code file</span>
              <input type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" data-manual-wristband-file>
              <small>${line.wristband_file ? `Selected: ${escapeHtml(line.wristband_file.name)}` : 'Use a single code column. The proof image remains separate.'}</small>
            </label>
            <div class="manual-stock-wristband-actions">
              ${wristbandSampleCsvUrl ? `<a class="ghost-button compact-button" href="${escapeHtml(wristbandSampleCsvUrl)}">CSV example</a>` : ''}
              ${wristbandSampleXlsxUrl ? `<a class="ghost-button compact-button" href="${escapeHtml(wristbandSampleXlsxUrl)}">XLSX example</a>` : ''}
              ${line.wristband_file ? '<button class="ghost-button compact-button danger-link" type="button" data-manual-wristband-clear>Remove code file</button>' : ''}
            </div>
            <p class="manual-stock-wristband-status ${statusClass}">${escapeHtml(statusText)}</p>
          </div>
        </details>
      `;
    };

    const runWristbandPreflight = async (line, index) => {
      if (!line.wristband_file || !wristbandPreflightUrl) {
        return;
      }

      line.wristband_preflight = { state: 'checking', valid_count: 0, message: '' };
      line.wristband_open = true;
      renderDraft();

      const formData = new FormData();
      formData.append('_token', csrfToken(page));
      formData.append('storage_id', line.storage_id);
      formData.append('mapping_mode', 'selected_item');
      formData.append('selected_item_id', line.item_id);
      formData.append('enable_external_qr_tracking', line.enable_external_qr_tracking ? '1' : '0');
      formData.append('wristband_file', line.wristband_file);

      try {
        const response = await fetch(wristbandPreflightUrl, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: formData,
        });
        const payload = await response.json();
        const currentLine = draftLines[index];

        if (currentLine !== line) {
          return;
        }
        if (!response.ok || !payload.ok) {
          throw new Error(payload.message || 'Wristband code validation failed.');
        }

        const validCount = Number(payload.stats?.valid || 0);
        const expectedCount = Number(line.base_quantity || 0);
        if (!payload.clean) {
          throw new Error(payload.message || 'Fix the wristband code file before confirming stock.');
        }
        if (!Number.isInteger(expectedCount) || validCount !== expectedCount) {
          throw new Error(`${validCount} valid codes do not match the ${formatNumber(expectedCount)}-unit restock quantity.`);
        }

        line.wristband_preflight = {
          state: 'ready',
          valid_count: validCount,
          message: payload.message || '',
        };
      } catch (error) {
        line.wristband_preflight = {
          state: 'error',
          valid_count: 0,
          message: error.message || 'Wristband code validation failed.',
        };
      }

      renderDraft();
    };

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

        updateProofRequirement();

        return;
      }

      draftWrap.innerHTML = draftLines.map((line, index) => `
        <div class="manual-stock-draft-row ${canImportWristbands && line.item?.wristband_eligible === true ? 'has-wristband-codes' : ''}" data-manual-stock-draft-index="${index}">
          ${itemImageMarkup(line.item)}
          <span>
            <strong>${escapeHtml(line.item.name)}</strong>
            <small>${escapeHtml([line.item.sku, line.item.barcode || 'No barcode', line.item.unit].filter(Boolean).join(' · '))}</small>
            <small>${escapeHtml(line.storage_label)}</small>
            ${line.reference_code ? `<small>Ref: ${escapeHtml(line.reference_code)}</small>` : ''}
            ${line.notes ? `<small>Notes: ${escapeHtml(line.notes)}</small>` : ''}
          </span>
          <em>
            ${escapeHtml(formatNumber(line.input_quantity))} ${escapeHtml(line.package_label)}
            <small>= ${escapeHtml(formatNumber(line.base_quantity))} ${escapeHtml(line.base_unit)}</small>
          </em>
          <button class="ghost-button danger-link" type="button" data-manual-stock-remove>Remove</button>
          ${wristbandMarkup(line)}
        </div>
      `).join('');

      if (summary instanceof HTMLElement) {
        const totals = Object.entries(draftTotals())
          .map(([unit, total]) => `${formatNumber(total)} ${unit}`)
          .join(' · ');
        summary.hidden = false;
        summary.innerHTML = `
          <strong>${lineCount} pending line${lineCount === 1 ? '' : 's'}</strong>
          <span>${escapeHtml(totals)} in canonical stock units</span>
        `;
      }

      updateProofRequirement();
    };

    page.dataset.manualStockBound = 'true';

    searchInput.addEventListener('input', scheduleLookup);

    if (packageSelect instanceof HTMLSelectElement) {
      packageSelect.addEventListener('change', updateConversionPreview);
    }

    if (quantityInput instanceof HTMLInputElement) {
      quantityInput.addEventListener('input', updateConversionPreview);
    }

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

      const preset = selectedPreset();
      const conversion = conversionForSelection();
      const baseQuantity = quantity * conversion;

      if (!Number.isFinite(baseQuantity) || baseQuantity <= 0) {
        setStatus('That package conversion is invalid. Review the item package preset.', 'danger');
        return;
      }

      draftLines.push({
        item: selectedItem,
        item_id: String(selectedItem.id || ''),
        storage_id: storageId,
        storage_label: storageLabel(storageId),
        input_quantity: formatNumber(quantity),
        package_preset_id: preset ? String(preset.id) : null,
        package_label: preset ? String(preset.label) : canonicalUnit(),
        conversion: formatNumber(conversion),
        base_quantity: formatNumber(baseQuantity),
        base_unit: canonicalUnit(),
        requires_refill_proof: selectedItem.requires_refill_proof === true,
        reference_code: referenceInput instanceof HTMLInputElement ? referenceInput.value.trim() : '',
        notes: notesInput instanceof HTMLInputElement ? notesInput.value.trim() : '',
        wristband_file: null,
        wristband_file_field: '',
        wristband_preflight: null,
        wristband_open: false,
        enable_external_qr_tracking: selectedItem.external_qr_tracking_enabled === true,
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

      if (packageSelect instanceof HTMLSelectElement) {
        packageSelect.value = '';
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

      if (!(target instanceof Element)) {
        return;
      }

      const row = target.closest('[data-manual-stock-draft-index]');
      const index = Number.parseInt(row?.getAttribute('data-manual-stock-draft-index') || '-1', 10);

      if (index >= 0 && target.closest('[data-manual-stock-remove]')) {
        draftLines.splice(index, 1);
        renderDraft();
        setStatus('Draft line removed.');
      } else if (index >= 0 && target.closest('[data-manual-wristband-clear]')) {
        draftLines[index].wristband_file = null;
        draftLines[index].wristband_file_field = '';
        draftLines[index].wristband_preflight = null;
        renderDraft();
        setStatus('Wristband code file removed.');
      }
    });

    draftWrap.addEventListener('toggle', (event) => {
      const details = event.target;

      if (!(details instanceof HTMLDetailsElement) || !details.matches('[data-manual-wristband-details]')) {
        return;
      }
      const row = details.closest('[data-manual-stock-draft-index]');
      const index = Number.parseInt(row?.getAttribute('data-manual-stock-draft-index') || '-1', 10);
      if (index >= 0 && draftLines[index]) {
        draftLines[index].wristband_open = details.open;
      }
    }, true);

    draftWrap.addEventListener('change', (event) => {
      const target = event.target;

      if (!(target instanceof HTMLInputElement)) {
        return;
      }
      const row = target.closest('[data-manual-stock-draft-index]');
      const index = Number.parseInt(row?.getAttribute('data-manual-stock-draft-index') || '-1', 10);
      const line = index >= 0 ? draftLines[index] : null;
      if (!line) {
        return;
      }

      if (target.matches('[data-manual-wristband-enable]')) {
        line.enable_external_qr_tracking = target.checked;
        line.wristband_open = true;
        if (line.wristband_file) {
          runWristbandPreflight(line, index);
        } else {
          renderDraft();
        }
        return;
      }

      if (target.matches('[data-manual-wristband-file]')) {
        const file = target.files?.[0] || null;
        line.wristband_file = file;
        line.wristband_file_field = file
          ? `wristband_file_${Date.now()}_${++wristbandSequence}`
          : '';
        line.wristband_preflight = null;
        line.wristband_open = true;
        if (file) {
          runWristbandPreflight(line, index);
        } else {
          renderDraft();
        }
      }
    });

    if (clearButton instanceof HTMLButtonElement) {
      clearButton.addEventListener('click', () => {
        draftLines = [];
        if (proofInput instanceof HTMLInputElement) {
          proofInput.value = '';
        }
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

        const proofRequired = draftLines.some((line) => line.requires_refill_proof === true);
        const wristbandNotReady = draftLines.find((line) => line.wristband_file && line.wristband_preflight?.state !== 'ready');

        if (wristbandNotReady) {
          setStatus(`The wristband code file for ${wristbandNotReady.item.name} must pass validation before confirmation.`, 'danger');
          return;
        }

        if (proofRequired && (!(proofInput instanceof HTMLInputElement) || !proofInput.files?.[0])) {
          setStatus('A proof image is required for one or more items in this refill.', 'danger');
          proofInput?.focus();
          return;
        }

        confirmButton.disabled = true;
        setStatus('Confirming manual stock additions...');

        const formData = new FormData();
        formData.append('_token', csrfToken(page));
        formData.append('lines', JSON.stringify(draftLines.map((line) => ({
          item_id: line.item_id,
          storage_id: line.storage_id,
          input_quantity: line.input_quantity,
          package_preset_id: line.package_preset_id,
          reference_code: line.reference_code,
          notes: line.notes,
          wristband_file_field: line.wristband_file_field,
          enable_external_qr_tracking: line.enable_external_qr_tracking,
        }))));

        draftLines.forEach((line) => {
          if (line.wristband_file && line.wristband_file_field) {
            formData.append(line.wristband_file_field, line.wristband_file);
          }
        });

        if (proofInput instanceof HTMLInputElement && proofInput.files?.[0]) {
          formData.append('proof_image', proofInput.files[0]);
        }

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
          if (proofInput instanceof HTMLInputElement) {
            proofInput.value = '';
          }
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

export const init = (root = document) => {
  initManualStockAdd(root);
};
