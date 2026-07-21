export const initSettingsSearch = (root = document) => {
  root.querySelectorAll('[data-settings-search]').forEach((searchRoot) => {
    if (searchRoot.dataset.jsBound === 'true') {
      return;
    }

    const form = searchRoot.closest('form') || document;
    const input = searchRoot.querySelector('[data-settings-search-input]');
    const clearButton = searchRoot.querySelector('[data-settings-search-clear]');
    const summary = searchRoot.querySelector('[data-settings-search-summary]');
    const accordion = form.querySelector('[data-settings-accordion]');

    if (!(input instanceof HTMLInputElement) || !accordion) {
      return;
    }

    searchRoot.dataset.jsBound = 'true';

    const normalize = (value) => String(value || '').trim().toLowerCase();
    const panels = Array.from(accordion.querySelectorAll('[data-settings-group]'));

    const filterSettings = () => {
      const query = normalize(input.value);
      let visibleFieldCount = 0;
      let visibleGroupCount = 0;

      panels.forEach((panel) => {
        const groupText = normalize(panel.dataset.settingsSearchText);
        const groupMatches = query !== '' && groupText.includes(query);
        const fields = Array.from(panel.querySelectorAll('[data-setting-field]'));
        let panelHasMatch = query === '';

        fields.forEach((field) => {
          const fieldText = normalize(field.dataset.settingsSearchText);
          const fieldMatches = query === '' || groupMatches || fieldText.includes(query);

          field.classList.toggle('is-setting-search-hidden', !fieldMatches);
          field.classList.toggle('is-setting-search-match', query !== '' && (groupMatches || fieldText.includes(query)));

          if (fieldMatches) {
            visibleFieldCount += 1;
            panelHasMatch = true;
          }
        });

        panel.classList.toggle('is-setting-search-hidden', !panelHasMatch);

        if (panelHasMatch) {
          visibleGroupCount += 1;
        }

        if (query === '') {
          panel.open = panel.dataset.settingsDefaultOpen === 'true';
        } else if (panelHasMatch) {
          panel.open = true;
        }
      });

      if (summary) {
        if (query === '') {
          summary.textContent = 'Type to find a control.';
        } else if (visibleFieldCount === 0) {
          summary.textContent = 'No settings match that search.';
        } else {
          summary.textContent = `${visibleFieldCount} setting${visibleFieldCount === 1 ? '' : 's'} in ${visibleGroupCount} group${visibleGroupCount === 1 ? '' : 's'}.`;
        }
      }
    };

    input.addEventListener('input', filterSettings);

    if (clearButton instanceof HTMLButtonElement) {
      clearButton.addEventListener('click', () => {
        input.value = '';
        filterSettings();
        input.focus();
      });
    }

    filterSettings();
  });
};
export const initWorkflowDocumentSettings = (root = document) => {
  const bindCustomSizeFields = (selectName, widthKey, heightKey, boundKey) => {
    root.querySelectorAll(`[name="${selectName}"]`).forEach((select) => {
      if (!(select instanceof HTMLSelectElement) || select.dataset[boundKey] === 'true') {
        return;
      }

      select.dataset[boundKey] = 'true';

      const widthField = document.querySelector(`[data-setting-field="${widthKey}"]`);
      const heightField = document.querySelector(`[data-setting-field="${heightKey}"]`);
      const widthInput = widthField?.querySelector('input');
      const heightInput = heightField?.querySelector('input');

      const sync = () => {
        const isCustom = select.value === 'custom';

        [widthField, heightField].forEach((field) => {
          if (field instanceof HTMLElement) {
            field.hidden = !isCustom;
          }
        });

        [widthInput, heightInput].forEach((input) => {
          if (input instanceof HTMLInputElement) {
            input.disabled = !isCustom;
            input.required = isCustom;
          }
        });
      };

      select.addEventListener('change', sync);
      sync();
    });
  };

  bindCustomSizeFields(
    'settings[workflow.signoff_image_size]',
    'workflow.signoff_image_custom_width',
    'workflow.signoff_image_custom_height',
    'workflowImageSettingBound',
  );

  bindCustomSizeFields(
    'settings[exports.item_xlsx_thumbnail_size]',
    'exports.item_xlsx_thumbnail_custom_width',
    'exports.item_xlsx_thumbnail_custom_height',
    'itemExportImageSettingBound',
  );
};

export const init = (root = document) => initSettingsSearch(root);
