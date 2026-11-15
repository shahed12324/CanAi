// node tests/js/desktop-voice-character.test.js   (pure logic of the desktop character and voice; no browser, no microphone)
const assert = require('assert');
const path = require('path');
const ui = p => require(path.join(__dirname, '..', '..', 'jarvis_agent', 'ui', p));
const ch = ui('character.js'), vc = ui('voice.js'), vs = ui('voice-session.js');
let n = 0; const t = (name, fn) => { try { fn(); n++; console.log('ok   ' + name); } catch (e) { console.log('FAIL ' + name + '\n' + e.stack); process.exitCode = 1; } };

// ---------- character: pose is a function of the REAL state ----------
t('every state has its own distinct pose', () => {
  const p = s => ch.computePose(s, 1000, { level: 0.5, blink: 0 });
  assert.strictEqual(p('idle').expression, 'neutral'); assert.strictEqual(p('listening').expression, 'listening');
  assert.strictEqual(p('thinking').expression, 'thinking'); assert.strictEqual(p('speaking').expression, 'happy'); assert.strictEqual(p('error').expression, 'sad');
  assert.ok(p('error').smile < 0 && p('idle').smile > 0);
});
t('listening reacts to the microphone level (louder = glow, wider)', () => {
  const quiet = ch.computePose('listening', 0, { level: 0 }), loud = ch.computePose('listening', 0, { level: 1 });
  assert.ok(loud.glow > quiet.glow && loud.scale > quiet.scale && loud.mouthOpen > quiet.mouthOpen);
});
t('speaking follows the viseme, not a random value', () => {
  const closed = ch.computePose('speaking', 0, { viseme: { open: 0, round: 0 } }), wide = ch.computePose('speaking', 0, { viseme: { open: 0.9, round: 0 } }), round = ch.computePose('speaking', 0, { viseme: { open: 0.5, round: 1 } });
  assert.strictEqual(closed.mouthOpen, 0); assert.strictEqual(wide.mouthOpen, 0.9); assert.strictEqual(round.mouthRound, 1);
});
t('same input gives the same pose (deterministic, no hidden randomness)', () => {
  const a = ch.computePose('thinking', 777, { blink: 0 }), b = ch.computePose('thinking', 777, { blink: 0 });
  assert.deepStrictEqual(a, b);
});
t('reduced motion (calm) removes continuous movement but keeps the state signal', () => {
  const a = ch.computePose('idle', 100, { calm: true }), b = ch.computePose('idle', 5000, { calm: true });
  assert.strictEqual(a.scale, 1); assert.strictEqual(a.eyeX, 0); assert.deepStrictEqual(a, b);
  assert.notStrictEqual(ch.computePose('listening', 0, { calm: true }).expression, a.expression);
});
t('blink closes the eyes and never leaves them shut', () => {
  assert.strictEqual(ch.computePose('idle', 0, { blink: 1 }).eyeOpen < 0.1, true); assert.strictEqual(ch.computePose('idle', 0, { blink: 0 }).eyeOpen, 1);
  assert.ok(ch.computePose('idle', 0, { blink: 5 }).eyeOpen >= 0); assert.ok(ch.computePose('idle', 0, { blink: -3 }).eyeOpen === 1);
});
t('personality changes the energy of idle motion', () => {
  const amp = pers => Math.abs(ch.computePose('idle', 1300 * Math.PI / 2, { personality: pers }).scale - 1);
  assert.ok(amp('playful') > amp('friendly') && amp('friendly') > amp('calm'));
});

