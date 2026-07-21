export const initDocumentationSearch = (root = document) => {
  root.querySelectorAll('[data-documentation-root]').forEach((docsRoot) => {
    if (docsRoot.dataset.jsBound === 'true') {
      return;
    }

    docsRoot.dataset.jsBound = 'true';
    const searchInput = docsRoot.querySelector('[data-documentation-search]') || document.querySelector('[data-documentation-search]');
    const sections = Array.from(docsRoot.querySelectorAll('[data-documentation-section]'));
    const navLinks = Array.from(docsRoot.querySelectorAll('[data-documentation-nav-link]'));
    const count = docsRoot.querySelector('[data-documentation-count]');
    const status = docsRoot.querySelector('[data-documentation-status]');
    const empty = docsRoot.querySelector('[data-documentation-empty]');
    const trackSections = Array.from(docsRoot.querySelectorAll('[data-documentation-track-section]'));
    const currentTitle = docsRoot.querySelector('[data-documentation-current-title]');
    const currentMeta = docsRoot.querySelector('[data-documentation-current-meta]');
    const progress = docsRoot.querySelector('[data-documentation-progress]');
    let activeSectionId = '';
    let trackingFrame = null;

    const setActiveDocumentationSection = (section) => {
      if (!section || !section.id) {
        return;
      }

      if (section.id !== activeSectionId) {
        activeSectionId = section.id;

        navLinks.forEach((link) => {
          link.classList.toggle('is-active', link.getAttribute('href') === `#${section.id}`);
        });
      }

      if (currentTitle) {
        currentTitle.textContent = section.dataset.documentationTitle || section.querySelector('h3')?.textContent?.trim() || 'Documentation';
      }

      if (currentMeta) {
        const visibleTrackSections = trackSections.filter((trackedSection) => !trackedSection.hidden);
        const currentIndex = visibleTrackSections.indexOf(section) + 1;
        const audience = section.dataset.documentationAudience || 'All users';
        currentMeta.textContent = currentIndex > 0
          ? `${currentIndex} of ${visibleTrackSections.length} · ${audience}`
          : audience;
      }
    };

    const updateDocumentationTracker = () => {
      trackingFrame = null;
      const visibleTrackSections = trackSections.filter((section) => !section.hidden);

      if (visibleTrackSections.length === 0) {
        navLinks.forEach((link) => link.classList.remove('is-active'));

        if (currentTitle) {
          currentTitle.textContent = 'No matching section';
        }

        if (currentMeta) {
          currentMeta.textContent = 'Try another search term.';
        }

        if (progress) {
          progress.style.width = '0%';
        }

        activeSectionId = '';
        return;
      }

      const viewportAnchor = Math.min(180, Math.max(96, window.innerHeight * 0.22));
      let activeSection = visibleTrackSections[0];

      visibleTrackSections.forEach((section) => {
        const rect = section.getBoundingClientRect();

        if (rect.top <= viewportAnchor && rect.bottom > viewportAnchor) {
          activeSection = section;
          return;
        }

        if (rect.top <= viewportAnchor) {
          activeSection = section;
        }
      });

      setActiveDocumentationSection(activeSection);

      if (progress) {
        const first = visibleTrackSections[0].getBoundingClientRect();
        const last = visibleTrackSections[visibleTrackSections.length - 1].getBoundingClientRect();
        const total = Math.max(1, (last.bottom - first.top) - window.innerHeight);
        const read = Math.min(total, Math.max(0, viewportAnchor - first.top));
        progress.style.width = `${Math.round((read / total) * 100)}%`;
      }
    };

    const scheduleDocumentationTracker = () => {
      if (trackingFrame !== null) {
        return;
      }

      trackingFrame = window.requestAnimationFrame(updateDocumentationTracker);
    };

    const applySearch = () => {
      const query = (searchInput?.value || '').trim().toLowerCase();
      let visibleCount = 0;

      sections.forEach((section) => {
        const isVisible = query === '' || (section.dataset.documentationText || '').includes(query);
        section.hidden = !isVisible;

        if (isVisible) {
          visibleCount += 1;
        }
      });

      navLinks.forEach((link) => {
        const target = link.getAttribute('href') || '';
        const section = target ? docsRoot.querySelector(target) : null;
        link.hidden = section ? section.hidden : false;
      });

      if (count) {
        count.textContent = String(visibleCount);
      }

      if (status) {
        status.textContent = query === ''
          ? 'Showing important sections, department guides, and full feature guides.'
          : `${visibleCount} result${visibleCount === 1 ? '' : 's'} for "${query}".`;
      }

      if (empty) {
        empty.hidden = visibleCount !== 0;
      }

      scheduleDocumentationTracker();
    };

    if (searchInput instanceof HTMLInputElement) {
      searchInput.addEventListener('input', applySearch);
    }

    window.addEventListener('scroll', scheduleDocumentationTracker, { passive: true });
    window.addEventListener('resize', scheduleDocumentationTracker);

    applySearch();
  });
};

export const init = (root = document) => initDocumentationSearch(root);
