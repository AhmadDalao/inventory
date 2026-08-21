function normalize(value) {
  return String(value || '').trim().toLowerCase();
}

function initEmployeeSearch(search) {
  if (search.dataset.mobileAdminBound === '1') return;
  search.dataset.mobileAdminBound = '1';

  const section = search.closest('.panel');
  const cards = Array.from(section?.querySelectorAll('[data-mobile-user-card]') || []);
  const empty = section?.querySelector('[data-mobile-user-empty]');

  const filterCards = () => {
    const query = normalize(search.value);
    let visible = 0;
    cards.forEach((card) => {
      const matches = query === '' || normalize(card.dataset.searchText).includes(query);
      card.hidden = !matches;
      if (matches) visible += 1;
    });
    if (empty) empty.hidden = visible !== 0;
  };

  search.addEventListener('input', filterCards);
  filterCards();
}

function initAccessForm(form) {
  if (form.dataset.mobileAdminBound === '1') return;
  form.dataset.mobileAdminBound = '1';

  const storageInputs = Array.from(form.querySelectorAll('[data-mobile-storage-option]'));
  const defaultStorage = form.querySelector('[data-mobile-default-storage]');
  const card = form.closest('[data-mobile-user-card]');
  const count = card?.querySelector('[data-mobile-storage-count]');
  if (!storageInputs.length || !defaultStorage) return;

  const selectedStorageIds = () => new Set(
    storageInputs.filter((input) => input.checked).map((input) => String(input.value)),
  );

  const syncStorageControls = () => {
    const selected = selectedStorageIds();
    Array.from(defaultStorage.options).forEach((option) => {
      if (option.value === '0') return;
      option.disabled = !selected.has(option.value);
    });

    if (defaultStorage.value !== '0' && !selected.has(defaultStorage.value)) {
      defaultStorage.value = storageInputs.find((input) => input.checked)?.value || '0';
    }
    if (defaultStorage.value === '0' && selected.size > 0) {
      defaultStorage.value = storageInputs.find((input) => input.checked)?.value || '0';
    }
    if (count) count.textContent = `${selected.size.toLocaleString()} assigned`;
  };

  storageInputs.forEach((input) => input.addEventListener('change', syncStorageControls));
  syncStorageControls();
}

export function init(root = document) {
  root.querySelectorAll('[data-mobile-user-search]').forEach(initEmployeeSearch);
  root.querySelectorAll('[data-mobile-access-form]').forEach(initAccessForm);
}
