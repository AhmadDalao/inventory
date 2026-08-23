import { csrfToken, escapeHtml, formatNumber } from '../core/runtime.js';

const initialized = new WeakSet();

function itemSearchText(item) {
  return `${item.name || ''} ${item.sku || ''} ${item.unit || ''}`.toLowerCase();
}

function initImportForm(form) {
  if (initialized.has(form)) return;
  initialized.add(form);

  const storage = form.querySelector('[data-wristband-storage]');
  const file = form.querySelector('[data-wristband-file]');
  const picker = form.querySelector('[data-wristband-selected-item]');
  const itemId = form.querySelector('[data-wristband-selected-item-id]');
  const search = form.querySelector('[data-wristband-item-search]');
  const summary = form.querySelector('[data-wristband-selected-summary]');
  const results = form.querySelector('[data-wristband-item-results]');
  const trackingWrap = form.querySelector('[data-wristband-tracking-opt-in]');
  const tracking = form.querySelector('[data-wristband-enable-tracking]');
  const previewButton = form.querySelector('[data-wristband-preview]');
  const confirmButton = form.querySelector('[data-wristband-confirm]');
  const status = form.querySelector('[data-wristband-import-status]');
  const preflight = form.querySelector('[data-wristband-preflight]');
  const preflightStats = form.querySelector('[data-wristband-preflight-stats]');
  const preflightMessage = form.querySelector('[data-wristband-preflight-message]');
  const preflightRows = form.querySelector('[data-wristband-preflight-rows]');
  const sampleCsv = form.querySelector('[data-wristband-sample-csv]');
  const sampleXlsx = form.querySelector('[data-wristband-sample-xlsx]');
  const exampleCopy = form.querySelector('[data-wristband-example-copy]');
  let items = [];
  let selectedItem = null;
  let previewClean = false;

  const mode = () => form.querySelector('[data-wristband-mapping-mode]:checked')?.value || 'selected_item';

  function invalidatePreview(message = 'Preview the current file before importing.') {
    previewClean = false;
    if (confirmButton) confirmButton.disabled = true;
    if (preflight) preflight.hidden = true;
    if (status) status.textContent = message;
  }

  function updateTracking() {
    if (!trackingWrap || !tracking) return;
    const needsEnable = mode() === 'selected_item' && selectedItem && !selectedItem.tracking_enabled;
    trackingWrap.hidden = !needsEnable;
    if (!needsEnable) tracking.checked = false;
  }

  function selectItem(item) {
    selectedItem = item || null;
    if (itemId) itemId.value = selectedItem ? String(selectedItem.id) : '0';
    if (summary) {
      summary.hidden = !selectedItem;
      summary.innerHTML = selectedItem ? `
        <img src="${escapeHtml(selectedItem.image_url || '')}" alt="">
        <span>
          <strong>${escapeHtml(selectedItem.name)}</strong>
          <small>${escapeHtml(selectedItem.sku)} · ${escapeHtml(selectedItem.unit)}</small>
          <small>${formatNumber(selectedItem.storage_quantity)} in this storage · ${formatNumber(selectedItem.registered_codes)} codes registered</small>
        </span>
        <button type="button" class="ghost-button compact-button" data-wristband-clear-item>Change</button>
      ` : '';
      summary.querySelector('[data-wristband-clear-item]')?.addEventListener('click', () => {
        selectItem(null);
        search?.focus();
      });
    }
    if (search) {
      search.value = selectedItem ? selectedItem.name : '';
      search.hidden = Boolean(selectedItem);
    }
    updateTracking();
    renderItems();
    invalidatePreview();
  }

  function renderItems() {
    if (!results || mode() !== 'selected_item') return;
    if (!storage?.value) {
      results.innerHTML = '<p class="empty-state">Choose a storage to load its wristband items.</p>';
      return;
    }
    if (selectedItem) {
      results.innerHTML = '';
      return;
    }
    const query = (search?.value || '').trim().toLowerCase();
    const visible = items.filter((item) => !query || itemSearchText(item).includes(query));
    if (!visible.length) {
      results.innerHTML = '<p class="empty-state">No eligible count-based item matches this search.</p>';
      return;
    }
    results.innerHTML = visible.map((item) => `
      <button type="button" class="wristband-item-result" data-wristband-item-id="${item.id}">
        <img src="${escapeHtml(item.image_url || '')}" alt="">
        <span class="wristband-item-result-copy">
          <strong>${escapeHtml(item.name)}</strong>
          <small>${escapeHtml(item.sku)} · ${escapeHtml(item.unit)}</small>
          <small>${formatNumber(item.storage_quantity)} in storage · ${formatNumber(item.available_codes)} available codes</small>
        </span>
        <span class="status-pill ${item.tracking_enabled ? 'pill-success' : 'pill-warning'}">${item.tracking_enabled ? 'Tracking on' : 'Tracking off'}</span>
      </button>
    `).join('');
    results.querySelectorAll('[data-wristband-item-id]').forEach((button) => {
      button.addEventListener('click', () => {
        const id = Number(button.dataset.wristbandItemId || 0);
        selectItem(items.find((item) => Number(item.id) === id) || null);
      });
    });
  }

  async function loadItems() {
    items = [];
    selectedItem = null;
    if (itemId) itemId.value = '0';
    if (summary) summary.hidden = true;
    if (search) {
      search.hidden = false;
      search.value = '';
      search.disabled = true;
    }
    updateTracking();
    invalidatePreview();
    if (!storage?.value) {
      renderItems();
      return;
    }
    if (results) results.innerHTML = '<p class="empty-state">Loading eligible items…</p>';
    try {
      const response = await fetch(`${form.dataset.itemsUrl}?storage_id=${encodeURIComponent(storage.value)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Could not load items.');
      items = Array.isArray(payload.items) ? payload.items : [];
      if (search) search.disabled = false;
      renderItems();
    } catch (error) {
      if (results) results.innerHTML = `<p class="empty-state error-copy">${escapeHtml(error.message || 'Could not load items.')}</p>`;
    }
  }

  function updateMode() {
    const selectedMode = mode() === 'selected_item';
    if (picker) picker.hidden = !selectedMode;
    if (!selectedMode) {
      selectedItem = null;
      if (itemId) itemId.value = '0';
      if (tracking) tracking.checked = false;
      if (trackingWrap) trackingWrap.hidden = true;
    } else {
      renderItems();
      updateTracking();
    }
    const query = `mapping_mode=${encodeURIComponent(mode())}`;
    if (sampleCsv) sampleCsv.href = `${sampleCsv.href.split('?')[0]}?${query}`;
    if (sampleXlsx) sampleXlsx.href = `${sampleXlsx.href.split('?')[0]}?${query}`;
    if (exampleCopy) {
      exampleCopy.innerHTML = selectedMode
        ? 'Selected item mode expects one column named <code>code</code>.'
        : 'Code + SKU mode expects two columns named <code>code,sku</code>.';
    }
    invalidatePreview();
  }

  function renderPreflight(payload) {
    const stats = payload.stats || {};
    if (preflightStats) {
      const cards = [
        ['Rows', stats.total || 0],
        ['Valid', stats.valid || 0],
        ['Duplicates', stats.duplicates || 0],
        ['Invalid', stats.invalid || 0],
        ['Unknown SKUs', stats.unknown_skus || 0],
        ['Conflicts', stats.conflicts || 0],
      ];
      preflightStats.innerHTML = cards.map(([label, value]) => `<span><small>${label}</small><strong>${formatNumber(value)}</strong></span>`).join('');
    }
    if (preflightMessage) {
      preflightMessage.textContent = payload.message || '';
      preflightMessage.classList.toggle('error-copy', !payload.clean);
    }
    if (preflightRows) {
      preflightRows.innerHTML = (payload.preview || []).map((row) => `
        <tr>
          <td>${formatNumber(row.row_number || 0)}</td>
          <td><code>${escapeHtml(row.code || '')}</code></td>
          <td>${escapeHtml(row.sku || '—')}</td>
          <td>${escapeHtml(row.item_name || row.message || '—')}</td>
          <td><span class="status-pill ${row.status === 'valid' ? 'pill-success' : 'pill-danger'}">${escapeHtml(row.status || 'invalid')}</span></td>
        </tr>
      `).join('');
    }
    if (preflight) preflight.hidden = false;
    previewClean = Boolean(payload.clean);
    if (confirmButton) confirmButton.disabled = !previewClean;
    if (status) status.textContent = previewClean ? 'Validation passed. Confirm to import every validated row.' : 'Import remains locked until every row is clean.';
  }

  async function previewImport() {
    if (!storage?.value) return invalidatePreview('Choose a storage first.');
    if (mode() === 'selected_item' && !selectedItem) return invalidatePreview('Choose the wristband item first.');
    if (!file?.files?.[0]) return invalidatePreview('Choose a CSV or Excel file first.');
    previewButton.disabled = true;
    if (status) status.textContent = 'Validating every uploaded row…';
    try {
      const body = new FormData(form);
      const response = await fetch(form.dataset.preflightUrl, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() },
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Validation failed.');
      renderPreflight(payload);
    } catch (error) {
      invalidatePreview(error.message || 'Validation failed.');
    } finally {
      previewButton.disabled = false;
    }
  }

  storage?.addEventListener('change', loadItems);
  search?.addEventListener('input', () => {
    selectedItem = null;
    if (itemId) itemId.value = '0';
    renderItems();
    invalidatePreview();
  });
  file?.addEventListener('change', () => invalidatePreview());
  tracking?.addEventListener('change', () => invalidatePreview());
  form.querySelectorAll('[data-wristband-mapping-mode]').forEach((control) => control.addEventListener('change', updateMode));
  previewButton?.addEventListener('click', previewImport);
  form.addEventListener('submit', (event) => {
    if (previewClean) return;
    event.preventDefault();
    invalidatePreview('Preview and pass validation before confirming the import.');
  });

  updateMode();
  renderItems();
}

function initCopyButton(button) {
  if (initialized.has(button)) return;
  initialized.add(button);
  button.addEventListener('click', async () => {
    const target = document.querySelector(button.dataset.copyWristbandKey || '');
    if (!target) return;
    try {
      await navigator.clipboard.writeText(target.value || target.textContent || '');
      const label = button.querySelector('span');
      if (label) label.textContent = 'Copied';
    } catch {
      target.select?.();
      document.execCommand('copy');
    }
  });
}

export function init(root = document) {
  root.querySelectorAll('[data-wristband-import-form]').forEach(initImportForm);
  root.querySelectorAll('[data-copy-wristband-key]').forEach(initCopyButton);
}
