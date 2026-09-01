import { showGlobalFlash } from '../core/runtime.js';

const normalizeText = (value) => String(value || '').trim().toLocaleLowerCase();

async function saveManager(form, managerUserId) {
  const formData = new FormData(form);
  formData.set('manager_user_id', managerUserId || '');

  const response = await fetch(form.action, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: formData,
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok || !payload.ok) {
    throw new Error(payload.message || 'The manager assignment could not be saved.');
  }

  return payload;
}

function treeNodeFor(workspace, userId) {
  return workspace.querySelector(`[data-team-node][data-team-user-id="${userId}"]`);
}

function moveTreeNode(workspace, userId, managerUserId) {
  const node = treeNodeFor(workspace, userId);
  const rootList = workspace.querySelector('[data-team-root-list]');
  if (!(node instanceof HTMLElement) || !(rootList instanceof HTMLElement)) return;

  if (managerUserId) {
    const managerNode = treeNodeFor(workspace, managerUserId);
    const children = managerNode?.querySelector(`:scope > [data-team-children-for="${managerUserId}"]`);
    if (children instanceof HTMLElement) children.appendChild(node);
    return;
  }

  rootList.appendChild(node);
}

function syncManagerState(workspace, userId, managerUserId, managerName) {
  workspace.querySelectorAll(`[data-team-user-id="${userId}"]`).forEach((container) => {
    container.querySelectorAll('[data-team-current-manager]').forEach((label) => {
      label.textContent = managerName || 'Top level';
    });
    container.querySelectorAll('[data-team-manager-form] select[name="manager_user_id"]').forEach((select) => {
      if (select instanceof HTMLSelectElement) select.value = managerUserId || '';
    });
    if (container.matches('[data-team-directory-row]')) {
      container.dataset.teamManagerId = managerUserId || 'unassigned';
    }
  });
}

function syncDirectReportLists(workspace, userId, managerUserId) {
  workspace.querySelectorAll(`[data-team-direct-report-user-id="${userId}"]`).forEach((entry) => entry.remove());

  if (managerUserId) {
    const managerRow = workspace.querySelector(`[data-team-directory-row][data-team-user-id="${managerUserId}"]`);
    const employeeRow = workspace.querySelector(`[data-team-directory-row][data-team-user-id="${userId}"]`);
    const list = managerRow?.querySelector('[data-team-direct-report-list]');
    if (list instanceof HTMLElement && employeeRow instanceof HTMLElement) {
      const link = document.createElement('a');
      link.href = employeeRow.dataset.teamUserEditUrl || '#';
      link.dataset.teamDirectReportUserId = userId;
      const name = document.createElement('strong');
      name.textContent = employeeRow.dataset.teamUserName || 'Employee';
      const email = document.createElement('small');
      email.textContent = employeeRow.dataset.teamUserEmail || '';
      link.append(name, email);
      list.prepend(link);
    }
  }

  workspace.querySelectorAll('.team-hierarchy-direct-report-details').forEach((details) => {
    const entries = details.querySelectorAll('[data-team-direct-report-user-id]');
    const count = details.querySelector('[data-team-direct-report-count]');
    const empty = details.querySelector('[data-team-direct-report-empty]');
    if (count) count.textContent = String(entries.length);
    if (empty instanceof HTMLElement) empty.hidden = entries.length !== 0;
  });
}

