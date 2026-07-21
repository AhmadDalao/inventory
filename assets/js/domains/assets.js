import { csrfToken } from '../core/runtime.js';

export const initAssetCategoryTrees = (root = document) => {
  root.querySelectorAll('[data-asset-category-tree]').forEach((tree) => {
    if (!(tree instanceof HTMLElement) || tree.dataset.assetCategoryTreeBound === 'true') {
      return;
    }

    tree.dataset.assetCategoryTreeBound = 'true';
    const reorderUrl = tree.dataset.reorderUrl || '';
    let draggedNode = null;

    const categoryId = (node) => node instanceof HTMLElement ? node.dataset.assetCategoryId || '' : '';
    const directNodeIds = (dropZone) => Array.from(dropZone.children)
      .filter((child) => child instanceof HTMLElement && child.matches('[data-asset-category-id]'))
      .map((child) => categoryId(child))
      .filter(Boolean);

    const saveMove = async (node, parentId, siblingIds) => {
      const formData = new FormData();
      formData.append('_token', csrfToken(tree));
      formData.append('category_id', categoryId(node));
      formData.append('parent_id', parentId || '');
      siblingIds.forEach((id) => formData.append('ordered_ids[]', id));

      const response = await fetch(reorderUrl, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: formData,
      });
      const payload = await response.json().catch(() => ({}));

      if (!response.ok || payload.ok === false) {
        throw new Error(payload.message || 'Could not save category move.');
      }
    };

    tree.querySelectorAll('[data-asset-category-id]').forEach((node) => {
      if (!(node instanceof HTMLElement)) {
        return;
      }

      node.addEventListener('dragstart', (event) => {
        draggedNode = node;
        node.classList.add('is-dragging');
        event.dataTransfer?.setData('text/plain', categoryId(node));
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = 'move';
        }
      });

      node.addEventListener('dragend', () => {
        node.classList.remove('is-dragging');
        draggedNode = null;
        tree.querySelectorAll('.is-drop-target').forEach((target) => target.classList.remove('is-drop-target'));
      });
    });

    tree.querySelectorAll('[data-asset-category-drop-parent]').forEach((dropZone) => {
      if (!(dropZone instanceof HTMLElement)) {
        return;
      }

      dropZone.addEventListener('dragover', (event) => {
        if (!draggedNode || draggedNode.contains(dropZone)) {
          return;
        }

        event.preventDefault();
        dropZone.classList.add('is-drop-target');
      });

      dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('is-drop-target');
      });

      dropZone.addEventListener('drop', async (event) => {
        if (!draggedNode || draggedNode.contains(dropZone)) {
          return;
        }

        event.preventDefault();
        dropZone.classList.remove('is-drop-target');
        const previousParent = draggedNode.parentElement;
        const previousNext = draggedNode.nextElementSibling;
        dropZone.appendChild(draggedNode);

        try {
          const parentId = dropZone.dataset.assetCategoryDropParent || '';
          await saveMove(draggedNode, parentId, directNodeIds(dropZone));
          draggedNode.dataset.assetCategoryParentId = parentId;
        } catch (error) {
          if (previousParent instanceof HTMLElement) {
            previousParent.insertBefore(draggedNode, previousNext);
          }
          window.alert(error.message || 'Could not save category move.');
        }
      });
    });
  });
};

export const init = (root = document) => initAssetCategoryTrees(root);
