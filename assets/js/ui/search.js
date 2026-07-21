import { escapeHtml } from '../core/runtime.js';

export const initGlobalSearch = (root = document) => {
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

export const init = (root = document) => initGlobalSearch(root);
