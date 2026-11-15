// Runs the real scene-form script from the blade view inside jsdom (skipped when jsdom is not installed).
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

let JSDOM = null;
try { ({JSDOM} = require('jsdom')); } catch (e) { try { ({JSDOM} = require('/tmp/jsd/node_modules/jsdom')); } catch (e2) { /* skipped */ } }
const skip = JSDOM ? false : 'jsdom not installed';

const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/video/index.blade.php'), 'utf8');
const tpl = blade.match(/<template id="scene-tpl">([\s\S]*?)<\/template>/)[1].replace(/@foreach[\s\S]*?@endforeach/, '<option value="dark">d</option><option value="light">l</option>');
const script = blade.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/@json\(route\('video\.script'\)\)/, '"/script"').replace(/@json\(\$rid\)/, '"r"');
const lib = fs.readFileSync(path.join(__dirname, '../../public/js/video-timeline.js'), 'utf8');

function page() {
    const html = `<form><input name="_token" value="t"><input name="title"><textarea name="narration"></textarea><textarea id="brief"></textarea>
      <input id="brief-n" value="3"><button type="button" id="write-script"></button><span id="script-msg"></span>
      <div id="timeline"></div><div id="scenes"></div><button type="button" id="add-scene"></button><span id="total"></span>
      <template id="scene-tpl">${tpl}</template></form>`;
    const dom = new JSDOM(`<!doctype html><body>${html}</body>`, {runScripts: 'outside-only', pretendToBeVisual: true});
    const w = dom.window;
    w.Element.prototype.scrollIntoView = function () {};
    w.eval(lib);
    w.eval(script);
    return w;
}
const scenes = w => Array.from(w.document.querySelectorAll('#scenes .scene'));
const set = (el, f, v) => { el.querySelector(`[data-f=${f}]`).value = v; el.querySelector(`[data-f=${f}]`).dispatchEvent(new el.ownerDocument.defaultView.Event('input')); };
const names = w => scenes(w).map(s => s.querySelector('[data-f=headline]').name);

test('starts with one scene, numbered and named for the server', {skip}, () => {
    const w = page();
    assert.strictEqual(scenes(w).length, 1);
    assert.deepStrictEqual(names(w), ['scenes[0][headline]']);
    assert.match(w.document.getElementById('total').textContent, /মোট 4 সেকেন্ড/);
    assert.strictEqual(w.document.querySelectorAll('#timeline button').length, 1);
});

test('adding scenes stops at 10 and the timeline follows the seconds', {skip}, () => {
    const w = page();
    const add = w.document.getElementById('add-scene');
    for (let i = 0; i < 12; i++) add.click();
    assert.strictEqual(scenes(w).length, 10);
    assert.ok(add.disabled);
    const [first] = scenes(w);
    set(first, 'seconds', '8');
    const widths = Array.from(w.document.querySelectorAll('#timeline button')).map(b => parseFloat(b.style.width));
    assert.ok(widths[0] > widths[1]);
    assert.ok(Math.abs(widths.reduce((a, b) => a + b, 0) - 100) < 1e-6);
});

test('over 60 seconds is flagged in red', {skip}, () => {
    const w = page();
    const add = w.document.getElementById('add-scene');
    for (let i = 0; i < 9; i++) add.click();
    scenes(w).forEach(s => set(s, 'seconds', '7'));
    const t = w.document.getElementById('total');
    assert.match(t.textContent, /৬০ সেকেন্ডের বেশি/);
    assert.ok(t.style.color !== '');
});

test('down / up move a scene, keep its text and renumber the field names', {skip}, () => {
    const w = page();
    const add = w.document.getElementById('add-scene');
    add.click(); add.click();
    scenes(w).forEach((s, i) => set(s, 'headline', 'H' + i));
    scenes(w)[0].querySelector('.down').click();
    assert.deepStrictEqual(scenes(w).map(s => s.querySelector('[data-f=headline]').value), ['H1', 'H0', 'H2']);
    assert.deepStrictEqual(names(w), ['scenes[0][headline]', 'scenes[1][headline]', 'scenes[2][headline]']);
    scenes(w)[2].querySelector('.up').click();
    assert.deepStrictEqual(scenes(w).map(s => s.querySelector('[data-f=headline]').value), ['H1', 'H2', 'H0']);
    assert.ok(scenes(w)[0].querySelector('.up').disabled && scenes(w)[2].querySelector('.down').disabled);
});

