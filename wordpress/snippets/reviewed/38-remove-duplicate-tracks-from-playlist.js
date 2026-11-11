/**
 * Vice Dhrk — Enforce one-instance-only per track (UI + data level)
 * - Removes duplicates from the playlist DOM whenever they appear
 * - Attempts to sync/remove from internal queue when possible
 * - Debounces "Add to Tracklist" clicks to avoid double adds
 * - Resilient to PJAX/AJAX navigation
 */
(function () {
  'use strict';

  // ---- Config: selectors to try (theme updates sometimes rename classes) ----
  const SEL = {
    playlistRoot: [
      '.vice-playlist',       // common
      '#vice-playlist',
      '[data-vice-playlist]',
      '.player-playlist',
      '.vice__playlist'
    ],
    playlistItem: [
      '.vice-playlist-item',
      '.playlist-item',
      '[data-track-id]',
      '[data-track]',
      'li[class*="playlist"]'
    ],
    itemRemoveBtn: [
      '.vice-remove',
      '[data-action="remove"]',
      '[data-remove]',
      '.remove'
    ],
    addBtn: [
      '.vice-add-to-tracklist',
      '[data-add-to-tracklist]',
      '[data-action="add-to-tracklist"]'
    ]
  };

  // Simple helper to find the first existing selector
  function qsOne(root, list) {
    for (const sel of list) {
      const el = root.querySelector(sel);
      if (el) return el;
    }
    return null;
  }
  function qsAll(root, list) {
    for (const sel of list) {
      const els = root.querySelectorAll(sel);
      if (els && els.length) return els;
    }
    return [];
  }

  // Build a stable “key” for a DOM playlist item
  function keyFromItem(el) {
    if (!el) return '';
    const a = (s) => (el.getAttribute(s) || '').trim().toLowerCase();
    // Try the most reliable attributes first:
    return (
      a('data-id') ||
      a('data-track-id') ||
      a('data-track') ||
      a('data-src') ||
      a('data-url') ||
      a('data-permalink') ||
      (() => {
        // As a last resort, derive from visible title/artist text
        const t = (el.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        return t;
      })()
    );
  }

  // Attempt to remove the *data* duplicate as well (if API is available)
  function tryRemoveFromQueueByKey(key) {
    const vp = window.vicePlayer || window.VicePlayer || window.player || null;
    if (!vp) return;

    const list =
      vp.tracks || vp.queue || vp.playlist || (vp.state && vp.state.tracks);
    if (!Array.isArray(list)) return;

    // Build a similar “key” for tracks
    const tKey = (t) => {
      const artist =
        (t.artist || t.author || (t.meta && t.meta.artist) || '').toString().toLowerCase();
      const title  = (t.title || '').toString().toLowerCase();
      const first =
        (t.id || t.track_id || t.trackId || t.permalink || t.guid ||
         t.src || t.url || t.audio || '').toString().toLowerCase();
      return first || (title && (title + '|' + artist)) || title;
    };

    // Find duplicates past the first occurrence
    const seen = new Set();
    for (let i = 0; i < list.length; i++) {
      const k = tKey(list[i]);
      if (!k) continue;
      if (k === key) {
        if (seen.has(k)) {
          // Remove this duplicate item
          list.splice(i, 1);
          i--;
        } else {
          seen.add(k);
        }
      }
    }
  }

  // Remove duplicates in the DOM playlist (keep the first occurrence)
  function dedupePlaylistDOM() {
    // Find the playlist root
    const root =
      qsOne(document, SEL.playlistRoot) || document;
    const items = Array.from(qsAll(root, SEL.playlistItem));
    if (!items.length) return;

    const seen = new Set();
    for (const el of items) {
      const k = keyFromItem(el);
      if (!k) continue;

      if (seen.has(k)) {
        // Duplicate — remove via remove button if available
        const removeBtn = qsOne(el, SEL.itemRemoveBtn) || el.querySelector('button, [role="button"]');
        if (removeBtn) {
          removeBtn.click();
        } else {
          // Fallback: hard-remove from DOM
          el.parentNode && el.parentNode.removeChild(el);
        }
        // Also try to purge from data queue
        tryRemoveFromQueueByKey(k);
      } else {
        seen.add(k);
      }
    }
  }

  // Debounce “Add to Tracklist” to avoid double-click storms
  let addClickLock = false;
  function clickGuardInstall() {
    document.addEventListener('click', function (e) {
      const btn = e.target.closest(SEL.addBtn.join(','));
      if (!btn) return;

      if (addClickLock) {
        e.stopImmediatePropagation();
        e.preventDefault();
        return;
      }

      addClickLock = true;
      // Let the theme add, then de-dupe
      setTimeout(() => {
        dedupePlaylistDOM();
        addClickLock = false;
      }, 250);
    }, true);
  }

  // Observe playlist changes and de-dupe as soon as nodes are inserted
  function installPlaylistObserver() {
    const root = qsOne(document, SEL.playlistRoot) || document.body;
    if (!root) return;

    const obs = new MutationObserver((mutations) => {
      for (const m of mutations) {
        if (m.addedNodes && m.addedNodes.length) {
          // Any time something is added under the playlist, sweep
          dedupePlaylistDOM();
          break;
        }
      }
    });

    obs.observe(root, { childList: true, subtree: true });
  }

  // Run once at boot and also on PJAX/AJAX lifecycle events
  function boot() {
    clickGuardInstall();
    installPlaylistObserver();
    // First pass (in case playlist already has dupes)
    setTimeout(dedupePlaylistDOM, 150);
    // Periodic safety sweep
    setInterval(dedupePlaylistDOM, 2000);
  }

  // Handle dynamic nav
  ['DOMContentLoaded', 'pjax:end'].forEach(evt =>
    document.addEventListener(evt, () => setTimeout(boot, 100))
  );

  // If already interactive, start now
  if (document.readyState === 'interactive' || document.readyState === 'complete') {
    boot();
  }
})();