function initViewSwitch(workspace) {
  const buttons = [...workspace.querySelectorAll('[data-team-view-button]')];
  const panels = [...workspace.querySelectorAll('[data-team-view-panel]')];
  if (buttons.length === 0 || panels.length === 0) return;

  const activate = (view) => {
    const selected = view === 'tree' ? 'tree' : 'directory';
    buttons.forEach((button) => {
      const active = button.dataset.teamViewButton === selected;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    panels.forEach((panel) => {
      panel.hidden = panel.dataset.teamViewPanel !== selected;
    });
    try {
      window.sessionStorage.setItem('inventory.teamHierarchyView', selected);
    } catch (error) {
      // Storage can be disabled without affecting hierarchy controls.
    }
  };

  buttons.forEach((button) => button.addEventListener('click', () => activate(button.dataset.teamViewButton)));
  let initialView = 'directory';
  try {
    initialView = window.sessionStorage.getItem('inventory.teamHierarchyView') || 'directory';
  } catch (error) {
    initialView = 'directory';
  }
  activate(initialView);
}

function initDirectory(workspace) {
  const rows = [...workspace.querySelectorAll('[data-team-directory-row]')];
  const search = workspace.querySelector('[data-team-search]');
  const managerFilter = workspace.querySelector('[data-team-manager-filter]');
  const departmentFilter = workspace.querySelector('[data-team-department-filter]');
  const mobileFilter = workspace.querySelector('[data-team-mobile-filter]');
  const visibleCount = workspace.querySelector('[data-team-visible-count]');
  const emptyState = workspace.querySelector('[data-team-filter-empty]');
  const selectVisible = workspace.querySelector('[data-team-select-visible]');
  const selectedCount = workspace.querySelector('[data-team-selected-count]');
  const bulkSubmit = workspace.querySelector('[data-team-bulk-submit]');
  const clearSelection = workspace.querySelector('[data-team-clear-selection]');
  const bulkForm = workspace.querySelector('[data-team-bulk-form]');
  const bulkManager = workspace.querySelector('[data-team-bulk-manager]');

  const selectableCheckboxes = () => [...workspace.querySelectorAll('[data-team-select-user]')]
    .filter((checkbox) => checkbox instanceof HTMLInputElement);
  const visibleCheckboxes = () => selectableCheckboxes().filter((checkbox) => !checkbox.closest('[data-team-directory-row]')?.hidden);

  const updateSelection = () => {
    const all = selectableCheckboxes();
    const visible = visibleCheckboxes();
    const selected = all.filter((checkbox) => checkbox.checked);
    rows.forEach((row) => {
      const checkbox = row.querySelector('[data-team-select-user]');
      row.classList.toggle('is-selected', checkbox instanceof HTMLInputElement && checkbox.checked);
    });
    if (selectedCount) selectedCount.textContent = `${selected.length} selected`;
    if (bulkSubmit instanceof HTMLButtonElement) {
      bulkSubmit.disabled = selected.length === 0;
      bulkSubmit.textContent = selected.length > 0 ? `Assign Manager (${selected.length})` : 'Assign Manager';
      const managerLabel = bulkManager instanceof HTMLSelectElement
        ? bulkManager.options[bulkManager.selectedIndex]?.textContent?.trim() || 'the selected manager'
        : 'the selected manager';
      bulkSubmit.dataset.confirm = `Assign ${selected.length} employee${selected.length === 1 ? '' : 's'} to ${managerLabel}?`;
    }
    if (clearSelection instanceof HTMLButtonElement) clearSelection.disabled = selected.length === 0;
    if (selectVisible instanceof HTMLInputElement) {
      const checkedVisible = visible.filter((checkbox) => checkbox.checked).length;
      selectVisible.checked = visible.length > 0 && checkedVisible === visible.length;
      selectVisible.indeterminate = checkedVisible > 0 && checkedVisible < visible.length;
      selectVisible.disabled = visible.length === 0;
    }
  };

  const applyFilters = () => {
    const query = normalizeText(search instanceof HTMLInputElement ? search.value : '');
    const manager = managerFilter instanceof HTMLSelectElement ? managerFilter.value : 'all';
    const department = departmentFilter instanceof HTMLSelectElement ? departmentFilter.value : 'all';
    const mobile = mobileFilter instanceof HTMLSelectElement ? mobileFilter.value : 'all';
    let shown = 0;

    rows.forEach((row) => {
      const matchesSearch = query === '' || normalizeText(row.dataset.teamSearchText).includes(query);
      const matchesManager = manager === 'all' || row.dataset.teamManagerId === manager;
      const matchesDepartment = department === 'all' || row.dataset.teamDepartment === department;
      const matchesMobile = mobile === 'all' || row.dataset.teamMobile === mobile;
      const visible = matchesSearch && matchesManager && matchesDepartment && matchesMobile;
      row.hidden = !visible;
      if (visible) shown += 1;
    });

    if (visibleCount) visibleCount.textContent = `${shown} shown`;
    if (emptyState instanceof HTMLElement) emptyState.hidden = shown !== 0;
    updateSelection();
  };

  search?.addEventListener('input', applyFilters);
  managerFilter?.addEventListener('change', applyFilters);
  departmentFilter?.addEventListener('change', applyFilters);
  mobileFilter?.addEventListener('change', applyFilters);
  bulkManager?.addEventListener('change', updateSelection);
  selectableCheckboxes().forEach((checkbox) => checkbox.addEventListener('change', updateSelection));

  if (selectVisible instanceof HTMLInputElement) {
    selectVisible.addEventListener('change', () => {
      visibleCheckboxes().forEach((checkbox) => {
        checkbox.checked = selectVisible.checked;
      });
      updateSelection();
    });
  }

  clearSelection?.addEventListener('click', () => {
    selectableCheckboxes().forEach((checkbox) => {
      checkbox.checked = false;
    });
    updateSelection();
  });

  bulkForm?.addEventListener('submit', (event) => {
    const selected = selectableCheckboxes().filter((checkbox) => checkbox.checked);
    if (selected.length === 0) {
      event.preventDefault();
      showGlobalFlash('Select at least one employee first.', 'danger');
      return;
    }
    const managerUserId = bulkManager instanceof HTMLSelectElement ? bulkManager.value : '';
    if (managerUserId && selected.some((checkbox) => checkbox.value === managerUserId)) {
      event.preventDefault();
      showGlobalFlash('A selected employee cannot also be the destination manager.', 'danger');
      return;
    }
  });

  workspace.addEventListener('team:manager-updated', applyFilters);
  applyFilters();
}

function initManagerControls(workspace) {
  let draggedNode = null;
  const rootDrop = workspace.querySelector('[data-team-root-drop]');

  const submitManagerForm = async (form, forcedManagerUserId = null) => {
    const userInput = form.querySelector('input[name="user_id"]');
    const select = form.querySelector('select[name="manager_user_id"]');
    if (!(userInput instanceof HTMLInputElement) || !(select instanceof HTMLSelectElement)) return;
    const userId = userInput.value;
    const managerUserId = forcedManagerUserId === null ? select.value : forcedManagerUserId;
    const node = treeNodeFor(workspace, userId);
    const managerNode = managerUserId ? treeNodeFor(workspace, managerUserId) : null;
    if (node instanceof HTMLElement && managerNode instanceof HTMLElement && node.contains(managerNode)) {
      showGlobalFlash('That manager assignment would create a reporting loop.', 'danger');
      return;
    }

    const payload = await saveManager(form, managerUserId);
    syncManagerState(workspace, userId, managerUserId, payload.manager_name || 'Top level');
    syncDirectReportLists(workspace, userId, managerUserId);
    moveTreeNode(workspace, userId, managerUserId);
    workspace.dispatchEvent(new CustomEvent('team:manager-updated'));
    showGlobalFlash(payload.message || 'Manager updated.', 'success');
    document.dispatchEvent(new CustomEvent('inventory:action-complete'));
  };

  workspace.querySelectorAll('[data-team-manager-form]').forEach((form) => {
    if (!(form instanceof HTMLFormElement) || form.dataset.teamFormBound === '1') return;
    form.dataset.teamFormBound = '1';
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await submitManagerForm(form);
      } catch (error) {
        showGlobalFlash(error.message || 'The manager assignment could not be saved.', 'danger');
      }
    });
  });

  workspace.querySelectorAll('[data-team-node]').forEach((node) => {
    if (!(node instanceof HTMLElement) || node.dataset.teamDragBound === '1') return;
    node.dataset.teamDragBound = '1';
    node.addEventListener('dragstart', (event) => {
      if (!(event.target instanceof HTMLElement) || !event.target.closest('.team-hierarchy-drag')) return;
      event.stopPropagation();
      draggedNode = node;
      node.classList.add('is-dragging');
      event.dataTransfer?.setData('text/plain', node.dataset.teamUserId || '');
      if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
    });
    node.addEventListener('dragend', (event) => {
      event.stopPropagation();
      node.classList.remove('is-dragging');
      workspace.querySelectorAll('.is-drop-target').forEach((target) => target.classList.remove('is-drop-target'));
      draggedNode = null;
    });
  });

  workspace.querySelectorAll('[data-team-manager-drop]').forEach((target) => {
    if (!(target instanceof HTMLElement) || target.dataset.teamDropBound === '1') return;
    target.dataset.teamDropBound = '1';
    target.addEventListener('dragover', (event) => {
      const managerNode = target.closest('[data-team-node]');
      if (!draggedNode || !managerNode || draggedNode === managerNode || draggedNode.contains(managerNode)) return;
      event.preventDefault();
      target.classList.add('is-drop-target');
    });
    target.addEventListener('dragleave', () => target.classList.remove('is-drop-target'));
    target.addEventListener('drop', async (event) => {
      event.preventDefault();
      event.stopPropagation();
      target.classList.remove('is-drop-target');
      const managerNode = target.closest('[data-team-node]');
      const form = draggedNode?.querySelector(':scope > .team-hierarchy-card [data-team-manager-form]');
      if (!(draggedNode instanceof HTMLElement) || !(managerNode instanceof HTMLElement) || !(form instanceof HTMLFormElement)) return;
      try {
        await submitManagerForm(form, managerNode.dataset.teamUserId || '');
      } catch (error) {
        showGlobalFlash(error.message || 'The manager assignment could not be saved.', 'danger');
      }
    });
  });

  if (rootDrop instanceof HTMLElement) {
    rootDrop.addEventListener('dragover', (event) => {
      if (!draggedNode) return;
      event.preventDefault();
      rootDrop.classList.add('is-drop-target');
    });
    rootDrop.addEventListener('dragleave', () => rootDrop.classList.remove('is-drop-target'));
    rootDrop.addEventListener('drop', async (event) => {
      event.preventDefault();
      rootDrop.classList.remove('is-drop-target');
      const form = draggedNode?.querySelector(':scope > .team-hierarchy-card [data-team-manager-form]');
      if (!(form instanceof HTMLFormElement)) return;
      try {
        await submitManagerForm(form, '');
      } catch (error) {
        showGlobalFlash(error.message || 'The manager assignment could not be saved.', 'danger');
      }
    });
  }
}

