export const initPermissionBuilders = (root = document) => {
  root.querySelectorAll('[data-permission-builder]').forEach((builder) => {
    if (builder.dataset.jsBound === 'true') {
      return;
    }

    const form = builder.closest('form');
    const roleSelect = form ? form.querySelector('[data-role-select]') : null;
    const positionSelect = form ? form.querySelector('[data-position-select]') : null;
    const applyButton = builder.querySelector('[data-apply-role-defaults]');
    const applyPositionButton = builder.querySelector('[data-apply-position-defaults]');
    const permissionSearch = builder.querySelector('[data-permission-search]') || (form ? form.querySelector('[data-permission-search]') : null);
    const selectAllButton = builder.querySelector('[data-select-all-permissions]') || (form ? form.querySelector('[data-select-all-permissions]') : null);
    const clearButton = builder.querySelector('[data-clear-permissions]') || (form ? form.querySelector('[data-clear-permissions]') : null);
    const assignedOwnerField = form ? form.querySelector('[data-assigned-owner-field]') : null;
    const assignedOwnerSelect = assignedOwnerField ? assignedOwnerField.querySelector('select') : null;
    const positionSummaryTargets = form ? Array.from(form.querySelectorAll('[data-position-summary]')) : [];
    const roleSummaryTargets = form ? Array.from(form.querySelectorAll('[data-role-summary]')) : [];
    const permissionCountTargets = form ? Array.from(form.querySelectorAll('[data-permission-count]')) : [];

    if (!form) {
      return;
    }

    let roleDefaults = {};
    let positionDefaults = {};
    let positionRoles = {};
    let syncingPositionRole = false;

    try {
      roleDefaults = JSON.parse(builder.dataset.roleDefaults || '{}');
    } catch (error) {
      roleDefaults = {};
    }

    try {
      positionDefaults = JSON.parse(builder.dataset.positionDefaults || '{}');
    } catch (error) {
      positionDefaults = {};
    }

    try {
      positionRoles = JSON.parse(builder.dataset.positionRoles || '{}');
    } catch (error) {
      positionRoles = {};
    }

    function permissionInputs() {
      return Array.from(builder.querySelectorAll('input[name="permissions[]"]'));
    }

    function selectedOptionText(select) {
      if (!(select instanceof HTMLSelectElement)) {
        return '';
      }

      return select.selectedOptions[0]?.textContent?.trim() || select.value || '';
    }

    function syncAssignedOwnerField() {
      const isStaffAccess = roleSelect instanceof HTMLSelectElement && roleSelect.value === 'staff';

      if (assignedOwnerField) {
        assignedOwnerField.hidden = !isStaffAccess;
      }

      if (assignedOwnerSelect instanceof HTMLSelectElement) {
        assignedOwnerSelect.disabled = !isStaffAccess;
      }
    }

    function updatePermissionSummary() {
      const inputs = permissionInputs();
      const checkedCount = inputs.filter((input) => input.checked).length;

      permissionCountTargets.forEach((target) => {
        target.textContent = String(checkedCount);
      });

      if (positionSelect instanceof HTMLSelectElement) {
        positionSummaryTargets.forEach((target) => {
          target.textContent = selectedOptionText(positionSelect);
        });
      }

      if (roleSelect instanceof HTMLSelectElement) {
        roleSummaryTargets.forEach((target) => {
          target.textContent = selectedOptionText(roleSelect);
        });
      }

      builder.querySelectorAll('[data-permission-card]').forEach((card) => {
        const cardInputs = Array.from(card.querySelectorAll('input[name="permissions[]"]'));
        const cardChecked = cardInputs.filter((input) => input.checked).length;
        const groupCount = card.querySelector('[data-permission-group-count]');

        if (groupCount) {
          groupCount.textContent = `${cardChecked} selected`;
        }
      });

      syncAssignedOwnerField();
    }

    function filterPermissionOptions() {
      const query = permissionSearch instanceof HTMLInputElement ? permissionSearch.value.trim().toLowerCase() : '';

      builder.querySelectorAll('[data-permission-card]').forEach((card) => {
        let visibleOptions = 0;

        card.querySelectorAll('[data-permission-option]').forEach((option) => {
          const match = query === '' || option.textContent.toLowerCase().includes(query);
          option.hidden = !match;

          if (match) {
            visibleOptions++;
          }
        });

        card.hidden = query !== '' && visibleOptions === 0;

        if (query !== '' && visibleOptions > 0 && card instanceof HTMLDetailsElement) {
          card.open = true;
        }
      });
    }

    const applyDefaultsForRole = (role) => {
      const defaults = new Set(roleDefaults[String(role)] || []);

      permissionInputs().forEach((input) => {
        input.checked = defaults.has(input.value);
      });

      updatePermissionSummary();
    };

    const applyDefaultsForPosition = (position) => {
      const key = String(position || '');
      const role = positionRoles[key] || '';

      if (role && roleSelect instanceof HTMLSelectElement) {
        syncingPositionRole = true;
        roleSelect.value = role;
        roleSelect.dispatchEvent(new Event('change', { bubbles: true }));
        syncingPositionRole = false;
      }

      const defaults = new Set(positionDefaults[key] || []);

      if (defaults.size === 0) {
        if (roleSelect instanceof HTMLSelectElement) {
          applyDefaultsForRole(roleSelect.value);
        }
        return;
      }

      permissionInputs().forEach((input) => {
        input.checked = defaults.has(input.value);
      });

      updatePermissionSummary();
    };

    builder.dataset.jsBound = 'true';

    if (applyButton instanceof HTMLButtonElement && roleSelect instanceof HTMLSelectElement) {
      applyButton.addEventListener('click', () => {
        applyDefaultsForRole(roleSelect.value);
      });
    }

    if (applyPositionButton && positionSelect instanceof HTMLSelectElement) {
      applyPositionButton.addEventListener('click', () => {
        applyDefaultsForPosition(positionSelect.value);
      });
    }

    if (roleSelect instanceof HTMLSelectElement) {
      roleSelect.addEventListener('change', () => {
        if (builder.dataset.autoRoleDefaults === 'true' && !syncingPositionRole) {
          applyDefaultsForRole(roleSelect.value);
          return;
        }

        updatePermissionSummary();
      });
    }

    if (positionSelect instanceof HTMLSelectElement) {
      positionSelect.addEventListener('change', () => {
        if (builder.dataset.autoRoleDefaults === 'true' && roleSelect instanceof HTMLSelectElement) {
          applyDefaultsForPosition(positionSelect.value);
          return;
        }

        updatePermissionSummary();
      });
    }

    permissionInputs().forEach((input) => {
      input.addEventListener('change', updatePermissionSummary);
    });

    if (permissionSearch instanceof HTMLInputElement) {
      permissionSearch.addEventListener('input', filterPermissionOptions);
    }

    if (selectAllButton instanceof HTMLButtonElement) {
      selectAllButton.addEventListener('click', () => {
        permissionInputs().forEach((input) => {
          input.checked = true;
        });
        updatePermissionSummary();
      });
    }

    if (clearButton instanceof HTMLButtonElement) {
      clearButton.addEventListener('click', () => {
        permissionInputs().forEach((input) => {
          input.checked = false;
        });
        updatePermissionSummary();
      });
    }

    updatePermissionSummary();
  });
};

export const init = (root = document) => initPermissionBuilders(root);
