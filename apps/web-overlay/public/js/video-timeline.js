/* Scene timeline helpers for the video studio form.  Pure functions (no DOM) so they can be tested with node. */
(function (root) {
    'use strict';
    var MAX_SCENES = 10, MAX_TOTAL = 60, MIN_SEC = 2, MAX_SEC = 8;

    /** New order of indexes after moving the item at `from` to position `to` (both clamped). */
    function order(n, from, to) {
        var idx = [], i;
        for (i = 0; i < n; i++) idx.push(i);
        if (n === 0 || from < 0 || from >= n) return idx;
        to = Math.max(0, Math.min(n - 1, to));
        idx.splice(to, 0, idx.splice(from, 1)[0]);
        return idx;
    }

    /** Seconds for a scene: a number between 2 and 8 in steps of 0.1; anything unreadable becomes 4. */
    function clampSeconds(v) {
        var n = parseFloat(v);
        if (!isFinite(n)) return 4;
        return Math.round(Math.max(MIN_SEC, Math.min(MAX_SEC, n)) * 10) / 10;
    }

    /** Timeline blocks: start second, length and width in percent (summing to 100). */
    function segments(seconds) {
        var total = 0, out = [], t = 0, i, s;
        for (i = 0; i < seconds.length; i++) total += seconds[i];
        if (total <= 0) return out;
        for (i = 0; i < seconds.length; i++) {
            s = seconds[i];
            out.push({start: Math.round(t * 10) / 10, len: s, pct: s / total * 100});
            t += s;
        }
        return out;
    }

    /** Message about the whole video: total length and whether it can be made. */
    function summary(seconds) {
        var total = 0, i;
        for (i = 0; i < seconds.length; i++) total += seconds[i];
        total = Math.round(total * 10) / 10;
        if (seconds.length > MAX_SCENES) return {ok: false, total: total, text: 'max ' + MAX_SCENES + ' scenes'};
        if (total > MAX_TOTAL) return {ok: false, total: total, text: 'Total ' + total + ' seconds — it has gone over 60 seconds!'};
        return {ok: true, total: total, text: 'Total ' + total + ' seconds · ' + seconds.length + ' scenes'};
    }

    var api = {order: order, clampSeconds: clampSeconds, segments: segments, summary: summary, MAX_SCENES: MAX_SCENES, MAX_TOTAL: MAX_TOTAL};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.VideoTimeline = api;
})(typeof window !== 'undefined' ? window : this);