// ---------- blinking ----------
t('blinker: blinks within the personality interval, and only briefly', () => {
  let seed = 7; const rng = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
  const b = ch.Blinker(rng, 'friendly', true); let t0 = 0, first = null, shut = 0, total = 0;
  for (let ms = 0; ms < 60000; ms += 10) { const v = b.closed(ms); total++; if (v > 0.5) { shut++; if (first === null) first = ms; } }
  assert.ok(first >= 2500 && first <= 5700, 'first blink at ' + first); assert.ok(shut / total < 0.05, 'eyes shut ' + (shut / total * 100).toFixed(1) + '%');
});
t('blinker is silent when disabled (reduced motion)', () => { const b = ch.Blinker(() => 0.5, 'friendly', false); for (let ms = 0; ms < 20000; ms += 50) assert.strictEqual(b.closed(ms), 0); });
t('calm blinks less often than playful', () => {
  const count = pers => { let seed = 3; const rng = () => (seed = (seed * 16807) % 2147483647) / 2147483647; const b = ch.Blinker(rng, pers, true); let prev = 0, c = 0; for (let ms = 0; ms < 120000; ms += 10) { const v = b.closed(ms); if (v > 0.9 && prev <= 0.9) c++; prev = v; } return c; };
  assert.ok(count('playful') > count('calm'));
});

// ---------- lip shapes ----------
t('visemes: vowels open, lips-closed consonants close, o/u round (English and Bengali)', () => {
  assert.ok(ch.visemeForChar('a').open > ch.visemeForChar('i').open); assert.strictEqual(ch.visemeForChar('m').open, 0); assert.ok(ch.visemeForChar('u').round > 0.9);
  assert.ok(ch.visemeForChar('আ').open > 0.8); assert.strictEqual(ch.visemeForChar('ম').open, 0); assert.ok(ch.visemeForChar('ও').round > 0.5);
  assert.strictEqual(ch.visemeForChar(' ').open, 0); assert.strictEqual(ch.visemeForChar('').open, 0);
});
t('word timeline: one cue per character, ordered, inside the word time', () => {
  const tl = ch.wordTimeline('hello', 500); assert.strictEqual(tl.length, 5); assert.strictEqual(tl[0].t, 0);
  for (let i = 1; i < tl.length; i++) assert.ok(tl[i].t > tl[i - 1].t && tl[i].t < 500);
  assert.strictEqual(ch.cueAt(tl, 0).open, ch.visemeForChar('h').open); assert.ok(ch.cueAt(tl, 499).open >= 0);
  assert.deepStrictEqual(ch.wordTimeline('', 300).length, 1);
});
t('faster speech = shorter words; clamped', () => { assert.ok(ch.wordMsFor('wonderful', 2) < ch.wordMsFor('wonderful', 1)); assert.ok(ch.wordMsFor('a', 1) >= 110); assert.strictEqual(ch.wordMsFor('abc', 99), ch.wordMsFor('abc', 2)); });
t('mic level -> mouth openness is clamped 0..1 and ignores room noise', () => { assert.strictEqual(ch.levelToOpen(0), 0); assert.strictEqual(ch.levelToOpen(0.01), 0); assert.strictEqual(ch.levelToOpen(5), 1); assert.ok(ch.levelToOpen(0.1) > 0 && ch.levelToOpen(0.1) < 1); });
t('syllable wave stays in a natural mouth range', () => { for (let ms = 0; ms < 3000; ms += 37) { const v = ch.waveOpen(ms, 1); assert.ok(v >= 0.25 && v <= 0.6); } });

