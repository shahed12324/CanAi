/*
 * Visual workflow builder (CAN AI "Agents & Workflows").
 * - The page gives it a definition (data-definition), the tool list (data-tools) and a form with a hidden field; every change rewrites that field,
 *   the server validates it again (WorkflowDefinition::validate) - nothing here is trusted.
 * - Pure model functions (addNode / removeNode / setLink / autoLayout / problems / toDefinition) are exported for tests (node + a real browser).
 * - All text is written with textContent, never innerHTML, so nothing a person typed can run as script.
 */
(function (global) {
  'use strict';

  var TYPES = {
    ai: { label: 'AI step', icon: '\u2726' },
    tool: { label: 'Tool', icon: '\u2699' },
    condition: { label: 'Condition', icon: '\u2442' },
    approval: { label: 'Approval', icon: '\u270b' },
    delay: { label: 'Wait', icon: '\u23f1' },
    output: { label: 'Result', icon: '\u2691' },
    parallel: { label: 'Parallel', icon: '\u21c9' }
  };
  var PALETTE = ['ai', 'tool', 'condition', 'approval', 'delay', 'output'];
  var OPS = ['empty', 'not_empty', 'contains', 'equals', 'gt', 'lt'];
  var MAX_NODES = 30;
  var NODE_W = 230;
  var LINK_FIELDS = ['next', 'then', 'else', 'join'];

  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  function normalise(def) {
    var d = def && typeof def === 'object' ? clone(def) : {};
    if (!d.nodes || Array.isArray(d.nodes) || typeof d.nodes !== 'object') { d.nodes = {}; }
    if (!d.layout || typeof d.layout !== 'object') { d.layout = {}; }
    if (!Array.isArray(d.inputs)) { d.inputs = []; }
    if (typeof d.start !== 'string' || !d.nodes[d.start]) { d.start = Object.keys(d.nodes)[0] || null; }
    return d;
  }

  function nextId(def, type) {
    var base = { ai: 'ai', tool: 'tool', condition: 'check', approval: 'approve', delay: 'wait', output: 'out' }[type] || 'step';
    var i = 1;
    while (def.nodes[base + i]) { i++; }
    return base + i;
  }

  var DEFAULTS = {
    ai: function () { return { type: 'ai', prompt: '', save_as: 'result', max_tokens: 600, next: '' }; },
    tool: function () { return { type: 'tool', tool: '', args: {}, next: '' }; },
    condition: function () { return { type: 'condition', 'var': '', op: 'not_empty', value: '', then: '', 'else': '' }; },
    approval: function () { return { type: 'approval', message: '', next: '' }; },
    delay: function () { return { type: 'delay', minutes: 5, next: '' }; },
    output: function () { return { type: 'output', text: '' }; }
  };

  /** @returns the new node id, or null when the workflow is full / the type is unknown */
  function addNode(def, type, pos) {
    if (!DEFAULTS[type] || Object.keys(def.nodes).length >= MAX_NODES) { return null; }
    var id = nextId(def, type);
    def.nodes[id] = DEFAULTS[type]();
    def.layout[id] = { x: Math.max(0, Math.round(pos && pos.x || 20)), y: Math.max(0, Math.round(pos && pos.y || 20)) };
    if (!def.start) { def.start = id; }
    return id;
  }

  /** Removes a node; whatever pointed at it now points to where it led (so the chain stays connected). */
  function removeNode(def, id) {
    var n = def.nodes[id];
    if (!n) { return; }
    var after = n.next || n.join || '';
    Object.keys(def.nodes).forEach(function (k) {
      if (k === id) { return; }
      var o = def.nodes[k];
      LINK_FIELDS.forEach(function (f) { if (o[f] === id) { o[f] = after === id ? '' : after; } });
      if (Array.isArray(o.branches)) { o.branches = o.branches.map(function (b) { return b === id ? after : b; }); }
    });
    delete def.nodes[id];
    delete def.layout[id];
    if (def.start === id) { def.start = (after && def.nodes[after]) ? after : (Object.keys(def.nodes)[0] || null); }
  }

  function linksOf(node) {
    var out = [];
    LINK_FIELDS.forEach(function (f) { if (typeof node[f] === 'string' && node[f] !== '') { out.push({ field: f, target: node[f] }); } });
    if (Array.isArray(node.branches)) { node.branches.forEach(function (b, i) { if (typeof b === 'string' && b) { out.push({ field: 'branch' + (i + 1), target: b }); } }); }
    return out;
  }

  function setLink(def, id, field, target) {
    if (!def.nodes[id] || LINK_FIELDS.indexOf(field) < 0) { return false; }
    if (target !== '' && !def.nodes[target]) { return false; }
    if (target === id) { return false; }
    def.nodes[id][field] = target;
    return true;
  }

  /** Columns by distance from the start; nodes that cannot be reached go underneath. */
  function autoLayout(def) {
    var depth = {};
    var queue = def.start && def.nodes[def.start] ? [def.start] : [];
    if (queue.length) { depth[queue[0]] = 0; }
    while (queue.length) {
      var cur = queue.shift();
      linksOf(def.nodes[cur]).forEach(function (l) {
        if (def.nodes[l.target] && depth[l.target] === undefined) { depth[l.target] = depth[cur] + 1; queue.push(l.target); }
      });
    }
    var rows = {};
    var extra = 0;
    Object.keys(def.nodes).forEach(function (id) {
      var col = depth[id];
      if (col === undefined) { col = 0; rows[col] = (rows[col] || 0) + 4 + extra; extra = 0; }
      var row = rows[col] || 0;
      rows[col] = row + 1;
      def.layout[id] = { x: 20 + col * (NODE_W + 50), y: 20 + row * 150 };
    });
  }

  /** Quick, friendly hints before saving.  The server's validation is the real one. */
  function problems(def, tools) {
    var p = [];
    var ids = Object.keys(def.nodes);
    if (!ids.length) { return ['Add at least one step.']; }
    if (!def.start || !def.nodes[def.start]) { p.push('Choose a start step.'); }
    var reach = {};
    (function walk(id) {
      if (!id || reach[id] || !def.nodes[id]) { return; }
      reach[id] = true;
      linksOf(def.nodes[id]).forEach(function (l) { walk(l.target); });
    })(def.start);
    var hasOutput = false;
    ids.forEach(function (id) {
      var n = def.nodes[id];
      if (n.type === 'output') { hasOutput = hasOutput || !!reach[id]; }
      if (!reach[id]) { p.push('"' + id + '" is not connected to the start.'); }
      if (n.type === 'ai' && !String(n.prompt || '').trim()) { p.push('"' + id + '": write the AI instructions (prompt).'); }
      if (n.type === 'ai' && !String(n.save_as || '').trim()) { p.push('"' + id + '": give the AI result a name (save as).'); }
      if (n.type === 'tool') {
        var spec = tools && tools[n.tool];
        if (!spec) { p.push('"' + id + '": choose a tool.'); } else {
          spec.args.forEach(function (a) { if (!a.optional && !String((n.args || {})[a.name] || '').trim()) { p.push('"' + id + '": fill in "' + a.name + '".'); } });
          if (spec.risk === 'money') { p.push('"' + id + '": money tools are not allowed.'); }
        }
      }
      if (n.type === 'condition' && !String(n['var'] || '').trim()) { p.push('"' + id + '": choose the variable to check.'); }
      if (n.type === 'approval' && !String(n.message || '').trim()) { p.push('"' + id + '": write the approval question.'); }
      if (n.type !== 'output') {
        var need = n.type === 'condition' ? ['then', 'else'] : (n.type === 'parallel' ? [] : ['next']);
        need.forEach(function (f) { if (!n[f]) { p.push('"' + id + '": connect "' + f + '" to another step.'); } });
      }
    });
    if (!hasOutput) { p.push('At least one reachable "Result" step is needed to finish.'); }
    var pathBack = (function () {
      var state = {};
      var bad = false;
      (function dfs(id) {
        if (!def.nodes[id] || bad) { return; }
        if (state[id] === 1) { bad = true; return; }
        if (state[id] === 2) { return; }
        state[id] = 1;
        linksOf(def.nodes[id]).forEach(function (l) { dfs(l.target); });
        state[id] = 2;
      })(def.start);
      return bad;
    })();
    if (pathBack) { p.push('The steps loop back to an earlier step - loops are not supported.'); }
    return p;
  }

  function toDefinition(def) {
    var out = { start: def.start, nodes: def.nodes };
    if (def.inputs && def.inputs.length) { out.inputs = def.inputs; }
    var lay = {};
    Object.keys(def.layout || {}).forEach(function (k) { if (def.nodes[k]) { lay[k] = def.layout[k]; } });
    if (Object.keys(lay).length) { out.layout = lay; }
    return out;
  }

  var api = { TYPES: TYPES, PALETTE: PALETTE, OPS: OPS, MAX_NODES: MAX_NODES, normalise: normalise, addNode: addNode, removeNode: removeNode, linksOf: linksOf, setLink: setLink, autoLayout: autoLayout, problems: problems, toDefinition: toDefinition, nextId: nextId };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  global.WFB = api;
  if (typeof document === 'undefined') { return; }

  // ------------------------------------------------------------------ DOM
  var CSS = '' +
    '.wfb{display:grid;grid-template-columns:150px 1fr 300px;gap:10px;color:#e8e6ff;font-size:13px}' +
    '@media(max-width:900px){.wfb{grid-template-columns:1fr}}' +
    '.wfb button,.wfb select,.wfb input,.wfb textarea{font:inherit}' +
    '.wfb-palette,.wfb-inspector{background:#14102b;border:1px solid #3b2f7a;border-radius:10px;padding:8px}' +
    '.wfb-palette h4,.wfb-inspector h4{margin:0 0 6px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#a99cff}' +
    '.wfb-pal{display:flex;gap:6px;align-items:center;width:100%;margin:0 0 6px;padding:6px 8px;border:1px solid #4b3fa0;border-radius:8px;background:#1d1745;color:#fff;cursor:grab;text-align:left}' +
    '.wfb-pal:hover{border-color:#7c5cff;background:#241b58}' +
    '.wfb-canvas{position:relative;min-height:540px;overflow:auto;border:1px solid #3b2f7a;border-radius:10px;background:radial-gradient(circle at 20% 0,#241a5a 0,#0f0b24 60%)}' +
    '.wfb-canvas.drop{outline:2px dashed #7c5cff}' +
    '.wfb-links{position:absolute;left:0;top:0;pointer-events:none}' +
    '.wfb-node{position:absolute;width:' + NODE_W + 'px;background:#1a1440;border:1px solid #4b3fa0;border-radius:10px;box-shadow:0 4px 14px rgba(0,0,0,.4)}' +
    '.wfb-node.sel{border-color:#60a5fa;box-shadow:0 0 0 2px rgba(96,165,250,.35)}' +
    '.wfb-node.start{border-color:#34d399}' +
    '.wfb-node.bad{border-color:#f87171}' +
    '.wfb-head{display:flex;justify-content:space-between;gap:6px;padding:6px 8px;border-radius:9px 9px 0 0;background:linear-gradient(90deg,#4c2fb8,#2563eb);cursor:move;user-select:none;touch-action:none}' +
    '.wfb-head b{font-weight:600}' +
    '.wfb-body{padding:6px 8px;min-height:34px;color:#cfc9ff;overflow:hidden;text-overflow:ellipsis;word-break:break-word}' +
    '.wfb-tag{display:inline-block;margin-top:4px;padding:0 6px;border-radius:99px;background:#2d2470;color:#d9d3ff;font-size:11px}' +
    '.wfb-tag.risk{background:#5b2a2a;color:#ffd0d0}' +
    '.wfb-inspector label{display:block;margin:6px 0 2px;color:#b9b2f5;font-size:12px}' +
    '.wfb-inspector input,.wfb-inspector select,.wfb-inspector textarea{width:100%;box-sizing:border-box;padding:4px 6px;border:1px solid #4b3fa0;border-radius:6px;background:#0f0b24;color:#fff}' +
    '.wfb-inspector textarea{min-height:110px}' +
    '.wfb-btn{padding:5px 10px;border:1px solid #4b3fa0;border-radius:8px;background:#241b58;color:#fff;cursor:pointer}' +
    '.wfb-btn.primary{background:linear-gradient(90deg,#6d4aff,#2563eb);border-color:transparent}' +
    '.wfb-btn.danger{background:#5b1f2a;border-color:#8a2f3f}' +
    '.wfb-warn{margin-top:8px;padding:6px 8px;border-radius:8px;background:#3a2a10;color:#ffd58a;font-size:12px}' +
    '.wfb-ok{margin-top:8px;padding:6px 8px;border-radius:8px;background:#0f3a2a;color:#9df0c6;font-size:12px}' +
    '.wfb-note{margin-top:6px;padding:6px 8px;border-radius:8px;background:#1f2b52;color:#bcd2ff;font-size:12px}';

  function h(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'class') { e.className = attrs[k]; } else if (k === 'text') { e.textContent = attrs[k]; } else if (k.slice(0, 2) === 'on') { e.addEventListener(k.slice(2), attrs[k]); } else { e.setAttribute(k, attrs[k]); }
    });
    (kids || []).forEach(function (c) { if (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
    return e;
  }

  function mount(rootEl) {
    var tools = {};
    try { tools = JSON.parse(rootEl.getAttribute('data-tools') || '{}'); } catch (e) { tools = {}; }
    var def;
    try { def = normalise(JSON.parse(rootEl.getAttribute('data-definition') || '{}')); } catch (e) { def = normalise({}); }
    var input = document.getElementById(rootEl.getAttribute('data-input'));
    var form = input ? input.form : null;
    var selected = def.start;
    var needsLayout = Object.keys(def.nodes).some(function (k) { return !def.layout[k]; });
    if (needsLayout) { autoLayout(def); }

    if (!document.getElementById('wfb-style')) { var st = h('style', { id: 'wfb-style' }); st.textContent = CSS; document.head.appendChild(st); }

    var palette = h('div', { class: 'wfb-palette', 'aria-label': 'Step types' }, [h('h4', { text: 'Add a step' }), h('p', { class: 'wfb-note', text: 'Drag onto the canvas, or click.' })]);
    var canvas = h('div', { class: 'wfb-canvas', tabindex: '0', 'aria-label': 'Workflow canvas' });
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'wfb-links');
    var inspector = h('div', { class: 'wfb-inspector', 'aria-label': 'Step details' });
    canvas.appendChild(svg);
    PALETTE.forEach(function (t) {
      var b = h('button', { type: 'button', class: 'wfb-pal', draggable: 'true', 'data-type': t, title: 'Add a ' + TYPES[t].label }, [h('span', { text: TYPES[t].icon }), h('span', { text: TYPES[t].label })]);
      b.addEventListener('dragstart', function (ev) { ev.dataTransfer.setData('text/plain', 'wfb:' + t); ev.dataTransfer.effectAllowed = 'copy'; });
      b.addEventListener('click', function () { var p = freeSpot(); add(t, p); });
      palette.appendChild(b);
    });
    rootEl.textContent = '';
    rootEl.classList.add('wfb');
    rootEl.appendChild(palette);
    rootEl.appendChild(canvas);
    rootEl.appendChild(inspector);

    function freeSpot() {
      var maxY = 0;
      Object.keys(def.layout).forEach(function (k) { maxY = Math.max(maxY, def.layout[k].y); });
      return { x: 20, y: Object.keys(def.nodes).length ? maxY + 150 : 20 };
    }

    function sync() {
      if (input) { input.value = JSON.stringify(toDefinition(def)); }
    }

    function add(type, pos) {
      var id = addNode(def, type, pos);
      if (id === null) { flash('At most ' + MAX_NODES + ' steps.'); return; }
      selected = id;
      render();
    }

    function flash(msg) { inspector.insertBefore(h('div', { class: 'wfb-warn', role: 'alert', text: msg }), inspector.firstChild); }

    canvas.addEventListener('dragover', function (ev) { ev.preventDefault(); canvas.classList.add('drop'); });
    canvas.addEventListener('dragleave', function () { canvas.classList.remove('drop'); });
    canvas.addEventListener('drop', function (ev) {
      ev.preventDefault();
      canvas.classList.remove('drop');
      var d = ev.dataTransfer.getData('text/plain') || '';
      if (d.indexOf('wfb:') !== 0) { return; }
      var r = canvas.getBoundingClientRect();
      add(d.slice(4), { x: ev.clientX - r.left + canvas.scrollLeft - NODE_W / 2, y: ev.clientY - r.top + canvas.scrollTop - 12 });
    });

    function drawLinks() {
      while (svg.firstChild) { svg.removeChild(svg.firstChild); }
      var w = 0, hgt = 0;
      var els = {};
      Array.prototype.forEach.call(canvas.querySelectorAll('.wfb-node'), function (el) { els[el.getAttribute('data-id')] = el; w = Math.max(w, el.offsetLeft + el.offsetWidth + 60); hgt = Math.max(hgt, el.offsetTop + el.offsetHeight + 60); });
      svg.setAttribute('width', String(Math.max(w, canvas.clientWidth)));
      svg.setAttribute('height', String(Math.max(hgt, canvas.clientHeight)));
      var defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
      defs.innerHTML = '<marker id="wfb-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="#8f7dff"/></marker>';
      svg.appendChild(defs);
      Object.keys(def.nodes).forEach(function (id) {
        var a = els[id];
        if (!a) { return; }
        linksOf(def.nodes[id]).forEach(function (l, i) {
          var b = els[l.target];
          if (!b) { return; }
          var x1 = a.offsetLeft + a.offsetWidth, y1 = a.offsetTop + a.offsetHeight / 2 + (l.field === 'else' ? 14 : (l.field === 'then' ? -14 : 0));
          var x2 = b.offsetLeft, y2 = b.offsetTop + b.offsetHeight / 2;
          var dx = Math.max(40, Math.abs(x2 - x1) / 2);
          var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
          path.setAttribute('d', 'M' + x1 + ' ' + y1 + ' C' + (x1 + dx) + ' ' + y1 + ',' + (x2 - dx) + ' ' + y2 + ',' + x2 + ' ' + y2);
          path.setAttribute('fill', 'none');
          path.setAttribute('stroke', l.field === 'else' ? '#f59e0b' : '#8f7dff');
          path.setAttribute('stroke-width', '2');
          path.setAttribute('marker-end', 'url(#wfb-arrow)');
          path.setAttribute('data-from', id);
          path.setAttribute('data-to', l.target);
          svg.appendChild(path);
          if (l.field === 'then' || l.field === 'else') {
            var t = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            t.setAttribute('x', String(x1 + 8)); t.setAttribute('y', String(y1 - 4)); t.setAttribute('fill', '#cfc9ff'); t.setAttribute('font-size', '11');
            t.textContent = l.field === 'then' ? 'yes' : 'no';
            svg.appendChild(t);
          }
        });
      });
    }

    function summary(n) {
      if (n.type === 'ai') { return String(n.prompt || '(no instructions yet)').slice(0, 90); }
      if (n.type === 'tool') { var s = tools[n.tool]; return s ? s.label : '(choose a tool)'; }
      if (n.type === 'condition') { return 'If ' + (n['var'] || '?') + ' ' + (n.op || ''); }
      if (n.type === 'approval') { return String(n.message || '(no question yet)').slice(0, 90); }
      if (n.type === 'delay') { return 'Wait ' + (n.minutes || 0) + ' min'; }
      if (n.type === 'output') { return String(n.text || '(empty result)').slice(0, 90); }
      if (n.type === 'parallel') { return 'Runs ' + (n.branches || []).length + ' branches (edit in Advanced JSON)'; }
      return '';
    }

    function renderNode(id) {
      var n = def.nodes[id];
      var pos = def.layout[id] || { x: 20, y: 20 };
      var meta = TYPES[n.type] || { label: n.type, icon: '?' };
      var head = h('div', { class: 'wfb-head' }, [h('b', { text: meta.icon + ' ' + meta.label }), h('span', { text: id })]);
      var body = h('div', { class: 'wfb-body' }, [h('div', { text: summary(n) })]);
      if (n.type === 'tool' && tools[n.tool]) {
        if (tools[n.tool].desktop) { body.appendChild(h('span', { class: 'wfb-tag risk', text: 'Your computer \u00b7 needs approval' })); }
        else if (tools[n.tool].risk === 'external') { body.appendChild(h('span', { class: 'wfb-tag risk', text: 'Sends outside \u00b7 needs approval' })); }
      }
      if (id === def.start) { body.appendChild(h('span', { class: 'wfb-tag', text: 'start' })); }
      var el = h('div', { class: 'wfb-node' + (id === selected ? ' sel' : '') + (id === def.start ? ' start' : ''), 'data-id': id, style: 'left:' + pos.x + 'px;top:' + pos.y + 'px', tabindex: '0', role: 'button', 'aria-label': meta.label + ' ' + id }, [head, body]);
      el.addEventListener('click', function () { if (selected !== id) { selected = id; render(); } });
      el.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); selected = id; render(); } });
      head.addEventListener('pointerdown', function (ev) {
        if (ev.button !== undefined && ev.button !== 0) { return; }
        var sx = ev.clientX, sy = ev.clientY, ox = pos.x, oy = pos.y, moved = false;
        head.setPointerCapture(ev.pointerId);
        function mv(e) {
          var nx = Math.max(0, Math.min(5000, Math.round(ox + e.clientX - sx))), ny = Math.max(0, Math.min(5000, Math.round(oy + e.clientY - sy)));
          if (nx !== pos.x || ny !== pos.y) { moved = true; }
          pos = { x: nx, y: ny };
          def.layout[id] = pos;
          el.style.left = nx + 'px'; el.style.top = ny + 'px';
          drawLinks();
        }
        function up() {
          head.removeEventListener('pointermove', mv);
          head.removeEventListener('pointerup', up);
          head.removeEventListener('pointercancel', up);
          sync();
          if (!moved && selected !== id) { selected = id; render(); }
        }
        head.addEventListener('pointermove', mv);
        head.addEventListener('pointerup', up);
        head.addEventListener('pointercancel', up);
      });
      return el;
    }

    function field(label, el) { return h('div', {}, [h('label', { text: label }), el]); }

    function linkSelect(n, f, label) {
      var s = h('select', { 'data-link': f, 'aria-label': label });
      s.appendChild(h('option', { value: '', text: '\u2014 not connected \u2014' }));
      Object.keys(def.nodes).forEach(function (k) { if (k !== selected) { var o = h('option', { value: k, text: k + ' \u00b7 ' + (TYPES[def.nodes[k].type] || { label: '' }).label }); if (n[f] === k) { o.selected = true; } s.appendChild(o); } });
      s.addEventListener('change', function () { setLink(def, selected, f, s.value); render(); });
      return field(label, s);
    }

    function textField(n, key, label, area, opts) {
      var e = area ? h('textarea', { maxlength: String(opts && opts.max || 4000) }) : h('input', { type: 'text', maxlength: String(opts && opts.max || 200) });
      e.value = n[key] === undefined ? '' : String(n[key]);
      e.addEventListener('input', function () { n[key] = e.value; sync(); refreshNode(); });
      return field(label, e);
    }

    function refreshNode() {
      var old = canvas.querySelector('.wfb-node[data-id="' + selected + '"]');
      if (old) { var fresh = renderNode(selected); canvas.replaceChild(fresh, old); drawLinks(); }
    }

    function renderInspector() {
      inspector.textContent = '';
      inspector.appendChild(h('h4', { text: 'Step details' }));
      var n = def.nodes[selected];
      if (!n) {
        inspector.appendChild(h('p', { class: 'wfb-note', text: 'Select a step on the canvas to change its instructions, tool, model settings and connections.' }));
      } else {
        inspector.appendChild(h('div', { class: 'wfb-note', text: (TYPES[n.type] || { label: n.type }).label + ' \u00b7 id: ' + selected }));
        if (n.type === 'ai') {
          inspector.appendChild(textField(n, 'prompt', 'AI prompt (use {{name}} for variables)', true, { max: 4000 }));
          inspector.appendChild(textField(n, 'save_as', 'Save the answer as', false, { max: 40 }));
          var mt = h('input', { type: 'number', min: '100', max: '1500', step: '50' }); mt.value = n.max_tokens || 600;
          mt.addEventListener('input', function () { var v = parseInt(mt.value, 10); if (!isNaN(v)) { n.max_tokens = v; sync(); } });
          inspector.appendChild(field('Max answer length (100\u20131500 tokens)', mt));
          inspector.appendChild(h('p', { class: 'wfb-note', text: 'The model is chosen in Settings (or automatic).' }));
          inspector.appendChild(linkSelect(n, 'next', 'Then go to'));
        } else if (n.type === 'tool') {
          var ts = h('select', { 'data-field': 'tool' });
          ts.appendChild(h('option', { value: '', text: '\u2014 choose a tool \u2014' }));
          var groups = { 'Your data': [], 'Outside the app (needs approval)': [], 'Your computer (permission + approval)': [] };
          Object.keys(tools).forEach(function (k) {
            var s = tools[k];
            if (s.risk === 'money') { return; }
            (s.desktop ? groups['Your computer (permission + approval)'] : (s.risk === 'external' ? groups['Outside the app (needs approval)'] : groups['Your data'])).push(k);
          });
          Object.keys(groups).forEach(function (g) {
            if (!groups[g].length) { return; }
            var og = h('optgroup', { label: g });
            groups[g].forEach(function (k) { var o = h('option', { value: k, text: tools[k].label + ' (' + k + ')' }); if (n.tool === k) { o.selected = true; } og.appendChild(o); });
            ts.appendChild(og);
          });
          ts.addEventListener('change', function () { n.tool = ts.value; n.args = {}; render(); });
          inspector.appendChild(field('Tool', ts));
          var spec = tools[n.tool];
          if (spec) {
            if (spec.desktop) { inspector.appendChild(h('div', { class: 'wfb-note', text: 'Uses your paired computer. It only works if the computer\'s own rules allow it, and every request waits for your approval on the My computer page. A test run never sends it.' })); }
            if (spec.risk === 'external') { inspector.appendChild(h('div', { class: 'wfb-note', text: 'Sends something outside the app: an Approval step must come before it on every path.' })); }
            n.args = n.args || {};
            spec.args.forEach(function (a) {
              var inp = h('input', { type: 'text', maxlength: '2000', 'data-arg': a.name }); inp.value = n.args[a.name] === undefined ? '' : String(n.args[a.name]);
              inp.addEventListener('input', function () { if (inp.value === '') { delete n.args[a.name]; } else { n.args[a.name] = inp.value; } sync(); });
              inspector.appendChild(field(a.name + (a.optional ? ' (optional)' : '') + ' \u2014 ' + a.label, inp));
            });
            inspector.appendChild(textField(n, 'save_as', 'Save the result as (optional)', false, { max: 40 }));
          }
          inspector.appendChild(linkSelect(n, 'next', 'Then go to'));
        } else if (n.type === 'condition') {
          inspector.appendChild(textField(n, 'var', 'Variable to check', false, { max: 40 }));
          var os = h('select', { 'data-field': 'op' });
          OPS.forEach(function (o) { var op = h('option', { value: o, text: o }); if (n.op === o) { op.selected = true; } os.appendChild(op); });
          os.addEventListener('change', function () { n.op = os.value; sync(); refreshNode(); });
          inspector.appendChild(field('Check', os));
          inspector.appendChild(textField(n, 'value', 'Value', false, { max: 200 }));
          inspector.appendChild(linkSelect(n, 'then', 'If yes, go to'));
          inspector.appendChild(linkSelect(n, 'else', 'If no, go to'));
        } else if (n.type === 'approval') {
          inspector.appendChild(textField(n, 'message', 'Question you will be asked', true, { max: 1000 }));
          inspector.appendChild(linkSelect(n, 'next', 'After approval, go to'));
        } else if (n.type === 'delay') {
          var mi = h('input', { type: 'number', min: '1', max: '1440' }); mi.value = n.minutes || 5;
          mi.addEventListener('input', function () { var v = parseInt(mi.value, 10); if (!isNaN(v)) { n.minutes = v; sync(); refreshNode(); } });
          inspector.appendChild(field('Wait (minutes, 1\u20131440)', mi));
          inspector.appendChild(linkSelect(n, 'next', 'Then go to'));
        } else if (n.type === 'output') {
          inspector.appendChild(textField(n, 'text', 'Final result (use {{name}})', true, { max: 4000 }));
        } else if (n.type === 'parallel') {
          inspector.appendChild(h('div', { class: 'wfb-note', text: 'This parallel step is kept exactly as it is. Use "Advanced (JSON)" to change its branches.' }));
        }
        var row = h('div', { style: 'margin-top:10px;display:flex;gap:6px;flex-wrap:wrap' });
        if (selected !== def.start) { row.appendChild(h('button', { type: 'button', class: 'wfb-btn', 'data-act': 'start', text: 'Make this the start', onclick: function () { def.start = selected; render(); } })); }
        row.appendChild(h('button', { type: 'button', class: 'wfb-btn danger', 'data-act': 'delete', text: 'Delete step', onclick: function () { removeNode(def, selected); selected = def.start; render(); } }));
        inspector.appendChild(row);
      }
      var ps = problems(def, tools);
      inspector.appendChild(ps.length ? h('div', { class: 'wfb-warn', role: 'status' }, ['Before you save:'].concat(ps.slice(0, 6).map(function (t) { return h('div', { text: '\u2022 ' + t }); }))) : h('div', { class: 'wfb-ok', role: 'status', text: 'Looks complete. Saving checks it again.' }));
      var inp = h('div', { style: 'margin-top:10px' }, [h('h4', { text: 'Run form fields' })]);
      def.inputs.forEach(function (it, i) {
        var k = h('input', { type: 'text', maxlength: '40', placeholder: 'name (a-z, 0-9, _)', 'data-input-key': String(i) }); k.value = it.key || '';
        var l = h('input', { type: 'text', maxlength: '60', placeholder: 'Label people see' }); l.value = it.label || '';
        k.addEventListener('input', function () { it.key = k.value; sync(); });
        l.addEventListener('input', function () { it.label = l.value; sync(); });
        inp.appendChild(h('div', { style: 'display:grid;grid-template-columns:1fr 1fr auto;gap:4px;margin-bottom:4px' }, [k, l, h('button', { type: 'button', class: 'wfb-btn', text: '\u2715', 'aria-label': 'Remove field', onclick: function () { def.inputs.splice(i, 1); render(); } })]));
      });
      if (def.inputs.length < 5) { inp.appendChild(h('button', { type: 'button', class: 'wfb-btn', 'data-act': 'add-input', text: 'Add a field', onclick: function () { def.inputs.push({ key: 'field' + (def.inputs.length + 1), label: 'Field' }); render(); } })); }
      inspector.appendChild(inp);
    }

    function render() {
      Array.prototype.forEach.call(canvas.querySelectorAll('.wfb-node'), function (e) { canvas.removeChild(e); });
      var bad = {};
      Object.keys(def.nodes).forEach(function (id) { canvas.appendChild(renderNode(id)); });
      drawLinks();
      renderInspector();
      sync();
      rootEl.dispatchEvent(new CustomEvent('wfb:change', { detail: { problems: problems(def, tools) } }));
    }

    if (form) {
      form.addEventListener('submit', function (ev) {
        var ps = problems(def, tools);
        if (ps.length && !form.hasAttribute('data-allow-incomplete')) {
          ev.preventDefault();
          renderInspector();
          inspector.scrollIntoView({ block: 'nearest' });
        }
      });
    }
    rootEl.__wfb = { get def() { return def; }, select: function (id) { selected = id; render(); }, add: add, render: render };
    render();
  }

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-wfb]'), mount);
  });
  if (document.readyState !== 'loading') { Array.prototype.forEach.call(document.querySelectorAll('[data-wfb]:not(.wfb)'), mount); }
  api.mount = mount;
})(typeof window !== 'undefined' ? window : globalThis);
