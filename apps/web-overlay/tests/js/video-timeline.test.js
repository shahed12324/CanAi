const test = require('node:test');
const assert = require('node:assert');
const T = require('../../public/js/video-timeline.js');

test('order moves one item and keeps the others in sequence', () => {
    assert.deepStrictEqual(T.order(4, 0, 2), [1, 2, 0, 3]);
    assert.deepStrictEqual(T.order(4, 3, 0), [3, 0, 1, 2]);
    assert.deepStrictEqual(T.order(4, 1, 1), [0, 1, 2, 3]);
});
test('order clamps the target and ignores a bad source', () => {
    assert.deepStrictEqual(T.order(3, 0, 99), [1, 2, 0]);
    assert.deepStrictEqual(T.order(3, 2, -5), [2, 0, 1]);
    assert.deepStrictEqual(T.order(3, -1, 1), [0, 1, 2]);
    assert.deepStrictEqual(T.order(3, 3, 1), [0, 1, 2]);
    assert.deepStrictEqual(T.order(0, 0, 0), []);
    assert.deepStrictEqual(T.order(1, 0, 0), [0]);
});
test('clampSeconds keeps 2..8 in tenths and defaults to 4', () => {
    assert.strictEqual(T.clampSeconds('1'), 2);
    assert.strictEqual(T.clampSeconds(99), 8);
    assert.strictEqual(T.clampSeconds('3.14'), 3.1);
    assert.strictEqual(T.clampSeconds(''), 4);
    assert.strictEqual(T.clampSeconds('abc'), 4);
    assert.strictEqual(T.clampSeconds(2), 2);
    assert.strictEqual(T.clampSeconds(8), 8);
});
test('segments give start, length and percentages that sum to 100', () => {
    const s = T.segments([4, 2, 2]);
    assert.deepStrictEqual(s.map(x => x.start), [0, 4, 6]);
    assert.deepStrictEqual(s.map(x => x.len), [4, 2, 2]);
    assert.ok(Math.abs(s.reduce((a, x) => a + x.pct, 0) - 100) < 1e-9);
    assert.strictEqual(s[0].pct, 50);
    assert.deepStrictEqual(T.segments([]), []);
    assert.deepStrictEqual(T.segments([0, 0]), []);
});
test('summary flags more than 60 seconds or 10 scenes', () => {
    assert.strictEqual(T.summary([4, 4]).ok, true);
    assert.strictEqual(T.summary([4, 4]).total, 8);
    assert.strictEqual(T.summary(new Array(10).fill(6)).ok, true);
    assert.strictEqual(T.summary(new Array(10).fill(6.1)).ok, false);
    assert.strictEqual(T.summary(new Array(11).fill(2)).ok, false);
    assert.match(T.summary([8, 8, 8, 8, 8, 8, 8, 8]).text, /৬০ সেকেন্ডের বেশি/);
    assert.strictEqual(T.summary([0.1, 0.2]).total, 0.3);
});
