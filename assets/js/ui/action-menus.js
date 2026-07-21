export const initRowActionMenus = (root = document) => {
  root.querySelectorAll('.row-action-menu').forEach((menu) => {
    if (!(menu instanceof HTMLDetailsElement) || menu.dataset.rowActionBound === 'true') {
      return;
    }

    menu.dataset.rowActionBound = 'true';
    menu.addEventListener('toggle', () => {
      if (!menu.open) {
        return;
      }

      document.querySelectorAll('.row-action-menu[open]').forEach((otherMenu) => {
        if (otherMenu !== menu && otherMenu instanceof HTMLDetailsElement) {
          otherMenu.open = false;
        }
      });
    });
  });

  if (document.documentElement.dataset.rowActionGlobalBound === 'true') {
    return;
  }

  document.documentElement.dataset.rowActionGlobalBound = 'true';
  document.addEventListener('click', (event) => {
    const activeMenu = event.target instanceof Element ? event.target.closest('.row-action-menu') : null;
    document.querySelectorAll('.row-action-menu[open]').forEach((menu) => {
      if (menu !== activeMenu && menu instanceof HTMLDetailsElement) {
        menu.open = false;
      }
    });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
      return;
    }

    document.querySelectorAll('.row-action-menu[open]').forEach((menu) => {
      if (menu instanceof HTMLDetailsElement) {
        menu.open = false;
      }
    });
  });
};

export const init = (root = document) => initRowActionMenus(root);
