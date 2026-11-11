(function() {
  // Map localized playlist into generic QT structure immediately for builds that read it on init
  if (window.VICE_AGG_PLAYLIST && Array.isArray(window.VICE_AGG_PLAYLIST.tracks) && window.VICE_AGG_PLAYLIST.tracks.length) {
    window.QT_DEFAULT_PLAYLIST = window.VICE_AGG_PLAYLIST.tracks.map(function(t){
      return {
        title: t.title || '',
        mp3: t.mp3 || '',
        link: t.link || t.release_url || '',
        meta: { album: t.release || '' }
      };
    });
  }

  function tryReplace(player){
    var src = (window.VICE_AGG_PLAYLIST && window.VICE_AGG_PLAYLIST.tracks) || [];
    if (!src.length) return false;
    var merged = src.map(function(t){
      return {
        title: t.title || '',
        mp3: t.mp3 || '',
        link: t.link || t.release_url || '',
        meta: { album: t.release || '' }
      };
    });
    try {
      if (typeof player.replacePlaylist === 'function') {
        player.replacePlaylist(merged);
      } else if (typeof player.setPlaylist === 'function') {
        player.setPlaylist(merged);
      } else {
        window.QT_DEFAULT_PLAYLIST = merged;
      }
      if (typeof player.load === 'function') player.load();
      return true;
    } catch (e) {
      console.warn('Vice Aggregate: replacePlaylist error', e);
      return false;
    }
  }

  function whenReady(cb, tries){
    tries = typeof tries === 'number' ? tries : 160;
    if (window.qtPlayer && (typeof window.qtPlayer.replacePlaylist === 'function' || typeof window.qtPlayer.setPlaylist === 'function')) return cb(window.qtPlayer);
    if (window.VICE && VICE.player && (typeof VICE.player.replacePlaylist === 'function' || typeof VICE.player.setPlaylist === 'function')) return cb(VICE.player);
    if (window.qtmplayer && (typeof window.qtmplayer.replacePlaylist === 'function' || typeof window.qtmplayer.setPlaylist === 'function')) return cb(window.qtmplayer);
    if (window.QTPlayer && (typeof window.QTPlayer.replacePlaylist === 'function' || typeof window.QTPlayer.setPlaylist === 'function')) return cb(window.QTPlayer);
    if (tries > 0) return setTimeout(function(){ whenReady(cb, tries-1); }, 250);
  }

  whenReady(function(player){
    if (!tryReplace(player)) {
      if (window.QT_DEFAULT_PLAYLIST) {
        var ev;
        try { ev = new CustomEvent('qt:replacePlaylist', { detail: window.QT_DEFAULT_PLAYLIST }); }
        catch(e){ ev = document.createEvent('CustomEvent'); ev.initCustomEvent('qt:replacePlaylist', true, true, window.QT_DEFAULT_PLAYLIST); }
        window.dispatchEvent(ev);
      }
    }
  });
})();