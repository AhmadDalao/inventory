import { replaceMainContentFromUrl, showGlobalFlash } from '../core/runtime.js';
import { initNotificationFeed } from './notifications.js';

export const initLiveActionForms = (root = document) => {
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

export const init = (root = document) => initLiveActionForms(root);