// ---------- voice: language, sentences, keys, errors, provider choice ----------
t('language detection: Bengali vs English vs mixed', () => {
  assert.strictEqual(vc.detectLang('আমি ভালো আছি'), 'bn-BD'); assert.strictEqual(vc.detectLang('I am fine'), 'en-US'); assert.strictEqual(vc.detectLang('123 !!!'), 'en-US'); assert.strictEqual(vc.detectLang(''), 'en-US');
  assert.strictEqual(vc.detectLang('Okay okay okay: আমি'), 'en-US'); assert.strictEqual(vc.detectLang('Hi আমি তুমি আমরা'), 'bn-BD');
});
t('sentences: split at . ! ? । and newlines, long ones cut at spaces, code and markdown removed', () => {
  assert.deepStrictEqual(vc.splitSentences('Hello there. How are you? Fine!'), ['Hello there.', 'How are you?', 'Fine!']);
  assert.deepStrictEqual(vc.splitSentences('আমি ভালো। তুমি কেমন আছো।'), ['আমি ভালো।', 'তুমি কেমন আছো।']);
  assert.deepStrictEqual(vc.splitSentences('Run this:\n```py\nprint(1)\n```\nDone.'), ['Run this:', 'Done.']);
  const long = vc.splitSentences('word '.repeat(200)); assert.ok(long.length > 1 && long.every(c => c.length <= 220));
  assert.deepStrictEqual(vc.splitSentences(''), []); assert.deepStrictEqual(vc.splitSentences('**bold** and `code`'), ['bold and code']);
});
t('push-to-talk key matching', () => {
  assert.ok(vc.pttMatches({ code: 'Space', key: ' ' }, 'Space')); assert.ok(vc.pttMatches({ key: 'F9' }, 'F9')); assert.ok(!vc.pttMatches({ key: 'F10' }, 'F9')); assert.ok(vc.pttMatches({ key: 'Control' }, 'Control')); assert.ok(!vc.pttMatches({ key: 'a' }, 'Control'));
});
t('push-to-talk never steals keys from a text box (F-keys excepted)', () => {
  const input = { tagName: 'INPUT' }, ta = { tagName: 'TEXTAREA' }, div = { tagName: 'DIV' }, ce = { tagName: 'DIV', isContentEditable: true };
  assert.ok(!vc.pttAllowed(input, 'Space')); assert.ok(!vc.pttAllowed(ta, 'Control')); assert.ok(!vc.pttAllowed(ce, 'Space')); assert.ok(vc.pttAllowed(div, 'Space')); assert.ok(vc.pttAllowed(input, 'F9')); assert.ok(vc.pttAllowed(null, 'Space'));
});
t('mic errors become clear Bengali/English messages', () => {
  assert.ok(vc.micMessage({ name: 'NotAllowedError' }, false).includes('denied')); assert.ok(vc.micMessage({ name: 'NotAllowedError' }, true).includes('denied'));
  assert.ok(vc.micMessage({ error: 'not-allowed' }, true).includes('denied')); assert.ok(vc.micMessage({ name: 'NotFoundError' }, true).includes('No microphone'));
  assert.ok(vc.micMessage({ name: 'NotReadableError' }, true).includes('in use')); assert.ok(vc.micMessage({ name: 'OverconstrainedError' }, true).includes('gone')); assert.ok(vc.micMessage({ error: 'network' }, true).includes('cloud'));
  assert.ok(vc.micMessage({ name: 'Weird' }, true).includes('Weird')); assert.ok(vc.micMessage(null, true).length > 0);
});
t('speech-to-text plan: browser if possible, cloud as fallback, none when nothing can listen', () => {
  assert.deepStrictEqual(vc.sttPlan({ stt_provider: 'browser' }, { sr: true, recorder: true }), { mode: 'browser', fallback: false });
  assert.deepStrictEqual(vc.sttPlan({ stt_provider: 'browser' }, { sr: false, recorder: true }), { mode: 'cloud', fallback: true });
  assert.deepStrictEqual(vc.sttPlan({ stt_provider: 'cloud' }, { sr: true, recorder: true }), { mode: 'cloud', fallback: false });
  assert.deepStrictEqual(vc.sttPlan({ stt_provider: 'cloud' }, { sr: true, recorder: false }), { mode: 'none', fallback: false });
  assert.deepStrictEqual(vc.sttPlan({}, { sr: false, recorder: false }), { mode: 'none', fallback: false });
});
t('voice choice: the chosen voice only if it fits the language, else best match', () => {
  const vs_ = [{ name: 'A', lang: 'en-US' }, { name: 'B', lang: 'bn-BD' }, { name: 'C', lang: 'bn-IN' }, { name: 'D', lang: 'en-GB' }];
  assert.strictEqual(vc.pickVoice(vs_, 'B', 'bn-BD').name, 'B'); assert.strictEqual(vc.pickVoice(vs_, 'A', 'bn-BD').name, 'B'); assert.strictEqual(vc.pickVoice(vs_, '', 'bn-BD').name, 'B');
  assert.strictEqual(vc.pickVoice([{ name: 'C', lang: 'bn-IN' }], '', 'bn-BD').name, 'C'); assert.strictEqual(vc.pickVoice(vs_, '', 'fr-FR'), null); assert.strictEqual(vc.pickVoice([], 'x', 'en-US'), null);
});

