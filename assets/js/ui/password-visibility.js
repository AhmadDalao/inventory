export const init = (root = document) => {
  root.querySelectorAll('[data-password-toggle]').forEach((button) => {
    if (button.dataset.jsBound === 'true') {
      return;
    }

    const wrap = button.closest('.password-input-wrap');
    const input = wrap ? wrap.querySelector('[data-password-input]') : null;
    const label = button.querySelector('[data-password-toggle-label]');

    if (!input) {
      return;
    }

    button.dataset.jsBound = 'true';
    button.addEventListener('click', () => {
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      button.setAttribute('aria-pressed', showing ? 'false' : 'true');
      button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
      if (label) {
        label.textContent = showing ? 'Show' : 'Hide';
      }
      input.focus({ preventScroll: true });
    });
  });
};
