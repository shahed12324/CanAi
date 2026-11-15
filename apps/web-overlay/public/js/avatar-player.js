/* Plays an avatar track: swaps the picture's expression / mouth at the times in `timeline` ([{t, m}], ms).  No audio here: pass `onTick` to sync with real speech later. */
(function (root) {
  'use strict';
  /** Mouth shape at time t (ms): the last cue whose time has passed; 'closed' before the first and after the end. */
  function cueAt(timeline, t) {
    var m = 'closed';
    if (!Array.isArray(timeline)) { return m; }
    for (var i = 0; i < timeline.length; i++) {
      if (timeline[i].t <= t) { m = timeline[i].m; } else { break; }
    }
    return m;
  }
  function url(base, expression, mouth) {
    return base + '?expression=' + encodeURIComponent(expression) + '&mouth=' + encodeURIComponent(mouth);
  }
  /** play(img, baseUrl, {expression, duration_ms, timeline}) -> stop().  Resets to a calm face at the end. */
  function play(img, base, cues) {
    var start = Date.now(), last = null, stopped = false, timer = null;
    function step() {
      if (stopped) { return; }
      var t = Date.now() - start, over = t >= cues.duration_ms;
      var m = over ? 'closed' : cueAt(cues.timeline, t);
      if (m !== last) { last = m; img.src = url(base, cues.expression, m); }
      if (over) { stopped = true; return; }
      timer = setTimeout(step, 40);
    }
    step();
    return function stop() { stopped = true; if (timer) { clearTimeout(timer); } img.src = url(base, 'neutral', 'closed'); };
  }
  /** Same as play(), but the clock is the AUDIO's own position (seconds -> ms), so the mouth follows what is really heard.  Stops when the audio ends or fails. */
  function playSynced(img, base, cues, audio) {
    var stopped = false, timer = null, last = null;
    function finish() {
      if (stopped) { return; }
      stopped = true;
      if (timer) { clearTimeout(timer); }
      img.src = url(base, 'neutral', 'closed');
    }
    function step() {
      if (stopped) { return; }
      if (audio.ended || audio.error) { finish(); return; }
      var m = audio.paused ? 'closed' : cueAt(cues.timeline, Math.round(audio.currentTime * 1000));
      if (m !== last) { last = m; img.src = url(base, cues.expression, m); }
      timer = setTimeout(step, 40);
    }
    var p = audio.play();
    if (p && typeof p.catch === 'function') { p.catch(finish); }
    step();
    return function stop() { if (!stopped) { try { audio.pause(); } catch (e) { /* ignore */ } } finish(); };
  }
  var api = { cueAt: cueAt, url: url, play: play, playSynced: playSynced };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { root.AvatarPlayer = api; }
})(this);
