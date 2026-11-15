/* The CAN AI character: an original SVG face that shows what the assistant REALLY is doing.
   States come only from real events (canai-state: idle | listening | thinking | speaking | error); the mouth follows real signals
   (microphone level while listening, word boundaries from the speech engine while speaking).  No random decoration except natural blinking.
   Pure logic (pose, blink, visemes) is exported for node tests; drawing is a thin layer on top. */
(function (root) {
  'use strict';
  var STATES = ['idle', 'listening', 'thinking', 'speaking', 'error'];
  var COLORS = { violet: ['#8b5cf6', '#38bdf8'], sky: ['#38bdf8', '#34d399'], mint: ['#34d399', '#38bdf8'], rose: ['#f472b6', '#8b5cf6'], amber: ['#fbbf24', '#f472b6'] };
  var BLINK = { friendly: [2500, 5500], calm: [3500, 7000], playful: [1800, 4000] };
  function clamp(x, a, b) { return Math.max(a, Math.min(b, x)); }

  /* ---- natural blinking: a closed-eye amount 0..1, double blinks sometimes.  rng and clock are injected so it is testable. ---- */
  function Blinker(rng, personality, enabled) {
    var range = BLINK[personality] || BLINK.friendly, next = null, start = null, double = false;
    function schedule(now) { next = now + range[0] + rng() * (range[1] - range[0]); }
    return {
      closed: function (now) {
        if (enabled === false) return 0;
        if (next === null) schedule(now);
        if (start === null && now >= next) { start = now; double = rng() < 0.15; }
        if (start === null) return 0;
        var d = now - start, DUR = 150;
        if (d < DUR) return 1 - Math.abs(d / DUR * 2 - 1);                       // 0 -> 1 -> 0
        if (double && d < DUR * 2.2) { var e = d - DUR * 1.2; return e > 0 && e < DUR ? 1 - Math.abs(e / DUR * 2 - 1) : 0; }
        start = null; schedule(now); return 0;
      }
    };
  }

  /* ---- mouth shapes from text (a rough, honest approximation: vowels open the mouth, b/m/p close the lips) ---- */
  var OPEN = { a: .9, 'আ': .9, 'া': .9, o: .7, 'ও': .7, 'ো': .7, 'অ': .6, u: .4, 'উ': .4, 'ু': .4, 'ূ': .4, e: .6, 'এ': .6, 'ে': .6, i: .35, 'ই': .35, 'ি': .35, 'ী': .35, y: .3 };
  var ROUND = { o: .8, 'ও': .8, 'ো': .8, u: 1, 'উ': 1, 'ু': 1, 'ূ': 1, w: .8 };
  var LIPS = 'bmpবমপ';
  function visemeForChar(ch) {
    var c = String(ch || '').toLowerCase();
    if (!c) return { open: 0, round: 0 };
    if (OPEN[c] !== undefined) return { open: OPEN[c], round: ROUND[c] || 0 };
    if (LIPS.indexOf(c) >= 0 && c) return { open: 0, round: 0 };
    if (/\s|[.,!?।;:]/.test(c)) return { open: 0, round: 0 };
    return { open: .18, round: 0 };
  }
  /** [{t, open, round}] for one word, spread over wordMs. */
  function wordTimeline(word, wordMs) {
    var chars = Array.from(String(word)), out = [], n = Math.max(1, chars.length), step = wordMs / n;
    if (!chars.length) return [{ t: 0, open: 0, round: 0 }];
    chars.forEach(function (c, i) { var v = visemeForChar(c); out.push({ t: Math.round(i * step), open: v.open, round: v.round }); });
    return out;
  }
  function cueAt(tl, t) { var v = { open: 0, round: 0 }; for (var i = 0; i < tl.length; i++) { if (tl[i].t <= t) v = tl[i]; else break; } return v; }
  function wordMsFor(word, rate) { return Math.max(110, Math.round(Array.from(word).length * 70 / clamp(rate || 1, .5, 2))); }
  /** No boundary events from the voice?  Then a plain syllable-rate wave (still tied to "speaking" being real). */
  function waveOpen(t, rate) { return .25 + .35 * Math.abs(Math.sin(t / 1000 * Math.PI * 3.2 * clamp(rate || 1, .5, 2))); }
  function speechLevelToViseme(rms) { var o = clamp((rms - 0.015) / 0.2, 0, 1); return { open: o, round: o > .55 ? .25 : 0 }; }   // real audio level (cloud voice) -> mouth
  function levelToOpen(rms) { return clamp((rms - 0.02) / 0.25, 0, 1); }

  /* ---- the pose for a state at a moment.  t = ms clock; input: {level, viseme:{open,round}|null, blink, personality, calm (no continuous motion)} ---- */
  function computePose(state, t, input) {
    input = input || {};
    var calm = !!input.calm, pers = input.personality || 'friendly', amp = calm ? 0 : (pers === 'playful' ? 1.3 : pers === 'calm' ? .6 : 1);
    var p = { state: state, expression: 'neutral', eyeOpen: 1, eyeX: 0, eyeY: 0, browL: 0, browR: 0, smile: .25, mouthOpen: 0, mouthRound: 0, blush: 0, glow: .35, scale: 1 };
    var blink = clamp(input.blink || 0, 0, 1);
    if (state === 'idle') {
      p.expression = input.afterSuccess ? 'happy' : 'neutral';
      if (input.afterSuccess) { p.smile = .8; p.blush = .5; p.eyeOpen = .85; }
      p.scale = 1 + .015 * amp * Math.sin(t / 1300);
      p.eyeX = 2.5 * amp * Math.sin(t / 3100); p.eyeY = 1.2 * amp * Math.sin(t / 4700);
    } else if (state === 'listening') {
      var lv = clamp(input.level || 0, 0, 1);
      p.expression = 'listening'; p.eyeOpen = 1.12; p.browL = p.browR = -3; p.smile = .3; p.glow = .5 + .5 * lv; p.scale = 1 + .04 * lv * (calm ? 0 : 1);
      p.mouthOpen = .08 + .25 * lv;
    } else if (state === 'thinking') {
      p.expression = 'thinking'; p.eyeX = 5 * (calm ? .6 : 1) * Math.cos(t / 600); p.eyeY = -4 + 1.5 * amp * Math.sin(t / 600);
      p.browL = -4; p.browR = 2; p.smile = 0; p.glow = .55 + .2 * amp * Math.sin(t / 400);
    } else if (state === 'speaking') {
      var v = input.viseme || { open: waveOpen(t, input.rate), round: 0 };
      p.expression = pers === 'calm' ? 'neutral' : 'happy'; p.smile = pers === 'calm' ? .3 : .6; p.blush = pers === 'playful' ? .5 : .25;
      p.mouthOpen = clamp(v.open, 0, 1); p.mouthRound = clamp(v.round || 0, 0, 1); p.glow = .6; p.eyeOpen = .95;
    } else if (state === 'error') {
      p.expression = 'sad'; p.smile = -.5; p.browL = 3; p.browR = 3; p.eyeOpen = .8; p.glow = .2; p.eyeY = 2;
    }
    p.eyeOpen = p.eyeOpen * (1 - .95 * blink);
    return p;
  }

  /* ---- drawing ---- */
  var NS = 'http://www.w3.org/2000/svg';
  function el(tag, a) { var e = document.createElementNS(NS, tag); Object.keys(a || {}).forEach(function (k) { e.setAttribute(k, a[k]); }); return e; }
  function build(color) {
    var c = COLORS[color] || COLORS.violet, svg = el('svg', { viewBox: '0 0 200 200', role: 'img', 'aria-label': 'CAN AI', width: '100%', height: '100%' });
    var defs = el('defs'), g1 = el('linearGradient', { id: 'cg1', x1: '0', y1: '0', x2: '1', y2: '1' });
    g1.appendChild(el('stop', { offset: '0', 'stop-color': c[0] })); g1.appendChild(el('stop', { offset: '1', 'stop-color': c[1] })); defs.appendChild(g1); svg.appendChild(defs);
    var parts = {};
    parts.ring = svg.appendChild(el('circle', { cx: 100, cy: 100, r: 92, fill: 'none', stroke: 'url(#cg1)', 'stroke-width': 3, opacity: .4 }));
    parts.body = svg.appendChild(el('g'));
    parts.body.appendChild(el('circle', { cx: 100, cy: 100, r: 72, fill: 'url(#cg1)' }));
    parts.body.appendChild(el('ellipse', { cx: 100, cy: 102, rx: 54, ry: 44, fill: '#0b1020' }));
    parts.blushL = parts.body.appendChild(el('ellipse', { cx: 70, cy: 116, rx: 9, ry: 5, fill: '#f472b6', opacity: 0 }));
    parts.blushR = parts.body.appendChild(el('ellipse', { cx: 130, cy: 116, rx: 9, ry: 5, fill: '#f472b6', opacity: 0 }));
    parts.eyeL = parts.body.appendChild(el('ellipse', { cx: 80, cy: 98, rx: 8, ry: 11, fill: c[1] }));
    parts.eyeR = parts.body.appendChild(el('ellipse', { cx: 120, cy: 98, rx: 8, ry: 11, fill: c[1] }));
    parts.glintL = parts.body.appendChild(el('circle', { cx: 83, cy: 94, r: 2.4, fill: '#fff', opacity: .85 }));
    parts.glintR = parts.body.appendChild(el('circle', { cx: 123, cy: 94, r: 2.4, fill: '#fff', opacity: .85 }));
    parts.browL = parts.body.appendChild(el('path', { d: '', stroke: c[1], 'stroke-width': 3, 'stroke-linecap': 'round', fill: 'none' }));
    parts.browR = parts.body.appendChild(el('path', { d: '', stroke: c[1], 'stroke-width': 3, 'stroke-linecap': 'round', fill: 'none' }));
    parts.mouth = parts.body.appendChild(el('path', { d: '', stroke: c[1], 'stroke-width': 3, 'stroke-linecap': 'round', fill: '#7c2d5a' }));
    return { svg: svg, parts: parts };
  }
  function apply(parts, p) {
    var ry = 11 * clamp(p.eyeOpen, .05, 1.3), ex = p.eyeX, ey = p.eyeY;
    parts.eyeL.setAttribute('cx', 80 + ex); parts.eyeR.setAttribute('cx', 120 + ex); parts.eyeL.setAttribute('cy', 98 + ey); parts.eyeR.setAttribute('cy', 98 + ey);
    parts.eyeL.setAttribute('ry', ry.toFixed(2)); parts.eyeR.setAttribute('ry', ry.toFixed(2));
    parts.glintL.setAttribute('cx', 83 + ex); parts.glintR.setAttribute('cx', 123 + ex); parts.glintL.setAttribute('cy', 94 + ey); parts.glintR.setAttribute('cy', 94 + ey);
    parts.glintL.setAttribute('opacity', p.eyeOpen > .3 ? .85 : 0); parts.glintR.setAttribute('opacity', p.eyeOpen > .3 ? .85 : 0);
    parts.browL.setAttribute('d', 'M 68 ' + (78 + p.browL) + ' Q 80 ' + (73 + p.browL * 1.2) + ' 92 ' + (78 + p.browL * .6));
    parts.browR.setAttribute('d', 'M 108 ' + (78 + p.browR * .6) + ' Q 120 ' + (73 + p.browR * 1.2) + ' 132 ' + (78 + p.browR));
    var w = 20 - 8 * p.mouthRound, o = p.mouthOpen * 20, cv = p.smile * 10, y = 128;
    parts.mouth.setAttribute('d', o < 1.5 ? 'M ' + (100 - w) + ' ' + y + ' Q 100 ' + (y + cv * 1.6) + ' ' + (100 + w) + ' ' + y : 'M ' + (100 - w) + ' ' + y + ' Q 100 ' + (y + cv) + ' ' + (100 + w) + ' ' + y + ' Q 100 ' + (y + o * 1.6 + cv) + ' ' + (100 - w) + ' ' + y + ' Z');
    parts.mouth.setAttribute('fill', o < 1.5 ? 'none' : '#7c2d5a');
    parts.blushL.setAttribute('opacity', p.blush); parts.blushR.setAttribute('opacity', p.blush);
    parts.ring.setAttribute('opacity', clamp(p.glow, 0, 1)); parts.ring.setAttribute('stroke-width', 2 + 3 * p.glow);
    parts.body.setAttribute('transform', 'translate(100 100) scale(' + p.scale.toFixed(3) + ') translate(-100 -100)');
  }

  /* ---- the live character: wiring to the window's real events ---- */
  function Live() {
    var st = { state: 'idle', level: 0, viseme: null, mount: null, parts: null, raf: null, last: 0, personality: 'friendly', color: 'violet', calm: false, low: false, rate: 1, afterSuccess: 0, name: 'CAN', lastPose: null };
    var blinker = Blinker(Math.random, 'friendly', true), timeline = null, tlStart = 0, tlMs = 0, lastBoundary = 0, speakStart = 0, voiceAlive = false, audioLevel = 0, audioLive = false;
    function prefs() { var s = root.CANAI && root.CANAI.state && root.CANAI.state(); return (s && s.prefs) || {}; }
    function now() { return root.performance ? performance.now() : Date.now(); }
    function currentViseme(t) {
      if (st.state !== 'speaking') return null;
      if (timeline && t - tlStart < tlMs + 80) return cueAt(timeline, t - tlStart);
      if (audioLive) return speechLevelToViseme(audioLevel);                    // real audio of the spoken reply (cloud voice)
      if (voiceAlive && t - lastBoundary < 500) return { open: 0, round: 0 };         // between words: lips close
      return null;                                                                      // no boundary events from this voice: syllable wave
    }
    function frame() {
      st.raf = null; var t = now();
      var pose = computePose(st.state, t, { level: st.level, viseme: currentViseme(t), blink: blinker.closed(t), personality: st.personality, calm: st.calm, rate: st.rate, afterSuccess: st.afterSuccess > t });
      st.lastPose = pose; if (st.parts) apply(st.parts, pose);
      root.dispatchEvent(new CustomEvent('canai-pose', { detail: pose }));
      schedule();
    }
    function schedule() {
      if (st.raf !== null || !st.parts || document.hidden) return;
      if (st.calm && st.state !== 'listening' && st.state !== 'speaking' && st.state !== 'thinking') return;     // reduced motion / low power: only redraw while something real is happening
      st.raf = st.low ? setTimeout(frame, 100) : root.requestAnimationFrame(frame);
    }
    function still() { var t = now(); st.lastPose = computePose(st.state, t, { level: st.level, viseme: currentViseme(t), blink: 0, personality: st.personality, calm: true, afterSuccess: st.afterSuccess > t }); if (st.parts) apply(st.parts, st.lastPose); root.dispatchEvent(new CustomEvent('canai-pose', { detail: st.lastPose })); }
    function syncPrefs() {
      var p = prefs(), rm = document.body.classList.contains('rm');
      var color = COLORS[p.character_color] ? p.character_color : 'violet', pers = BLINK[p.character_personality] ? p.character_personality : 'friendly';
      st.calm = rm; st.low = p.performance === 'low'; st.rate = p.voice_rate || 1; st.name = p.character_name || 'CAN';
      if (pers !== st.personality) { st.personality = pers; blinker = Blinker(Math.random, pers, !rm); }
      if (color !== st.color || !st.parts) { st.color = color; remount(); }
    }
    function remount() {
      var host = document.getElementById('stage'); if (!host) { st.parts = null; return; }
      host.textContent = ''; var b = build(st.color); st.parts = b.parts; host.appendChild(b.svg);
      var cap = document.createElement('div'); cap.className = 'mut'; cap.id = 'stage-name'; cap.style.setProperty('text-align', 'center'); cap.style.setProperty('margin-top', '4px');
      host.setAttribute('data-char-state', st.state); st.nameEl = cap; host.appendChild(cap); cap.textContent = st.name + ' · ' + st.state;
    }
    function setState(s) {
      if (STATES.indexOf(s) < 0) return;
      var prev = st.state; st.state = s;
      if (s === 'speaking') { speakStart = now(); timeline = null; lastBoundary = 0; voiceAlive = false; audioLive = false; audioLevel = 0; }
      if (s !== 'speaking') audioLive = false;
      if ((prev === 'speaking' || prev === 'thinking') && s === 'idle') st.afterSuccess = now() + 2500;
      if (s !== 'listening') st.level = 0;
      var host = document.getElementById('stage'); if (host) host.setAttribute('data-char-state', s);
      if (st.nameEl) st.nameEl.textContent = st.name + ' · ' + s;
      if (st.calm) still(); schedule();
    }
    root.addEventListener('canai-state', function (e) { setState(e.detail); });
    root.addEventListener('canai-level', function (e) { st.level = levelToOpen(+e.detail || 0) * .9 + .1 * (+e.detail > 0.02 ? 1 : 0); if (st.calm && st.state === 'listening') still(); });
    root.addEventListener('canai-speech-level', function (e) { audioLevel = +e.detail || 0; audioLive = st.state === 'speaking'; if (st.calm) still(); });
    root.addEventListener('canai-boundary', function (e) {                       // {word, rate} from the speech engine
      var d = e.detail || {}, ms = wordMsFor(d.word || '', d.rate || st.rate); timeline = wordTimeline(d.word || '', ms); tlStart = now(); tlMs = ms; lastBoundary = tlStart; voiceAlive = true;
      if (st.calm) still();
    });
    root.addEventListener('canai-mount', function () { syncPrefs(); remount(); if (st.calm) still(); schedule(); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) schedule(); });
    setInterval(function () { if (document.getElementById('stage') && !document.getElementById('stage').firstChild) { syncPrefs(); remount(); } if (st.parts) { var before = st.color + st.personality + st.calm + st.low; syncPrefs(); if (before !== st.color + st.personality + st.calm + st.low) { if (st.calm) still(); schedule(); } } }, 1000);
    return { pose: function () { return st.lastPose || computePose(st.state, 0, {}); }, state: function () { return st.state; }, running: function () { return st.raf !== null; } };
  }

  var api = { STATES: STATES, COLORS: COLORS, Blinker: Blinker, visemeForChar: visemeForChar, wordTimeline: wordTimeline, cueAt: cueAt, wordMsFor: wordMsFor, waveOpen: waveOpen, speechLevelToViseme: speechLevelToViseme, levelToOpen: levelToOpen, computePose: computePose };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; return; }
  root.CANAI_CHAR_LOGIC = api;
  if (root.document) root.CANAI_CHARACTER = Live();
})(typeof window !== 'undefined' ? window : this);
