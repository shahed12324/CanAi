const assert = require('assert');
const V = require('../../public/js/voice-session.js');

// ---- wake phrase
assert.deepStrictEqual(V.matchWake('Hey CanAI, what is the time?'), { matched: true, rest: 'what is the time' });
assert.strictEqual(V.matchWake('hey canai').matched, true);
assert.strictEqual(V.matchWake('hey canai').rest, '');
assert.strictEqual(V.matchWake('um hey canai open notes').rest, 'open notes');          // one filler word before is fine
assert.strictEqual(V.matchWake('hey kanai').matched, true);                                // one slip in a longer word
assert.strictEqual(V.matchWake('they canai').matched, false);                              // 'they' is not 'hey'
assert.strictEqual(V.matchWake('hello there').matched, false);
assert.strictEqual(V.matchWake('I said hey canai later').matched, false);                  // must be at the start, not anywhere in the sentence
assert.strictEqual(V.matchWake('').matched, false);
assert.strictEqual(V.matchWake(null).matched, false);
assert.strictEqual(V.matchWake('হে ক্যানএআই আজকের আবহাওয়া').matched, true);
assert.strictEqual(V.matchWake('হে ক্যানএআই আজকের আবহাওয়া').rest, 'আজকের আবহাওয়া');
assert.strictEqual(V.matchWake('ওকে ক্যান এআই').matched, true);
assert.strictEqual(V.matchWake('hey jarvis', ['hey jarvis']).matched, true);               // phrases are configurable
assert.strictEqual(V.matchWake('hey canai', ['x']).matched, false);
assert.strictEqual(V.matchWake('hey ca', ['hey ca']).matched, true);                       // short words must match exactly
assert.strictEqual(V.matchWake('hey cb', ['hey ca']).matched, false);
assert.strictEqual(V.isStopWord('Stop!'), true); assert.strictEqual(V.isStopWord('থামো'), true); assert.strictEqual(V.isStopWord('please stop now'), false);
assert.strictEqual(V.lev('kitten', 'sitting'), 3);

// ---- end-pointing
function run(samples, o) {
  const ep = V.Endpointer(o); const out = [];
  samples.forEach(([rms, t]) => { const r = ep.feed(rms, t); if (r) out.push([r, t]); });
  return out;
}
assert.deepStrictEqual(run([[0, 0], [0.2, 100], [0.2, 300], [0.2, 600], [0, 700], [0, 1900], [0, 2000], [0, 2100]]), [['speech_start', 300], ['end', 1900]].map(x => x));
assert.deepStrictEqual(run([[0.2, 0], [0, 50], [0.2, 100], [0, 150]]), []);                                   // a click shorter than minSpeech is not speech
assert.deepStrictEqual(run([[0, 0], [0, 3000], [0, 6000], [0, 7000]]), [['no_speech', 6000]]);
assert.deepStrictEqual(run([[0.2, 0], [0.2, 200], [0.2, 15000], [0.2, 30000], [0.2, 31000]]), [['speech_start', 200], ['too_long', 30000]]);
const ep = V.Endpointer(); assert.strictEqual(ep.feed(0, 0), null); ep.feed(0, 7000); assert.strictEqual(ep.feed(0.5, 8000), null, 'finished: no more events');

// ---- avatar
assert.strictEqual(V.avatarFor('listening').expression, 'happy');
assert.deepStrictEqual(V.avatarFor('nope'), { expression: 'neutral', mouth: 'closed' });
assert.strictEqual(V.VOICE_CAN_APPROVE, false);

// ---- session with fakes
function rig(over) {
  over = over || {};
  const log = { states: [], ind: [], delivered: [], spoken: [], cancel: 0, wakeStarts: 0, wakeStops: 0, captureStarts: 0, captureStops: 0 };
  let clock = 0, level = 0, onText = null, timers = [];
  const adapters = {
    wake: { start(cb) { log.wakeStarts++; onText = cb; }, stop() { log.wakeStops++; onText = null; } },
    capture: { start() { log.captureStarts++; }, stop() { log.captureStops++; return Promise.resolve('BLOB'); }, level() { return level; } },
    speaker: { speak(t) { log.spoken.push(t); return over.speakPending ? new Promise(() => {}) : Promise.resolve(); }, cancel() { log.cancel++; } }
  };
  const hooks = { deliver: over.deliver || ((u) => { log.delivered.push(u); return Promise.resolve('the answer'); }), onState: (s) => log.states.push(s), onIndicator: (m) => log.ind.push(m) };
  const s = V.VoiceSession(adapters, hooks, { now: () => clock, setInterval: (f) => { timers.push(f); return timers.length; }, clearInterval: (id) => { timers[id - 1] = null; }, cooldownMs: 1000, speak: over.speak });
  return { s, log, say: (t) => onText && onText(t), advance: (ms, lv) => { clock += ms; level = lv; timers.forEach((f) => f && f()); }, setClock: (t) => { clock = t; }, flush: () => new Promise((r) => setTimeout(r, 0)), hasListener: () => onText !== null };
}

