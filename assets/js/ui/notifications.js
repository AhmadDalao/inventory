import { escapeHtml, formatDateTimeCopy } from '../core/runtime.js';

const notificationSoundStorageKey = 'inventory-notification-sound-enabled';
let notificationAudioUnlocked = false;
let notificationAudioContext = null;
let notificationSoundEnabled = window.localStorage.getItem(notificationSoundStorageKey) !== '0';
let lastKnownNotificationCount = Number.parseInt(document.querySelector('[data-notification-badge]')?.textContent || '0', 10) || 0;

export const setNotificationSoundPreference = (enabled) => {
  notificationSoundEnabled = Boolean(enabled);
  window.localStorage.setItem(notificationSoundStorageKey, notificationSoundEnabled ? '1' : '0');
  document.querySelectorAll('[data-notification-sound-toggle]').forEach((button) => {
    button.textContent = notificationSoundEnabled ? 'Sound On' : 'Sound Off';
    button.setAttribute('aria-pressed', notificationSoundEnabled ? 'true' : 'false');
    button.classList.toggle('is-muted', !notificationSoundEnabled);
  });
};

export const unlockNotificationAudio = () => {
  notificationAudioUnlocked = true;
};

export const playNotificationSound = (force = false) => {
  const AudioContextClass = window.AudioContext || window.webkitAudioContext;

  if ((!notificationSoundEnabled && !force) || (!notificationAudioUnlocked && !force) || !AudioContextClass) {
    return;
  }

  try {
    if (!notificationAudioContext) {
      notificationAudioContext = new AudioContextClass();
    }

    const context = notificationAudioContext;

    if (context.state === 'suspended') {
      context.resume();
    }

    const oscillator = context.createOscillator();
    const gain = context.createGain();

    oscillator.type = 'triangle';
    oscillator.frequency.setValueAtTime(880, context.currentTime);
    oscillator.frequency.exponentialRampToValueAtTime(1174, context.currentTime + 0.08);
    gain.gain.setValueAtTime(0.0001, context.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.06, context.currentTime + 0.025);
    gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.28);

    oscillator.connect(gain);
    gain.connect(context.destination);
    oscillator.start();
    oscillator.stop(context.currentTime + 0.3);
  } catch (error) {
    // Ignore browser audio failures.
  }
};

export const notificationToastContainer = () => {
  let container = document.querySelector('[data-notification-toast-container]');

  if (!container) {
    container = document.createElement('section');
    container.className = 'notification-toast-stack';
    container.setAttribute('data-notification-toast-container', '');
    container.setAttribute('aria-live', 'polite');
    container.setAttribute('aria-label', 'New notifications');
    document.body.appendChild(container);
  }

  return container;
};

export const showNotificationToast = (item) => {
  if (!item || !item.title) {
    return;
  }

  const container = notificationToastContainer();
  const toast = document.createElement('article');
  const actorCopy = item.actor_name ? `<span class="tiny-copy">By ${escapeHtml(item.actor_name)}</span>` : '';
  const messageCopy = item.message ? `<p>${escapeHtml(item.message)}</p>` : '';
  const actionLink = item.action_url
    ? `<a class="notification-toast-link" href="${escapeHtml(item.action_url)}">Open</a>`
    : '';

  toast.className = 'notification-toast';
  toast.innerHTML = `
    <div>
      <span class="eyebrow">New notification</span>
      <strong>${escapeHtml(item.title)}</strong>
      ${actorCopy}
      ${messageCopy}
    </div>
    <div class="notification-toast-actions">
      ${actionLink}
      <button class="notification-toast-close" type="button" aria-label="Close notification popup">&times;</button>
    </div>
  `;

  const closeToast = () => {
    toast.classList.add('is-closing');
    window.setTimeout(() => toast.remove(), 180);
  };

  toast.querySelector('.notification-toast-close')?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    closeToast();
  });

  container.prepend(toast);
  window.setTimeout(closeToast, 8000);
};

