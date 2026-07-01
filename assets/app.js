document.addEventListener('DOMContentLoaded', () => {
  const shell = document.querySelector('[data-shell]');
  const menuToggles = Array.from(document.querySelectorAll('[data-menu-toggle]'));
  const sidebarBackdrop = document.querySelector('[data-sidebar-backdrop]');
  const lightbox = document.querySelector('[data-image-lightbox]');
  const lightboxImage = document.querySelector('[data-image-lightbox-image]');
  const lightboxCaption = document.querySelector('[data-image-lightbox-caption]');
  const topbarTitle = document.querySelector('.topbar h2');
  const sidebarStorageKey = 'inventory-sidebar-collapsed';
  const notificationSoundStorageKey = 'inventory-notification-sound-enabled';
  let notificationAudioUnlocked = false;
  let notificationAudioContext = null;
  let notificationSoundEnabled = window.localStorage.getItem(notificationSoundStorageKey) !== '0';
  let lastKnownNotificationCount = Number.parseInt(document.querySelector('[data-notification-badge]')?.textContent || '0', 10) || 0;
  let tesseractLoaderPromise = null;
  let pdfJsLoaderPromise = null;
  const purchaseOcrLanguages = 'ara+eng';
  const purchaseOcrLanguageLabel = 'Arabic + English';

  const parseNumber = (value) => {
    const number = Number.parseFloat(value);
    return Number.isFinite(number) ? number : 0;
  };

  const formatNumber = (value) => {
    const number = Math.round(parseNumber(value) * 100) / 100;
    return Number.isInteger(number) ? String(number) : String(number);
  };

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[character] || character));

  const csrfToken = (root = document) => root.querySelector('input[name="_token"]')?.value
    || document.querySelector('input[name="_token"]')?.value
    || '';

  const loadScriptOnce = (src, globalName) => {
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

  const postPurchaseOcr = async (ocrUrl, formData) => {
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

  const browserOcrTextFromFiles = async (files, setStatus = () => {}, options = {}) => {
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

  const formatQuantity = (value) => {
    const formatted = new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2,
    }).format(value);

    return formatted.replace(/\.00$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
  };

  const formatMoney = (value) => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(value);

  const formatCount = (value) => new Intl.NumberFormat('en-US').format(value);

  const confidenceScore = (value, fallback = 0) => {
    const score = Number.parseFloat(value);

    if (!Number.isFinite(score)) {
      return fallback;
    }

    return Math.max(0, Math.min(1, score));
  };

  const confidenceClass = (score) => {
    if (score < 0.7) {
      return 'is-low';
    }

    if (score < 0.85) {
      return 'is-medium';
    }

    return '';
  };

  const ocrConfidenceMarkup = (parsed = {}) => {
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

  const looksLikeScanCode = (value) => {
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

  const formatDateTimeCopy = (value) => {
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

  const localDateTimeValue = () => {
    const now = new Date();
    const offsetMs = now.getTimezoneOffset() * 60000;
    return new Date(now.getTime() - offsetMs).toISOString().slice(0, 16);
  };

  const showGlobalFlash = (message, type = 'success') => {
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

  const replaceMainContentFromUrl = async (url) => {
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

    if (topbarTitle && nextTopbarTitle) {
      topbarTitle.textContent = nextTopbarTitle.textContent || topbarTitle.textContent;
    }

    initInteractiveUi(document);
  };

  const openLightbox = (image) => {
    if (!lightbox || !lightboxImage || !image) {
      return;
    }

    const src = image.getAttribute('src');
    const alt = image.getAttribute('alt') || '';

    if (!src) {
      return;
    }

    lightboxImage.src = src;
    lightboxImage.alt = alt;

    if (lightboxCaption) {
      lightboxCaption.textContent = alt;
      lightboxCaption.hidden = alt === '';
    }

    lightbox.hidden = false;
    document.body.style.overflow = 'hidden';
  };

  const closeLightbox = () => {
    if (!lightbox || !lightboxImage) {
      return;
    }

    lightbox.hidden = true;
    lightboxImage.src = '';
    lightboxImage.alt = '';

    if (lightboxCaption) {
      lightboxCaption.textContent = '';
      lightboxCaption.hidden = true;
    }

    document.body.style.overflow = '';
  };

  const initNavigation = () => {
    if (!shell || menuToggles.length === 0) {
      return;
    }

    const isMobileViewport = () => window.matchMedia('(max-width: 1360px)').matches;
    const setMobileNavigationOpen = (open) => {
      shell.classList.toggle('nav-open', open);
      document.documentElement.classList.toggle('nav-modal-open', open);
    };
    const closeMobileNavigation = () => {
      setMobileNavigationOpen(false);
    };

    const syncNavigationState = () => {
      if (isMobileViewport()) {
        shell.classList.remove('nav-collapsed');
        return;
      }

      const collapsed = window.localStorage.getItem(sidebarStorageKey) === '1';
      shell.classList.toggle('nav-collapsed', collapsed);
      closeMobileNavigation();
    };

    menuToggles.forEach((toggle) => {
      if (toggle.dataset.jsBound === 'true') {
        return;
      }

      toggle.dataset.jsBound = 'true';
      toggle.addEventListener('click', () => {
        if (isMobileViewport()) {
          setMobileNavigationOpen(!shell.classList.contains('nav-open'));
          return;
        }

        const nextCollapsed = !shell.classList.contains('nav-collapsed');
        shell.classList.toggle('nav-collapsed', nextCollapsed);
        window.localStorage.setItem(sidebarStorageKey, nextCollapsed ? '1' : '0');
      });
    });

    document.querySelectorAll('.nav-link').forEach((link) => {
      if (link.dataset.navCloseBound === 'true') {
        return;
      }

      link.dataset.navCloseBound = 'true';
      link.addEventListener('click', () => {
        if (isMobileViewport()) {
          closeMobileNavigation();
        }
      });
    });

    if (sidebarBackdrop && sidebarBackdrop.dataset.jsBound !== 'true') {
      sidebarBackdrop.dataset.jsBound = 'true';
      sidebarBackdrop.addEventListener('click', closeMobileNavigation);
    }

    document.querySelectorAll('[data-open-notifications]').forEach((button) => {
      if (button.dataset.jsBound === 'true') {
        return;
      }

      button.dataset.jsBound = 'true';
      button.addEventListener('click', (event) => {
        event.stopPropagation();
        const feed = document.querySelector('[data-notification-feed]');
        const accountMenu = button.closest('.topbar-user-menu');

        if (feed instanceof HTMLDetailsElement) {
          feed.open = true;
        }

        if (accountMenu instanceof HTMLDetailsElement) {
          accountMenu.open = false;
        }
      });
    });

    if (document.body.dataset.topbarMenusBound !== 'true') {
      document.body.dataset.topbarMenusBound = 'true';
      document.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Node)) {
          return;
        }

        document.querySelectorAll('.topbar-user-menu, [data-notification-feed]').forEach((menu) => {
          if (menu instanceof HTMLDetailsElement && !menu.contains(target)) {
            menu.open = false;
          }
        });
      });
    }

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && isMobileViewport()) {
        closeMobileNavigation();
      }
    });

    window.addEventListener('resize', syncNavigationState);
    syncNavigationState();
  };

  const confirmDialog = (() => {
    let activeResolve = null;
    let modal = null;

    const close = (confirmed = false) => {
      if (!modal) {
        return;
      }

      modal.hidden = true;
      document.body.classList.remove('modal-open');

      if (activeResolve) {
        activeResolve(confirmed);
        activeResolve = null;
      }
    };

    const ensureModal = () => {
      if (modal) {
        return modal;
      }

      modal = document.createElement('div');
      modal.className = 'confirm-modal-backdrop';
      modal.hidden = true;
      modal.innerHTML = `
        <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title">
          <div>
            <p class="eyebrow">Confirm Action</p>
            <h3 id="confirm-modal-title">Are you sure?</h3>
            <p data-confirm-modal-message></p>
          </div>
          <div class="confirm-modal-actions">
            <button class="ghost-button" type="button" data-confirm-modal-cancel>Cancel</button>
            <button class="primary-button" type="button" data-confirm-modal-accept>Confirm</button>
          </div>
        </div>
      `;
      document.body.appendChild(modal);

      modal.addEventListener('click', (event) => {
        const target = event.target;

        if (target === modal || (target instanceof Element && target.matches('[data-confirm-modal-cancel]'))) {
          close(false);
        }

        if (target instanceof Element && target.matches('[data-confirm-modal-accept]')) {
          close(true);
        }
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && !modal.hidden) {
          close(false);
        }
      });

      return modal;
    };

    return (message) => new Promise((resolve) => {
      const dialog = ensureModal();
      const messageNode = dialog.querySelector('[data-confirm-modal-message]');
      const acceptButton = dialog.querySelector('[data-confirm-modal-accept]');

      if (messageNode) {
        messageNode.textContent = message || 'Are you sure?';
      }

      activeResolve = resolve;
      dialog.hidden = false;
      document.body.classList.add('modal-open');

      if (acceptButton instanceof HTMLButtonElement) {
        acceptButton.focus();
      }
    });
  })();

  const initConfirmButtons = (root = document) => {
    root.querySelectorAll('[data-confirm]').forEach((button) => {
      if (button.dataset.jsBound === 'true') {
        return;
      }

      button.dataset.jsBound = 'true';
      button.addEventListener('click', (event) => {
        if (button.dataset.confirmBypass === 'true') {
          return;
        }

        const message = button.getAttribute('data-confirm') || 'Are you sure?';

        event.preventDefault();
        event.stopImmediatePropagation();

        confirmDialog(message).then((confirmed) => {
          if (!confirmed) {
            return;
          }

          button.dataset.confirmBypass = 'true';

          if (button instanceof HTMLButtonElement && button.form) {
            if (typeof button.form.requestSubmit === 'function') {
              button.form.requestSubmit(button);
            } else {
              button.form.submit();
            }
          } else if (button instanceof HTMLAnchorElement && button.href) {
            window.location.href = button.href;
          } else {
            button.click();
          }

          window.setTimeout(() => {
            delete button.dataset.confirmBypass;
          }, 0);
        });
      });
    });
  };

  const initUnitSelectors = (root = document) => {
    root.querySelectorAll('[data-unit-select]').forEach((select) => {
      if (select.dataset.jsBound === 'true') {
        return;
      }

      const field = select.closest('.field');
      const customUnitField = field ? field.querySelector('[data-custom-unit]') : null;

      if (!customUnitField) {
        return;
      }

      const syncCustomUnit = () => {
        const showCustom = select.value === 'custom';
        customUnitField.hidden = !showCustom;
        customUnitField.required = showCustom;
      };

      select.dataset.jsBound = 'true';
      select.addEventListener('change', syncCustomUnit);
      syncCustomUnit();
    });
  };

  const initStocktakeStorageSelects = (root = document) => {
    root.querySelectorAll('[data-stocktake-storage-select]').forEach((select) => {
      if (select.dataset.jsBound === 'true') {
        return;
      }

      select.dataset.jsBound = 'true';
      select.addEventListener('change', () => {
        const baseUrl = select.getAttribute('data-stocktake-create-base') || '';

        if (!select.value || !baseUrl) {
          return;
        }

        window.location.href = `${baseUrl}${encodeURIComponent(select.value)}`;
      });
    });
  };

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

  const normalizeCode39 = (value) => {
    const normalized = String(value || '')
      .trim()
      .toUpperCase()
      .replace(/[^0-9A-Z .\-\/+$%]/g, '-')
      .replace(/^-+|-+$/g, '');

    return normalized || 'INV';
  };

  const code39SvgMarkup = (value, height = 48) => {
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

  const initItemCodePreview = (root = document) => {
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

  const initImageExpanders = (root = document) => {
    root.querySelectorAll('[data-expand-image]').forEach((image) => {
      if (image.dataset.jsBound === 'true') {
        return;
      }

      image.dataset.jsBound = 'true';

      image.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openLightbox(image);
      });

      image.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openLightbox(image);
        }
      });
    });
  };

  const initLightboxChrome = () => {
    if (!lightbox || lightbox.dataset.jsBound === 'true') {
      return;
    }

    lightbox.dataset.jsBound = 'true';

    document.querySelectorAll('[data-image-lightbox-close]').forEach((element) => {
      element.addEventListener('click', closeLightbox);
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !lightbox.hidden) {
        closeLightbox();
      }
    });
  };

  const setNotificationSoundPreference = (enabled) => {
    notificationSoundEnabled = Boolean(enabled);
    window.localStorage.setItem(notificationSoundStorageKey, notificationSoundEnabled ? '1' : '0');
    document.querySelectorAll('[data-notification-sound-toggle]').forEach((button) => {
      button.textContent = notificationSoundEnabled ? 'Sound On' : 'Sound Off';
      button.setAttribute('aria-pressed', notificationSoundEnabled ? 'true' : 'false');
      button.classList.toggle('is-muted', !notificationSoundEnabled);
    });
  };

  const unlockNotificationAudio = () => {
    notificationAudioUnlocked = true;
  };

  const playNotificationSound = (force = false) => {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;

    if ((!notificationSoundEnabled && !force) || (!notificationAudioUnlocked && !force) || !AudioContextClass) {
      return;
    }

    try {
      if (!notificationAudioContext) {
        notificationAudioContext = new AudioContextClass();
      }

      const context = notificationAudioContext;

      if (context.state === 'suspended') {
        context.resume();
      }

      const oscillator = context.createOscillator();
      const gain = context.createGain();

      oscillator.type = 'triangle';
      oscillator.frequency.setValueAtTime(880, context.currentTime);
      oscillator.frequency.exponentialRampToValueAtTime(1174, context.currentTime + 0.08);
      gain.gain.setValueAtTime(0.0001, context.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.06, context.currentTime + 0.025);
      gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.28);

      oscillator.connect(gain);
      gain.connect(context.destination);
      oscillator.start();
      oscillator.stop(context.currentTime + 0.3);
    } catch (error) {
      // Ignore browser audio failures.
    }
  };

  const initGlobalSearch = (root = document) => {
    root.querySelectorAll('[data-global-search]').forEach((form) => {
      if (form.dataset.jsBound === 'true') {
        return;
      }

      const input = form.querySelector('[data-global-search-input]');
      const panel = form.querySelector('[data-global-search-panel]');
      const status = form.querySelector('[data-global-search-status]');
      const resultsWrap = form.querySelector('[data-global-search-results]');
      const searchUrl = form.dataset.globalSearchUrl || form.action;

      if (!(input instanceof HTMLInputElement) || !panel || !status || !resultsWrap || !searchUrl) {
        return;
      }

      let activeController = null;
      let debounceTimer = null;
      let activeIndex = -1;
      let lastResults = [];
      let fallbackUrl = '';
      let directUrl = '';

      const openPanel = () => {
        panel.hidden = false;
      };

      const closePanel = () => {
        panel.hidden = true;
        activeIndex = -1;
      };

      const setStatus = (message, loading = false) => {
        status.textContent = message;
        status.classList.toggle('is-loading', loading);
      };

      const resultLinks = () => Array.from(resultsWrap.querySelectorAll('[data-global-search-result]'));

      const syncActiveResult = () => {
        resultLinks().forEach((link, index) => {
          const isActive = index === activeIndex;
          link.classList.toggle('is-active', isActive);
          link.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
      };

      const groupedResultsMarkup = (results) => {
        const groups = new Map();

        results.forEach((result) => {
          const group = result.group || 'Results';

          if (!groups.has(group)) {
            groups.set(group, []);
          }

          groups.get(group).push(result);
        });

        return Array.from(groups.entries()).map(([group, groupResults]) => `
          <section class="global-search-group">
            <span>${escapeHtml(group)}</span>
            ${groupResults.map((result, index) => `
              <a class="global-search-result" href="${escapeHtml(result.url || '#')}" data-global-search-result data-result-index="${index}">
                <span class="global-search-result-icon">${escapeHtml((result.icon || result.group || '?').slice(0, 1).toUpperCase())}</span>
                <span class="global-search-result-copy">
                  <strong>${escapeHtml(result.title || '')}</strong>
                  <small>${escapeHtml(result.subtitle || '')}</small>
                </span>
                ${result.badge ? `<em>${escapeHtml(result.badge)}</em>` : ''}
              </a>
            `).join('')}
          </section>
        `).join('');
      };

      const renderPayload = (payload) => {
        lastResults = Array.isArray(payload.results) ? payload.results : [];
        fallbackUrl = payload.fallback_url || '';
        directUrl = payload.direct_url || '';

        if (directUrl) {
          setStatus(`Opening ${payload.direct_reference || 'reference'}...`, true);
          window.location.href = directUrl;
          return;
        }

        if (lastResults.length === 0) {
          resultsWrap.innerHTML = '';
          setStatus(payload.message || 'No matching records found.');
          openPanel();
          return;
        }

        resultsWrap.innerHTML = groupedResultsMarkup(lastResults);
        setStatus(`${lastResults.length} result${lastResults.length === 1 ? '' : 's'} found.`);
        activeIndex = 0;
        syncActiveResult();
        openPanel();
      };

      const runSearch = async () => {
        const query = input.value.trim();

        if (query.length < 2) {
          lastResults = [];
          fallbackUrl = '';
          directUrl = '';
          resultsWrap.innerHTML = '';
          setStatus('Type at least 2 characters.');
          closePanel();
          return;
        }

        if (activeController) {
          activeController.abort();
        }

        activeController = new AbortController();
        setStatus('Searching...', true);
        openPanel();

        try {
          const url = `${searchUrl}?${new URLSearchParams({ q: query }).toString()}`;
          const response = await fetch(url, {
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            signal: activeController.signal,
          });
          const payload = await response.json();

          if (!response.ok || !payload.ok) {
            throw new Error(payload.message || 'Search failed.');
          }

          renderPayload(payload);
        } catch (error) {
          if (activeController?.signal.aborted) {
            return;
          }

          resultsWrap.innerHTML = '';
          setStatus(error.message || 'Search failed.');
          openPanel();
        }
      };

      const scheduleSearch = () => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(runSearch, 220);
      };

      form.dataset.jsBound = 'true';

      input.addEventListener('input', scheduleSearch);
      input.addEventListener('focus', () => {
        if (input.value.trim().length >= 2) {
          scheduleSearch();
        }
      });

      input.addEventListener('keydown', (event) => {
        const links = resultLinks();

        if (event.key === 'Escape') {
          closePanel();
          input.blur();
          return;
        }

        if (event.key === 'ArrowDown' && links.length > 0) {
          event.preventDefault();
          activeIndex = Math.min(activeIndex + 1, links.length - 1);
          syncActiveResult();
          return;
        }

        if (event.key === 'ArrowUp' && links.length > 0) {
          event.preventDefault();
          activeIndex = Math.max(activeIndex - 1, 0);
          syncActiveResult();
        }
      });

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const links = resultLinks();
        const target = links[activeIndex] || links[0];

        if (target instanceof HTMLAnchorElement && target.href) {
          window.location.href = target.href;
          return;
        }

        if (directUrl) {
          window.location.href = directUrl;
        } else if (fallbackUrl) {
          window.location.href = fallbackUrl;
        } else if (input.value.trim().length >= 2) {
          runSearch();
        }
      });

      document.addEventListener('click', (event) => {
        if (event.target instanceof Node && !form.contains(event.target)) {
          closePanel();
        }
      });
    });
  };

  const initSearchableSelects = (root = document) => {
    const selects = Array.from(root.querySelectorAll?.('[data-searchable-select]') || []);

    selects.forEach((select) => {
      if (!(select instanceof HTMLSelectElement) || select.dataset.searchableBound === 'true') {
        return;
      }

      const wrapper = document.createElement('div');
      wrapper.className = 'searchable-select';

      const search = document.createElement('input');
      search.type = 'search';
      search.className = 'searchable-select-input';
      search.placeholder = select.dataset.searchablePlaceholder || 'Search options...';
      search.setAttribute('aria-label', `${select.name || 'Select'} search`);
      search.autocomplete = 'off';

      const empty = document.createElement('p');
      empty.className = 'searchable-select-empty';
      empty.hidden = true;
      empty.textContent = 'No matching options.';

      select.parentNode.insertBefore(wrapper, select);
      wrapper.append(search, select, empty);
      select.dataset.searchableBound = 'true';

      const options = Array.from(select.options);
      const optionText = (option) => `${option.textContent || ''} ${option.value || ''} ${option.dataset.searchText || ''}`.toLowerCase();

      const filterOptions = () => {
        const query = search.value.trim().toLowerCase();
        let matches = 0;

        options.forEach((option) => {
          const isDefaultOption = option.value === '';
          const isMatch = query === '' || isDefaultOption || optionText(option).includes(query);

          option.hidden = !isMatch;
          option.disabled = !isMatch && !option.selected;

          if (isMatch && !isDefaultOption) {
            matches += 1;
          }
        });

        empty.hidden = query === '' || matches > 0;
      };

      search.addEventListener('input', filterOptions);
      search.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          search.value = '';
          filterOptions();
          select.focus();
        }
      });

      filterOptions();
    });
  };

  const notificationToastContainer = () => {
    let container = document.querySelector('[data-notification-toast-container]');

    if (!container) {
      container = document.createElement('section');
      container.className = 'notification-toast-stack';
      container.setAttribute('data-notification-toast-container', '');
      container.setAttribute('aria-live', 'polite');
      container.setAttribute('aria-label', 'New notifications');
      document.body.appendChild(container);
    }

    return container;
  };

  const showNotificationToast = (item) => {
    if (!item || !item.title) {
      return;
    }

    const container = notificationToastContainer();
    const toast = document.createElement('article');
    const actorCopy = item.actor_name ? `<span class="tiny-copy">By ${escapeHtml(item.actor_name)}</span>` : '';
    const messageCopy = item.message ? `<p>${escapeHtml(item.message)}</p>` : '';
    const actionLink = item.action_url
      ? `<a class="notification-toast-link" href="${escapeHtml(item.action_url)}">Open</a>`
      : '';

    toast.className = 'notification-toast';
    toast.innerHTML = `
      <div>
        <span class="eyebrow">New notification</span>
        <strong>${escapeHtml(item.title)}</strong>
        ${actorCopy}
        ${messageCopy}
      </div>
      <div class="notification-toast-actions">
        ${actionLink}
        <button class="notification-toast-close" type="button" aria-label="Close notification popup">&times;</button>
      </div>
    `;

    const closeToast = () => {
      toast.classList.add('is-closing');
      window.setTimeout(() => toast.remove(), 180);
    };

    toast.querySelector('.notification-toast-close')?.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      closeToast();
    });

    container.prepend(toast);
    window.setTimeout(closeToast, 8000);
  };

  const initNotificationFeed = () => {
    const feed = document.querySelector('[data-notification-feed]');

    if (!feed || feed.dataset.jsBound === 'true') {
      return;
    }

    const knownNotificationIds = new Set(
      Array.from(feed.querySelectorAll('[data-notification-id]'))
        .map((row) => row.getAttribute('data-notification-id') || '')
        .filter((id) => id !== '')
    );
    const soundToggle = feed.querySelector('[data-notification-sound-toggle]');
    const soundTest = feed.querySelector('[data-notification-sound-test]');

    if (soundToggle instanceof HTMLButtonElement) {
      setNotificationSoundPreference(notificationSoundEnabled);
      soundToggle.addEventListener('click', () => {
        unlockNotificationAudio();
        setNotificationSoundPreference(!notificationSoundEnabled);

        if (notificationSoundEnabled) {
          playNotificationSound(true);
        }
      });
    }

    if (soundTest instanceof HTMLButtonElement) {
      soundTest.addEventListener('click', () => {
        unlockNotificationAudio();
        playNotificationSound(true);
      });
    }

    const renderNotificationItem = (item) => {
      const actorCopy = item.actor_name ? `<span class="tiny-copy">By ${escapeHtml(item.actor_name)}</span>` : '';
      const messageCopy = item.message ? `<p>${escapeHtml(item.message)}</p>` : '';
      const badge = item.read_at ? '' : '<span class="notification-status-dot" aria-label="Unread notification"></span>';
      const createdAtCopy = escapeHtml(item.created_at_display || formatDateTimeCopy(item.created_at) || 'Just now');
      const notificationId = item.id ? ` data-notification-id="${escapeHtml(item.id)}"` : '';

      return `
        <a class="notification-row" href="${escapeHtml(item.action_url || '#')}"${notificationId}>
          <div class="notification-row-copy">
            <strong>${escapeHtml(item.title || '')}</strong>
            ${actorCopy}
            ${messageCopy}
            <span class="tiny-copy">${createdAtCopy}</span>
          </div>
          ${badge}
        </a>
      `;
    };

    const refreshNotificationFeed = async (silent = false) => {
      const feedUrl = feed.dataset.feedUrl;

      if (!feedUrl) {
        return;
      }

      try {
        const response = await fetch(feedUrl, {
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        });

        if (!response.ok) {
          throw new Error(`Notification feed failed: ${response.status}`);
        }

        const payload = await response.json();
        const unreadCount = Number.parseInt(payload.unread_count, 10) || 0;
        const badge = feed.querySelector('[data-notification-badge]');
        let itemsWrapper = feed.querySelector('[data-notification-items]');
        let emptyState = feed.querySelector('[data-notification-empty]');

        if (!itemsWrapper && payload.items && payload.items.length > 0) {
          itemsWrapper = document.createElement('div');
          itemsWrapper.setAttribute('data-notification-items', '');
          const panel = feed.querySelector('[data-notification-panel]');
          const footer = panel?.querySelector('.notification-panel-footer');

          if (footer) {
            panel?.insertBefore(itemsWrapper, footer);
          } else {
            panel?.appendChild(itemsWrapper);
          }
        }

        if (itemsWrapper) {
          itemsWrapper.innerHTML = (payload.items || []).map(renderNotificationItem).join('');
          itemsWrapper.hidden = (payload.items || []).length === 0;
        }

        if (emptyState) {
          emptyState.hidden = (payload.items || []).length > 0;
        } else if ((payload.items || []).length === 0) {
          emptyState = document.createElement('p');
          emptyState.className = 'empty-state';
          emptyState.setAttribute('data-notification-empty', '');
          emptyState.textContent = 'No notifications yet.';
          const panel = feed.querySelector('[data-notification-panel]');
          const footer = panel?.querySelector('.notification-panel-footer');

          if (footer) {
            panel?.insertBefore(emptyState, footer);
          } else {
            panel?.appendChild(emptyState);
          }
        }

        if (badge) {
          badge.textContent = unreadCount > 0 ? String(unreadCount) : '';
          badge.hidden = unreadCount === 0;
        } else if (unreadCount > 0) {
          const summary = feed.querySelector('.notification-toggle');

          if (summary) {
            const nextBadge = document.createElement('span');
            nextBadge.className = 'notification-badge';
            nextBadge.setAttribute('data-notification-badge', '');
            nextBadge.textContent = String(unreadCount);
            summary.appendChild(nextBadge);
          }
        }

        if (!silent && unreadCount > lastKnownNotificationCount) {
          playNotificationSound();

          (payload.items || [])
            .filter((item) => item && !item.read_at && item.id && !knownNotificationIds.has(String(item.id)))
            .slice(0, Math.max(1, unreadCount - lastKnownNotificationCount))
            .reverse()
            .forEach(showNotificationToast);
        }

        (payload.items || []).forEach((item) => {
          if (item && item.id) {
            knownNotificationIds.add(String(item.id));
          }
        });

        lastKnownNotificationCount = unreadCount;
      } catch (error) {
        // Ignore notification refresh failures.
      }
    };

    feed.dataset.jsBound = 'true';

    ['click', 'keydown', 'touchstart'].forEach((eventName) => {
      document.addEventListener(eventName, () => {
        unlockNotificationAudio();
      }, { once: true });
    });

    feed.addEventListener('toggle', () => {
      if (feed.open) {
        refreshNotificationFeed(false);
      }
    });

    document.addEventListener('inventory:action-complete', () => {
      refreshNotificationFeed(false);
    });

    window.setInterval(() => {
      if (document.visibilityState === 'visible') {
        refreshNotificationFeed(false);
      }
    }, 25000);
  };

  const initLiveActionForms = (root = document) => {
    root.querySelectorAll('[data-live-action-form]').forEach((form) => {
      if (form.dataset.jsBound === 'true') {
        return;
      }

      form.dataset.jsBound = 'true';
      form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const submitButton = form.querySelector('button[type="submit"]');

        if (submitButton instanceof HTMLButtonElement) {
          submitButton.disabled = true;
        }

        try {
          const response = await fetch(form.action, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: new FormData(form),
          });

          const payload = await response.json();

          if (!response.ok || !payload.ok) {
            throw new Error(payload.message || 'Action failed.');
          }

          if (payload.redirect_url) {
            await replaceMainContentFromUrl(payload.redirect_url);
          }

          showGlobalFlash(payload.message || 'Saved.', 'success');
          document.dispatchEvent(new CustomEvent('inventory:action-complete'));
          initNotificationFeed();
        } catch (error) {
          showGlobalFlash(error.message || 'Action failed.', 'danger');
        } finally {
          if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = false;
          }
        }
      });
    });
  };

  const initHandoverCloseForms = (root = document) => {
    root.querySelectorAll('[data-handover-close-form]').forEach((form) => {
      if (form.dataset.handoverBound === 'true') {
        return;
      }

      const toggleOtherField = (row) => {
        const reason = row.querySelector('[data-handover-usage-reason]');
        const other = row.querySelector('[data-handover-usage-other]');

        if (!(reason instanceof HTMLSelectElement) || !(other instanceof HTMLInputElement)) {
          return;
        }

        const isOther = reason.value === 'other';
        other.hidden = !isOther;

        if (!isOther) {
          other.value = '';
        }
      };

      const syncEditor = (editor) => {
        const usedField = editor.querySelector('[data-handover-used]');
        const closeLine = editor.closest('[data-handover-close-line]') || editor.closest('tr');
        const returnedField = closeLine?.querySelector('[data-handover-returned]');
        const cardUsedLabel = closeLine?.querySelector('[data-handover-card-used]');
        const cardReturnedLabel = closeLine?.querySelector('[data-handover-card-returned]');
        const totalLabel = editor.querySelector('[data-handover-used-total]');
        const warning = editor.querySelector('[data-handover-usage-warning]');

        if (!(usedField instanceof HTMLInputElement)) {
          return;
        }

        const handed = parseNumber(usedField.dataset.handoverHanded || '0');
        let used = 0;

        editor.querySelectorAll('[data-handover-usage-quantity]').forEach((field) => {
          if (!(field instanceof HTMLInputElement)) {
            return;
          }

          used += Math.max(0, parseNumber(field.value));
        });

        used = Math.round(used * 100) / 100;
        const returned = Math.max(0, handed - used);
        usedField.value = formatQuantity(used);

        if (totalLabel instanceof HTMLElement) {
          totalLabel.textContent = formatQuantity(used);
        }

        if (cardUsedLabel instanceof HTMLElement) {
          cardUsedLabel.textContent = formatQuantity(used);
        }

        if (cardReturnedLabel instanceof HTMLElement) {
          cardReturnedLabel.textContent = formatQuantity(returned);
        }

        if (returnedField instanceof HTMLInputElement) {
          returnedField.value = formatQuantity(returned);
        }

        if (warning instanceof HTMLElement) {
          warning.hidden = used <= handed;
        }
      };

      const bindUsageRow = (row, editor) => {
        if (!(row instanceof HTMLElement)) {
          return;
        }

        row.querySelectorAll('input, select').forEach((field) => {
          field.addEventListener('input', () => syncEditor(editor));
          field.addEventListener('change', () => {
            toggleOtherField(row);
            syncEditor(editor);
          });
        });

        const removeButton = row.querySelector('[data-remove-handover-usage]');

        if (removeButton instanceof HTMLButtonElement) {
          removeButton.addEventListener('click', () => {
            const rows = Array.from(editor.querySelectorAll('[data-handover-usage-row]'));

            if (rows.length <= 1) {
              row.querySelectorAll('input').forEach((field) => {
                if (field instanceof HTMLInputElement) {
                  field.value = '';
                }
              });
              const reason = row.querySelector('[data-handover-usage-reason]');
              if (reason instanceof HTMLSelectElement) {
                reason.value = 'unspecified';
              }
              toggleOtherField(row);
              syncEditor(editor);
              return;
            }

            row.remove();
            syncEditor(editor);
          });
        }

        toggleOtherField(row);
      };

      form.dataset.handoverBound = 'true';

      form.querySelectorAll('[data-handover-usage-editor]').forEach((editor) => {
        if (!(editor instanceof HTMLElement)) {
          return;
        }

        editor.querySelectorAll('[data-handover-usage-row]').forEach((row) => bindUsageRow(row, editor));

        const addButton = editor.querySelector('[data-add-handover-usage]');
        const template = editor.querySelector('[data-handover-usage-template]');
        const list = editor.querySelector('[data-handover-usage-list]');

        if (addButton instanceof HTMLButtonElement && template instanceof HTMLTemplateElement && list instanceof HTMLElement) {
          addButton.addEventListener('click', () => {
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-handover-usage-row]');
            list.appendChild(fragment);

            if (row instanceof HTMLElement) {
              bindUsageRow(row, editor);
              const quantity = row.querySelector('[data-handover-usage-quantity]');
              if (quantity instanceof HTMLInputElement) {
                quantity.focus();
              }
            }

            syncEditor(editor);
          });
        }

        syncEditor(editor);
      });
    });
  };

  const initHandoverApprovalForms = (root = document) => {
    root.querySelectorAll('[data-handover-approval-form]').forEach((form) => {
      if (form.dataset.handoverApprovalBound === 'true') {
        return;
      }

      const syncLine = (line) => {
        const returnedField = line.querySelector('[data-handover-approval-returned]');
        const usedLabel = line.querySelector('[data-handover-approval-used]');
        const warning = line.querySelector('[data-handover-approval-warning]');

        if (!(returnedField instanceof HTMLInputElement)) {
          return;
        }

        const received = Math.max(0, parseNumber(returnedField.dataset.handoverReceived || '0'));
        const returned = parseNumber(returnedField.value);
        const isInvalid = returned < 0 || returned > received;
        const used = Math.max(0, received - Math.max(0, returned));

        if (usedLabel instanceof HTMLElement) {
          usedLabel.textContent = formatQuantity(Math.round(used * 100) / 100);
        }

        if (warning instanceof HTMLElement) {
          warning.hidden = !isInvalid;
        }

        returnedField.classList.toggle('is-invalid', isInvalid);
      };

      form.dataset.handoverApprovalBound = 'true';

      form.querySelectorAll('[data-handover-approval-line]').forEach((line) => {
        if (!(line instanceof HTMLElement)) {
          return;
        }

        const returnedField = line.querySelector('[data-handover-approval-returned]');

        if (returnedField instanceof HTMLInputElement) {
          returnedField.addEventListener('input', () => syncLine(line));
          returnedField.addEventListener('change', () => syncLine(line));
        }

        syncLine(line);
      });
    });
  };

  const initTableShell = (shellElement) => {
    if (shellElement.dataset.jsBound === 'true') {
      return;
    }

    const table = shellElement.querySelector('table');
    const tbody = table ? table.querySelector('tbody') : null;
    const searchInput = shellElement.querySelector('[data-table-search]');
    const pageSizeSelect = shellElement.querySelector('[data-table-page-size]');
    const results = shellElement.querySelector('[data-table-results]');
    const pagination = shellElement.querySelector('[data-table-pagination]');
    const totalBadge = shellElement.querySelector('[data-table-total]');

    if (!table || !tbody || !pagination) {
      return;
    }

    shellElement.dataset.jsBound = 'true';

    const staticEmptyRow = Array.from(tbody.querySelectorAll('tr')).find((row) => row.querySelector('.empty-cell')) || null;
    const rows = Array.from(tbody.querySelectorAll('tr')).filter((row) => row !== staticEmptyRow);
    const columnCount = table.querySelectorAll('thead th').length || 1;
    const defaultPageSize = Number.parseInt(shellElement.dataset.defaultPageSize || '10', 10);
    const emptyText = shellElement.dataset.emptyText || 'No matching records found.';

    let filteredRows = [...rows];
    let currentPage = 1;

    const dynamicEmptyRow = document.createElement('tr');
    dynamicEmptyRow.hidden = true;
    dynamicEmptyRow.innerHTML = `<td colspan="${columnCount}" class="empty-cell">${emptyText}</td>`;

    const clampPageSize = () => {
      const parsed = Number.parseInt(pageSizeSelect ? pageSizeSelect.value : String(defaultPageSize), 10);
      return Number.isFinite(parsed) && parsed > 0 ? parsed : defaultPageSize;
    };

    const pageSequence = (totalPages, current) => {
      if (totalPages <= 7) {
        return Array.from({ length: totalPages }, (_, index) => index + 1);
      }

      const pages = [1];
      const start = Math.max(2, current - 1);
      const end = Math.min(totalPages - 1, current + 1);

      if (start > 2) {
        pages.push('start-ellipsis');
      }

      for (let page = start; page <= end; page += 1) {
        pages.push(page);
      }

      if (end < totalPages - 1) {
        pages.push('end-ellipsis');
      }

      pages.push(totalPages);

      return pages;
    };

    const mountEmptyRow = (show) => {
      if (!show) {
        if (dynamicEmptyRow.parentElement) {
          dynamicEmptyRow.remove();
        }

        if (staticEmptyRow) {
          staticEmptyRow.hidden = true;
        }

        return;
      }

      if (rows.length === 0 && staticEmptyRow) {
        staticEmptyRow.hidden = false;
        return;
      }

      dynamicEmptyRow.hidden = false;

      if (!dynamicEmptyRow.parentElement) {
        tbody.appendChild(dynamicEmptyRow);
      }
    };

    const render = () => {
      const pageSize = clampPageSize();
      const totalRows = rows.length;
      const totalFiltered = filteredRows.length;
      const query = searchInput ? searchInput.value.trim() : '';
      const totalPages = totalFiltered === 0 ? 1 : Math.ceil(totalFiltered / pageSize);
      const safePage = Math.min(Math.max(currentPage, 1), totalPages);
      const startIndex = totalFiltered === 0 ? 0 : (safePage - 1) * pageSize;
      const endIndex = totalFiltered === 0 ? 0 : Math.min(startIndex + pageSize, totalFiltered);
      const visibleRows = filteredRows.slice(startIndex, endIndex);

      currentPage = safePage;

      rows.forEach((row) => {
        row.hidden = true;
      });

      visibleRows.forEach((row) => {
        row.hidden = false;
      });

      mountEmptyRow(totalFiltered === 0);

      if (totalBadge) {
        totalBadge.textContent = formatCount(totalRows);
      }

      if (results) {
        if (totalRows === 0) {
          results.textContent = 'Showing 0 to 0 of 0 entries';
        } else if (totalFiltered === 0) {
          results.textContent = query === ''
            ? 'Showing 0 to 0 of 0 entries'
            : `Showing 0 to 0 of 0 matching entries from ${formatCount(totalRows)} total`;
        } else {
          results.textContent = `Showing ${formatCount(startIndex + 1)} to ${formatCount(endIndex)} of ${formatCount(totalFiltered)} entries`;

          if (query !== '' && totalFiltered !== totalRows) {
            results.textContent += ` from ${formatCount(totalRows)} total`;
          }
        }
      }

      pagination.innerHTML = '';
      pagination.hidden = totalRows === 0 || totalPages <= 1;

      if (pagination.hidden) {
        return;
      }

      const makeButton = (label, page, options = {}) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'table-page-button';
        button.textContent = label;

        if (options.active) {
          button.classList.add('is-active');
          button.setAttribute('aria-current', 'page');
        }

        if (options.ellipsis) {
          button.classList.add('is-ellipsis');
          button.disabled = true;
          return button;
        }

        if (options.disabled) {
          button.disabled = true;
        } else {
          button.addEventListener('click', () => {
            currentPage = page;
            render();
          });
        }

        return button;
      };

      pagination.appendChild(makeButton('Previous', safePage - 1, { disabled: safePage === 1 }));

      pageSequence(totalPages, safePage).forEach((page) => {
        if (typeof page === 'string') {
          pagination.appendChild(makeButton('...', safePage, { ellipsis: true }));
          return;
        }

        pagination.appendChild(makeButton(String(page), page, { active: page === safePage }));
      });

      pagination.appendChild(makeButton('Next', safePage + 1, { disabled: safePage === totalPages }));
    };

    const updateFilters = () => {
      const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
      filteredRows = rows.filter((row) => row.textContent.toLowerCase().includes(query));
      currentPage = 1;
      render();
    };

    if (pageSizeSelect) {
      pageSizeSelect.addEventListener('change', () => {
        currentPage = 1;
        render();
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', updateFilters);
    }

    render();
  };

  const initDataTables = (root = document) => {
    root.querySelectorAll('[data-table-shell]').forEach((shellElement) => {
      initTableShell(shellElement);
    });
  };

  const initMovementForm = (root = document) => {
    root.querySelectorAll('[data-movement-form]').forEach((movementForm) => {
      if (movementForm.dataset.jsBound === 'true') {
        return;
      }

      const movementType = movementForm.querySelector('[data-movement-type]');
      const quantityInput = movementForm.querySelector('[data-quantity-input]');
      const quantityHint = movementForm.querySelector('[data-quantity-hint]');
      const feedback = movementForm.querySelector('[data-movement-feedback]');
      const summary = document.querySelector('[data-item-summary]');

      if (!movementType || !quantityInput || !quantityHint || !feedback || !summary) {
        return;
      }

      movementForm.dataset.jsBound = 'true';

      const submitButton = movementForm.querySelector('[data-movement-submit]');
      const historyBody = document.querySelector('[data-history-body]');
      const sourceField = movementForm.querySelector('[data-source-field]');
      const destinationField = movementForm.querySelector('[data-destination-field]');
      const sourceLabel = movementForm.querySelector('[data-source-label]');
      const destinationLabel = movementForm.querySelector('[data-destination-label]');
      const sourceStorage = movementForm.querySelector('[data-source-storage]');
      const destinationStorage = movementForm.querySelector('[data-destination-storage]');
      const stockNumber = summary.querySelector('[data-stock-number]');
      const stockUnit = summary.querySelector('[data-stock-unit]');
      const stockValueLabel = summary.querySelector('[data-stock-value-label]');
      const totalUsed = summary.querySelector('[data-total-used]');
      const totalAdded = summary.querySelector('[data-total-added]');
      const totalTransferred = summary.querySelector('[data-total-transferred]');
      const movementCount = summary.querySelector('[data-movement-count]');
      const stockValueMetric = summary.querySelector('[data-stock-value-metric]');
      const previewDelta = movementForm.querySelector('[data-preview-delta]');
      const previewBalance = movementForm.querySelector('[data-preview-balance]');
      const previewSource = movementForm.querySelector('[data-preview-source]');
      const previewDestination = movementForm.querySelector('[data-preview-destination]');
      const previewSourceLabel = movementForm.querySelector('[data-preview-source-label]');
      const previewDestinationLabel = movementForm.querySelector('[data-preview-destination-label]');
      const previewValue = movementForm.querySelector('[data-preview-value]');
      const dateInput = movementForm.querySelector('input[name="used_at"]');
      const referenceInput = movementForm.querySelector('input[name="reference_code"]');
      const notesInput = movementForm.querySelector('textarea[name="notes"]');

      let currentQuantity = parseNumber(summary.dataset.currentQuantity);
      let costPerUnit = parseNumber(summary.dataset.costPerUnit);
      let currentUnit = summary.dataset.unit || 'pcs';
      let locationBalances = {};

      try {
        locationBalances = JSON.parse(summary.dataset.balanceMap || '{}');
      } catch (error) {
        locationBalances = {};
      }

      const showFeedback = (message, type) => {
        feedback.hidden = false;
        feedback.className = `movement-feedback flash flash-${type}`;
        feedback.textContent = message;
      };

      const clearFeedback = () => {
        feedback.hidden = true;
        feedback.textContent = '';
        feedback.className = 'movement-feedback';
      };

      const getLocationBalance = (storageId) => {
        if (!storageId) {
          return 0;
        }

        return parseNumber(locationBalances[String(storageId)]);
      };

      const setPreviewValue = (element, value, unit, negative = false) => {
        if (!element) {
          return;
        }

        element.textContent = value === null ? '-' : `${formatQuantity(value)} ${unit}`;
        element.classList.toggle('danger-text', negative);
      };

      const syncMovementLayout = () => {
        const type = movementType.value;
        const needsSource = type === 'usage' || type === 'transfer' || type === 'adjustment';
        const needsDestination = type === 'restock' || type === 'transfer';

        if (sourceField) {
          sourceField.hidden = !needsSource;
        }

        if (destinationField) {
          destinationField.hidden = !needsDestination;
        }

        if (sourceStorage) {
          sourceStorage.required = needsSource;
        }

        if (destinationStorage) {
          destinationStorage.required = needsDestination;
        }

        if (sourceLabel) {
          sourceLabel.textContent = type === 'adjustment' ? 'Adjust Location' : 'From Location';
        }

        if (destinationLabel) {
          destinationLabel.textContent = type === 'restock' ? 'To Location' : 'Destination';
        }

        if (previewSourceLabel) {
          previewSourceLabel.textContent = type === 'adjustment' ? 'Adjusted Location After' : 'Source After';
        }

        if (previewDestinationLabel) {
          previewDestinationLabel.textContent = type === 'restock' ? 'Restock Location After' : 'Destination After';
        }
      };

      const syncMovementState = () => {
        const type = movementType.value;
        const rawQuantity = parseNumber(quantityInput.value);
        const absoluteQuantity = Math.abs(rawQuantity);
        const sourceId = sourceStorage ? sourceStorage.value : '';
        const destinationId = destinationStorage ? destinationStorage.value : '';
        const sourceCurrent = getLocationBalance(sourceId);
        const destinationCurrent = getLocationBalance(destinationId);

        let delta = 0;
        let projectedBalance = currentQuantity;
        let projectedValue = currentQuantity * costPerUnit;
        let sourceAfter = null;
        let destinationAfter = null;
        let invalid = false;

        if (type === 'adjustment') {
          delta = rawQuantity;
          projectedBalance = currentQuantity + delta;
          sourceAfter = sourceId ? sourceCurrent + rawQuantity : null;
          quantityHint.textContent = 'Adjustments can be positive or negative, but the location cannot go below zero.';
          invalid = !sourceId || sourceAfter === null || sourceAfter < 0;
        } else if (type === 'restock') {
          delta = absoluteQuantity;
          projectedBalance = currentQuantity + delta;
          destinationAfter = destinationId ? destinationCurrent + absoluteQuantity : null;
          quantityHint.textContent = 'Restock adds stock to the selected location.';
          invalid = !destinationId;
        } else if (type === 'transfer') {
          delta = 0;
          projectedBalance = currentQuantity;
          sourceAfter = sourceId ? sourceCurrent - absoluteQuantity : null;
          destinationAfter = destinationId ? destinationCurrent + absoluteQuantity : null;
          quantityHint.textContent = 'Transfer moves stock between locations without changing the total on hand.';
          invalid = !sourceId || !destinationId || sourceId === destinationId || sourceAfter === null || sourceAfter < 0;
        } else {
          delta = -absoluteQuantity;
          projectedBalance = currentQuantity + delta;
          sourceAfter = sourceId ? sourceCurrent - absoluteQuantity : null;
          quantityHint.textContent = 'Usage subtracts stock from the selected location. Type 100 to use 100.';
          invalid = !sourceId || sourceAfter === null || sourceAfter < 0;
        }

        projectedValue = projectedBalance * costPerUnit;

        if (previewDelta) {
          previewDelta.textContent = `${formatQuantity(delta)} ${currentUnit}`;
          previewDelta.classList.toggle('danger-text', delta < 0);
        }

        if (previewBalance) {
          previewBalance.textContent = `${formatQuantity(projectedBalance)} ${currentUnit}`;
          previewBalance.classList.toggle('danger-text', projectedBalance < 0);
        }

        setPreviewValue(previewSource, sourceAfter, currentUnit, sourceAfter !== null && sourceAfter < 0);
        setPreviewValue(previewDestination, destinationAfter, currentUnit, false);

        if (previewValue) {
          previewValue.textContent = formatMoney(projectedValue);
        }

        if (submitButton) {
          const hasQuantity = quantityInput.value !== '';
          const invalidNegative = projectedBalance < 0;
          submitButton.disabled = invalid || invalidNegative || !hasQuantity;
          submitButton.classList.toggle('is-disabled', submitButton.disabled);
        }
      };

      movementType.addEventListener('change', () => {
        syncMovementLayout();
        syncMovementState();
      });

      quantityInput.addEventListener('input', syncMovementState);
      sourceStorage?.addEventListener('change', syncMovementState);
      destinationStorage?.addEventListener('change', syncMovementState);

      syncMovementLayout();
      syncMovementState();

      movementForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFeedback();

        if (submitButton) {
          submitButton.disabled = true;
          submitButton.textContent = 'Saving...';
        }

        const formData = new FormData(movementForm);

        try {
          const response = await fetch(movementForm.action, {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
          });

          const payload = await response.json();

          if (!response.ok) {
            showFeedback((payload.errors || [payload.message || 'Movement could not be saved.']).join(' '), 'danger');
            return;
          }

          currentQuantity = payload.item.current_quantity_raw;
          costPerUnit = payload.item.cost_per_unit_raw;
          currentUnit = payload.item.unit;

          summary.dataset.currentQuantity = String(currentQuantity);
          summary.dataset.costPerUnit = String(costPerUnit);
          summary.dataset.unit = currentUnit;

          if (payload.item.balance_map_json) {
            summary.dataset.balanceMap = payload.item.balance_map_json;

            try {
              locationBalances = JSON.parse(payload.item.balance_map_json);
            } catch (error) {
              locationBalances = {};
            }
          }

          if (stockNumber) {
            stockNumber.textContent = payload.item.current_quantity;
          }

          if (stockUnit) {
            stockUnit.textContent = `${currentUnit} on hand`;
          }

          if (stockValueLabel) {
            stockValueLabel.textContent = `${payload.item.stock_value} stock value`;
          }

          if (totalUsed) {
            totalUsed.textContent = `${payload.item.total_used} ${currentUnit}`;
          }

          if (totalAdded) {
            totalAdded.textContent = `${payload.item.total_added} ${currentUnit}`;
          }

          if (totalTransferred) {
            totalTransferred.textContent = `${payload.item.total_transferred} ${currentUnit}`;
          }

          if (movementCount) {
            movementCount.textContent = String(payload.item.movement_count);
          }

          if (stockValueMetric) {
            stockValueMetric.textContent = payload.item.stock_value;
          }

          const locationBalancesSection = document.querySelector('[data-location-balances]');
          if (locationBalancesSection && payload.item.location_balances_html) {
            locationBalancesSection.outerHTML = payload.item.location_balances_html;
          }

          if (historyBody && payload.movement && payload.movement.row_html) {
            const emptyStateRow = historyBody.querySelector('.empty-cell');

            if (emptyStateRow) {
              const emptyStateParent = emptyStateRow.parentElement;

              if (emptyStateParent) {
                emptyStateParent.remove();
              }
            }

            historyBody.insertAdjacentHTML('afterbegin', payload.movement.row_html);
          }

          quantityInput.value = '';

          if (sourceStorage) {
            sourceStorage.value = '';
          }

          if (destinationStorage) {
            destinationStorage.value = '';
          }

          if (referenceInput) {
            referenceInput.value = '';
          }

          if (notesInput) {
            notesInput.value = '';
          }

          if (dateInput) {
            dateInput.value = localDateTimeValue();
          }

          showFeedback(payload.message || 'Movement saved.', 'success');
          document.dispatchEvent(new CustomEvent('inventory:action-complete'));
        } catch (error) {
          showFeedback('Live update failed. Refresh the page and try again.', 'danger');
        } finally {
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Save Movement';
          }

          syncMovementLayout();
          syncMovementState();
        }
      });
    });
  };

  const initLiveFilterRegion = (region) => {
    if (region.dataset.jsBound === 'true') {
      return;
    }

    const regionName = region.dataset.liveFilterRegion;
    const form = region.querySelector('[data-live-filter-form]');

    if (!regionName || !form) {
      return;
    }

    region.dataset.jsBound = 'true';

    let activeController = null;
    const formUrl = () => {
      const url = new URL(form.action, window.location.origin);
      const formData = new FormData(form);
      const params = new URLSearchParams();

      formData.forEach((value, key) => {
        const stringValue = String(value).trim();

        if (stringValue !== '') {
          params.append(key, stringValue);
        }
      });

      return params.toString() === '' ? url.pathname : `${url.pathname}?${params.toString()}`;
    };

    const loadRegion = async (url, focusState = null) => {
      if (activeController) {
        activeController.abort();
      }

      region.classList.add('is-loading');
      region.setAttribute('aria-busy', 'true');

      const controller = new AbortController();
      activeController = controller;

      try {
        const response = await fetch(url, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
          },
          signal: controller.signal,
        });

        if (!response.ok) {
          throw new Error(`Request failed: ${response.status}`);
        }

        const html = await response.text();
        const documentClone = new DOMParser().parseFromString(html, 'text/html');
        const nextRegion = documentClone.querySelector(`[data-live-filter-region="${regionName}"]`);

        if (!nextRegion) {
          throw new Error('Live filter region missing from response.');
        }

        history.replaceState(null, '', url);
        region.replaceWith(nextRegion);
        initInteractiveUi(nextRegion);

        if (focusState && focusState.name) {
          const escapedName = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
            ? CSS.escape(focusState.name)
            : focusState.name.replace(/"/g, '\\"');
          const nextField = nextRegion.querySelector(`[name="${escapedName}"]`);

          if (nextField instanceof HTMLInputElement || nextField instanceof HTMLTextAreaElement) {
            nextField.focus({ preventScroll: true });

            if (typeof focusState.start === 'number' && typeof focusState.end === 'number' && nextField.setSelectionRange) {
              nextField.setSelectionRange(focusState.start, focusState.end);
            }
          } else if (nextField instanceof HTMLSelectElement) {
            nextField.focus({ preventScroll: true });
          }
        }
      } catch (error) {
        if (controller.signal.aborted) {
          return;
        }

        window.location.href = url;
      } finally {
        if (region.isConnected) {
          region.classList.remove('is-loading');
          region.removeAttribute('aria-busy');
        }
      }
    };

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      loadRegion(formUrl());
    });

    form.addEventListener('change', (event) => {
      const target = event.target;

      if (!(target instanceof HTMLElement)) {
        return;
      }

      if (target.matches('select, input[type="date"], input[type="checkbox"], input[type="radio"]')) {
        loadRegion(formUrl(), target instanceof HTMLInputElement || target instanceof HTMLSelectElement ? {
          name: target.name,
        } : null);
      }
    });

    form.addEventListener('input', (event) => {
      const target = event.target;

      if (!(target instanceof HTMLElement)) {
        return;
      }

      if (target.matches('input[type="text"], input[type="search"], input[type="date"]')) {
        loadRegion(formUrl(), target instanceof HTMLInputElement ? {
          name: target.name,
          start: target.selectionStart,
          end: target.selectionEnd,
        } : null);
      }
    });

    region.querySelectorAll('[data-live-filter-link]').forEach((link) => {
      if (link.dataset.liveFilterBound === 'true') {
        return;
      }

      link.dataset.liveFilterBound = 'true';
      link.addEventListener('click', (event) => {
        event.preventDefault();
        loadRegion(link.href);
      });
    });
  };

  const initLiveFilters = (root = document) => {
    const regions = [];

    if (root instanceof Element && root.matches('[data-live-filter-region]')) {
      regions.push(root);
    }

    root.querySelectorAll('[data-live-filter-region]').forEach((region) => {
      regions.push(region);
    });

    regions.forEach((region) => {
      initLiveFilterRegion(region);
    });
  };

  const updateLabelPrintSelection = () => {
    const cards = Array.from(document.querySelectorAll('[data-label-print-card]'));
    const checkboxes = cards
      .map((card) => card.querySelector('[data-label-select-checkbox]'))
      .filter((checkbox) => checkbox instanceof HTMLInputElement);
    const selected = checkboxes.filter((checkbox) => checkbox.checked);
    const printButton = document.querySelector('[data-label-print-button]');
    const printButtonText = document.querySelector('[data-label-print-button-text]');
    const countBadge = document.querySelector('[data-label-selection-count]');
    const selectAll = document.querySelector('[data-label-select-all]');
    const selectedCount = selected.length;
    const totalCount = checkboxes.length;

    cards.forEach((card) => {
      const checkbox = card.querySelector('[data-label-select-checkbox]');
      card.classList.toggle('is-selected-for-print', checkbox instanceof HTMLInputElement && checkbox.checked);
    });

    if (printButton instanceof HTMLButtonElement) {
      printButton.disabled = selectedCount === 0;
      printButton.title = selectedCount === 0 ? 'Select one or more labels first.' : `Print ${selectedCount} selected label${selectedCount === 1 ? '' : 's'}.`;
    }

    if (printButtonText instanceof HTMLElement) {
      printButtonText.textContent = selectedCount === 0
        ? 'Print Selected'
        : `Print ${selectedCount} Selected`;
    }

    if (countBadge instanceof HTMLElement) {
      countBadge.textContent = `${selectedCount} selected`;
    }

    if (selectAll instanceof HTMLInputElement) {
      selectAll.checked = totalCount > 0 && selectedCount === totalCount;
      selectAll.indeterminate = selectedCount > 0 && selectedCount < totalCount;
      selectAll.disabled = totalCount === 0;
    }
  };

  const initLabelPrintSelection = () => {
    updateLabelPrintSelection();

    if (document.documentElement.dataset.labelPrintBound === 'true') {
      return;
    }

    document.documentElement.dataset.labelPrintBound = 'true';

    document.addEventListener('change', (event) => {
      const target = event.target;

      if (!(target instanceof HTMLInputElement)) {
        return;
      }

      if (target.matches('[data-label-select-checkbox]')) {
        updateLabelPrintSelection();
        return;
      }

      if (target.matches('[data-label-select-all]')) {
        document.querySelectorAll('[data-label-select-checkbox]').forEach((checkbox) => {
          if (checkbox instanceof HTMLInputElement) {
            checkbox.checked = target.checked;
          }
        });
        updateLabelPrintSelection();
      }
    });

    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;

      if (!target) {
        return;
      }

      const clearButton = target.closest('[data-label-clear-selection]');
      if (clearButton) {
        document.querySelectorAll('[data-label-select-checkbox]').forEach((checkbox) => {
          if (checkbox instanceof HTMLInputElement) {
            checkbox.checked = false;
          }
        });
        updateLabelPrintSelection();
        return;
      }

      const printButton = target.closest('[data-label-print-button]');
      if (printButton) {
        const selected = document.querySelectorAll('[data-label-select-checkbox]:checked');

        if (selected.length === 0) {
          updateLabelPrintSelection();
          return;
        }

        document.body.classList.add('label-print-selected');
        updateLabelPrintSelection();
        window.print();
      }
    });

    window.addEventListener('afterprint', () => {
      document.body.classList.remove('label-print-selected');
    });
  };

  const initWorkflowLineBuilders = (root = document) => {
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

      const currentItems = () => catalog[String(storageSelect.value)] || [];

      const findSelectedItem = (itemId) => currentItems().find((item) => String(item.id) === String(itemId || '')) || null;

      const closePanels = (exceptPanel = null) => {
        body.querySelectorAll('[data-workflow-picker-panel]').forEach((panel) => {
          if (panel !== exceptPanel) {
            panel.hidden = true;
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

      body.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof HTMLElement)) {
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
      body.querySelectorAll('[data-workflow-line]').forEach((line) => syncLine(line));
    });
  };

  const initPermissionBuilders = (root = document) => {
    root.querySelectorAll('[data-permission-builder]').forEach((builder) => {
      if (builder.dataset.jsBound === 'true') {
        return;
      }

      const form = builder.closest('form');
      const roleSelect = form ? form.querySelector('[data-role-select]') : null;
      const positionSelect = form ? form.querySelector('[data-position-select]') : null;
      const applyButton = builder.querySelector('[data-apply-role-defaults]');
      const applyPositionButton = builder.querySelector('[data-apply-position-defaults]');
      const permissionSearch = builder.querySelector('[data-permission-search]') || (form ? form.querySelector('[data-permission-search]') : null);
      const selectAllButton = builder.querySelector('[data-select-all-permissions]') || (form ? form.querySelector('[data-select-all-permissions]') : null);
      const clearButton = builder.querySelector('[data-clear-permissions]') || (form ? form.querySelector('[data-clear-permissions]') : null);
      const assignedOwnerField = form ? form.querySelector('[data-assigned-owner-field]') : null;
      const assignedOwnerSelect = assignedOwnerField ? assignedOwnerField.querySelector('select') : null;
      const positionSummaryTargets = form ? Array.from(form.querySelectorAll('[data-position-summary]')) : [];
      const roleSummaryTargets = form ? Array.from(form.querySelectorAll('[data-role-summary]')) : [];
      const permissionCountTargets = form ? Array.from(form.querySelectorAll('[data-permission-count]')) : [];

      if (!form) {
        return;
      }

      let roleDefaults = {};
      let positionDefaults = {};
      let positionRoles = {};
      let syncingPositionRole = false;

      try {
        roleDefaults = JSON.parse(builder.dataset.roleDefaults || '{}');
      } catch (error) {
        roleDefaults = {};
      }

      try {
        positionDefaults = JSON.parse(builder.dataset.positionDefaults || '{}');
      } catch (error) {
        positionDefaults = {};
      }

      try {
        positionRoles = JSON.parse(builder.dataset.positionRoles || '{}');
      } catch (error) {
        positionRoles = {};
      }

      function permissionInputs() {
        return Array.from(builder.querySelectorAll('input[name="permissions[]"]'));
      }

      function selectedOptionText(select) {
        if (!(select instanceof HTMLSelectElement)) {
          return '';
        }

        return select.selectedOptions[0]?.textContent?.trim() || select.value || '';
      }

      function syncAssignedOwnerField() {
        const isStaffAccess = roleSelect instanceof HTMLSelectElement && roleSelect.value === 'staff';

        if (assignedOwnerField) {
          assignedOwnerField.hidden = !isStaffAccess;
        }

        if (assignedOwnerSelect instanceof HTMLSelectElement) {
          assignedOwnerSelect.disabled = !isStaffAccess;
        }
      }

      function updatePermissionSummary() {
        const inputs = permissionInputs();
        const checkedCount = inputs.filter((input) => input.checked).length;

        permissionCountTargets.forEach((target) => {
          target.textContent = String(checkedCount);
        });

        if (positionSelect instanceof HTMLSelectElement) {
          positionSummaryTargets.forEach((target) => {
            target.textContent = selectedOptionText(positionSelect);
          });
        }

        if (roleSelect instanceof HTMLSelectElement) {
          roleSummaryTargets.forEach((target) => {
            target.textContent = selectedOptionText(roleSelect);
          });
        }

        builder.querySelectorAll('[data-permission-card]').forEach((card) => {
          const cardInputs = Array.from(card.querySelectorAll('input[name="permissions[]"]'));
          const cardChecked = cardInputs.filter((input) => input.checked).length;
          const groupCount = card.querySelector('[data-permission-group-count]');

          if (groupCount) {
            groupCount.textContent = `${cardChecked} selected`;
          }
        });

        syncAssignedOwnerField();
      }

      function filterPermissionOptions() {
        const query = permissionSearch instanceof HTMLInputElement ? permissionSearch.value.trim().toLowerCase() : '';

        builder.querySelectorAll('[data-permission-card]').forEach((card) => {
          let visibleOptions = 0;

          card.querySelectorAll('[data-permission-option]').forEach((option) => {
            const match = query === '' || option.textContent.toLowerCase().includes(query);
            option.hidden = !match;

            if (match) {
              visibleOptions++;
            }
          });

          card.hidden = query !== '' && visibleOptions === 0;

          if (query !== '' && visibleOptions > 0 && card instanceof HTMLDetailsElement) {
            card.open = true;
          }
        });
      }

      const applyDefaultsForRole = (role) => {
        const defaults = new Set(roleDefaults[String(role)] || []);

        permissionInputs().forEach((input) => {
          input.checked = defaults.has(input.value);
        });

        updatePermissionSummary();
      };

      const applyDefaultsForPosition = (position) => {
        const key = String(position || '');
        const role = positionRoles[key] || '';

        if (role && roleSelect instanceof HTMLSelectElement) {
          syncingPositionRole = true;
          roleSelect.value = role;
          roleSelect.dispatchEvent(new Event('change', { bubbles: true }));
          syncingPositionRole = false;
        }

        const defaults = new Set(positionDefaults[key] || []);

        if (defaults.size === 0) {
          if (roleSelect instanceof HTMLSelectElement) {
            applyDefaultsForRole(roleSelect.value);
          }
          return;
        }

        permissionInputs().forEach((input) => {
          input.checked = defaults.has(input.value);
        });

        updatePermissionSummary();
      };

      builder.dataset.jsBound = 'true';

      if (applyButton instanceof HTMLButtonElement && roleSelect instanceof HTMLSelectElement) {
        applyButton.addEventListener('click', () => {
          applyDefaultsForRole(roleSelect.value);
        });
      }

      if (applyPositionButton && positionSelect instanceof HTMLSelectElement) {
        applyPositionButton.addEventListener('click', () => {
          applyDefaultsForPosition(positionSelect.value);
        });
      }

      if (roleSelect instanceof HTMLSelectElement) {
        roleSelect.addEventListener('change', () => {
          if (builder.dataset.autoRoleDefaults === 'true' && !syncingPositionRole) {
            applyDefaultsForRole(roleSelect.value);
            return;
          }

          updatePermissionSummary();
        });
      }

      if (positionSelect instanceof HTMLSelectElement) {
        positionSelect.addEventListener('change', () => {
          if (builder.dataset.autoRoleDefaults === 'true' && roleSelect instanceof HTMLSelectElement) {
            applyDefaultsForPosition(positionSelect.value);
            return;
          }

          updatePermissionSummary();
        });
      }

      permissionInputs().forEach((input) => {
        input.addEventListener('change', updatePermissionSummary);
      });

      if (permissionSearch instanceof HTMLInputElement) {
        permissionSearch.addEventListener('input', filterPermissionOptions);
      }

      if (selectAllButton instanceof HTMLButtonElement) {
        selectAllButton.addEventListener('click', () => {
          permissionInputs().forEach((input) => {
            input.checked = true;
          });
          updatePermissionSummary();
        });
      }

      if (clearButton instanceof HTMLButtonElement) {
        clearButton.addEventListener('click', () => {
          permissionInputs().forEach((input) => {
            input.checked = false;
          });
          updatePermissionSummary();
        });
      }

      updatePermissionSummary();
    });
  };

  const initSettingsSearch = (root = document) => {
    root.querySelectorAll('[data-settings-search]').forEach((searchRoot) => {
      if (searchRoot.dataset.jsBound === 'true') {
        return;
      }

      const form = searchRoot.closest('form') || document;
      const input = searchRoot.querySelector('[data-settings-search-input]');
      const clearButton = searchRoot.querySelector('[data-settings-search-clear]');
      const summary = searchRoot.querySelector('[data-settings-search-summary]');
      const accordion = form.querySelector('[data-settings-accordion]');

      if (!(input instanceof HTMLInputElement) || !accordion) {
        return;
      }

      searchRoot.dataset.jsBound = 'true';

      const normalize = (value) => String(value || '').trim().toLowerCase();
      const panels = Array.from(accordion.querySelectorAll('[data-settings-group]'));

      const filterSettings = () => {
        const query = normalize(input.value);
        let visibleFieldCount = 0;
        let visibleGroupCount = 0;

        panels.forEach((panel) => {
          const groupText = normalize(panel.dataset.settingsSearchText);
          const groupMatches = query !== '' && groupText.includes(query);
          const fields = Array.from(panel.querySelectorAll('[data-setting-field]'));
          let panelHasMatch = query === '';

          fields.forEach((field) => {
            const fieldText = normalize(field.dataset.settingsSearchText);
            const fieldMatches = query === '' || groupMatches || fieldText.includes(query);

            field.classList.toggle('is-setting-search-hidden', !fieldMatches);
            field.classList.toggle('is-setting-search-match', query !== '' && (groupMatches || fieldText.includes(query)));

            if (fieldMatches) {
              visibleFieldCount += 1;
              panelHasMatch = true;
            }
          });

          panel.classList.toggle('is-setting-search-hidden', !panelHasMatch);

          if (panelHasMatch) {
            visibleGroupCount += 1;
          }

          if (query === '') {
            panel.open = panel.dataset.settingsDefaultOpen === 'true';
          } else if (panelHasMatch) {
            panel.open = true;
          }
        });

        if (summary) {
          if (query === '') {
            summary.textContent = 'Type to find a control.';
          } else if (visibleFieldCount === 0) {
            summary.textContent = 'No settings match that search.';
          } else {
            summary.textContent = `${visibleFieldCount} setting${visibleFieldCount === 1 ? '' : 's'} in ${visibleGroupCount} group${visibleGroupCount === 1 ? '' : 's'}.`;
          }
        }
      };

      input.addEventListener('input', filterSettings);

      if (clearButton instanceof HTMLButtonElement) {
        clearButton.addEventListener('click', () => {
          input.value = '';
          filterSettings();
          input.focus();
        });
      }

      filterSettings();
    });
  };

  const initDocumentationSearch = (root = document) => {
    root.querySelectorAll('[data-documentation-root]').forEach((docsRoot) => {
      if (docsRoot.dataset.jsBound === 'true') {
        return;
      }

      docsRoot.dataset.jsBound = 'true';
      const searchInput = docsRoot.querySelector('[data-documentation-search]') || document.querySelector('[data-documentation-search]');
      const sections = Array.from(docsRoot.querySelectorAll('[data-documentation-section]'));
      const navLinks = Array.from(docsRoot.querySelectorAll('[data-documentation-nav-link]'));
      const count = docsRoot.querySelector('[data-documentation-count]');
      const status = docsRoot.querySelector('[data-documentation-status]');
      const empty = docsRoot.querySelector('[data-documentation-empty]');
      const trackSections = Array.from(docsRoot.querySelectorAll('[data-documentation-track-section]'));
      const currentTitle = docsRoot.querySelector('[data-documentation-current-title]');
      const currentMeta = docsRoot.querySelector('[data-documentation-current-meta]');
      const progress = docsRoot.querySelector('[data-documentation-progress]');
      let activeSectionId = '';
      let trackingFrame = null;

      const setActiveDocumentationSection = (section) => {
        if (!section || !section.id) {
          return;
        }

        if (section.id !== activeSectionId) {
          activeSectionId = section.id;

          navLinks.forEach((link) => {
            link.classList.toggle('is-active', link.getAttribute('href') === `#${section.id}`);
          });
        }

        if (currentTitle) {
          currentTitle.textContent = section.dataset.documentationTitle || section.querySelector('h3')?.textContent?.trim() || 'Documentation';
        }

        if (currentMeta) {
          const visibleTrackSections = trackSections.filter((trackedSection) => !trackedSection.hidden);
          const currentIndex = visibleTrackSections.indexOf(section) + 1;
          const audience = section.dataset.documentationAudience || 'All users';
          currentMeta.textContent = currentIndex > 0
            ? `${currentIndex} of ${visibleTrackSections.length} · ${audience}`
            : audience;
        }
      };

      const updateDocumentationTracker = () => {
        trackingFrame = null;
        const visibleTrackSections = trackSections.filter((section) => !section.hidden);

        if (visibleTrackSections.length === 0) {
          navLinks.forEach((link) => link.classList.remove('is-active'));

          if (currentTitle) {
            currentTitle.textContent = 'No matching section';
          }

          if (currentMeta) {
            currentMeta.textContent = 'Try another search term.';
          }

          if (progress) {
            progress.style.width = '0%';
          }

          activeSectionId = '';
          return;
        }

        const viewportAnchor = Math.min(180, Math.max(96, window.innerHeight * 0.22));
        let activeSection = visibleTrackSections[0];

        visibleTrackSections.forEach((section) => {
          const rect = section.getBoundingClientRect();

          if (rect.top <= viewportAnchor && rect.bottom > viewportAnchor) {
            activeSection = section;
            return;
          }

          if (rect.top <= viewportAnchor) {
            activeSection = section;
          }
        });

        setActiveDocumentationSection(activeSection);

        if (progress) {
          const first = visibleTrackSections[0].getBoundingClientRect();
          const last = visibleTrackSections[visibleTrackSections.length - 1].getBoundingClientRect();
          const total = Math.max(1, (last.bottom - first.top) - window.innerHeight);
          const read = Math.min(total, Math.max(0, viewportAnchor - first.top));
          progress.style.width = `${Math.round((read / total) * 100)}%`;
        }
      };

      const scheduleDocumentationTracker = () => {
        if (trackingFrame !== null) {
          return;
        }

        trackingFrame = window.requestAnimationFrame(updateDocumentationTracker);
      };

      const applySearch = () => {
        const query = (searchInput?.value || '').trim().toLowerCase();
        let visibleCount = 0;

        sections.forEach((section) => {
          const isVisible = query === '' || (section.dataset.documentationText || '').includes(query);
          section.hidden = !isVisible;

          if (isVisible) {
            visibleCount += 1;
          }
        });

        navLinks.forEach((link) => {
          const target = link.getAttribute('href') || '';
          const section = target ? docsRoot.querySelector(target) : null;
          link.hidden = section ? section.hidden : false;
        });

        if (count) {
          count.textContent = String(visibleCount);
        }

        if (status) {
          status.textContent = query === ''
            ? 'Showing important sections, department guides, and full feature guides.'
            : `${visibleCount} result${visibleCount === 1 ? '' : 's'} for "${query}".`;
        }

        if (empty) {
          empty.hidden = visibleCount !== 0;
        }

        scheduleDocumentationTracker();
      };

      if (searchInput instanceof HTMLInputElement) {
        searchInput.addEventListener('input', applySearch);
      }

      window.addEventListener('scroll', scheduleDocumentationTracker, { passive: true });
      window.addEventListener('resize', scheduleDocumentationTracker);

      applySearch();
    });
  };

  const initReorderDraftForms = (root = document) => {
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

  const initPurchaseLineBuilders = (root = document) => {
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

  const initPurchaseOcrImport = (root = document) => {
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

  const initPurchaseBulkImport = (root = document) => {
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

  const initScanCenter = (root = document) => {
    root.querySelectorAll('[data-scan-center]').forEach((scanner) => {
      if (scanner.dataset.scanBound === 'true') {
        return;
      }

      const lookupUrl = scanner.dataset.scanLookupUrl;
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
      let storages = [];
      let movementTypes = [];
      let currentItems = [];
      let selectedItem = null;
      let batchMode = false;
      const batchItems = new Map();
      let cameraStream = null;
      let cameraScanning = false;
      let entryLookupTimer = null;
      let batchLookupTimer = null;
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

  const initSupplierTypeOtherFields = (root = document) => {
    root.querySelectorAll('[data-supplier-type-select]').forEach((select) => {
      if (select.dataset.supplierTypeBound === 'true') {
        return;
      }

      select.dataset.supplierTypeBound = 'true';

      const scope = select.closest('.purchase-import-card, .purchase-new-supplier, .reorder-new-supplier-grid, .stack-form, form') || select.parentElement;
      const field = scope?.querySelector('[data-supplier-type-other-field]');
      const input = field?.querySelector('[data-supplier-type-other-input]');

      const sync = () => {
        const shouldShow = select instanceof HTMLSelectElement && select.value === 'other';

        if (field instanceof HTMLElement) {
          field.hidden = !shouldShow;
        }

        if (input instanceof HTMLInputElement) {
          input.required = shouldShow;
          input.disabled = !shouldShow || select.disabled;

          if (!shouldShow) {
            input.value = '';
          }
        }
      };

      select.addEventListener('change', sync);

      const observer = new MutationObserver(sync);
      observer.observe(select, { attributes: true, attributeFilter: ['disabled'] });

      sync();
    });
  };

  const initWorkflowDocumentSettings = (root = document) => {
    const bindCustomSizeFields = (selectName, widthKey, heightKey, boundKey) => {
      root.querySelectorAll(`[name="${selectName}"]`).forEach((select) => {
        if (!(select instanceof HTMLSelectElement) || select.dataset[boundKey] === 'true') {
          return;
        }

        select.dataset[boundKey] = 'true';

        const widthField = document.querySelector(`[data-setting-field="${widthKey}"]`);
        const heightField = document.querySelector(`[data-setting-field="${heightKey}"]`);
        const widthInput = widthField?.querySelector('input');
        const heightInput = heightField?.querySelector('input');

        const sync = () => {
          const isCustom = select.value === 'custom';

          [widthField, heightField].forEach((field) => {
            if (field instanceof HTMLElement) {
              field.hidden = !isCustom;
            }
          });

          [widthInput, heightInput].forEach((input) => {
            if (input instanceof HTMLInputElement) {
              input.disabled = !isCustom;
              input.required = isCustom;
            }
          });
        };

        select.addEventListener('change', sync);
        sync();
      });
    };

    bindCustomSizeFields(
      'settings[workflow.signoff_image_size]',
      'workflow.signoff_image_custom_width',
      'workflow.signoff_image_custom_height',
      'workflowImageSettingBound',
    );

    bindCustomSizeFields(
      'settings[exports.item_xlsx_thumbnail_size]',
      'exports.item_xlsx_thumbnail_custom_width',
      'exports.item_xlsx_thumbnail_custom_height',
      'itemExportImageSettingBound',
    );
  };

  const initAssetCategoryTrees = (root = document) => {
    root.querySelectorAll('[data-asset-category-tree]').forEach((tree) => {
      if (!(tree instanceof HTMLElement) || tree.dataset.assetCategoryTreeBound === 'true') {
        return;
      }

      tree.dataset.assetCategoryTreeBound = 'true';
      const reorderUrl = tree.dataset.reorderUrl || '';
      let draggedNode = null;

      const categoryId = (node) => node instanceof HTMLElement ? node.dataset.assetCategoryId || '' : '';
      const directNodeIds = (dropZone) => Array.from(dropZone.children)
        .filter((child) => child instanceof HTMLElement && child.matches('[data-asset-category-id]'))
        .map((child) => categoryId(child))
        .filter(Boolean);

      const saveMove = async (node, parentId, siblingIds) => {
        const formData = new FormData();
        formData.append('_token', csrfToken(tree));
        formData.append('category_id', categoryId(node));
        formData.append('parent_id', parentId || '');
        siblingIds.forEach((id) => formData.append('ordered_ids[]', id));

        const response = await fetch(reorderUrl, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: formData,
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || payload.ok === false) {
          throw new Error(payload.message || 'Could not save category move.');
        }
      };

      tree.querySelectorAll('[data-asset-category-id]').forEach((node) => {
        if (!(node instanceof HTMLElement)) {
          return;
        }

        node.addEventListener('dragstart', (event) => {
          draggedNode = node;
          node.classList.add('is-dragging');
          event.dataTransfer?.setData('text/plain', categoryId(node));
          if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
          }
        });

        node.addEventListener('dragend', () => {
          node.classList.remove('is-dragging');
          draggedNode = null;
          tree.querySelectorAll('.is-drop-target').forEach((target) => target.classList.remove('is-drop-target'));
        });
      });

      tree.querySelectorAll('[data-asset-category-drop-parent]').forEach((dropZone) => {
        if (!(dropZone instanceof HTMLElement)) {
          return;
        }

        dropZone.addEventListener('dragover', (event) => {
          if (!draggedNode || draggedNode.contains(dropZone)) {
            return;
          }

          event.preventDefault();
          dropZone.classList.add('is-drop-target');
        });

        dropZone.addEventListener('dragleave', () => {
          dropZone.classList.remove('is-drop-target');
        });

        dropZone.addEventListener('drop', async (event) => {
          if (!draggedNode || draggedNode.contains(dropZone)) {
            return;
          }

          event.preventDefault();
          dropZone.classList.remove('is-drop-target');
          const previousParent = draggedNode.parentElement;
          const previousNext = draggedNode.nextElementSibling;
          dropZone.appendChild(draggedNode);

          try {
            const parentId = dropZone.dataset.assetCategoryDropParent || '';
            await saveMove(draggedNode, parentId, directNodeIds(dropZone));
            draggedNode.dataset.assetCategoryParentId = parentId;
          } catch (error) {
            if (previousParent instanceof HTMLElement) {
              previousParent.insertBefore(draggedNode, previousNext);
            }
            window.alert(error.message || 'Could not save category move.');
          }
        });
      });
    });
  };

  const initInteractiveUi = (root = document) => {
    initConfirmButtons(root);
    initUnitSelectors(root);
    initStocktakeStorageSelects(root);
    initItemCodePreview(root);
    initSupplierTypeOtherFields(root);
    initWorkflowDocumentSettings(root);
    initImageExpanders(root);
    initGlobalSearch(root);
    initSearchableSelects(root);
    initNotificationFeed();
    initDataTables(root);
    initLiveActionForms(root);
    initHandoverCloseForms(root);
    initHandoverApprovalForms(root);
    initMovementForm(root);
    initLiveFilters(root);
    initLabelPrintSelection(root);
    initWorkflowLineBuilders(root);
    initPurchaseLineBuilders(root);
    initPurchaseOcrImport(root);
    initPurchaseBulkImport(root);
    initScanCenter(root);
    initPermissionBuilders(root);
    initSettingsSearch(root);
    initDocumentationSearch(root);
    initReorderDraftForms(root);
    initAssetCategoryTrees(root);
  };

  initNavigation();
  initLightboxChrome();
  initInteractiveUi(document);
});
