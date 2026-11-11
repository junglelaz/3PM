(() => {
  'use strict';
  if (window.threepmAudioTracking) return;
  window.threepmAudioTracking = true;
  const sessions = new WeakMap();
  const send = async (action, values) => {
    const body = new FormData();
    body.set('action', action);
    Object.entries(values).forEach(([key, value]) => body.set(key, value));
    const response = await fetch(window.threepmAudioEndpoint, {method: 'POST', credentials: 'same-origin', body});
    const result = await response.json();
    if (!result.success) throw new Error(result.data?.message || 'Measurement unavailable');
    return result.data;
  };
  document.addEventListener('playing', async event => {
    const audio = event.target;
    if (!(audio instanceof HTMLMediaElement)) return;
    const source = audio.currentSrc || audio.src;
    let session = sessions.get(audio);
    if (session?.source === source) return;
    session = {source, elapsed: 0, last: performance.now(), token: null, finished: false};
    sessions.set(audio, session);
    try {
      const response = await send('threepm_audio_begin', {source});
      session.token = response.token;
      session.finished = !!response.excluded;
      session.last = performance.now();
    } catch (_) { session.finished = true; }
  }, true);
  setInterval(() => {
    document.querySelectorAll('audio,video').forEach(async audio => {
      const session = sessions.get(audio);
      if (!session || session.finished || !session.token) return;
      const now = performance.now(), delta = now - session.last;
      session.last = now;
      if (audio.paused || audio.ended || audio.seeking || audio.readyState < 3 || audio.currentSrc !== session.source) return;
      // Long timer gaps, seeks and buffered stalls do not count as listening.
      if (delta > 0 && delta < 2500) session.elapsed += delta;
      if (session.elapsed < 31000 || session.sending) return;
      session.sending = true;
      try { await send('threepm_audio_complete', {token: session.token}); session.finished = true; }
      catch (_) { session.failures = (session.failures || 0) + 1; if (session.failures >= 3) session.finished = true; }
      finally { session.sending = false; }
    });
  }, 1000);
})();
