import { csrfToken, escapeHtml, formatNumber, looksLikeScanCode, parseNumber } from '../core/runtime.js';

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
  initScanCenter(root);
};