function initHierarchy(workspace) {
  if (workspace.dataset.teamHierarchyBound === '1') return;
  workspace.dataset.teamHierarchyBound = '1';
  initViewSwitch(workspace);
  initDirectory(workspace);
  initManagerControls(workspace);
}

function initUserReporting(panel) {
  if (!(panel instanceof HTMLDetailsElement) || panel.dataset.userReportingBound === '1') return;
  panel.dataset.userReportingBound = '1';

  document.querySelectorAll('[data-open-user-reporting]').forEach((trigger) => {
    if (!(trigger instanceof HTMLElement) || trigger.dataset.userReportingTriggerBound === '1') return;
    trigger.dataset.userReportingTriggerBound = '1';
    trigger.addEventListener('click', () => {
      panel.open = true;
    });
  });
  if (window.location.hash === '#reporting-lines') {
    panel.open = true;
    window.requestAnimationFrame(() => panel.scrollIntoView({ block: 'start' }));
  }

  const form = panel.querySelector('[data-user-team-add-form]');
  if (!(form instanceof HTMLFormElement)) return;
  const search = form.querySelector('[data-user-team-search]');
  const candidates = [...form.querySelectorAll('[data-user-team-candidate]')];
  const checkboxes = [...form.querySelectorAll('[data-user-team-checkbox]')]
    .filter((checkbox) => checkbox instanceof HTMLInputElement);
  const selectedCount = panel.querySelector('[data-user-team-selected-count]');
  const selectVisible = form.querySelector('[data-user-team-select-visible]');
  const clear = form.querySelector('[data-user-team-clear]');
  const submit = form.querySelector('[data-user-team-submit]');
  const empty = form.querySelector('[data-user-team-filter-empty]');

  const updateSelection = () => {
    const selected = checkboxes.filter((checkbox) => checkbox.checked);
    if (selectedCount) selectedCount.textContent = `${selected.length} selected`;
    if (clear instanceof HTMLButtonElement) clear.disabled = selected.length === 0;
    if (submit instanceof HTMLButtonElement) {
      submit.disabled = selected.length === 0;
      submit.textContent = selected.length > 0 ? `Add Selected Employees (${selected.length})` : 'Add Selected Employees';
      submit.dataset.confirm = `Assign ${selected.length} employee${selected.length === 1 ? '' : 's'} to this manager?`;
    }
  };

  const visibleCheckboxes = () => candidates
    .filter((candidate) => !candidate.hidden)
    .map((candidate) => candidate.querySelector('[data-user-team-checkbox]'))
    .filter((checkbox) => checkbox instanceof HTMLInputElement);

  const applySearch = () => {
    const query = normalizeText(search instanceof HTMLInputElement ? search.value : '');
    let shown = 0;
    candidates.forEach((candidate) => {
      const visible = query === '' || normalizeText(candidate.dataset.searchText).includes(query);
      candidate.hidden = !visible;
      if (visible) shown += 1;
    });
    if (empty instanceof HTMLElement) empty.hidden = shown !== 0 || candidates.length === 0;
  };

  search?.addEventListener('input', applySearch);
  checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateSelection));
  selectVisible?.addEventListener('click', () => {
    visibleCheckboxes().forEach((checkbox) => {
      checkbox.checked = true;
    });
    updateSelection();
  });
  clear?.addEventListener('click', () => {
    checkboxes.forEach((checkbox) => {
      checkbox.checked = false;
    });
    updateSelection();
  });
  form.addEventListener('submit', (event) => {
    if (!checkboxes.some((checkbox) => checkbox.checked)) {
      event.preventDefault();
      showGlobalFlash('Select at least one employee first.', 'danger');
    }
  });
  applySearch();
  updateSelection();
}

export function init(root = document) {
  root.querySelectorAll('[data-team-hierarchy]').forEach(initHierarchy);
  root.querySelectorAll('[data-user-reporting]').forEach(initUserReporting);
}
