document.addEventListener('DOMContentLoaded', () => {
  const shell = document.querySelector('[data-shell]');
  const toggle = document.querySelector('[data-menu-toggle]');
  const movementType = document.querySelector('[data-movement-type]');
  const quantityHint = document.querySelector('[data-quantity-hint]');

  if (toggle && shell) {
    toggle.addEventListener('click', () => {
      shell.classList.toggle('nav-open');
    });
  }

  document.querySelectorAll('[data-confirm]').forEach((button) => {
    button.addEventListener('click', (event) => {
      const message = button.getAttribute('data-confirm') || 'Are you sure?';
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });

  if (movementType && quantityHint) {
    const syncHint = () => {
      if (movementType.value === 'adjustment') {
        quantityHint.textContent = 'Adjustments can be positive or negative.';
        return;
      }

      quantityHint.textContent = 'For usage/restock, enter a positive number.';
    };

    movementType.addEventListener('change', syncHint);
    syncHint();
  }
});