test('duplicate copies the text right after the original, not the photo', {skip}, () => {
    const w = page();
    set(scenes(w)[0], 'headline', 'ঈদ'); set(scenes(w)[0], 'seconds', '6');
    scenes(w)[0].querySelector('.dup').click();
    const s = scenes(w);
    assert.strictEqual(s.length, 2);
    assert.strictEqual(s[1].querySelector('[data-f=headline]').value, 'ঈদ');
    assert.strictEqual(s[1].querySelector('[data-f=seconds]').value, '6');
    assert.strictEqual(s[1].querySelector('[data-f=photo]').value, '');
});

test('remove keeps at least one scene', {skip}, () => {
    const w = page();
    w.document.getElementById('add-scene').click();
    scenes(w)[0].querySelector('.rm').click();
    assert.strictEqual(scenes(w).length, 1);
    assert.ok(scenes(w)[0].querySelector('.rm').disabled);
    scenes(w)[0].querySelector('.rm').click();
    assert.strictEqual(scenes(w).length, 1);
});

test('dragging one scene onto another moves it', {skip}, () => {
    const w = page();
    const add = w.document.getElementById('add-scene');
    add.click(); add.click();
    scenes(w).forEach((s, i) => set(s, 'headline', 'H' + i));
    const [a, , c] = scenes(w);
    const ev = (type) => { const e = new w.Event(type, {bubbles: true, cancelable: true}); e.dataTransfer = {setData() {}, effectAllowed: ''}; return e; };
    a.querySelector('.drag').dispatchEvent(ev('dragstart'));
    c.dispatchEvent(ev('drop'));
    assert.deepStrictEqual(scenes(w).map(s => s.querySelector('[data-f=headline]').value), ['H1', 'H2', 'H0']);
});

test('timeline blocks scroll to their scene', {skip}, () => {
    const w = page();
    w.document.getElementById('add-scene').click();
    let hit = -1;
    scenes(w).forEach((s, i) => { s.scrollIntoView = () => { hit = i; }; });
    w.document.querySelectorAll('#timeline button')[1].click();
    assert.strictEqual(hit, 1);
});

test('the AI script fills the form and replaces the scenes', {skip}, async () => {
    const w = page();
    w.document.getElementById('brief').value = 'ঈদ অফার';
    w.fetch = () => Promise.resolve({ok: true, json: () => Promise.resolve({ok: true, script: {title: 'ঈদ', narration: 'স্বাগতম', scenes: [
        {headline: 'এক', subline: 'ক', cta: '', seconds: 3}, {headline: 'দুই', subline: 'খ', cta: 'Order', seconds: 5}]}})});
    w.document.getElementById('write-script').click();
    await new Promise(r => setTimeout(r, 20));
    assert.deepStrictEqual(scenes(w).map(s => s.querySelector('[data-f=headline]').value), ['এক', 'দুই']);
    assert.strictEqual(scenes(w)[1].querySelector('[data-f=cta]').value, 'Order');
    assert.strictEqual(w.document.querySelector('[name=narration]').value, 'স্বাগতম');
    assert.strictEqual(w.document.querySelector('[name=title]').value, 'ঈদ');
    assert.match(w.document.getElementById('total').textContent, /মোট 8 সেকেন্ড/);
});

test('a failed script request shows the server message and keeps the scenes', {skip}, async () => {
    const w = page();
    w.document.getElementById('brief').value = 'x';
    w.fetch = () => Promise.resolve({ok: false, json: () => Promise.resolve({ok: false, message: 'Credits ran out.'})});
    w.document.getElementById('write-script').click();
    await new Promise(r => setTimeout(r, 20));
    assert.strictEqual(w.document.getElementById('script-msg').textContent, 'Credits ran out.');
    assert.strictEqual(scenes(w).length, 1);
    assert.ok(!w.document.getElementById('write-script').disabled);
});

test('duplicate does nothing once there are 10 scenes', {skip}, () => {
    const w = page();
    const add = w.document.getElementById('add-scene');
    for (let i = 0; i < 9; i++) add.click();
    assert.strictEqual(scenes(w).length, 10);
    scenes(w)[3].querySelector('.dup').click();
    assert.strictEqual(scenes(w).length, 10);
});