export const initNotificationFeed = () => {
  const feed = document.querySelector('[data-notification-feed]');

  if (!feed || feed.dataset.jsBound === 'true') {
    return;
  }

  const knownNotificationIds = new Set(
    Array.from(feed.querySelectorAll('[data-notification-id]'))
      .map((row) => row.getAttribute('data-notification-id') || '')
      .filter((id) => id !== '')
  );
  const soundToggle = feed.querySelector('[data-notification-sound-toggle]');
  const soundTest = feed.querySelector('[data-notification-sound-test]');

  if (soundToggle instanceof HTMLButtonElement) {
    setNotificationSoundPreference(notificationSoundEnabled);
    soundToggle.addEventListener('click', () => {
      unlockNotificationAudio();
      setNotificationSoundPreference(!notificationSoundEnabled);

      if (notificationSoundEnabled) {
        playNotificationSound(true);
      }
    });
  }

  if (soundTest instanceof HTMLButtonElement) {
    soundTest.addEventListener('click', () => {
      unlockNotificationAudio();
      playNotificationSound(true);
    });
  }

  const renderNotificationItem = (item) => {
    const actorCopy = item.actor_name ? `<span class="tiny-copy">By ${escapeHtml(item.actor_name)}</span>` : '';
    const messageCopy = item.message ? `<p>${escapeHtml(item.message)}</p>` : '';
    const badge = item.read_at ? '' : '<span class="notification-status-dot" aria-label="Unread notification"></span>';
    const createdAtCopy = escapeHtml(item.created_at_display || formatDateTimeCopy(item.created_at) || 'Just now');
    const notificationId = item.id ? ` data-notification-id="${escapeHtml(item.id)}"` : '';

    return `
      <a class="notification-row" href="${escapeHtml(item.action_url || '#')}"${notificationId}>
        <div class="notification-row-copy">
          <strong>${escapeHtml(item.title || '')}</strong>
          ${actorCopy}
          ${messageCopy}
          <span class="tiny-copy">${createdAtCopy}</span>
        </div>
        ${badge}
      </a>
    `;
  };

  const refreshNotificationFeed = async (silent = false) => {
    const feedUrl = feed.dataset.feedUrl;

    if (!feedUrl) {
      return;
    }

    try {
      const response = await fetch(feedUrl, {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      if (!response.ok) {
        throw new Error(`Notification feed failed: ${response.status}`);
      }

      const payload = await response.json();
      const unreadCount = Number.parseInt(payload.unread_count, 10) || 0;
      const badge = feed.querySelector('[data-notification-badge]');
      let itemsWrapper = feed.querySelector('[data-notification-items]');
      let emptyState = feed.querySelector('[data-notification-empty]');

      if (!itemsWrapper && payload.items && payload.items.length > 0) {
        itemsWrapper = document.createElement('div');
        itemsWrapper.setAttribute('data-notification-items', '');
        const panel = feed.querySelector('[data-notification-panel]');
        const footer = panel?.querySelector('.notification-panel-footer');

        if (footer) {
          panel?.insertBefore(itemsWrapper, footer);
        } else {
          panel?.appendChild(itemsWrapper);
        }
      }

      if (itemsWrapper) {
        itemsWrapper.innerHTML = (payload.items || []).map(renderNotificationItem).join('');
        itemsWrapper.hidden = (payload.items || []).length === 0;
      }

      if (emptyState) {
        emptyState.hidden = (payload.items || []).length > 0;
      } else if ((payload.items || []).length === 0) {
        emptyState = document.createElement('p');
        emptyState.className = 'empty-state';
        emptyState.setAttribute('data-notification-empty', '');
        emptyState.textContent = 'No notifications yet.';
        const panel = feed.querySelector('[data-notification-panel]');
        const footer = panel?.querySelector('.notification-panel-footer');

        if (footer) {
          panel?.insertBefore(emptyState, footer);
        } else {
          panel?.appendChild(emptyState);
        }
      }

      if (badge) {
        badge.textContent = unreadCount > 0 ? String(unreadCount) : '';
        badge.hidden = unreadCount === 0;
      } else if (unreadCount > 0) {
        const summary = feed.querySelector('.notification-toggle');

        if (summary) {
          const nextBadge = document.createElement('span');
          nextBadge.className = 'notification-badge';
          nextBadge.setAttribute('data-notification-badge', '');
          nextBadge.textContent = String(unreadCount);
          summary.appendChild(nextBadge);
        }
      }

      if (!silent && unreadCount > lastKnownNotificationCount) {
        playNotificationSound();

        (payload.items || [])
          .filter((item) => item && !item.read_at && item.id && !knownNotificationIds.has(String(item.id)))
          .slice(0, Math.max(1, unreadCount - lastKnownNotificationCount))
          .reverse()
          .forEach(showNotificationToast);
      }

      (payload.items || []).forEach((item) => {
        if (item && item.id) {
          knownNotificationIds.add(String(item.id));
        }
      });

      lastKnownNotificationCount = unreadCount;
    } catch (error) {
      // Ignore notification refresh failures.
    }
  };

  feed.dataset.jsBound = 'true';

  ['click', 'keydown', 'touchstart'].forEach((eventName) => {
    document.addEventListener(eventName, () => {
      unlockNotificationAudio();
    }, { once: true });
  });

  feed.addEventListener('toggle', () => {
    if (feed.open) {
      refreshNotificationFeed(false);
    }
  });

  document.addEventListener('inventory:action-complete', () => {
    refreshNotificationFeed(false);
  });

  window.setInterval(() => {
    if (document.visibilityState === 'visible') {
      refreshNotificationFeed(false);
    }
  }, 25000);
};

export const init = () => initNotificationFeed();