// ---------- the shared session logic (same file as the website) ----------
t('wake phrase: only at the start, one slip allowed; random speech is not a wake', () => {
  assert.ok(vs.matchWake('hey canai what time is it').matched); assert.strictEqual(vs.matchWake('hey canai what time is it').rest, 'what time is it'); assert.ok(vs.matchWake('hey kenai').matched);
  assert.ok(!vs.matchWake('I said hey canai yesterday').matched); assert.ok(!vs.matchWake('good morning everyone').matched); assert.ok(vs.matchWake('হে ক্যানএআই আজ কী খবর').matched);
});
t('speech can never approve anything', () => { assert.strictEqual(vs.VOICE_CAN_APPROVE, false); });
t('stop words are recognised locally', () => { assert.ok(vs.isStopWord('stop')); assert.ok(vs.isStopWord('থামো')); assert.ok(!vs.isStopWord('please do not stop me')); });
t('end-pointing: speech then silence ends the utterance; silence alone gives up', () => {
  const ep = vs.Endpointer({ silenceMs: 500, noSpeechMs: 2000 }); ep.start(0); let r = null, now = 0;
  for (; now < 400; now += 50) r = ep.feed(0.2, now) || r; assert.strictEqual(r, 'speech_start'); r = null;
  for (; now < 1500; now += 50) { const x = ep.feed(0, now); if (x) { r = x; break; } } assert.strictEqual(r, 'end');
  const q = vs.Endpointer({ noSpeechMs: 1000 }); q.start(0); let g = null; for (let m = 0; m < 1500; m += 50) g = q.feed(0, m) || g; assert.strictEqual(g, 'no_speech');
});
t('WebView2-style engine that exists but fails is remembered, and diagnostics are readable', () => {
  assert.strictEqual(vc.srBrokenError('network'), true); assert.strictEqual(vc.srBrokenError('service-not-allowed'), true); assert.strictEqual(vc.srBrokenError('not-allowed'), true);
  assert.strictEqual(vc.srBrokenError('no-speech'), false); assert.strictEqual(vc.srBrokenError('aborted'), false);
  assert.deepStrictEqual(vc.sttPlan({ stt_provider: 'browser' }, { sr: true, srBroken: true, recorder: true }), { mode: 'cloud', fallback: true });
  assert.deepStrictEqual(vc.sttPlan({ stt_provider: 'browser' }, { sr: true, srBroken: true, recorder: false }), { mode: 'none', fallback: false });
  const d = vc.diagnose({ sr: true, srBroken: true, recorder: true, tts: false }, { stt_provider: 'browser' }, { secure: true, permission: 'granted', inputs: 2, lastError: 'x', ua: 'UA' });
  for (const part of ['secure context: true', 'failed earlier', 'recorder', 'speech synthesis: missing', 'permission: granted', 'inputs seen: 2', 'plan: cloud', 'uses credits', 'last error: x', 'user agent: UA']) assert.ok(d.includes(part), part);
  assert.ok(!/token|password/i.test(d));
});
t('real audio level drives the mouth (cloud voice)', () => {
  const cc = ui('character.js'); const f = cc.speechLevelToViseme;
  assert.strictEqual(f(0).open, 0); assert.strictEqual(f(0.01).open, 0);
  assert.ok(f(0.1).open > 0.3 && f(0.1).open < 0.6); assert.strictEqual(f(1).open, 1); assert.ok(f(0.3).round > 0);
  assert.ok(f(0.05).open < f(0.1).open && f(0.1).open < f(0.2).open);
  const loud = cc.computePose('speaking', 1000, { viseme: f(0.2) }), quiet = cc.computePose('speaking', 1000, { viseme: f(0) });
  assert.ok(loud.mouthOpen > 0.8 && quiet.mouthOpen === 0);
});
console.log('\n' + n + ' tests passed');
