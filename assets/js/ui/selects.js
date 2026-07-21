import { escapeHtml } from '../core/runtime.js';

export const initSearchableSelects = (root = document) => {
  const selects = Array.from(root.querySelectorAll?.('[data-searchable-select]') || []);

  selects.forEach((select) => {
    if (!(select instanceof HTMLSelectElement) || select.dataset.searchableBound === 'true') {
      return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'searchable-select';

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'searchable-select-input';
    search.placeholder = select.dataset.searchablePlaceholder || 'Search options...';
    search.setAttribute('aria-label', `${select.name || 'Select'} search`);
    search.autocomplete = 'off';

    const empty = document.createElement('p');
    empty.className = 'searchable-select-empty';
    empty.hidden = true;
    empty.textContent = 'No matching options.';

    select.parentNode.insertBefore(wrapper, select);
    wrapper.append(search, select, empty);
    select.dataset.searchableBound = 'true';

    const options = Array.from(select.options);
    const optionText = (option) => `${option.textContent || ''} ${option.value || ''} ${option.dataset.searchText || ''}`.toLowerCase();

    const filterOptions = () => {
      const query = search.value.trim().toLowerCase();
      let matches = 0;

      options.forEach((option) => {
        const isDefaultOption = option.value === '';
        const isMatch = query === '' || isDefaultOption || optionText(option).includes(query);

        option.hidden = !isMatch;
        option.disabled = !isMatch && !option.selected;

        if (isMatch && !isDefaultOption) {
          matches += 1;
        }
      });

      empty.hidden = query === '' || matches > 0;
    };

    search.addEventListener('input', filterOptions);
    search.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        search.value = '';
        filterOptions();
        select.focus();
      }
    });

    filterOptions();
  });
};

