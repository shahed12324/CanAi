/* Desktop voice: push-to-talk, click-to-talk with silence detection, speaking replies, hands-free wake phrase (only with consent).
   The decisions (wake phrase, stop words, end-pointing, barge-in, "speech can never approve anything") live in voice-session.js, shared with the website and tested there.
   Providers: "browser" = Web Speech (in Chrome/Edge the audio goes to the browser vendor's speech service); "cloud" = your server's voice endpoint (uses credits).
   Nothing opens the microphone by itself: PTT / the mic button / the hands-free switch (with its consent box, asked on every visit) are the only ways in. */
(function (root) {
  'use strict';
  /* ---------- pure helpers (exported for node tests) ---------- */
  function detectLang(text) {                                         // "bn-BD" when at least a third of the letters are Bengali
    var letters = String(text || '').match(/[A-Za-zঀ-৿]/g) || [], bn = letters.filter(function (c) { return c >= 'ঀ' && c <= '৿'; }).length;
    return letters.length && bn / letters.length >= 0.33 ? 'bn-BD' : 'en-US';
  }
  function splitSentences(text, max) {                                // sentence-sized chunks so speech can start before the whole reply is read out
    max = max || 220; var out = [], parts = String(text || '').replace(/```[\s\S]*?```/g, ' ').replace(/[*`#_>]+/g, '').split(/(?<=[.!?।\n])\s+/);
    parts.forEach(function (p) {
      p = p.trim(); if (!p) return;
      while (p.length > max) { var cut = p.lastIndexOf(' ', max); if (cut < 40) cut = max; out.push(p.slice(0, cut).trim()); p = p.slice(cut).trim(); }
      if (p) out.push(p);
    });
    return out;
  }
  function pttMatches(e, key) {
    if (key === 'Space') return e.code === 'Space';
    if (key === 'Control') return e.key === 'Control';
    return e.key === key;
  }
  function pttAllowed(target, key) {                                 // never steal keys from someone who is typing (F-keys excepted)
    var typing = target && (/^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName) || target.isContentEditable);
    return !typing || /^F\d+$/.test(key);
  }
  function micMessage(err, en) {
    var n = err && (err.name || err.error || err);
    var m = {
      NotAllowedError: ['Microphone permission was denied — allow it from the lock icon in the address bar'],
      'not-allowed': null, SecurityError: null, NotFoundError: ['No microphone was found'], NotReadableError: ['The microphone is in use by another app'],
      OverconstrainedError: ['The chosen microphone is gone — pick another in settings'], 'no-speech': ['Nothing was heard'],
      network: ['The speech service could not be reached — try cloud mode'], 'audio-capture': ['No audio from the microphone']
    };
    var e = m[n] === null || (n === 'not-allowed' || n === 'service-not-allowed' || n === 'SecurityError') ? m.NotAllowedError : m[n];
    return e ? e[0] : 'Voice error: ' + (n || '?');
  }
  function srBrokenError(code) { return code === 'network' || code === 'service-not-allowed' || code === 'not-allowed' || code === 'language-not-supported'; }  // engine exists but cannot work (typical in WebView2)
  function diagnose(caps, prefs, info) {                              // plain-text report the user can copy; no audio, no personal data
    info = info || {}; var plan = sttPlan(prefs || {}, caps), L = [];
    L.push('secure context: ' + (info.secure === undefined ? '?' : info.secure));
    L.push('speech recognition object: ' + (caps.sr ? 'present' : 'missing') + (caps.srBroken ? ' (failed earlier in this session -> not used)' : ''));
    L.push('audio recorder (getUserMedia+MediaRecorder): ' + (caps.recorder ? 'present' : 'missing'));
    L.push('speech synthesis: ' + (caps.tts ? 'present' : 'missing'));
    L.push('microphone permission: ' + (info.permission || 'unknown'));
    L.push('audio inputs seen: ' + (info.inputs === undefined ? '?' : info.inputs));
    L.push('chosen plan: ' + plan.mode + (plan.fallback ? ' (cloud fallback: uses credits)' : ''));
    if (info.lastError) L.push('last error: ' + info.lastError);
    L.push('user agent: ' + (info.ua || '?'));
    return L.join('\n');
  }
  function sttPlan(prefs, caps) {                                    // -> {mode:'browser'|'cloud'|'none', fallback:boolean}
    var want = prefs.stt_provider === 'cloud' ? 'cloud' : 'browser';
    if (want === 'cloud') return caps.recorder ? { mode: 'cloud', fallback: false } : { mode: 'none', fallback: false };
    if (caps.sr && !caps.srBroken) return { mode: 'browser', fallback: false };
    return caps.recorder ? { mode: 'cloud', fallback: true } : { mode: 'none', fallback: false };
  }
  function pickVoice(voices, name, lang) {
    voices = voices || []; var base = String(lang || '').slice(0, 2).toLowerCase();
    if (name) { var named = voices.filter(function (v) { return v.name === name && String(v.lang).slice(0, 2).toLowerCase() === base; })[0]; if (named) return named; }
    return voices.filter(function (v) { return String(v.lang).toLowerCase() === String(lang).toLowerCase(); })[0] || voices.filter(function (v) { return String(v.lang).slice(0, 2).toLowerCase() === base; })[0] || null;
  }
  var pure = { detectLang: detectLang, splitSentences: splitSentences, pttMatches: pttMatches, pttAllowed: pttAllowed, micMessage: micMessage, sttPlan: sttPlan, srBrokenError: srBrokenError, diagnose: diagnose, pickVoice: pickVoice };
  if (typeof module !== 'undefined' && module.exports) { module.exports = pure; return; }
  if (!root.document) return;

  /* ---------- runtime ---------- */
  var SR = root.SpeechRecognition || root.webkitSpeechRecognition;
  var VS = root.VoiceSession;
  var muted = false, micState = 'unknown', micOn = false, level = 0, busy = false, speaking = false, queue = [], gen = 0, session = null, handsFree = false, lastErr = '';
  var srBroken = false, cloudNoticed = false;
  var active = null;                                                  // {stream, ctx, an, buf, mr, chunks, rec, timer, text, ep}
  function C() { return root.CANAI; }
  function prefs() { return (C() && C().state() && C().state().prefs) || {}; }
  function en() { return C() && C().lang() === 'en'; }
  function say(text, bad) { if (C()) C().msg(text, bad); }
  function emit(n, d) { root.dispatchEvent(new CustomEvent(n, { detail: d })); }
  function setMic(on) { micOn = on; if (C() && C().refreshShell) C().refreshShell(); }
  function caps() { return { sr: !!SR, srBroken: srBroken, recorder: !!(root.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia), tts: !!root.speechSynthesis }; }
  function sttLang() { var p = prefs(); return p.voice_lang && p.voice_lang !== 'auto' ? p.voice_lang : ('en-US'); }

  function stream() {
    var p = prefs(), a = { echoCancellation: true, noiseSuppression: true };
    if (p.mic_device) a.deviceId = { exact: p.mic_device };
    return navigator.mediaDevices.getUserMedia({ audio: a }).then(function (s) { micState = 'granted'; return s; }, function (e) { micState = e && e.name === 'NotAllowedError' ? 'denied' : 'error'; throw e; });
  }
  function levelOf(an, buf) { an.getFloatTimeDomainData(buf); var s = 0; for (var i = 0; i < buf.length; i++) s += buf[i] * buf[i]; return Math.sqrt(s / buf.length); }
  function b64(blob) { return new Promise(function (res, rej) { var r = new FileReader(); r.onload = function () { res(String(r.result).split(',')[1] || ''); }; r.onerror = rej; r.readAsDataURL(blob); }); }

  function blocked() {
    if (muted) { say('The microphone is muted', true); return true; }
    if (!C() || !C().state() || !C().state().paired) { say('Pair this computer first', true); return true; }
    return false;
  }

  /* one capture: stream + level meter + recorder (+ browser recognition when that is the plan) */
  function begin(mode) {                                              // mode: 'ptt' (until key released) | 'tap' (until silence)
    if (active || busy || blocked()) return;
    cancelSpeech(); gen++;
    var plan = sttPlan(prefs(), caps());
    if (plan.mode === 'none') { say('No speech input is available in this window — open it in Chrome/Edge or switch to cloud voice', true); return; }
    var a = active = { mode: mode, plan: plan, chunks: [], text: '', done: false };
    emit('canai-state', 'listening'); setMic(true);
    stream().then(function (s) {
      if (active !== a) { s.getTracks().forEach(function (t) { t.stop(); }); return; }
      a.stream = s;
      try { var AC = root.AudioContext || root.webkitAudioContext; a.ctx = new AC(); a.an = a.ctx.createAnalyser(); a.an.fftSize = 1024; a.buf = new Float32Array(1024); a.ctx.createMediaStreamSource(s).connect(a.an); } catch (e) { /* no meter */ }
      if (caps().recorder) { try { a.mr = new MediaRecorder(s); a.mr.ondataavailable = function (e) { if (e.data && e.data.size) a.chunks.push(e.data); }; a.mr.start(); } catch (e) { /* recorder optional for browser plan */ } }
      if (plan.mode === 'browser') {
        var rec = a.rec = new SR(); rec.lang = sttLang(); rec.interimResults = true; rec.continuous = mode === 'tap';
        rec.onresult = function (ev) { var t = ''; for (var i = 0; i < ev.results.length; i++) t += ev.results[i][0].transcript; a.text = t.trim(); };
        rec.onerror = function (ev) { a.err = ev.error; if (srBrokenError(ev.error)) srBroken = true; };
        rec.onend = function () { a.recEnded = true; if (a.finishing) conclude(a); };
        try { rec.start(); } catch (e) { a.err = 'audio-capture'; srBroken = true; }
      }
      var ep = VS && VS.Endpointer ? VS.Endpointer({ silenceMs: 1100, noSpeechMs: 7000, maxMs: 30000 }) : null, t0 = Date.now(); if (ep) ep.start(t0);
      a.timer = setInterval(function () {
        var rms = a.an ? levelOf(a.an, a.buf) : 0; level = rms; emit('canai-level', rms);
        if (mode === 'tap' && ep) { var r = ep.feed(rms, Date.now()); if (r === 'end' || r === 'too_long' || r === 'no_speech') finish(a, r === 'no_speech'); }
      }, 50);
    }).catch(function (e) { fail(a, e); });
  }
  function fail(a, e) { cleanup(a); if (active === a) active = null; lastErr = micMessage(e, en()); say(lastErr, true); emit('canai-state', 'error'); setTimeout(function () { if (!busy && !speaking && !active) emit('canai-state', 'idle'); }, 1800); setMic(false); }
  function cleanup(a) {
    if (a.timer) clearInterval(a.timer);
    try { if (a.rec) { a.rec.onend = null; a.rec.stop(); } } catch (e) { /* ignore */ }
    try { if (a.mr && a.mr.state !== 'inactive') a.mr.stop(); } catch (e) { /* ignore */ }
    if (a.stream) a.stream.getTracks().forEach(function (t) { t.stop(); });
    if (a.ctx) { try { a.ctx.close(); } catch (e) { /* ignore */ } }
    level = 0; emit('canai-level', 0);
  }
  function finish(a, silent) {                                        // stop capturing, then work out the text
    if (a.finishing || active !== a) return; a.finishing = true;
    if (a.timer) { clearInterval(a.timer); a.timer = null; }
    var done = function () { conclude(a, silent); };
    if (a.plan.mode === 'browser' && a.rec && !a.recEnded) { try { a.rec.stop(); } catch (e) { done(); return; } setTimeout(function () { if (!a.concluded) done(); }, 2500); }
    else done();
  }
  function conclude(a, silent) {
    if (a.concluded) return; a.concluded = true;
    var mr = a.mr, blobP = Promise.resolve(null);
    if (mr && mr.state !== 'inactive') blobP = new Promise(function (res) { mr.onstop = function () { res(new Blob(a.chunks, { type: mr.mimeType || 'audio/webm' })); }; try { mr.stop(); } catch (e) { res(null); } });
    else if (a.chunks.length) blobP = Promise.resolve(new Blob(a.chunks, { type: 'audio/webm' }));
    blobP.then(function (blob) {
      cleanup(a); if (active === a) active = null; setMic(false);
      if (silent) { emit('canai-state', 'idle'); say('Nothing was heard', true); return; }
      if (a.plan.mode === 'browser' && a.text) return deliverText(a.text);
      if (a.plan.mode === 'browser' && a.err && a.err !== 'no-speech' && a.err !== 'aborted') { if (blob && blob.size > 200) return viaCloud(blob); emit('canai-state', 'error'); say(micMessage({ error: a.err }, en()), true); setTimeout(function () { emit('canai-state', 'idle'); }, 1800); return; }
      if (a.plan.mode === 'cloud' && blob && blob.size > 200) return viaCloud(blob);
      emit('canai-state', 'idle'); say('Nothing was heard', true);
    });
  }
  function viaCloud(blob) {
    if (!cloudNoticed && prefs().stt_provider !== 'cloud') { cloudNoticed = true; say('Speech recognition is not available in this window - using cloud voice (uses credits)'); }
    busy = true; emit('canai-state', 'thinking');
    return b64(blob).then(function (data) { return C().cloud('POST', 'voice/listen', { audio_b64: data, mime: blob.type || 'audio/webm', language: sttLang().slice(0, 2) }); })
      .then(function (r) { busy = false; if (!r.ok || !r.json.text) { emit('canai-state', r.ok ? 'idle' : 'error'); say(r.ok ? ('Nothing was heard') : (r.status === 501 ? C().t('tooold') : (r.json.error || 'error')), true); if (!r.ok) setTimeout(function () { emit('canai-state', 'idle'); }, 1800); return null; } return deliverText(r.json.text); })
      .catch(function () { busy = false; emit('canai-state', 'error'); say('Voice failed', true); });
  }
  function deliverText(text) {
    busy = true;
    // spoken words are only ever a question: they go to chat exactly like typed text and can never approve anything
    return C().sendChat(text, { silent: true, fromVoice: true }).then(function (reply) { busy = false; if (reply && (prefs().voice_reply || handsFree)) return speak(reply); });
  }

  /* ---------- speaking ---------- */
  function cancelSpeech() { gen++; queue = []; if (root.speechSynthesis) { try { root.speechSynthesis.cancel(); } catch (e) { /* ignore */ } } if (cloudAudio) { try { cloudAudio.pause(); } catch (e) { /* ignore */ } cloudAudio = null; } if (speaking) { speaking = false; emit('canai-state', 'idle'); } }
  var cloudAudio = null;
  function speak(text) {
    var p = prefs(); if (!text) return Promise.resolve();
    cancelSpeech(); var my = gen;
    if (p.tts_provider === 'cloud') return speakCloud(text, my);
    if (!caps().tts) { emit('canai-state', 'idle'); return Promise.resolve(); }
    queue = splitSentences(text); speaking = true; emit('canai-state', 'speaking');
    return new Promise(function (resolve) {
      function next() {
        if (gen !== my) { resolve(); return; }
        var chunk = queue.shift();
        if (!chunk) { speaking = false; emit('canai-state', 'idle'); resolve(); return; }
        var lang = p.voice_lang && p.voice_lang !== 'auto' ? p.voice_lang : detectLang(chunk), u = new root.SpeechSynthesisUtterance(chunk);
        u.lang = lang; u.rate = p.voice_rate || 1; var v = pickVoice(root.speechSynthesis.getVoices(), p.voice_name, lang); if (v) u.voice = v;
        u.onboundary = function (e) { var w = (chunk.slice(e.charIndex).match(/^\S+/) || [''])[0]; emit('canai-boundary', { word: w, rate: u.rate }); };
        u.onend = next; u.onerror = function () { if (gen === my) next(); };
        root.speechSynthesis.speak(u);
      }
      next();
    });
  }
  function speakCloud(text, my) {
    speaking = true; emit('canai-state', 'thinking');
    return C().cloud('POST', 'voice/speak', { text: text.slice(0, 2000) })
      .then(function (r) {
        if (gen !== my) return;
        if (!r.ok || !r.json.audio_b64) { speaking = false; emit('canai-state', 'idle'); say('Cloud voice is not available', true); return; }
        var a = cloudAudio = new root.Audio('data:' + (r.json.mime || 'audio/mpeg') + ';base64,' + r.json.audio_b64), sink = prefs().speaker_device;
        if (sink && a.setSinkId) { try { a.setSinkId(sink); } catch (e) { /* default output */ } }
        return new Promise(function (resolve) {
          var meter = null;                                           // real audio level of the reply -> the character's mouth
          function stopMeter() { if (meter) { clearInterval(meter.iv); try { meter.ctx.close(); } catch (e) { /* ignore */ } meter = null; } }
          function startMeter() {
            try {
              var AC = root.AudioContext || root.webkitAudioContext, ctx = new AC(), src = ctx.createMediaElementSource(a), an = ctx.createAnalyser(), buf = new Float32Array(1024); an.fftSize = 1024; src.connect(an); an.connect(ctx.destination);
              meter = { ctx: ctx, iv: setInterval(function () { emit('canai-speech-level', levelOf(an, buf)); }, 50) };
            } catch (e) { meter = null; }                              // no analyser: the character falls back to its syllable wave
          }
          a.onplaying = function () { emit('canai-state', 'speaking'); if (!meter) startMeter(); }; a.onended = a.onerror = function () { stopMeter(); if (gen === my) { speaking = false; emit('canai-state', 'idle'); } resolve(); };
          var pr = a.play(); if (pr && pr.catch) pr.catch(function () { speaking = false; emit('canai-state', 'idle'); resolve(); });
        });
      }).catch(function () { speaking = false; emit('canai-state', 'idle'); });
  }

  /* ---------- hands-free (wake phrase) ---------- */
  function startHandsFree() {
    if (!VS || !SR || muted) { say('Hands-free needs speech recognition (Chrome/Edge) and an unmuted microphone', true); return false; }
    if (!prefs().wake_word) { say('Turn on "Wake word" in the voice settings first', true); return false; }
    var rec = null, want = false, cb = null, cap = null;
    var wake = {
      start: function (fn) { cb = fn; if (want) return; want = true; boot(); },
      stop: function () { want = false; cb = null; if (rec) { try { rec.onend = null; rec.stop(); } catch (e) { /* ignore */ } rec = null; } }
    };
    function boot() {
      if (!want) return; rec = new SR(); rec.continuous = true; rec.interimResults = true; rec.lang = sttLang();
      rec.onresult = function (e) { for (var i = e.resultIndex; i < e.results.length; i++) if (cb) cb(e.results[i][0].transcript); };
      rec.onend = function () { if (want) setTimeout(boot, 250); }; rec.onerror = function (e) { if (e.error === 'not-allowed' || e.error === 'service-not-allowed') want = false; };
      try { rec.start(); } catch (e) { /* already running */ }
    }
    var capture = {
      start: function () { cap = { chunks: [] }; stream().then(function (s) { cap.stream = s; cap.mr = new MediaRecorder(s); cap.mr.ondataavailable = function (e) { if (e.data && e.data.size) cap.chunks.push(e.data); }; cap.mr.start(); var AC = root.AudioContext || root.webkitAudioContext; cap.ctx = new AC(); cap.an = cap.ctx.createAnalyser(); cap.an.fftSize = 1024; cap.buf = new Float32Array(1024); cap.ctx.createMediaStreamSource(s).connect(cap.an); }).catch(function () { cap = null; }); },
      level: function () { var v = cap && cap.an ? levelOf(cap.an, cap.buf) : 0; emit('canai-level', v); return v; },
      stop: function () { return new Promise(function (res) { if (!cap || !cap.mr || cap.mr.state === 'inactive') { cleanupCap(); res(null); return; } cap.mr.onstop = function () { var b = new Blob(cap.chunks, { type: cap.mr.mimeType || 'audio/webm' }); cleanupCap(); res(b); }; try { cap.mr.stop(); } catch (e) { cleanupCap(); res(null); } }); }
    };
    function cleanupCap() { if (cap) { if (cap.stream) cap.stream.getTracks().forEach(function (t) { t.stop(); }); if (cap.ctx) { try { cap.ctx.close(); } catch (e) { /* ignore */ } } cap = null; } emit('canai-level', 0); }
    var speaker = { speak: function (t) { return speak(t); }, cancel: function () { cancelSpeech(); } };
    session = VS.VoiceSession({ wake: wake, capture: capture, speaker: speaker }, {
      deliver: function (u) { var textP = u.text ? Promise.resolve(u.text) : (u.blob && u.blob.size > 200 ? b64(u.blob).then(function (d) { return C().cloud('POST', 'voice/listen', { audio_b64: d, mime: u.blob.type || 'audio/webm', language: sttLang().slice(0, 2) }); }).then(function (r) { return r.ok && r.json.text ? r.json.text : ''; }) : Promise.resolve(''));
        return textP.then(function (t) { if (!t) return null; emit('canai-state', 'thinking'); return C().sendChat(t, { silent: true, fromVoice: true }); }); },
      onState: function (s) { emit('canai-state', { off: 'idle', armed: 'idle', listening: 'listening', thinking: 'thinking', speaking: 'speaking', error: 'error' }[s] || 'idle'); },
      onIndicator: function (on) { setMic(on); }
    }, { speak: false });
    session.grant(); handsFree = true; setMic(true); return true;
  }
  function stopHandsFree() { if (session) { session.stopAll(); session = null; } handsFree = false; cancelSpeech(); setMic(false); emit('canai-state', 'idle'); }

  /* ---------- settings panel ---------- */
  function renderSettings() {
    var slot = document.getElementById('voice-settings-slot'); if (!slot || !C()) return;
    var h = C().h, p = prefs(), E = en(), cp = caps(); slot.textContent = '';
    function seg(key, opts, label) { return h('div', { class: 'row sb' }, h('span', { text: label }), h('div', { class: 'seg', role: 'group' }, opts.map(function (o) { return h('button', { 'data-pref': key, 'data-pv': String(o[0]), 'aria-pressed': String(p[key] === o[0]), text: o[1] }); }))); }
    var card = h('section', { class: 'card', id: 'voice-card' }, h('h2', { text: 'Voice & character' }));
    card.appendChild(h('p', { class: 'mut', id: 'voice-caps', text: ('Speech input: ') + (cp.sr ? ('browser speech recognition ✓') : ('not in this window (cloud voice can be used)')) + ' · ' + ('Speech output: ') + (cp.tts ? '✓' : '✗') }));
    card.appendChild(h('div', { class: 'row sb' }, h('span', { id: 'perm-state', text: ('Microphone: ') + ({ granted: 'allowed', denied: 'blocked', unknown: 'not asked yet', error: 'problem' }[micState] || micState) }),
      h('div', { class: 'row' }, h('button', { class: 'btn', id: 'mic-test', text: 'Test microphone' }), h('button', { class: 'btn', id: 'mute-set', 'aria-pressed': String(muted), text: muted ? ('Unmute') : ('Mute') }))));
    card.appendChild(h('div', { class: 'meter', 'aria-label': 'mic level' }, h('i', { id: 'test-level' })));
    card.appendChild(h('div', { class: 'row' }, h('button', { class: 'btn', id: 'diag-go', text: 'Voice diagnostics' })));
    card.appendChild(h('pre', { class: 'mut', id: 'diag-out', hidden: true, style: 'white-space:pre-wrap;user-select:all' }));
    var msel = h('select', { id: 'mic-dev', 'aria-label': 'Microphone' }), ssel = h('select', { id: 'spk-dev', 'aria-label': 'Speaker' }), vsel = h('select', { id: 'voice-name', 'aria-label': 'Voice' });
    msel.appendChild(h('option', { value: '', text: 'System default microphone' })); ssel.appendChild(h('option', { value: '', text: 'System default speaker' })); vsel.appendChild(h('option', { value: '', text: 'Automatic voice' }));
    if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) navigator.mediaDevices.enumerateDevices().then(function (ds) { ds.forEach(function (d) { var o = h('option', { value: d.deviceId, text: d.label || (d.kind + ' ' + d.deviceId.slice(0, 6)) }); if (d.kind === 'audioinput') { if (d.deviceId === p.mic_device) o.selected = true; msel.appendChild(o); } else if (d.kind === 'audiooutput') { if (d.deviceId === p.speaker_device) o.selected = true; ssel.appendChild(o); } }); }).catch(function () {});
    function voices() { while (vsel.options.length > 1) vsel.remove(1); (root.speechSynthesis ? root.speechSynthesis.getVoices() : []).forEach(function (v) { var o = h('option', { value: v.name, text: v.name + ' (' + v.lang + ')' }); if (v.name === p.voice_name) o.selected = true; vsel.appendChild(o); }); }
    voices(); if (root.speechSynthesis) root.speechSynthesis.onvoiceschanged = voices;
    card.appendChild(h('div', { class: 'row' }, msel, ssel, vsel));
    card.appendChild(h('p', { class: 'mut', text: 'The speaker choice applies to cloud voice audio; browser voices play on the system default output.' }));
    card.appendChild(h('div', { class: 'row' }, h('label', { for: 'voice-rate', text: ('Speed ') + (p.voice_rate || 1) + '×' }), h('input', { id: 'voice-rate', type: 'range', min: '0.5', max: '2', step: '0.1', value: String(p.voice_rate || 1), 'aria-label': 'Speaking speed' }), h('button', { class: 'btn', id: 'voice-test', text: 'Test voice' }), h('button', { class: 'btn d', id: 'voice-quiet', text: 'Stop speaking' })));
    card.appendChild(seg('voice_lang', [['auto', 'Auto'], ['bn-BD', 'বাংলা'], ['en-US', 'English']], 'Voice language'));
    card.appendChild(seg('voice_reply', [[true, 'On'], [false, 'Off']], 'Speak replies aloud'));
    card.appendChild(seg('stt_provider', [['browser', 'Browser'], ['cloud', 'Cloud (credits)']], 'Speech to text'));
    card.appendChild(seg('tts_provider', [['browser', 'Browser'], ['cloud', 'Cloud (credits)']], 'Text to speech'));
    card.appendChild(seg('push_to_talk_key', [['F9', 'F9'], ['F10', 'F10'], ['Space', 'Space'], ['Control', 'Ctrl']], 'Push-to-talk key (hold)'));
    card.appendChild(seg('wake_word', [[true, 'Allowed'], [false, 'Off']], 'Wake word: "Hey CAN AI"'));
    if (p.wake_word) {
      card.appendChild(h('label', { class: 'k' }, h('input', { type: 'checkbox', id: 'hf-consent' }), h('span', { style: 'flex:1', text: 'I agree: while hands-free is on, the microphone listens for the phrase. In Chrome/Edge that audio goes to the browser vendor\'s speech service. Asked again every visit.' })));
      card.appendChild(h('div', { class: 'row' }, h('button', { class: 'btn p', id: 'hf-toggle', text: handsFree ? ('Turn off hands-free') : ('Turn on hands-free') }), h('span', { class: 'chip ' + (handsFree ? 'ok' : ''), id: 'hf-state', text: handsFree ? ('Microphone ON') : ('off') })));
    }
    card.appendChild(h('h2', { style: 'margin-top:16px', text: 'Character' }));
    card.appendChild(seg('character_color', [['violet', 'Violet'], ['sky', 'Sky'], ['mint', 'Mint'], ['rose', 'Rose'], ['amber', 'Amber']], 'Colour'));
    card.appendChild(seg('character_personality', [['friendly', 'Friendly'], ['calm', 'Calm'], ['playful', 'Playful']], 'Personality'));
    card.appendChild(h('div', { class: 'row' }, h('input', { id: 'char-name', value: p.character_name || 'CAN', maxlength: '24', 'aria-label': 'Character name' }), h('button', { class: 'btn', id: 'char-name-save', text: 'Save name' })));
    slot.appendChild(card);
  }

  /* ---------- events ---------- */
  var pttDown = false;
  document.addEventListener('keydown', function (e) {
    var key = prefs().push_to_talk_key || 'F9';
    if (e.key === 'Escape' && (speaking || active)) { cancelSpeech(); if (active) { var a = active; active = null; cleanup(a); setMic(false); emit('canai-state', 'idle'); } return; }
    if (e.repeat || pttDown || !pttMatches(e, key) || !pttAllowed(e.target, key)) return;
    pttDown = true; if (/^F\d+$/.test(key)) e.preventDefault(); begin('ptt');
  });
  document.addEventListener('keyup', function (e) {
    var key = prefs().push_to_talk_key || 'F9'; if (!pttDown || !pttMatches(e, key)) return;
    pttDown = false; if (active && active.mode === 'ptt') finish(active, false);
  });
  root.addEventListener('blur', function () { if (pttDown) { pttDown = false; if (active && active.mode === 'ptt') finish(active, false); } });
  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('button') : null; if (!el) return;
    if (el.id === 'mic-go') { if (active) finish(active, false); else begin('tap'); }
    else if (el.id === 'mute-go' || el.id === 'mute-set') { muted = !muted; if (muted) { if (active) { var a = active; active = null; cleanup(a); } if (handsFree) stopHandsFree(); emit('canai-state', 'idle'); } setMic(false); say(muted ? ('Microphone muted') : ('Microphone unmuted')); if (el.id === 'mute-go') { el.textContent = muted ? '🔇' : '🔊'; } renderSettings(); }
    else if (el.id === 'voice-quiet') cancelSpeech();
    else if (el.id === 'voice-test') speak('Hello! I am your assistant. Can you hear me?');
    else if (el.id === 'char-name-save') C().setPref('character_name', document.getElementById('char-name').value.trim() || 'CAN');
    else if (el.id === 'mic-test') testMic();
    else if (el.id === 'diag-go') runDiagnose();
    else if (el.id === 'hf-toggle') {
      if (handsFree) { stopHandsFree(); renderSettings(); return; }
      var ok = document.getElementById('hf-consent'); if (!ok || !ok.checked) { say('Tick the consent box first', true); return; }
      if (startHandsFree()) renderSettings();
    }
  });
  document.addEventListener('change', function (e) {
    var t = e.target; if (!C()) return;
    if (t.id === 'mic-dev') C().setPref('mic_device', t.value); else if (t.id === 'spk-dev') C().setPref('speaker_device', t.value);
    else if (t.id === 'voice-name') C().setPref('voice_name', t.value); else if (t.id === 'voice-rate') C().setPref('voice_rate', parseFloat(t.value));
  });
  function runDiagnose() {
    var out = document.getElementById('diag-out'); if (!out) return;
    var info = { secure: !!root.isSecureContext, ua: (navigator.userAgent || '').slice(0, 160), lastError: lastErr || '' };
    var p1 = navigator.permissions && navigator.permissions.query ? navigator.permissions.query({ name: 'microphone' }).then(function (r) { info.permission = r.state; }, function () { info.permission = 'not queryable'; }) : Promise.resolve();
    var p2 = navigator.mediaDevices && navigator.mediaDevices.enumerateDevices ? navigator.mediaDevices.enumerateDevices().then(function (d) { info.inputs = d.filter(function (x) { return x.kind === 'audioinput'; }).length; }, function () {}) : Promise.resolve();
    Promise.all([p1, p2]).then(function () { out.textContent = diagnose(caps(), prefs(), info); out.hidden = false; });
  }
  function testMic() {
    var bar = document.getElementById('test-level'), P = document.getElementById('perm-state'); emit('canai-state', 'listening');
    stream().then(function (s) {
      var AC = root.AudioContext || root.webkitAudioContext, ctx = new AC(), an = ctx.createAnalyser(); an.fftSize = 1024; var buf = new Float32Array(1024); ctx.createMediaStreamSource(s).connect(an); var n = 0, peak = 0;
      var iv = setInterval(function () { var v = levelOf(an, buf); peak = Math.max(peak, v); emit('canai-level', v); if (bar) bar.style.setProperty('width', Math.min(100, Math.round(v * 400)) + '%'); if (++n > 40) { clearInterval(iv); s.getTracks().forEach(function (t) { t.stop(); }); try { ctx.close(); } catch (x) { /* ignore */ } emit('canai-level', 0); emit('canai-state', 'idle'); say(peak > 0.01 ? ('Microphone works ✓') : ('No sound detected — check the microphone'), peak <= 0.01); renderSettings(); } }, 75);
    }).catch(function (e) { emit('canai-state', 'idle'); say(micMessage(e, en()), true); renderSettings(); });
  }
  root.addEventListener('canai-mount', function (e) { if (e.detail === 'voice-settings') renderSettings(); });
  if (navigator.permissions && navigator.permissions.query) navigator.permissions.query({ name: 'microphone' }).then(function (r) { var m = { granted: 'granted', denied: 'denied', prompt: 'unknown' }; micState = m[r.state] || 'unknown'; r.onchange = function () { micState = m[r.state] || 'unknown'; if (C() && C().refreshShell) C().refreshShell(); }; }).catch(function () {});
  root.addEventListener('pagehide', function () { stopHandsFree(); if (active) { cleanup(active); active = null; } cancelSpeech(); });

  root.CANAI_VOICE = {
    muted: function () { return muted; }, listening: function () { return micOn && !muted; }, handsFree: function () { return handsFree; }, speak: function (t) { return speak(t); }, cancel: cancelSpeech, caps: caps, micState: function () { return micState; },
    label: function (lang) { var E = lang === 'en'; return muted ? ('Mic muted') : micOn ? (handsFree ? ('Hands-free ON') : ('Listening…')) : micState === 'denied' ? ('Mic blocked') : ('Mic off'); }
  };
})(typeof window !== 'undefined' ? window : this);
