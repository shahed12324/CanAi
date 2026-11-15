// node tests/js/workflow-builder.test.js   (pure model of the visual builder; the DOM part is tested in a real browser: tests/browser/test_workflow_builder.py)
const assert = require('assert');
const W = require('../../public/js/workflow-builder.js');
let n = 0;
function t(name, fn) { fn(); n++; console.log('ok  ' + name); }

const TOOLS = {
  'fake.read': { label: 'Read', risk: 'read', args: [{ name: 'text', optional: false, label: 'T' }], desktop: false },
  'fake.ext': { label: 'Send', risk: 'external', args: [], desktop: false },
  'fake.pay': { label: 'Pay', risk: 'money', args: [], desktop: false },
  'pc.read_file': { label: 'PC', risk: 'write_own', args: [{ name: 'path', optional: false, label: 'P' }], desktop: true },
};

function simple() {
  return W.normalise({ start: 'a', nodes: { a: { type: 'ai', prompt: 'hi {{x}}', save_as: 'x', max_tokens: 600, next: 'b' }, b: { type: 'output', text: '{{x}}' } } });
}

t('normalise fills layout/inputs and picks a start', () => {
  const d = W.normalise({ nodes: { z: { type: 'output', text: '' } } });
  assert.strictEqual(d.start, 'z'); assert.deepStrictEqual(d.inputs, []); assert.deepStrictEqual(d.layout, {});
  const e = W.normalise(null); assert.strictEqual(e.start, null); assert.deepStrictEqual(e.nodes, {});
  assert.deepStrictEqual(W.normalise({ nodes: [1, 2] }).nodes, {});
});

t('addNode creates unique ids, positions, and makes the first node the start', () => {
  const d = W.normalise({});
  const a = W.addNode(d, 'ai', { x: 10.6, y: -4 });
  const b = W.addNode(d, 'ai', { x: 300, y: 20 });
  assert.deepStrictEqual([a, b], ['ai1', 'ai2']);
  assert.strictEqual(d.start, 'ai1');
  assert.deepStrictEqual(d.layout.ai1, { x: 11, y: 0 });
  assert.strictEqual(W.addNode(d, 'nonsense'), null);
  for (let i = 0; i < 40; i++) W.addNode(d, 'output');
  assert.strictEqual(Object.keys(d.nodes).length, W.MAX_NODES);
  assert.strictEqual(W.addNode(d, 'ai'), null);
});

t('removeNode relinks whatever pointed at it and moves the start', () => {
  const d = W.normalise({ start: 'a', nodes: { a: { type: 'ai', prompt: 'p', save_as: 'x', next: 'b' }, b: { type: 'delay', minutes: 5, next: 'c' }, c: { type: 'output', text: '' } }, layout: { a: { x: 1, y: 1 }, b: { x: 2, y: 2 }, c: { x: 3, y: 3 } } });
  W.removeNode(d, 'b');
  assert.strictEqual(d.nodes.a.next, 'c'); assert.ok(!d.nodes.b); assert.ok(!d.layout.b);
  W.removeNode(d, 'a');
  assert.strictEqual(d.start, 'c');
  W.removeNode(d, 'c');
  assert.strictEqual(d.start, null);
  W.removeNode(d, 'nope');
});

t('removeNode on a condition target clears it (no dangling link)', () => {
  const d = W.normalise({ start: 'c', nodes: { c: { type: 'condition', 'var': 'x', op: 'empty', value: '', then: 'o1', 'else': 'o2' }, o1: { type: 'output', text: '' }, o2: { type: 'output', text: '' } } });
  W.removeNode(d, 'o1');
  assert.strictEqual(d.nodes.c.then, ''); assert.strictEqual(d.nodes.c['else'], 'o2');
});

t('setLink only accepts real targets, never itself, never unknown fields', () => {
  const d = simple();
  assert.ok(W.setLink(d, 'a', 'next', 'b'));
  assert.ok(!W.setLink(d, 'a', 'next', 'ghost'));
  assert.ok(!W.setLink(d, 'a', 'next', 'a'));
  assert.ok(!W.setLink(d, 'a', 'prompt', 'b'));
  assert.ok(!W.setLink(d, 'zz', 'next', 'b'));
  assert.ok(W.setLink(d, 'a', 'next', ''));
  assert.strictEqual(d.nodes.a.next, '');
});

t('linksOf lists next/then/else/join and parallel branches', () => {
  assert.deepStrictEqual(W.linksOf({ type: 'condition', then: 'x', 'else': 'y' }).map(l => l.field + ':' + l.target), ['then:x', 'else:y']);
  assert.deepStrictEqual(W.linksOf({ type: 'parallel', branches: ['p', 'q'], join: 'j' }).map(l => l.field + ':' + l.target), ['join:j', 'branch1:p', 'branch2:q']);
  assert.deepStrictEqual(W.linksOf({ type: 'output' }), []);
});

t('autoLayout puts nodes in columns by depth, unreachable ones in column 0 below', () => {
  const d = W.normalise({ start: 'a', nodes: { a: { type: 'ai', next: 'b' }, b: { type: 'ai', next: 'c' }, c: { type: 'output' }, lone: { type: 'output' } } });
  W.autoLayout(d);
  assert.ok(d.layout.a.x < d.layout.b.x && d.layout.b.x < d.layout.c.x);
  assert.strictEqual(d.layout.lone.x, d.layout.a.x);
  assert.ok(d.layout.lone.y > d.layout.a.y);
});

t('problems: a complete flow has none', () => {
  assert.deepStrictEqual(W.problems(simple(), TOOLS), []);
});

t('problems: finds empty prompt, missing link, unreachable step, missing output, loop, bad tool', () => {
  let d = simple(); d.nodes.a.prompt = ' ';
  assert.ok(W.problems(d, TOOLS).some(p => /instructions/.test(p)));
  d = simple(); d.nodes.a.next = '';
  assert.ok(W.problems(d, TOOLS).some(p => /connect "next"/.test(p)));
  assert.ok(W.problems(d, TOOLS).some(p => /not connected to the start/.test(p)));
  assert.ok(W.problems(d, TOOLS).some(p => /Result/.test(p)));
  d = simple(); d.nodes.b = { type: 'ai', prompt: 'p', save_as: 'y', next: 'a' };
  assert.ok(W.problems(d, TOOLS).some(p => /loop/.test(p)));
  d = simple(); d.nodes.a = { type: 'tool', tool: 'fake.pay', args: {}, next: 'b' };
  assert.ok(W.problems(d, TOOLS).some(p => /money/.test(p)));
  d.nodes.a = { type: 'tool', tool: 'fake.read', args: {}, next: 'b' };
  assert.ok(W.problems(d, TOOLS).some(p => /fill in "text"/.test(p)));
  d.nodes.a = { type: 'tool', tool: '', args: {}, next: 'b' };
  assert.ok(W.problems(d, TOOLS).some(p => /choose a tool/.test(p)));
  assert.deepStrictEqual(W.problems(W.normalise({}), TOOLS), ['Add at least one step.']);
});

t('toDefinition keeps start/nodes/inputs and only layout of existing nodes', () => {
  const d = simple(); d.layout = { a: { x: 1, y: 2 }, ghost: { x: 9, y: 9 } }; d.inputs = [{ key: 'q', label: 'Q' }];
  const o = W.toDefinition(d);
  assert.deepStrictEqual(Object.keys(o), ['start', 'nodes', 'inputs', 'layout']);
  assert.deepStrictEqual(o.layout, { a: { x: 1, y: 2 } });
  assert.ok(!('inputs' in W.toDefinition(simple())));
});

console.log(n + ' passed');
