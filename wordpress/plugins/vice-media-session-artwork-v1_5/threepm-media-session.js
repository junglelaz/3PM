(function () {
  'use strict';
  if (!('mediaSession' in navigator) || typeof MediaMetadata !== 'function' || window.THREEPM_MEDIASESSION_READY) return;
  window.THREEPM_MEDIASESSION_READY = true;
  var debug = false;
  try { debug = localStorage.getItem('THREEPM_MEDIASESSION_DEBUG') === '1'; } catch (error) {}
  var vars = window.THREEPM_MEDIASESSION_VARS || {}, lastSignature = '', timer = 0, observedPlayer;
  var boundAudio = new WeakSet(), boundFrames = new WeakSet();
  function text(root, selector) { var el = root.querySelector(selector); return el ? el.textContent.trim() : ''; }
  function meta(root, key) { var el = root.querySelector('meta[property="og:' + key + '"]'); return el ? el.getAttribute('content') || '' : ''; }
  function safeArtwork(url) {
    if (!url || typeof url !== 'string') return [];
    try {
      var parsed = new URL(url, document.baseURI);
      if (!/^https?:$/.test(parsed.protocol)) return [];
      var extension = parsed.pathname.split('.').pop().toLowerCase();
      var types = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif' };
      var result = { src: parsed.href }; if (types[extension]) result.type = types[extension];
      return [result];
    } catch (error) { return []; }
  }
  function extract(root) {
    root = root || document;
    var player = root.querySelector('#QWplayerbar,.vice-global-player,.vice-player,.qt-now-playing,.qt-player,.qtmplayer') || root;
    var cover = player.querySelector('#qwPlayerCover img,.track-cover img,[data-track-cover]');
    var image = cover && (cover.currentSrc || cover.src || cover.getAttribute('data-track-cover'));
    return {
      title: text(player, '#qwPlayerTitle,.qt-track-title,.vice-track-title,.player-track-title,.track-title,[data-track-title]') || meta(root, 'title') || root.title || document.title || '3PM',
      artist: text(player, '#qwPlayerAuthor,.qt-track-author,.vice-track-artist,.player-track-artist,.track-artist,[data-track-artist]') || vars.siteName || '3PM',
      album: text(player, '.qt-track-album,.vice-track-album,.track-album,[data-track-album]') || '3PM Release',
      // Retain the site's intentional fixed-artwork override when configured.
      artwork: safeArtwork(vars.overrideArtwork || image || meta(root, 'image') || vars.fallbackArtwork)
    };
  }
  function update(explicit, root) {
    var value = extract(root), input = explicit && typeof explicit === 'object' ? explicit : {};
    ['title', 'artist', 'album'].forEach(function (key) { if (typeof input[key] === 'string' && input[key].trim()) value[key] = input[key].trim(); });
    if (Array.isArray(input.artwork) && input.artwork.length) value.artwork = input.artwork.flatMap(function (entry) { return safeArtwork(entry && entry.src); });
    var signature = JSON.stringify(value);
    if (signature === lastSignature) return;
    try { navigator.mediaSession.metadata = new MediaMetadata(value); lastSignature = signature; }
    catch (error) { if (debug) console.debug('[3PM MediaSession]', error); }
  }
  function schedule() { if (timer) return; timer = setTimeout(function () { timer = 0; update(); }, 80); }
  function bindAudio(root) {
    root.querySelectorAll('audio').forEach(function (audio) {
      if (boundAudio.has(audio)) return; boundAudio.add(audio);
      ['play', 'loadedmetadata'].forEach(function (name) { audio.addEventListener(name, function () { update(null, root); }, { passive: true }); });
    });
  }
  var playerObserver = new MutationObserver(schedule);
  function discover() {
    var player = document.querySelector('#QWplayerbar');
    if (player && player !== observedPlayer) {
      playerObserver.disconnect(); observedPlayer = player;
      playerObserver.observe(player, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['src', 'data-state', 'data-mp3'] });
    }
    bindAudio(document);
    document.querySelectorAll('iframe').forEach(function (frame) {
      if (boundFrames.has(frame)) return; boundFrames.add(frame);
      function bindFrame() { try { if (frame.contentDocument) bindAudio(frame.contentDocument); } catch (error) {} }
      frame.addEventListener('load', bindFrame); bindFrame();
    });
    schedule();
  }
  var documentObserver = new MutationObserver(function (records) {
    if (records.some(function (record) { return Array.from(record.addedNodes).some(function (node) { return node.nodeType === 1 && (node.matches('audio,iframe,#QWplayerbar') || node.querySelector('audio,iframe,#QWplayerbar')); }); })) discover();
  });
  function start() { discover(); documentObserver.observe(document.documentElement, { childList: true, subtree: true }); update(); }
  document.addEventListener('click', function (event) { if (event.target.closest('#qwPlayerPlay,#qwPlayerNext,#qwPlayerPrev,[data-playtrack],[data-addtoplaylyst]')) schedule(); }, true);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) schedule(); });
  window.THREEPM_MEDIASESSION_SET = function (value) { update(value); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
