import { formatCount } from '../core/runtime.js';

const shell = document.querySelector('[data-shell]');

export const initTableShell = (shellElement) => {
  if (shellElement.dataset.jsBound === 'true') {
    return;
  }

  const table = shellElement.querySelector('table');
  const tbody = table ? table.querySelector('tbody') : null;
  const searchInput = shellElement.querySelector('[data-table-search]');
  const pageSizeSelect = shellElement.querySelector('[data-table-page-size]');
  const results = shellElement.querySelector('[data-table-results]');
  const pagination = shellElement.querySelector('[data-table-pagination]');
  const totalBadge = shellElement.querySelector('[data-table-total]');

  if (!table || !tbody || !pagination) {
    return;
  }

  shellElement.dataset.jsBound = 'true';

  const staticEmptyRow = Array.from(tbody.querySelectorAll('tr')).find((row) => row.querySelector('.empty-cell')) || null;
  const rows = Array.from(tbody.querySelectorAll('tr')).filter((row) => row !== staticEmptyRow);
  const columnCount = table.querySelectorAll('thead th').length || 1;
  const defaultPageSize = Number.parseInt(shellElement.dataset.defaultPageSize || '10', 10);
  const emptyText = shellElement.dataset.emptyText || 'No matching records found.';

  let filteredRows = [...rows];
  let currentPage = 1;

  const dynamicEmptyRow = document.createElement('tr');
  dynamicEmptyRow.hidden = true;
  dynamicEmptyRow.innerHTML = `<td colspan="${columnCount}" class="empty-cell">${emptyText}</td>`;

  const clampPageSize = () => {
    const parsed = Number.parseInt(pageSizeSelect ? pageSizeSelect.value : String(defaultPageSize), 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : defaultPageSize;
  };

  const pageSequence = (totalPages, current) => {
    if (totalPages <= 7) {
      return Array.from({ length: totalPages }, (_, index) => index + 1);
    }

    const pages = [1];
    const start = Math.max(2, current - 1);
    const end = Math.min(totalPages - 1, current + 1);

    if (start > 2) {
      pages.push('start-ellipsis');
    }

    for (let page = start; page <= end; page += 1) {
      pages.push(page);
    }

    if (end < totalPages - 1) {
      pages.push('end-ellipsis');
    }

    pages.push(totalPages);

    return pages;
  };

  const mountEmptyRow = (show) => {
    if (!show) {
      if (dynamicEmptyRow.parentElement) {
        dynamicEmptyRow.remove();
      }

      if (staticEmptyRow) {
        staticEmptyRow.hidden = true;
      }

      return;
    }

    if (rows.length === 0 && staticEmptyRow) {
      staticEmptyRow.hidden = false;
      return;
    }

    dynamicEmptyRow.hidden = false;

    if (!dynamicEmptyRow.parentElement) {
      tbody.appendChild(dynamicEmptyRow);
    }
  };

  const render = () => {
    const pageSize = clampPageSize();
    const totalRows = rows.length;
    const totalFiltered = filteredRows.length;
    const query = searchInput ? searchInput.value.trim() : '';
    const totalPages = totalFiltered === 0 ? 1 : Math.ceil(totalFiltered / pageSize);
    const safePage = Math.min(Math.max(currentPage, 1), totalPages);
    const startIndex = totalFiltered === 0 ? 0 : (safePage - 1) * pageSize;
    const endIndex = totalFiltered === 0 ? 0 : Math.min(startIndex + pageSize, totalFiltered);
    const visibleRows = filteredRows.slice(startIndex, endIndex);

    currentPage = safePage;

    rows.forEach((row) => {
      row.hidden = true;
    });

    visibleRows.forEach((row) => {
      row.hidden = false;
    });

    mountEmptyRow(totalFiltered === 0);

    if (totalBadge) {
      totalBadge.textContent = formatCount(totalRows);
    }

    if (results) {
      if (totalRows === 0) {
        results.textContent = 'Showing 0 to 0 of 0 entries';
      } else if (totalFiltered === 0) {
        results.textContent = query === ''
          ? 'Showing 0 to 0 of 0 entries'
          : `Showing 0 to 0 of 0 matching entries from ${formatCount(totalRows)} total`;
      } else {
        results.textContent = `Showing ${formatCount(startIndex + 1)} to ${formatCount(endIndex)} of ${formatCount(totalFiltered)} entries`;

        if (query !== '' && totalFiltered !== totalRows) {
          results.textContent += ` from ${formatCount(totalRows)} total`;
        }
      }
    }

    pagination.innerHTML = '';
    pagination.hidden = totalRows === 0 || totalPages <= 1;

    if (pagination.hidden) {
      return;
    }

    const makeButton = (label, page, options = {}) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'table-page-button';
      button.textContent = label;

      if (options.active) {
        button.classList.add('is-active');
        button.setAttribute('aria-current', 'page');
      }

      if (options.ellipsis) {
        button.classList.add('is-ellipsis');
        button.disabled = true;
        return button;
      }

      if (options.disabled) {
        button.disabled = true;
      } else {
        button.addEventListener('click', () => {
          currentPage = page;
          render();
        });
      }

      return button;
    };

    pagination.appendChild(makeButton('Previous', safePage - 1, { disabled: safePage === 1 }));

    pageSequence(totalPages, safePage).forEach((page) => {
      if (typeof page === 'string') {
        pagination.appendChild(makeButton('...', safePage, { ellipsis: true }));
        return;
      }

      pagination.appendChild(makeButton(String(page), page, { active: page === safePage }));
    });

    pagination.appendChild(makeButton('Next', safePage + 1, { disabled: safePage === totalPages }));
  };

  const updateFilters = () => {
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
    filteredRows = rows.filter((row) => row.textContent.toLowerCase().includes(query));
    currentPage = 1;
    render();
  };

  if (pageSizeSelect) {
    pageSizeSelect.addEventListener('change', () => {
      currentPage = 1;
      render();
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', updateFilters);
  }

  render();
};

export const initDataTables = (root = document) => {
  root.querySelectorAll('[data-table-shell]').forEach((shellElement) => {
    initTableShell(shellElement);
  });
};

export const init = (root = document) => initDataTables(root);
