import { csrfToken, escapeHtml, formatNumber, looksLikeScanCode, parseNumber } from '../core/runtime.js';

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

export const init = (root = document) => {
  initManualStockAdd(root);
};
