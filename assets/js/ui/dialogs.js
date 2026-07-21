export const confirmDialog = (() => {
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

export const initConfirmButtons = (root = document) => {
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

export const init = (root = document) => initConfirmButtons(root);
