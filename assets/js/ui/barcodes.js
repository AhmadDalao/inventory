import { escapeHtml } from '../core/runtime.js';

const code39Patterns = {
  '0': 'nnnwwnwnn',
  '1': 'wnnwnnnnw',
  '2': 'nnwwnnnnw',
  '3': 'wnwwnnnnn',
  '4': 'nnnwwnnnw',
  '5': 'wnnwwnnnn',
  '6': 'nnwwwnnnn',
  '7': 'nnnwnnwnw',
  '8': 'wnnwnnwnn',
  '9': 'nnwwnnwnn',
  A: 'wnnnnwnnw',
  B: 'nnwnnwnnw',
  C: 'wnwnnwnnn',
  D: 'nnnnwwnnw',
  E: 'wnnnwwnnn',
  F: 'nnwnwwnnn',
  G: 'nnnnnwwnw',
  H: 'wnnnnwwnn',
  I: 'nnwnnwwnn',
  J: 'nnnnwwwnn',
  K: 'wnnnnnnww',
  L: 'nnwnnnnww',
  M: 'wnwnnnnwn',
  N: 'nnnnwnnww',
  O: 'wnnnwnnwn',
  P: 'nnwnwnnwn',
  Q: 'nnnnnnwww',
  R: 'wnnnnnwwn',
  S: 'nnwnnnwwn',
  T: 'nnnnwnwwn',
  U: 'wwnnnnnnw',
  V: 'nwwnnnnnw',
  W: 'wwwnnnnnn',
  X: 'nwnnwnnnw',
  Y: 'wwnnwnnnn',
  Z: 'nwwnwnnnn',
  '-': 'nwnnnnwnw',
  '.': 'wwnnnnwnn',
  ' ': 'nwwnnnwnn',
  '$': 'nwnwnwnnn',
  '/': 'nwnwnnnwn',
  '+': 'nwnnnwnwn',
  '%': 'nnnwnwnwn',
  '*': 'nwnnwnwnn',
};

export const normalizeCode39 = (value) => {
  const normalized = String(value || '')
    .trim()
    .toUpperCase()
    .replace(/[^0-9A-Z .\-\/+$%]/g, '-')
    .replace(/^-+|-+$/g, '');

  return normalized || 'INV';
};

export const code39SvgMarkup = (value, height = 48) => {
  const label = normalizeCode39(value);
  const code = `*${label}*`;
  const narrow = 2;
  const wide = 5;
  const gap = narrow;
  let x = 0;
  let bars = '';

  Array.from(code).forEach((character) => {
    const pattern = code39Patterns[character] || code39Patterns['-'];

    Array.from(pattern).forEach((widthKey, index) => {
      const width = widthKey === 'w' ? wide : narrow;

      if (index % 2 === 0) {
        bars += `<rect x="${x}" y="0" width="${width}" height="${height}"/>`;
      }

      x += width;
    });

    x += gap;
  });

  return `<svg class="barcode-svg" viewBox="0 0 ${x} ${height}" role="img" aria-label="${escapeHtml(label)}" xmlns="http://www.w3.org/2000/svg">${bars}</svg><code>${escapeHtml(label)}</code>`;
};

export const initItemCodePreview = (root = document) => {
  root.querySelectorAll('[data-item-code-preview]').forEach((preview) => {
    if (preview.dataset.jsBound === 'true') {
      return;
    }

    const form = preview.closest('form');
    const skuInput = form?.querySelector('input[name="sku"]');
    const barcodeInput = form?.querySelector('input[name="barcode"]');
    const valueNode = preview.querySelector('[data-item-code-value]');
    const sourceNode = preview.querySelector('[data-item-code-source]');
    const svgNode = preview.querySelector('[data-item-code-svg]');

    const syncPreview = () => {
      const barcode = barcodeInput instanceof HTMLInputElement ? barcodeInput.value.trim() : '';
      const sku = skuInput instanceof HTMLInputElement ? skuInput.value.trim() : '';
      const scanCode = normalizeCode39(barcode || sku);

      if (valueNode) {
        valueNode.textContent = scanCode;
      }

      if (sourceNode) {
        sourceNode.textContent = barcode ? 'Barcode preview' : 'SKU fallback preview';
      }

      if (svgNode) {
        svgNode.innerHTML = code39SvgMarkup(scanCode, 48);
      }
    };

    preview.dataset.jsBound = 'true';
    [skuInput, barcodeInput].forEach((input) => {
      if (input instanceof HTMLInputElement) {
        input.addEventListener('input', syncPreview);
        input.addEventListener('change', syncPreview);
      }
    });
    syncPreview();
  });
};

export const init = (root = document) => initItemCodePreview(root);
