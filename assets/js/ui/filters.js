import { initInteractiveUi } from '../core/registry.js';

export const initLiveFilterRegion = (region) => {
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
      document.dispatchEvent(new CustomEvent('inventory:content-replaced', {
        detail: { root: nextRegion },
      }));

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

export const initLiveFilters = (root = document) => {
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

export const init = (root = document) => initLiveFilters(root);