(async () => {
  // nothing listens before consent
  let r = rig();
  assert.strictEqual(r.s.state(), 'off'); assert.strictEqual(r.s.micOn(), false); assert.strictEqual(r.log.wakeStarts, 0);
  r.say('hey canai hello'); assert.strictEqual(r.log.delivered.length, 0);

  // consent -> armed with the indicator on
  r.s.grant();
  assert.strictEqual(r.s.state(), 'armed'); assert.strictEqual(r.s.micOn(), true); assert.deepStrictEqual(r.log.ind, [true]);

  // talk that is not for us is dropped unseen
  r.say('the meeting is at three'); r.say('hello canai');
  assert.strictEqual(r.log.delivered.length, 0); assert.strictEqual(r.s.state(), 'armed'); assert.strictEqual(r.log.captureStarts, 0);

  // wake phrase alone -> listen -> speak -> endpoint -> deliver the recording -> speak the answer -> armed again
  r.say('hey canai');
  assert.strictEqual(r.s.state(), 'listening'); assert.strictEqual(r.log.captureStarts, 1); assert.strictEqual(r.log.wakeStops, 1);
  r.advance(100, 0); r.advance(200, 0.3); r.advance(300, 0.3); r.advance(200, 0); r.advance(1500, 0);
  await r.flush(); await r.flush();
  assert.strictEqual(r.log.delivered.length, 1);
  assert.deepStrictEqual(r.log.delivered[0], { source: 'voice', via: 'wake', blob: 'BLOB' });
  assert.deepStrictEqual(r.log.spoken, ['the answer']);
  assert.strictEqual(r.s.state(), 'armed'); assert.strictEqual(r.s.micOn(), true);
  assert.ok(r.log.states.indexOf('thinking') > r.log.states.indexOf('listening') && r.log.states.indexOf('speaking') > r.log.states.indexOf('thinking'));

  // one-breath command: "hey canai <words>" is delivered as text without opening the recorder
  r = rig(); r.s.grant(); r.say('Hey CanAI what time is it'); await r.flush(); await r.flush();
  assert.deepStrictEqual(r.log.delivered, [{ source: 'voice', via: 'wake', text: 'what time is it' }]); assert.strictEqual(r.log.captureStarts, 0);

  // cooldown: a second wake right after is ignored
  r = rig({ speak: false }); r.s.grant(); r.say('hey canai hello'); await r.flush(); await r.flush();
  assert.strictEqual(r.s.state(), 'armed'); r.say('hey canai again'); await r.flush();
  assert.strictEqual(r.log.delivered.length, 1, 'inside the cooldown');
  r.setClock(5000); r.say('hey canai again'); await r.flush(); await r.flush();
  assert.strictEqual(r.log.delivered.length, 2);

  // no speech after the wake phrase -> back to armed, nothing delivered
  r = rig(); r.s.grant(); r.say('hey canai'); r.advance(3000, 0); r.advance(3500, 0); await r.flush(); await r.flush();
  assert.strictEqual(r.log.delivered.length, 0); assert.strictEqual(r.s.state(), 'armed'); assert.strictEqual(r.log.captureStops, 1);

  // barge-in: a stop word while speaking cuts the speech; the wake phrase starts a new question
  r = rig({ speakPending: true }); r.s.grant(); r.say('hey canai tell me a story'); await r.flush(); await r.flush();
  assert.strictEqual(r.s.state(), 'speaking'); assert.strictEqual(r.s.micOn(), true);
  r.say('stop'); assert.strictEqual(r.log.cancel, 1); assert.strictEqual(r.s.state(), 'armed');
  r = rig({ speakPending: true }); r.s.grant(); r.say('hey canai tell me a story'); await r.flush(); await r.flush();
  r.setClock(9000); r.say('hey canai something else'); await r.flush(); await r.flush();
  assert.strictEqual(r.log.delivered.length, 2); assert.strictEqual(r.log.delivered[1].text, 'something else');
  r = rig({ speakPending: true }); r.s.grant(); r.say('hey canai tell me a story'); await r.flush(); await r.flush();
  r.say('what a lovely day'); assert.strictEqual(r.log.cancel, 0, 'ordinary words do not interrupt');

  // revoke / stopAll: everything off, indicator off, no further reaction
  r = rig(); r.s.grant(); r.s.stopAll();
  assert.strictEqual(r.s.state(), 'off'); assert.strictEqual(r.s.micOn(), false); assert.ok(r.log.cancel >= 1);
  r.say('hey canai hi'); assert.strictEqual(r.log.delivered.length, 0);
  assert.strictEqual(r.hasListener(), false);

  // the answer arriving after revoke is not spoken, and the mic does not come back
  let release; r = rig({ deliver: () => new Promise((res) => { release = res; }) });
  r.s.grant(); r.say('hey canai hi'); await r.flush(); r.s.revoke(); release('late answer'); await r.flush(); await r.flush();
  assert.deepStrictEqual(r.log.spoken, []); assert.strictEqual(r.s.state(), 'off'); assert.strictEqual(r.s.micOn(), false);

  // a failing server never leaves the mic stuck on in the thinking state
  r = rig({ deliver: () => Promise.reject(new Error('500')) }); r.s.grant(); r.say('hey canai hi'); await r.flush(); await r.flush(); await r.flush();
  assert.ok(r.log.states.indexOf('error') >= 0); assert.strictEqual(r.s.state(), 'armed');

  console.log('voice-session ok');
})().catch((e) => { console.error(e); process.exit(1); });
