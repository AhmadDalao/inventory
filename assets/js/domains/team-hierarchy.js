import { showGlobalFlash } from '../core/runtime.js';

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

function initHierarchy(tree) {
  if (tree.dataset.teamHierarchyBound === '1') return;
  tree.dataset.teamHierarchyBound = '1';

  let draggedNode = null;
  const rootList = tree.querySelector('[data-team-root-list]');
  const rootDrop = tree.querySelector('[data-team-root-drop]');

  const moveNode = async (node, managerNode) => {
    if (!(node instanceof HTMLElement)) return;
    const form = node.querySelector(':scope > .team-hierarchy-card [data-team-manager-form]');
    if (!(form instanceof HTMLFormElement)) return;

    const managerUserId = managerNode?.dataset.userId || '';
    const payload = await saveManager(form, managerUserId);
    const select = form.querySelector('select[name="manager_user_id"]');
    if (select instanceof HTMLSelectElement) select.value = managerUserId;
    const managerLabel = node.querySelector(':scope > .team-hierarchy-card [data-team-current-manager]');
    if (managerLabel) managerLabel.textContent = payload.manager_name || 'Top level';

    if (managerNode instanceof HTMLElement) {
      const children = managerNode.querySelector(`:scope > [data-team-children-for="${managerUserId}"]`);
      if (children) children.appendChild(node);
    } else if (rootList) {
      rootList.appendChild(node);
    }
    showGlobalFlash(payload.message || 'Manager updated.', 'success');
    document.dispatchEvent(new CustomEvent('inventory:action-complete'));
  };

  tree.querySelectorAll('[data-team-node]').forEach((node) => {
    if (!(node instanceof HTMLElement) || node.dataset.teamDragBound === '1') return;
    node.dataset.teamDragBound = '1';
    node.addEventListener('dragstart', (event) => {
      if (!(event.target instanceof HTMLElement) || !event.target.closest('.team-hierarchy-drag')) return;
      event.stopPropagation();
      draggedNode = node;
      node.classList.add('is-dragging');
      event.dataTransfer?.setData('text/plain', node.dataset.userId || '');
      if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
    });
    node.addEventListener('dragend', (event) => {
      event.stopPropagation();
      node.classList.remove('is-dragging');
      tree.querySelectorAll('.is-drop-target').forEach((target) => target.classList.remove('is-drop-target'));
      draggedNode = null;
    });
  });

  tree.querySelectorAll('[data-team-manager-drop]').forEach((target) => {
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
      if (!draggedNode || !managerNode || draggedNode === managerNode || draggedNode.contains(managerNode)) return;
      try {
        await moveNode(draggedNode, managerNode);
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
      if (!draggedNode) return;
      try {
        await moveNode(draggedNode, null);
      } catch (error) {
        showGlobalFlash(error.message || 'The manager assignment could not be saved.', 'danger');
      }
    });
  }

  tree.querySelectorAll('[data-team-manager-form]').forEach((form) => {
    if (!(form instanceof HTMLFormElement) || form.dataset.teamFormBound === '1') return;
    form.dataset.teamFormBound = '1';
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const node = form.closest('[data-team-node]');
      const select = form.querySelector('select[name="manager_user_id"]');
      if (!(node instanceof HTMLElement) || !(select instanceof HTMLSelectElement)) return;
      const managerNode = select.value
        ? tree.querySelector(`[data-team-node][data-user-id="${select.value}"]`)
        : null;
      if (managerNode instanceof HTMLElement && node.contains(managerNode)) {
        showGlobalFlash('That manager assignment would create a reporting loop.', 'danger');
        return;
      }
      try {
        await moveNode(node, managerNode instanceof HTMLElement ? managerNode : null);
      } catch (error) {
        showGlobalFlash(error.message || 'The manager assignment could not be saved.', 'danger');
      }
    });
  });
}

export function init(root = document) {
  root.querySelectorAll('[data-team-hierarchy]').forEach(initHierarchy);
}
