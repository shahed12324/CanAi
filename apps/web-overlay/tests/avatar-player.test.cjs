const assert = require('assert');
const { cueAt, url, play } = require('../public/js/avatar-player.js');
const tl = [{ t: 0, m: 'open' }, { t: 100, m: 'round' }, { t: 250, m: 'closed' }];
assert.strictEqual(cueAt(tl, 0), 'open');
assert.strictEqual(cueAt(tl, 99), 'open');
assert.strictEqual(cueAt(tl, 100), 'round');
assert.strictEqual(cueAt(tl, 9999), 'closed');
assert.strictEqual(cueAt([{ t: 50, m: 'wide' }], 10), 'closed');
assert.strictEqual(cueAt([], 10), 'closed');
assert.strictEqual(cueAt(null, 10), 'closed');
assert.strictEqual(url('/a.svg', 'happy', 'o&x'), '/a.svg?expression=happy&mouth=o%26x');
// play: fake timers
const img = { src: '' }; let t = 1000; const realNow = Date.now; Date.now = () => t;
const timers = []; global.setTimeout = (f) => { timers.push(f); return timers.length; }; global.clearTimeout = () => {};
const stop = play(img, '/a.svg', { expression: 'sad', duration_ms: 200, timeline: tl });
assert.strictEqual(img.src, '/a.svg?expression=sad&mouth=open');
t = 1120; timers.shift()(); assert.strictEqual(img.src, '/a.svg?expression=sad&mouth=round');
t = 1300; timers.shift()(); assert.strictEqual(img.src, '/a.svg?expression=sad&mouth=closed');
assert.strictEqual(timers.length, 0, 'no more ticks after the end');
const stop2 = play(img, '/b.svg', { expression: 'happy', duration_ms: 1000, timeline: tl }); stop2();
assert.strictEqual(img.src, '/b.svg?expression=neutral&mouth=closed');

// playSynced: the audio clock decides
timers.length = 0;
const { playSynced } = require('../public/js/avatar-player.js');
const au = { currentTime: 0, paused: false, ended: false, error: null, played: 0, pauses: 0, play() { this.played++; return Promise.resolve(); }, pause() { this.pauses++; this.paused = true; } };
const img2 = { src: '' };
const stop3 = playSynced(img2, '/c.svg', { expression: 'happy', duration_ms: 400, timeline: tl }, au);
assert.strictEqual(au.played, 1);
assert.strictEqual(img2.src, '/c.svg?expression=happy&mouth=open');
au.currentTime = 0.12; timers.shift()(); assert.strictEqual(img2.src, '/c.svg?expression=happy&mouth=round');
au.paused = true; timers.shift()(); assert.strictEqual(img2.src, '/c.svg?expression=happy&mouth=closed');   // paused audio = closed mouth
au.paused = false; au.currentTime = 0.01; timers.shift()(); assert.strictEqual(img2.src, '/c.svg?expression=happy&mouth=open');
au.ended = true; timers.shift()(); assert.strictEqual(img2.src, '/c.svg?expression=neutral&mouth=closed');
assert.strictEqual(timers.length, 0);
const au2 = { currentTime: 0, paused: false, ended: false, error: null, play() { return Promise.reject(new Error('blocked')); }, pause() {} };
const img3 = { src: '' };
playSynced(img3, '/d.svg', { expression: 'sad', duration_ms: 400, timeline: tl }, au2);
Promise.resolve().then(() => Promise.resolve()).then(() => { assert.strictEqual(img3.src, '/d.svg?expression=neutral&mouth=closed', 'play() refused -> calm face'); console.log('playSynced ok'); });
const au3 = { currentTime: 0, paused: false, ended: false, error: null, pauses: 0, play() { return undefined; }, pause() { this.pauses++; } };
const stop4 = playSynced({ src: '' }, '/e.svg', { expression: 'happy', duration_ms: 400, timeline: tl }, au3); stop4(); stop4();
assert.strictEqual(au3.pauses, 1, 'stop pauses once');
Date.now = realNow; console.log('avatar-player ok');
