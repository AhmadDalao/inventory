import { replaceMainContentFromUrl } from '../core/runtime.js';

const pollIntervalMs = 5000;

const isEditableField = (element) => element instanceof HTMLInputElement
  || element instanceof HTMLTextAreaElement
  || element instanceof HTMLSelectElement;

const hasUnsavedFormWork = () => document.body.dataset.formDirty === 'true'
  || isEditableField(document.activeElement);

const refreshVisibleRegions = () => {
  const regions = document.querySelectorAll('[data-live-filter-region]');
  regions.forEach((region) => region.dispatchEvent(new CustomEvent('inventory:refresh')));
  return regions.length;
};

const isSafeDetailPage = () => {
  const path = window.location.pathname;
  return /(?:^|\/)(?:items|storages|handovers|requests|purchases)\/\d+\/?$/.test(path)
    || /(?:^|\/)scan(?:\/.*)?$/.test(path);
};

const refreshVisiblePage = async () => {
  if (refreshVisibleRegions() > 0 || !isSafeDetailPage()) {
    return;
  }

  await replaceMainContentFromUrl(window.location.href);
};

export const init = (root = document) => {
  if (root !== document || document.body.dataset.realtimeBound === 'true') {
    return;
  }

  const syncUrl = document.body.dataset.liveSyncUrl;

  if (!syncUrl || document.body.dataset.userId === '') {
    return;
  }

  document.body.dataset.realtimeBound = 'true';
  let cursor = null;
  let polling = false;
  let timer = null;

  document.addEventListener('input', (event) => {
    if (event.target instanceof HTMLElement && event.target.closest('form:not([data-live-filter-form])')) {
      document.body.dataset.formDirty = 'true';
    }
  });
  document.addEventListener('change', (event) => {
    if (event.target instanceof HTMLElement && event.target.closest('form:not([data-live-filter-form])')) {
      document.body.dataset.formDirty = 'true';
    }
  });
  document.addEventListener('inventory:action-complete', () => {
    document.body.dataset.formDirty = 'false';
  });

  const poll = async () => {
    if (polling || document.visibilityState !== 'visible') {
      return;
    }

    polling = true;
    try {
      const response = await fetch(syncUrl, {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        cache: 'no-store',
      });

      if (!response.ok) {
        return;
      }

      const payload = await response.json();
      const nextCursor = Number.parseInt(payload.cursor ?? '0', 10);

      if (!Number.isFinite(nextCursor)) {
        return;
      }

      if (cursor !== null && nextCursor > cursor && !hasUnsavedFormWork()) {
        await refreshVisiblePage();
      }
      cursor = Math.max(cursor ?? 0, nextCursor);
    } catch (_) {
      // A transient refresh failure must never interrupt active work.
    } finally {
      polling = false;
    }
  };

  const start = () => {
    if (timer !== null) {
      window.clearInterval(timer);
    }
    if (document.visibilityState !== 'visible') {
      timer = null;
      return;
    }
    poll();
    timer = window.setInterval(poll, pollIntervalMs);
  };

  document.addEventListener('visibilitychange', start);
  window.addEventListener('focus', poll);
  start();
};
