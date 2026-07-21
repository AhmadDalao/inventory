import { initInteractiveUi, registerInitializer } from './js/core/registry.js';
import { init as initNavigation } from './js/ui/navigation.js';
import { init as initDialogs } from './js/ui/dialogs.js';
import { init as initFormControls } from './js/ui/form-controls.js';
import { init as initBarcodes } from './js/ui/barcodes.js';
import { init as initMedia } from './js/ui/media.js';
import { init as initSearch } from './js/ui/search.js';
import { init as initSelects } from './js/ui/selects.js';
import { init as initNotifications } from './js/ui/notifications.js';
import { init as initLiveActions } from './js/ui/live-actions.js';
import { init as initTables } from './js/ui/tables.js';
import { init as initFilters } from './js/ui/filters.js';
import { init as initActionMenus } from './js/ui/action-menus.js';
import { init as initLegacyWorkflows } from './js/domains/legacy-workflows.js';

registerInitializer('navigation', initNavigation);
registerInitializer('dialogs', initDialogs);
registerInitializer('form-controls', initFormControls);
registerInitializer('barcodes', initBarcodes);
registerInitializer('media', initMedia);
registerInitializer('search', initSearch);
registerInitializer('selects', initSelects);
registerInitializer('notifications', initNotifications);
registerInitializer('live-actions', initLiveActions);
registerInitializer('tables', initTables);
registerInitializer('filters', initFilters);
registerInitializer('action-menus', initActionMenus);
registerInitializer('legacy-workflows', initLegacyWorkflows);

document.addEventListener('DOMContentLoaded', () => {
  initInteractiveUi(document);
});
