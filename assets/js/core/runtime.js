import { initInteractiveUi } from './registry.js';

let tesseractLoaderPromise = null;
let pdfJsLoaderPromise = null;
const purchaseOcrLanguages = 'ara+eng';
const purchaseOcrLanguageLabel = 'Arabic + English';

export const parseNumber = (value) => {
  const number = Number.parseFloat(value);
  return Number.isFinite(number) ? number : 0;
};

export const formatNumber = (value) => {
  const number = Math.round(parseNumber(value) * 100) / 100;
  return Number.isInteger(number) ? String(number) : String(number);
};

export const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#39;',
}[character] || character));

export const csrfToken = (root = document) => root.querySelector('input[name="_token"]')?.value
  || document.querySelector('input[name="_token"]')?.value
  || '';

export const loadScriptOnce = (src, globalName) => {
  if (globalName && window[globalName]) {
    return Promise.resolve(window[globalName]);
  }

  return new Promise((resolve, reject) => {
    const existing = document.querySelector(`script[src="${src}"]`);

    if (existing) {
      existing.addEventListener('load', () => resolve(globalName ? window[globalName] : true), { once: true });
      existing.addEventListener('error', () => reject(new Error('Could not load OCR library.')), { once: true });
      return;
    }

    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.onload = () => resolve(globalName ? window[globalName] : true);
    script.onerror = () => reject(new Error('Could not load OCR library.'));
    document.head.appendChild(script);
  });
};

export const postPurchaseOcr = async (ocrUrl, formData) => {
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

export const browserOcrTextFromFiles = async (files, setStatus = () => {}, options = {}) => {
  const imageFiles = files.filter((file) => /^image\/(jpeg|png|webp)$/i.test(file.type));
  const pdfFiles = files.filter((file) => file.type === 'application/pdf' || /\.pdf$/i.test(file.name));

  if (imageFiles.length === 0 && pdfFiles.length === 0) {
    throw new Error('Browser OCR supports JPG, PNG, WebP, and scanned PDFs.');
  }

  if (!tesseractLoaderPromise) {
    setStatus('Loading browser OCR engine. This may take a moment the first time...');
    tesseractLoaderPromise = loadScriptOnce('https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js', 'Tesseract');
  }

  const Tesseract = await tesseractLoaderPromise;
  const recognizeImage = async (imageSource, label) => {
    setStatus(`Reading ${label} in ${purchaseOcrLanguageLabel}...`);
    const result = await Tesseract.recognize(imageSource, purchaseOcrLanguages, {
      logger: (progress) => {
        if (progress && progress.status) {
          const pct = typeof progress.progress === 'number' ? ` ${Math.round(progress.progress * 100)}%` : '';
          setStatus(`${label}: ${progress.status}${pct}`);
        }
      },
    });

    return result?.data?.text || '';
  };

  let text = '';

  for (const file of imageFiles) {
    text += `\n${await recognizeImage(file, file.name)}`;
  }

  if (pdfFiles.length > 0) {
    if (!pdfJsLoaderPromise) {
      setStatus('Loading PDF renderer...');
      pdfJsLoaderPromise = loadScriptOnce('https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js', 'pdfjsLib').then((pdfjsLib) => {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
        return pdfjsLib;
      });
    }

    const pdfjsLib = await pdfJsLoaderPromise;
    const configuredMaxPages = Number.parseInt(options.maxPagesPerPdf || '8', 10);
    const maxPagesPerPdf = Number.isFinite(configuredMaxPages) ? Math.max(1, Math.min(20, configuredMaxPages)) : 8;

    for (const file of pdfFiles) {
      setStatus(`Opening scanned PDF ${file.name}...`);
      const pdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
      const pageCount = Math.min(pdf.numPages, maxPagesPerPdf);

      if (pdf.numPages > maxPagesPerPdf) {
        setStatus(`${file.name} has ${pdf.numPages} pages. Reading first ${maxPagesPerPdf} pages to keep the browser responsive.`);
      }

      for (let pageNumber = 1; pageNumber <= pageCount; pageNumber += 1) {
        const page = await pdf.getPage(pageNumber);
        const baseViewport = page.getViewport({ scale: 1 });
        const maxDimension = Math.max(baseViewport.width, baseViewport.height);
        const scale = Math.max(1.35, Math.min(2.25, 1800 / Math.max(maxDimension, 1)));
        const viewport = page.getViewport({ scale });
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { alpha: false });

        if (!context) {
          throw new Error('Could not create a browser canvas for PDF OCR.');
        }

        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        setStatus(`Rendering ${file.name}, page ${pageNumber} of ${pdf.numPages}...`);
        await page.render({ canvasContext: context, viewport }).promise;
        text += `\n${await recognizeImage(canvas, `${file.name} page ${pageNumber}`)}`;
        canvas.width = 1;
        canvas.height = 1;
      }
    }
  }

  return text.trim();
};

export const formatQuantity = (value) => {
  const formatted = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(value);

  return formatted.replace(/\.00$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
};

export const formatMoney = (value) => new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
}).format(value);

export const formatCount = (value) => new Intl.NumberFormat('en-US').format(value);