export const initComboboxSelects = (root = document) => {
  const selects = Array.from(root.querySelectorAll?.('select[data-combobox-select]') || []);

  selects.forEach((select) => {
    if (!(select instanceof HTMLSelectElement) || select.dataset.comboboxBound === 'true') {
      return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = ['select-combobox', 'workflow-picker', select.dataset.comboboxClass || '']
      .join(' ')
      .trim();

    const toggle = document.createElement('button');
    toggle.className = 'workflow-picker-toggle select-combobox-toggle';
    toggle.type = 'button';
    toggle.setAttribute('aria-haspopup', 'listbox');
    toggle.setAttribute('aria-expanded', 'false');

    const panel = document.createElement('div');
    panel.className = 'workflow-picker-panel select-combobox-panel';
    panel.hidden = true;

    const search = document.createElement('input');
    search.className = 'workflow-picker-search select-combobox-search';
    search.type = 'search';
    search.autocomplete = 'off';
    search.placeholder = select.dataset.comboboxPlaceholder || select.dataset.searchablePlaceholder || 'Search options...';

    const optionsWrap = document.createElement('div');
    optionsWrap.className = 'workflow-picker-options select-combobox-options';
    optionsWrap.setAttribute('role', 'listbox');

    panel.append(search, optionsWrap);
    select.parentNode.insertBefore(wrapper, select);
    wrapper.append(select, toggle, panel);
    select.classList.add('select-combobox-native');
    select.dataset.comboboxBound = 'true';

    const options = Array.from(select.options);

    const optionTitle = (option) => (option.dataset.labelTitle || option.textContent || '').trim() || 'Option';
    const optionMeta = (option) => (option.dataset.labelMeta || option.dataset.searchText || '').trim();
    const optionSearchText = (option) => [
      option.textContent || '',
      option.value || '',
      option.dataset.searchText || '',
      option.dataset.labelTitle || '',
      option.dataset.labelMeta || '',
    ].join(' ').toLowerCase();

    const renderSelected = () => {
      const selectedOption = select.selectedOptions[0] || options[0];
      if (!selectedOption) {
        toggle.innerHTML = '<span class="workflow-picker-placeholder">Select option</span>';
        return;
      }

      const meta = optionMeta(selectedOption);
      toggle.innerHTML = `
        <span class="workflow-picker-selected select-combobox-selected">
          <span class="workflow-picker-thumb workflow-picker-thumb-fallback">${escapeHtml(optionTitle(selectedOption).charAt(0).toUpperCase() || '?')}</span>
          <span>
            <strong>${escapeHtml(optionTitle(selectedOption))}</strong>
            ${meta ? `<span class="tiny-copy">${escapeHtml(meta)}</span>` : ''}
          </span>
        </span>
      `;
    };

    const setComboboxOwnerOpenState = (isOpen) => {
      const owner = wrapper.closest('.panel, .filter-panel, [data-live-filter-region]');

      if (owner instanceof HTMLElement) {
        const hasOpenCombobox = Boolean(owner.querySelector('.select-combobox.is-open'));
        owner.classList.toggle('has-combobox-open', isOpen || hasOpenCombobox);
      }
    };

    const closePanel = () => {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      wrapper.classList.remove('is-open');
      setComboboxOwnerOpenState(false);
    };

    const openPanel = () => {
      document.querySelectorAll('.select-combobox.is-open').forEach((openWrapper) => {
        if (openWrapper === wrapper) {
          return;
        }

        const openPanelElement = openWrapper.querySelector('.select-combobox-panel');
        const openToggle = openWrapper.querySelector('.select-combobox-toggle');

        if (openPanelElement instanceof HTMLElement) {
          openPanelElement.hidden = true;
        }

        if (openToggle instanceof HTMLElement) {
          openToggle.setAttribute('aria-expanded', 'false');
        }

        openWrapper.classList.remove('is-open');
        const owner = openWrapper.closest('.panel, .filter-panel, [data-live-filter-region]');
        if (owner instanceof HTMLElement && !owner.querySelector('.select-combobox.is-open')) {
          owner.classList.remove('has-combobox-open');
        }
      });

      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      wrapper.classList.add('is-open');
      setComboboxOwnerOpenState(true);
      search.focus();
      search.select();
    };

    const renderOptions = () => {
      const query = search.value.trim().toLowerCase();
      const matches = options.filter((option) => query === '' || optionSearchText(option).includes(query));

      if (!matches.length) {
        optionsWrap.innerHTML = `<div class="workflow-picker-empty">${escapeHtml(select.dataset.comboboxEmpty || 'No matching options.')}</div>`;
        return;
      }

      optionsWrap.innerHTML = matches.map((option) => {
        const index = options.indexOf(option);
        const selected = option.selected;
        const meta = optionMeta(option);

        return `
          <button class="workflow-picker-option select-combobox-option${selected ? ' is-selected' : ''}" type="button" role="option" aria-selected="${selected ? 'true' : 'false'}" data-select-option-index="${index}">
            <span class="workflow-picker-thumb workflow-picker-thumb-fallback">${escapeHtml(optionTitle(option).charAt(0).toUpperCase() || '?')}</span>
            <span>
              <strong>${escapeHtml(optionTitle(option))}</strong>
              ${meta ? `<span class="tiny-copy">${escapeHtml(meta)}</span>` : ''}
            </span>
          </button>
        `;
      }).join('');
    };

    toggle.addEventListener('click', () => {
      if (panel.hidden) {
        renderOptions();
        openPanel();
      } else {
        closePanel();
      }
    });

    search.addEventListener('input', renderOptions);
    search.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        search.value = '';
        renderOptions();
        closePanel();
        toggle.focus();
      }
    });

    optionsWrap.addEventListener('click', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-select-option-index]') : null;
      if (!(button instanceof HTMLElement)) {
        return;
      }

      const option = options[Number.parseInt(button.dataset.selectOptionIndex || '-1', 10)];
      if (!option) {
        return;
      }

      select.value = option.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      renderSelected();
      renderOptions();
      closePanel();
      toggle.focus();
    });

    document.addEventListener('click', (event) => {
      if (event.target instanceof Node && !wrapper.contains(event.target)) {
        closePanel();
      }
    });

    select.addEventListener('change', () => {
      renderSelected();
      renderOptions();
    });

    renderSelected();
    renderOptions();
  });
};

export const init = (root = document) => {
  initSearchableSelects(root);
  initComboboxSelects(root);
};
