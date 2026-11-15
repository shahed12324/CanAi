/* Hands-free voice for CANAI: wake phrase -> listen -> think -> speak.  Everything that decides anything is plain logic with injected adapters, so it is tested without a microphone.
   Privacy rules (enforced here, tested):
   - nothing listens until the person grants consent in this session (never remembered between visits);
   - while waiting for the wake phrase every transcript is dropped on the spot: it is not stored, not sent, not logged;
   - a visible "microphone is on" indicator callback fires whenever ANY listening is active;
   - local stop words ("stop", "থামো") stop speech and listening without any server call.
   Safety rule: speech is untrusted input.  Whatever the person says arrives as {source:'voice'} and can NEVER count as an approval of anything. */
(function (root) {
  'use strict';

  var VOICE_CAN_APPROVE = false;
  var DEFAULT_WAKE = ['hey canai', 'hey can ai', 'hey kenai', 'ok canai', 'হে ক্যানএআই', 'হে ক্যান এআই', 'ওকে ক্যানএআই', 'ওকে ক্যান এআই'];
  var STOP_WORDS = ['stop', 'cancel', 'be quiet', 'থামো', 'থামুন', 'বন্ধ করো', 'চুপ'];

  function normalize(s) {
    s = String(s == null ? '' : s).toLowerCase();
    s = s.replace(/[‌‍]/g, '');
    s = s.replace(/[.,!?;:"'`()\[\]{}।‘’“”\-_]+/g, ' ');
    return s.replace(/\s+/g, ' ').trim();
  }

  function lev(a, b) {
    if (a === b) { return 0; }
    var m = a.length, n = b.length, prev = [], cur = [], i, j;
    if (!m) { return n; }
    if (!n) { return m; }
    for (j = 0; j <= n; j++) { prev[j] = j; }
    for (i = 1; i <= m; i++) {
      cur[0] = i;
      for (j = 1; j <= n; j++) { cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1)); }
      prev = cur.slice();
    }
    return prev[n];
  }

  /** Does the transcript START with a wake phrase (allowing one slip in longer words)?  -> {matched, rest} where rest is what was said after it. */
  function matchWake(text, phrases) {
    var words = normalize(text).split(' ').filter(Boolean);
    phrases = phrases || DEFAULT_WAKE;
    for (var p = 0; p < phrases.length; p++) {
      var pw = normalize(phrases[p]).split(' ').filter(Boolean);
      if (!pw.length || words.length < pw.length) { continue; }
      // the phrase may be at the very start, or after one filler word ("um", "hmm")
      for (var off = 0; off <= Math.min(1, words.length - pw.length); off++) {
        var ok = true;
        for (var k = 0; k < pw.length && ok; k++) {
          var w = words[off + k], t = pw[k];
          ok = w === t || (t.length >= 5 && lev(w, t) <= 1);
        }
        if (ok) { return { matched: true, rest: words.slice(off + pw.length).join(' ') }; }
      }
    }
    return { matched: false, rest: '' };
  }

  function isStopWord(text) {
    var t = normalize(text);
    for (var i = 0; i < STOP_WORDS.length; i++) { if (t === STOP_WORDS[i]) { return true; } }
    return false;
  }

  /** Decides when an utterance starts and ends from a stream of loudness samples (rms 0..1).  feed(rms, nowMs) -> null | 'speech_start' | 'end' | 'no_speech' | 'too_long'. */
  function Endpointer(o) {
    o = o || {};
    var thr = o.threshold || 0.04, minSpeech = o.minSpeechMs || 150, silence = o.silenceMs || 1200, noSpeech = o.noSpeechMs || 6000, maxMs = o.maxMs || 30000;
    var t0 = null, speechSince = null, speaking = false, lastLoud = null, done = false;
    return {
      feed: function (rms, now) {
        if (done) { return null; }
        if (t0 === null) { t0 = now; }
        var loud = rms >= thr;
        if (loud) { if (speechSince === null) { speechSince = now; } lastLoud = now; } else { speechSince = speaking ? speechSince : null; }
        if (!speaking && loud && speechSince !== null && now - speechSince >= minSpeech) { speaking = true; return 'speech_start'; }
        if (!speaking && now - t0 >= noSpeech) { done = true; return 'no_speech'; }
        if (speaking && now - t0 >= maxMs) { done = true; return 'too_long'; }
        if (speaking && lastLoud !== null && now - lastLoud >= silence) { done = true; return 'end'; }
        return null;
      },
      start: function (now) { t0 = now; },
      speaking: function () { return speaking; }
    };
  }

  /** What the avatar should show for each state (expression + a mouth hint).  The real mouth shapes come from the audio during 'speaking'. */
  function avatarFor(state) {
    return ({
      off: { expression: 'neutral', mouth: 'closed' },
      armed: { expression: 'neutral', mouth: 'closed' },
      listening: { expression: 'happy', mouth: 'closed' },
      thinking: { expression: 'neutral', mouth: 'closed' },
      speaking: { expression: 'happy', mouth: 'open' },
      error: { expression: 'sad', mouth: 'closed' }
    })[state] || { expression: 'neutral', mouth: 'closed' };
  }

  /**
   * adapters:
   *   wake      { start(onText), stop() }          continuous recognition of short phrases; onText(text) for each transcript (final or interim)
   *   capture   { start(), stop() -> Promise<blob> }   records ONE utterance after the wake phrase; level() -> rms 0..1
   *   speaker   { speak(text) -> Promise, cancel() }    reads the answer aloud
   * hooks:
   *   deliver(utterance)  utterance = {source:'voice', text?, blob?, via:'wake'}  -> Promise<answerText|null>
   *   onState(state), onIndicator(micOn)
   */
  function VoiceSession(adapters, hooks, opts) {
    opts = opts || {};
    var now = opts.now || function () { return Date.now(); };
    var state = 'off', consent = false, micOn = false, ep = null, tick = null, busy = false;
    var wakePhrases = opts.wake || DEFAULT_WAKE;
    var cooldownUntil = 0, cooldownMs = opts.cooldownMs || 1500;
    var setTimer = opts.setInterval || root.setInterval, clearTimer = opts.clearInterval || root.clearInterval;

    function setState(s) { if (state !== s) { state = s; if (hooks.onState) { hooks.onState(s); } } }
    function indicator(on) { if (micOn !== on) { micOn = on; if (hooks.onIndicator) { hooks.onIndicator(on); } } }
    function stopTick() { if (tick !== null) { clearTimer(tick); tick = null; } }

    function armed() { setState('armed'); indicator(true); adapters.wake.start(onWakeText); }

    function onWakeText(text) {
      if (!consent || state !== 'armed' && state !== 'speaking') { return; }          // anything else is dropped unseen
      if (state === 'speaking') {                                                       // barge-in: a stop word, or the wake phrase, cuts the speech
        if (isStopWord(text)) { adapters.speaker.cancel(); armed(); }
        else if (matchWake(text, wakePhrases).matched) { adapters.speaker.cancel(); startListening(matchWake(text, wakePhrases).rest); }
        return;
      }
      if (now() < cooldownUntil) { return; }
      var m = matchWake(text, wakePhrases);
      if (!m.matched) { return; }                                                       // not for us: dropped, never stored or sent
      startListening(m.rest);
    }

    function startListening(inlineText) {
      adapters.wake.stop();
      cooldownUntil = now() + cooldownMs;
      if (inlineText && inlineText.length >= 2) { finish({ source: 'voice', via: 'wake', text: inlineText }); return; }   // "hey canai what's the time" in one breath
      setState('listening');
      ep = Endpointer(opts.endpoint);
      ep.start(now());
      adapters.capture.start();
      tick = setTimer(function () {
        var r = ep.feed(adapters.capture.level(), now());
        if (r === 'no_speech') { stopTick(); adapters.capture.stop().then(function () { if (consent) { armed(); } }, function () { if (consent) { armed(); } }); }
        else if (r === 'end' || r === 'too_long') {
          stopTick();
          adapters.capture.stop().then(function (blob) { finish({ source: 'voice', via: 'wake', blob: blob }); }, function () { fail(); });
        }
      }, opts.tickMs || 100);
    }

    function fail() { setState('error'); if (consent) { setTimeout(function () { if (consent) { armed(); } }, 0); } }

    function finish(utterance) {
      if (busy) { return; }
      busy = true;
      indicator(false);                      // the mic is closed while the answer is worked out
      setState('thinking');
      Promise.resolve().then(function () { return hooks.deliver(utterance); }).then(function (answer) {
        busy = false;
        if (!consent) { return; }
        if (answer && opts.speak !== false) {
          setState('speaking'); indicator(true); adapters.wake.start(onWakeText);   // keep listening for stop / barge-in while talking
          return adapters.speaker.speak(answer).then(function () { if (consent && state === 'speaking') { adapters.wake.stop(); armed(); } });
        }
        armed();
      }).catch(function () { busy = false; if (consent) { fail(); } });
    }

    return {
      /** Consent must be given on every visit; there is deliberately no way to start without it. */
      grant: function () { consent = true; armed(); },
      revoke: function () { consent = false; this.dispose(); },
      dispose: function () {
        consent = false; stopTick(); busy = false;
        try { adapters.wake.stop(); } catch (e) { /* ignore */ }
        try { adapters.speaker.cancel(); } catch (e) { /* ignore */ }
        try { adapters.capture.stop().catch(function () {}); } catch (e) { /* ignore */ }
        indicator(false); setState('off');
      },
      /** Panic button / emergency stop: silence everything now. */
      stopAll: function () { this.dispose(); },
      state: function () { return state; },
      micOn: function () { return micOn; }
    };
  }

  var api = { VOICE_CAN_APPROVE: VOICE_CAN_APPROVE, DEFAULT_WAKE: DEFAULT_WAKE, normalize: normalize, matchWake: matchWake, isStopWord: isStopWord, Endpointer: Endpointer, avatarFor: avatarFor, VoiceSession: VoiceSession, lev: lev };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { root.VoiceSession = api; }
})(this);
