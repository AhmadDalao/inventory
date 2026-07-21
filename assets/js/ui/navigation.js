const shell = document.querySelector('[data-shell]');
const menuToggles = Array.from(document.querySelectorAll('[data-menu-toggle]'));
const sidebarBackdrop = document.querySelector('[data-sidebar-backdrop]');
const sidebarStorageKey = 'inventory-sidebar-collapsed';

export const initNavigation = () => {
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

  if (document.documentElement.dataset.navigationGlobalBound !== 'true') {
    document.documentElement.dataset.navigationGlobalBound = 'true';
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && isMobileViewport()) {
        closeMobileNavigation();
      }
    });

    window.addEventListener('resize', syncNavigationState);
  }

  syncNavigationState();
};

export const init = () => initNavigation();