export const confidenceScore = (value, fallback = 0) => {
  const score = Number.parseFloat(value);

  if (!Number.isFinite(score)) {
    return fallback;
  }

  return Math.max(0, Math.min(1, score));
};

export const confidenceClass = (score) => {
  if (score < 0.7) {
    return 'is-low';
  }

  if (score < 0.85) {
    return 'is-medium';
  }

  return '';
};

export const ocrConfidenceMarkup = (parsed = {}) => {
  const confidence = parsed.confidence || {};
  const flags = Array.isArray(parsed.review_flags) ? parsed.review_flags : [];
  const overall = confidenceScore(confidence.overall, 0);
  const supplier = confidenceScore(confidence.supplier, overall);
  const lines = confidenceScore(confidence.lines, overall);
  const engine = confidence.engine ? ` · ${confidence.engine}` : '';
  const lineCount = Array.isArray(parsed.lines) ? parsed.lines.length : 0;
  const needsReview = overall < 0.7 || flags.length > 0 || lineCount === 0;
  const title = needsReview ? 'Needs human review' : 'Ready for review';
  const action = lineCount === 0
    ? 'No item rows were detected. Add rows manually or rerun with AI if enabled.'
    : (needsReview ? 'Check supplier, quantities, unit prices, and any generated SKU before creating the draft.' : 'Review the fields once, then submit the draft normally.');
  const flagMarkup = flags.length
    ? `<ul class="ocr-review-flags">${flags.slice(0, 8).map((flag) => `<li>${escapeHtml(flag)}</li>`).join('')}</ul>`
    : '';

  return `
    <div class="ocr-review-summary ${needsReview ? 'needs-review' : 'is-ready'}">
      <div>
        <span class="ocr-confidence-chip ${confidenceClass(overall)}">OCR Confidence ${Math.round(overall * 100)}%${escapeHtml(engine)}</span>
        <strong>${title}</strong>
        <span class="tiny-copy">${escapeHtml(action)}</span>
      </div>
      <div class="ocr-review-metrics" aria-label="OCR metrics">
        <span><strong>${Math.round(supplier * 100)}%</strong> Supplier</span>
        <span><strong>${Math.round(lines * 100)}%</strong> Lines</span>
        <span><strong>${formatCount(lineCount)}</strong> Rows</span>
      </div>
    </div>
    ${flagMarkup || '<p class="tiny-copy ocr-review-clean">No warning flags from OCR. Still review before approval.</p>'}
  `;
};

export const looksLikeScanCode = (value) => {
  const normalized = String(value || '').trim();

  if (normalized === '') {
    return false;
  }

  if (/^(HDO|REQ|PO|STK)-\d{8,}-[A-Z0-9]+$/i.test(normalized)) {
    return true;
  }

  if (normalized.length >= 6 && !/\s/.test(normalized) && /[A-Z0-9]/i.test(normalized)) {
    return true;
  }

  return false;
};

export const formatDateTimeCopy = (value) => {
  const normalized = String(value || '').trim();

  if (normalized === '') {
    return '';
  }

  const parsed = new Date(normalized.includes('T') ? normalized : normalized.replace(' ', 'T'));

  if (Number.isNaN(parsed.getTime())) {
    return normalized;
  }

  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(parsed);
};

export const localDateTimeValue = () => {
  const now = new Date();
  const offsetMs = now.getTimezoneOffset() * 60000;
  return new Date(now.getTime() - offsetMs).toISOString().slice(0, 16);
};

export const showGlobalFlash = (message, type = 'success') => {
  if (!message) {
    return;
  }

  const content = document.querySelector('.content');

  if (!content) {
    return;
  }

  let flashStack = content.querySelector('.flash-stack[data-live-flash-stack]');

  if (!flashStack) {
    flashStack = document.createElement('section');
    flashStack.className = 'flash-stack';
    flashStack.setAttribute('data-live-flash-stack', '');
    content.prepend(flashStack);
  }

  flashStack.innerHTML = `<div class="flash flash-${escapeHtml(type)}">${escapeHtml(message)}</div>`;
};

export const replaceMainContentFromUrl = async (url) => {
  const response = await fetch(url, {
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
    },
  });

  if (!response.ok) {
    throw new Error(`Reload failed: ${response.status}`);
  }

  const html = await response.text();
  const documentClone = new DOMParser().parseFromString(html, 'text/html');
  const nextContent = documentClone.querySelector('main.content');
  const nextTopbarTitle = documentClone.querySelector('.topbar h2');
  const currentContent = document.querySelector('main.content');

  if (!nextContent || !currentContent) {
    throw new Error('Could not refresh page content.');
  }

  history.replaceState(null, '', url);
  currentContent.replaceWith(nextContent);

  const topbarTitle = document.querySelector('.topbar h2');

  if (topbarTitle && nextTopbarTitle) {
    topbarTitle.textContent = nextTopbarTitle.textContent || topbarTitle.textContent;
  }

  initInteractiveUi(document);
  document.dispatchEvent(new CustomEvent('inventory:content-replaced', {
    detail: { root: nextContent },
  }));
};
