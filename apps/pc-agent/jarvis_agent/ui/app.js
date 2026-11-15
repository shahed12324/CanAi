'use strict';
(function () {
  var TOKEN = location.hash.slice(1);
  var S = null, view = 'home', LANG = 'en', chat = { conv: '', msgs: [], busy: false, list: [], q: '', loaded: false };
  var boot = null, lastSeen = 0, cwd = '', timer = null, kept = { goal: null, conn: null }, lastSig = '';
  var VIEWS = [['home', '◈'], ['chat', '✦'], ['agents', '⚡'], ['workflows', '⛓'], ['computer', '▣'], ['browser', '◍'], ['files', '▤'], ['memory', '❖'],
    ['tools', '✚'], ['pairing', '⇄'], ['approvals', '✔'], ['hist', '↺'], ['monitor', '∿'], ['rules', '⚙']];
  var I = {
    home: 'Home', chat: 'AI Chat', agents: 'AI Agents', workflows: 'Workflows',
    computer: 'Computer Control', browser: 'Browser Automation', files: 'Files & Documents',
    memory: 'AI Memory', tools: 'Tools & Skills', pairing: 'Device Pairing', approvals: 'Approvals',
    hist: 'Activity History', monitor: 'System Monitor', rules: 'Settings & Security',
    agentmode: 'Agent mode', companion: 'Companion mode',
    running: 'Agent is running', stopped: 'Agent is stopped', pairhead: 'Pair this computer',
    start: 'Start', stop: 'Stop', estop: 'Emergency stop', resume: 'Resume',
    estopOn: 'Emergency stop is ON — nothing will run',
    pair: 'Pair', server: 'Server', code: 'Code', send: 'Send', save: 'Save', add: 'Add',
    notpaired: 'Pair this computer first (Device Pairing)',
    tooold: 'Your server does not support this yet — update the server',
    offline: 'Cannot reach the server', revoked: 'The server no longer accepts this device — pair again',
    cmdph: 'What would you like to do? Type…', quick: 'Quick actions', progress: 'Current task',
    recent: 'Recent activity', devices: 'Connected devices', system: 'System status',
    none: 'Nothing yet', thisdev: 'This computer', open: 'Open',
    cpu: 'CPU', mem: 'Memory', disk: 'Disk', battery: 'Battery',
    mic: 'Mic off', notif: 'Notifications', profile: 'Profile', model: 'AI model',
    roots: 'Allowed folders', hosts: 'Allowed websites', apps: 'Allowed apps', commands: 'Allowed commands',
    kinds: 'Which kinds of tasks may run', when: 'Time', what: 'Task', detail: 'Detail', result: 'Result',
    done: 'Done', failed: 'Failed/refused', running2: 'Running', stoppedst: 'Stopped',
    approvalsnote: 'Tasks are approved on the website or phone — never from this window. Risky tasks need a PIN on the phone.',
    openweb: 'Open on the website', theme: 'Theme', language: 'Language', dark: 'Dark', light: 'Light',
    charvis: 'Show character', rmotion: 'Reduce motion', compact: 'Compact (floating) layout',
    perf: 'Performance', bal: 'Balanced', low: 'Low resources', notifs: 'Notifications',
    aot: 'Always on top (desktop app)', auto: 'Auto', on: 'On', off: 'Off',
    search: 'Search', newchat: 'New chat', run: 'Run', refresh: 'Refresh', unpair: 'Unpair (this computer only)',
    test: 'Test connection', up: 'Up', folders: 'Folders', browse: 'Browse',
    asyncgo: 'Sync with server', as_synced: 'matches the server copy', as_local_tampered: 'local file is broken — nothing uploaded',
    as_diverged: '⚠ does NOT match the server copy — was the file changed or removed?', as_offline: 'server not reachable',
    as_server_too_old: 'the server does not support this yet — update it', as_error: 'sync failed', as_unpaired: 'pair first',
    audit: 'Audit chain', intact: 'intact', broken: 'BROKEN — it was changed!',
    memph: 'What should I remember?', delete: 'Delete', risky: 'Risk'
  };
  function t(k) { var e = I[k]; return e !== undefined ? e : k; }
  function $(s, r) { return (r || document).querySelector(s); }
  function h(tag, a) {
    var e = document.createElement(tag);
    if (a) Object.keys(a).forEach(function (k) {
      if (k === 'class') e.className = a[k]; else if (k === 'text') e.textContent = a[k];
      else if (k === 'style') { if (a[k]) String(a[k]).split(';').forEach(function (d) { var i = d.indexOf(':'); if (i > 0) e.style.setProperty(d.slice(0, i).trim(), d.slice(i + 1).trim()); }); }   // via CSSOM: allowed under style-src 'self'
      else if (a[k] === true) e.setAttribute(k, ''); else if (a[k] !== false && a[k] != null) e.setAttribute(k, a[k]);
    });
    function add(c) {
      if (c == null || c === false) return;
      if (Array.isArray(c)) { c.forEach(add); return; }
      e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    for (var i = 2; i < arguments.length; i++) add(arguments[i]);
    return e;
  }
  function msg(text, bad) { var m = $('#msg'); m.textContent = text || ''; m.style.color = bad ? 'var(--bad)' : 'var(--ok)'; }
  function pct(v) { return v == null ? '—' : Math.round(v) + '%'; }
  function when(ts) { return ts ? new Date(ts * 1000).toLocaleString() : ''; }
  function emit(state) { try { window.dispatchEvent(new CustomEvent('canai-state', { detail: state })); } catch (e) {} }

  function raw(method, path, body) {
    return fetch(path, { method: method, headers: { 'X-Agent-Token': TOKEN, 'Content-Type': 'application/json' }, body: method === 'POST' ? JSON.stringify(body || {}) : undefined })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, json: j }; }); })
      .catch(function () { return { ok: false, status: 0, json: { error: 'offline' } }; });
  }
  function api(path, body) {
    return raw(body ? 'POST' : 'GET', path, body).then(function (r) { if (!r.ok) { msg(r.json.error || 'Failed', true); throw new Error('api'); } return r.json; });
  }
  function cloud(method, path, body) { return raw(method, '/api/cloud/' + path, body); }
  function cloudError(r) {
    var e = r.json && r.json.error;
    if (e === 'not_paired') return t('notpaired');
    if (r.status === 501 || e === 'server_too_old') return t('tooold');
    if (e === 'offline' || r.status === 502 || r.status === 0) return t('offline');
    if (e === 'device_revoked' || r.status === 401) return t('revoked');
    return e || 'error ' + r.status;
  }
  function note(text, cls) { return h('div', { class: 'card ' + (cls || '') }, h('span', { class: 'mut', text: text })); }

  /* ---------- tiny safe markdown: fenced code, `inline`, **bold**; everything goes through textContent ---------- */
  function md(text) {
    var box = h('div'), parts = String(text).split('```');
    parts.forEach(function (p, i) {
      if (i % 2 === 1) { box.appendChild(h('pre', null, h('code', { text: p.replace(/^[a-zA-Z0-9_+-]*\n/, '') }))); return; }
      p.split(/(`[^`\n]+`|\*\*[^*\n]+\*\*)/).forEach(function (s) {
        if (!s) return;
        if (s.length > 2 && s[0] === '`') box.appendChild(h('code', { class: 'mono', text: s.slice(1, -1) }));
        else if (s.length > 4 && s.slice(0, 2) === '**') box.appendChild(h('b', { text: s.slice(2, -2) }));
        else box.appendChild(document.createTextNode(s));
      });
    });
    return box;
  }

  /* ---------- shell ---------- */
  function drawShell() {
    var side = $('#side'); side.textContent = '';
    side.appendChild(h('div', { class: 'brand' }, h('img', { src: '/ui/logo.svg', alt: '' }), h('span', { text: 'CAN AI' })));
    VIEWS.forEach(function (v) {
      side.appendChild(h('button', { 'data-v': v[0], 'aria-current': String(view === v[0]) }, h('span', { class: 'ic', text: v[1], 'aria-hidden': 'true' }), h('span', { class: 'lb', text: t(v[0]) })));
    });
    var top = $('#top'); top.textContent = '';
    var comp = S && S.mode === 'companion';
    top.appendChild(h('div', { class: 'seg', role: 'group', 'aria-label': 'Mode' },
      h('button', { 'data-mode': 'companion', 'aria-pressed': String(comp), text: t('companion') }), h('button', { 'data-mode': 'agent', 'aria-pressed': String(!comp), text: t('agentmode') })));
    var paired = S && S.paired;
    top.appendChild(h('span', { class: 'pill', id: 'devstat' }, h('span', { class: 'dot ' + (!paired ? 'bad' : S.running ? 'ok' : 'warn') }), paired ? (S.running ? t('running2') : t('stopped')) : t('notpaired').split(' (')[0]));
    var sel = h('select', { id: 'model', 'aria-label': t('model'), disabled: !(boot && boot.models && boot.models.length) });
    if (boot && boot.models && boot.models.length) boot.models.forEach(function (m) { var o = h('option', { value: m.id, text: m.name }); if (S && S.prefs.model_id === m.id) o.selected = true; sel.appendChild(o); });
    else sel.appendChild(h('option', { text: t('model') + ' —' }));
    top.appendChild(sel);
    var vs = window.CANAI_VOICE && window.CANAI_VOICE.label ? window.CANAI_VOICE.label(LANG) : t('mic');
    top.appendChild(h('button', { class: 'pill', 'data-go': 'rules', id: 'micstat', 'aria-label': 'Microphone' }, h('span', { class: 'dot ' + (window.CANAI_VOICE && window.CANAI_VOICE.listening && window.CANAI_VOICE.listening() ? 'ok' : '') }), vs));
    top.appendChild(h('span', { class: 'sp' }));
    var unseen = S ? (S.history || []).filter(function (r) { return !r.ok && r.t > lastSeen; }).length : 0;
    top.appendChild(h('button', { class: 'pill', 'data-go': 'hist', id: 'bell', 'aria-label': t('notif') }, '🔔', unseen ? h('b', { text: String(unseen) }) : null));
    top.appendChild(h('button', { class: 'pill', 'data-go': 'pairing', id: 'prof', 'aria-label': t('profile') }, '👤', (boot && boot.user && boot.user.name) ? boot.user.name : (S && S.device_id ? S.device_id.slice(0, 6) : '—')));
    top.appendChild(h('button', { class: 'btn estop', id: 'estop-top', text: S && S.emergency ? t('resume') : t('estop') }));
  }

  /* ---------- views ---------- */
  function pairForm() {
    return h('section', { class: 'card' }, h('h1', { text: t('pairhead') }),
      h('p', { class: 'mut', text: 'On the website open "My computer" → "Pair" to get a one-time code (valid 10 minutes).' }),
      h('div', { class: 'row' }, h('input', { id: 'srv', placeholder: 'https://app.example.com', 'aria-label': t('server') }), h('input', { id: 'cod', placeholder: t('code'), 'aria-label': t('code') }), h('button', { class: 'btn p', id: 'pair', text: t('pair') })));
  }
  function meters(m) {
    var wrap = h('div', { class: 'meters' });
    meterRows(wrap, m);
    return wrap;
  }
  function meterRows(wrap, m) {
    wrap.textContent = '';
    var box = h('div', { class: 'grid' });
    if (!m) { wrap.appendChild(note('Health numbers could not be read.')); return; }
    [['cpu', m.cpu], ['mem', m.mem], ['disk', m.disk]].concat(m.battery != null ? [['battery', m.battery]] : []).forEach(function (x) {
      box.appendChild(h('div', null, h('div', { class: 'row sb' }, h('span', { text: t(x[0]) }), h('b', { text: pct(x[1]) })), h('div', { class: 'meter' }, h('i', { style: 'width:' + Math.max(0, Math.min(100, x[1] || 0)) + '%' }))));
    });
    wrap.appendChild(box);
  }
  function historyTable(rows, n) {
    if (!rows.length) return h('p', { class: 'mut', text: t('none') });
    var tb = h('table', null, h('thead', null, h('tr', null, h('th', { text: t('when') }), h('th', { text: t('what') }), h('th', { text: t('detail') }), h('th', { text: t('result') }))), h('tbody'));
    rows.slice(0, n || 200).forEach(function (r) { tb.lastChild.appendChild(h('tr', null, h('td', { text: when(r.t) }), h('td', { class: 'mono', text: r.kind }), h('td', { text: r.text }), h('td', null, h('span', { class: 'chip ' + (r.ok ? 'ok' : 'bad'), text: r.ok ? t('done') : t('failed') })))); });
    return tb;
  }
  function runButtons() {
    return h('div', { class: 'row' }, h('button', { class: 'btn p', id: 'go', disabled: S.running, text: t('start') }), h('button', { class: 'btn d', id: 'stop', disabled: !S.running, text: t('stop') }));
  }
  function quick(label, sub, go) { return h('button', { class: 'qa', 'data-go': go }, h('b', { text: label }), h('span', { class: 'mut', text: sub })); }

  function vHome(box) {
    if (!S.paired) { box.appendChild(pairForm()); return; }
    box.appendChild(h('section', { class: 'card' }, h('div', { class: 'row sb' }, h('div', null, h('h1', { text: S.running ? t('running') : t('stopped') }), h('div', { class: 'mono', text: S.server + ' · ' + S.os + ' · ' + S.version })),
      h('span', { class: 'chip ' + (S.running ? 'ok' : 'warn'), text: S.running ? t('running2') : t('stopped') })),
      S.error ? h('p', { class: 'chip bad', text: S.error }) : null, S.emergency ? h('p', { class: 'chip bad', text: t('estopOn') }) : null, h('div', { style: 'margin-top:12px' }, runButtons())));
    box.appendChild(h('section', { class: 'card hero' }, h('div', { class: 'stage', id: 'stage', 'aria-label': 'CAN AI' }),
      h('div', null, h('h2', { text: S.mode === 'companion' ? t('companion') : t('agentmode') }),
        h('textarea', { id: 'cmd', placeholder: t('cmdph'), 'aria-label': t('cmdph') }),
        h('div', { class: 'row', style: 'margin-top:8px' }, h('button', { class: 'btn p', id: 'cmd-go', text: t('send') }), h('button', { class: 'btn', id: 'mic-go', 'aria-label': 'Voice', text: '🎤' }), h('button', { class: 'btn', id: 'mute-go', 'aria-label': 'Mute', text: (window.CANAI_VOICE && window.CANAI_VOICE.muted()) ? '🔇' : '🔊' })))));
    box.appendChild(h('section', null, h('h2', { text: t('quick') }), h('div', { class: 'grid' },
      quick(t('chat'), 'Talk, ask, write', 'chat'), quick(t('files'), 'Browse allowed folders', 'files'),
      quick(t('browser'), 'Allowed websites', 'browser'), quick(t('workflows'), 'Run saved automations', 'workflows'))));
    var g = h('div', { class: 'grid' });
    var prog = h('section', { class: 'card' }, h('h2', { text: t('progress') }), h('div', { id: 'curtask', class: 'mut', text: '…' }));
    g.appendChild(prog);
    g.appendChild(h('section', { class: 'card' }, h('h2', { text: t('system') }), meters(S.metrics)));
    g.appendChild(h('section', { class: 'card' }, h('h2', { text: t('devices') }), h('div', null, h('span', { class: 'dot ' + (S.running ? 'ok' : 'warn') }), ' ' + t('thisdev') + ' · ', h('span', { class: 'mono', text: S.device_id.slice(0, 8) })), h('div', { class: 'mut', text: S.server })));
    g.appendChild(h('section', { class: 'card' }, h('h2', { text: t('approvals') }), h('p', { class: 'mut', text: t('approvalsnote') }), h('div', { id: 'pendcount', class: 'mut' }), h('button', { class: 'btn', 'data-go': 'approvals', text: t('open') })));
    box.appendChild(g);
    box.appendChild(h('section', { class: 'card' }, h('h2', { text: t('recent') }), historyTable(S.history.slice().reverse(), 5)));
    api('/api/tasks').then(function (r) {
      var c = $('#curtask'); if (!c) return; var cur = r.tasks[0];
      c.textContent = cur ? (cur.state === 'running' ? t('running2') : cur.state === 'done' ? t('done') : cur.state === 'stopped' ? t('stoppedst') : t('failed')) + ' · ' + cur.task.slice(0, 12) + ' · ' + cur.steps + ' step' : t('none');
    }).catch(function () {});
    cloud('GET', 'bootstrap').then(function (r) { var c = $('#pendcount'); if (c && r.ok) c.textContent = (r.json.pending_approvals != null ? r.json.pending_approvals : 0) + ' ' + ('waiting'); });
    window.dispatchEvent(new CustomEvent('canai-mount', { detail: 'stage' }));
  }

  function listEditor(title, sub, arr, act, ph) {
    var s = h('section', { class: 'card' }, h('h2', { text: title }), h('p', { class: 'mut', text: sub }));
    var tags = h('div'); if (!arr.length) tags.appendChild(h('span', { class: 'mut', text: t('none') }));
    arr.forEach(function (x) { tags.appendChild(h('span', { class: 'tag' }, h('span', { class: 'mono', text: x }), h('button', { 'data-rm': act, 'data-val': x, 'aria-label': t('delete') + ' ' + x, text: '×' }))); });
    s.appendChild(tags);
    s.appendChild(h('div', { class: 'row', style: 'margin-top:8px' }, h('input', { id: 'in-' + act, placeholder: ph, 'aria-label': title }), h('button', { class: 'btn', 'data-add': act, text: t('add') })));
    return s;
  }
  function kindsCard(filter) {
    var s = h('section', { class: 'card' }, h('h2', { text: t('kinds') }));
    S.kinds.filter(filter || function () { return true; }).forEach(function (k) {
      s.appendChild(h('label', { class: 'k' }, h('input', { type: 'checkbox', 'data-kind': k.id, checked: k.on }), h('span', { style: 'flex:1', text: k.label }), h('span', { class: 'chip ' + (k.risk === 'high' ? 'hi' : k.risk === 'medium' ? 'med' : 'low'), text: t('risky') + ': ' + k.risk })));
    });
    s.appendChild(h('p', { class: 'mut', text: ('Not supported: ') + S.unsupported.join(', ') }));
    return s;
  }
  function crashCard() {
    var list = h('div', { id: 'crash-list' }), det = h('pre', { id: 'crash-detail', class: 'mut', hidden: true });
    var card = h('section', { class: 'card', id: 'crash-card' }, h('h2', { text: 'Crash reports' }),
      h('p', { class: 'mut', text: 'If the agent ever crashes, a small report is saved on this computer. It holds no passwords, tokens or task contents, and nothing is sent automatically.' }), list, det);
    function load() {
      api('/api/crashes').then(function (r) {
        list.textContent = '';
        var rows = (r && r.reports) || [];
        if (!rows.length) { list.appendChild(h('p', { class: 'mut', text: 'No crash reports.' })); return; }
        rows.forEach(function (x) {
          list.appendChild(h('div', { class: 'row sb' }, h('span', { text: new Date(x.time * 1000).toLocaleString() + ' · ' + x.type + (x.message ? ' · ' + x.message.slice(0, 60) : '') }),
            h('button', { class: 'btn', 'data-crash': x.name, text: 'Details' })));
        });
        list.appendChild(h('button', { class: 'btn', id: 'crash-clear', text: 'Delete all reports' }));
      }).catch(function () {});
    }
    card.addEventListener('click', function (e) {
      var el = e.target.closest ? e.target.closest('button') : null; if (!el) return;
      if (el.id === 'crash-clear') api('/api/crashes/clear', {}).then(function () { det.hidden = true; load(); }).catch(function () {});
      else if (el.dataset.crash) api('/api/crashes?name=' + encodeURIComponent(el.dataset.crash)).then(function (d) { det.hidden = false; det.textContent = JSON.stringify(d, null, 1); }).catch(function () {});
    });
    load();
    return card;
  }
  function vRules(box) {
    var P = S.prefs;
    function seg(key, opts, label) {
      return h('div', { class: 'row sb' }, h('span', { text: label }), h('div', { class: 'seg', role: 'group' }, opts.map(function (o) { return h('button', { 'data-pref': key, 'data-pv': String(o[0]), 'aria-pressed': String(P[key] === o[0]), text: o[1] }); })));
    }
    function tog(key, label) { return seg(key, [[true, t('on')], [false, t('off')]], label); }
    box.appendChild(h('section', { class: 'card' }, h('h2', { text: t('theme') }),
      seg('theme', [['dark', t('dark')], ['light', t('light')]], t('theme')),
      tog('character_visible', t('charvis')), seg('reduced_motion', [['auto', t('auto')], ['on', t('on')], ['off', t('off')]], t('rmotion')), tog('compact', t('compact')), tog('always_on_top', t('aot')),
      tog('notifications', t('notifs')), seg('performance', [['balanced', t('bal')], ['low', t('low')]], t('perf'))));
    var voice = $('#voice-settings-slot'); box.appendChild(h('div', { id: 'voice-settings-slot' }));
    box.appendChild(listEditor(t('roots'), 'File work happens only inside these folders. Empty = nothing.', S.roots, 'root', '/home/me/work'));
    box.appendChild(listEditor(t('hosts'), 'Only https and these sites (and subdomains).', S.hosts, 'host', 'example.com'));
    box.appendChild(listEditor(t('apps'), 'Only these program names may be opened.', S.apps, 'app', 'notepad'));
    box.appendChild(listEditor(t('commands'), 'Program names only; no shell; stops after 30 s.', S.commands, 'command', 'git'));
    box.appendChild(kindsCard());
    box.appendChild(crashCard());
    window.dispatchEvent(new CustomEvent('canai-mount', { detail: 'voice-settings' }));
  }
  function vBrowser(box) {
    box.appendChild(h('section', { class: 'card' }, h('h1', { text: t('browser') }), h('p', { class: 'mut', text: 'Web pages are untrusted data: nothing written on a page can give orders to the agent. Only the sites below, only https.' })));
    box.appendChild(listEditor(t('hosts'), '', S.hosts, 'host', 'example.com'));
    box.appendChild(kindsCard(function (k) { return k.id === 'browser_navigate' || k.id === 'browser_extract'; }));
    box.appendChild(h('section', { class: 'card' }, h('h2', { text: t('recent') }), historyTable(S.history.filter(function (r) { return /browser/.test(r.kind); }).reverse(), 20)));
  }
  function vTools(box) {
    box.appendChild(h('section', { class: 'card' }, h('h1', { text: t('tools') }), h('p', { class: 'mut', text: 'Local task kinds this computer may run (its own rules, checked again on this PC) and the tools your workflows can use.' })));
    box.appendChild(kindsCard());
    box.appendChild(h('section', { class: 'card' }, h('h2', { text: 'Workflow tools' }), h('div', { id: 'toollist', class: 'mut', text: '…' })));
    cloud('GET', 'tools').then(function (r) {
      var c = $('#toollist'); if (!c) return; c.textContent = '';
      if (!r.ok) { c.textContent = cloudError(r); return; }
      (r.json.tools || []).forEach(function (x) { c.appendChild(h('div', { class: 'row sb' }, h('span', { text: x.label }), h('span', null, h('span', { class: 'mono', text: x.key + ' ' }), h('span', { class: 'chip ' + (x.risk === 'external' || x.risk === 'money' ? 'hi' : x.risk === 'write_own' ? 'med' : 'low'), text: x.risk })))); });
      if (!(r.json.tools || []).length) c.textContent = t('none');
    });
  }
  function vComputer(box) {
    box.appendChild(h('section', { class: 'card' }, h('div', { class: 'row sb' }, h('h1', { text: t('computer') }), h('span', { class: 'chip ' + (S.emergency ? 'bad' : S.running ? 'ok' : 'warn'), text: S.emergency ? t('estop') : S.running ? t('running2') : t('stopped') })),
      h('p', { class: 'mut', text: S.mode === 'companion' ? ('Companion mode: this computer does nothing on request.') : ('Agent mode: jobs from the website/phone run inside the local rules.') }),
      S.paired ? runButtons() : h('span', { class: 'mut', text: t('notpaired') }),
      h('div', { class: 'row', style: 'margin-top:10px' }, h('button', { class: 'btn estop', id: 'estop', text: S.emergency ? t('resume') : t('estop') }))));
    box.appendChild(h('section', { class: 'card' }, h('h2', { text: 'Tell the computer what to do' }),
      h('p', { class: 'mut', text: 'The model only drafts a plan. A person approves it on the website/phone. Nothing runs from here.' }),
      h('textarea', { id: 'goal', 'aria-label': 'goal' }), h('div', { class: 'row', style: 'margin-top:8px' }, h('button', { class: 'btn p', id: 'goal-go', text: t('send') })), h('div', { id: 'goalres', class: 'mut' }, kept.goal ? h('span', { class: 'chip ' + kept.goal.cls, text: kept.goal.text }) : null)));
    box.appendChild(h('section', { class: 'card', id: 'live-card' }, h('div', { class: 'row sb' }, h('h2', { text: 'Running now' }),
      h('button', { class: 'btn', id: 'pause-go', 'aria-pressed': 'false', text: 'Pause' })), h('div', { id: 'live', class: 'mut', text: '…' })));
    drawLive();
    var tl = h('section', { class: 'card' }, h('h2', { text: 'Local tasks' }), h('div', { id: 'tasklist', class: 'mut', text: '…' })); box.appendChild(tl);
    api('/api/tasks').then(function (r) {
      var c = $('#tasklist'); if (!c) return; c.textContent = '';
      c.appendChild(h('p', null, t('audit') + ': ', h('span', { class: 'chip ' + (r.audit_ok ? 'ok' : 'bad'), text: r.audit_ok ? t('intact') + ' (' + r.audit_rows + ')' : t('broken') })));
      var as = r.audit_sync || {}, ast = as.status ? t('as_' + as.status) + (as.server_rows != null ? ' (' + as.local_rows + '/' + as.server_rows + ')' : '') : '';
      c.appendChild(h('p', null, h('button', { class: 'btn', id: 'audit-sync', text: t('asyncgo') }), ' ', h('span', { id: 'audit-sync-state', class: 'chip ' + (as.status === 'synced' ? 'ok' : as.status ? 'bad' : ''), text: ast })));
      if (!r.tasks.length) { c.appendChild(h('span', { text: t('none') })); return; }
      var tb = h('table', null, h('tbody')); r.tasks.forEach(function (x) { tb.lastChild.appendChild(h('tr', null, h('td', { class: 'mono', text: x.task.slice(0, 14) }), h('td', { text: when(x.started) }), h('td', { text: x.steps + ' step' }), h('td', null, h('span', { class: 'chip ' + (x.state === 'done' ? 'ok' : x.state === 'running' ? 'warn' : 'bad'), text: x.state })), h('td', { text: x.detail }))); });
      c.appendChild(tb);
    }).catch(function () {});
  }
  function vFiles(box) {
    var s = h('section', { class: 'card' }, h('h1', { text: t('files') }), h('div', { id: 'crumb', class: 'row', style: 'margin:8px 0' }), h('div', { id: 'flist' }));
    box.appendChild(s);
    box.appendChild(listEditor(t('roots'), 'Only these folders can be browsed or touched.', S.roots, 'root', '/home/me/work'));
    loadFolder(cwd);
  }
  function loadFolder(path) {
    raw('GET', '/api/files?path=' + encodeURIComponent(path || ''), null).then(function (r) {
      var c = $('#flist'), cr = $('#crumb'); if (!c) return; c.textContent = ''; cr.textContent = '';
      if (!r.ok) { cwd = ''; c.appendChild(h('p', { class: 'chip bad', text: r.json.error || 'error' })); return; }
      var d = r.json; cwd = d.path;
      cr.appendChild(h('span', { class: 'mono', text: d.roots ? t('folders') : d.path }));
      if (!d.roots) cr.appendChild(h('button', { class: 'btn', 'data-dir': d.parent, text: '↑ ' + t('up') }));
      if (!d.entries.length) c.appendChild(h('p', { class: 'mut', text: d.roots ? ('No folder is allowed yet — add one below.') : t('none') }));
      var tb = h('table', null, h('tbody'));
      d.entries.forEach(function (e) {
        tb.lastChild.appendChild(h('tr', null, h('td', null, (d.roots || e.dir) ? h('button', { class: 'btn', 'data-dir': e.path, text: '📁 ' + e.name }) : h('span', { text: '📄 ' + e.name })), h('td', { class: 'mut', text: e.dir || d.roots ? '' : (e.size < 1024 ? e.size + ' B' : Math.round(e.size / 1024) + ' KB') }), h('td', { class: 'mut', text: e.mtime ? when(e.mtime) : '' })));
      });
      c.appendChild(tb); if (d.truncated) c.appendChild(h('p', { class: 'mut', text: '500+' }));
    });
  }
  function vApprovals(box) {
    box.appendChild(h('section', { class: 'card' }, h('h1', { text: t('approvals') }), h('p', { class: 'mut', text: t('approvalsnote') }),
      S.paired ? h('a', { class: 'btn p', href: S.server + '/app/computer', target: '_blank', rel: 'noopener noreferrer', text: t('openweb') }) : null));
    var s = h('section', { class: 'card' }, h('h2', { text: 'Waiting for approval' }), h('div', { id: 'pend', class: 'mut', text: '…' })); box.appendChild(s);
    cloud('GET', 'approvals').then(function (r) {
      var c = $('#pend'); if (!c) return; c.textContent = '';
      if (!r.ok) { c.textContent = cloudError(r); return; }
      var items = r.json.items || []; if (!items.length) { c.textContent = t('none'); return; }
      items.forEach(function (x) { c.appendChild(h('div', { class: 'row sb', style: 'padding:6px 0;border-top:1px solid var(--line)' }, h('span', { text: x.title }), h('span', { class: 'chip warn', text: x.kind + (x.risk ? ' · ' + x.risk : '') }))); });
    });
  }
  function vMonitor(box) {
    box.appendChild(h('section', { class: 'card' }, h('h1', { text: t('monitor') }), h('div', { class: 'mono', text: S.os + ' · ' + S.version }), h('div', { style: 'margin-top:12px' }, meters(S.metrics))));
  }
  function vPairing(box) {
    if (!S.paired) { box.appendChild(pairForm()); return; }
    box.appendChild(h('section', { class: 'card' }, h('h1', { text: t('pairing') }), h('table', null, h('tbody', null,
      h('tr', null, h('td', { text: t('server') }), h('td', { class: 'mono', text: S.server })), h('tr', null, h('td', { text: 'Device' }), h('td', { class: 'mono', text: S.device_id })), h('tr', null, h('td', { text: 'OS' }), h('td', { text: S.os })))),
      h('div', { class: 'row', style: 'margin-top:12px' }, h('button', { class: 'btn p', id: 'conn-test', text: t('test') }), h('button', { class: 'btn', id: 'rotate-go', text: 'Rotate device token' }), h('button', { class: 'btn d', id: 'unpair', text: t('unpair') })),
      h('p', { class: 'mut', text: 'To revoke this device everywhere, remove it on the website (My computer).' }), h('div', { id: 'conn-res', class: 'mut', style: kept.conn ? 'color:' + (kept.conn.ok ? 'var(--ok)' : 'var(--bad)') : null, text: kept.conn ? kept.conn.text : '' })));
  }
  function vHist(box) { box.appendChild(h('section', { class: 'card', id: 'hist' }, h('h1', { text: t('hist') }), historyTable(S.history.slice().reverse()))); lastSeen = Math.floor(Date.now() / 1000); }

  /* cloud-backed: chat, agents, workflows, memory */
  function vChat(box) {
    var side = h('section', { class: 'card list' }, h('button', { class: 'btn p', id: 'chat-new', text: t('newchat') }), h('input', { id: 'chat-q', placeholder: t('search'), 'aria-label': t('search'), value: chat.q, style: 'width:100%;margin-top:8px' }), h('div', { id: 'chat-list', style: 'margin-top:8px' }));
    var main = h('section', { class: 'card' }, h('div', { class: 'msgs', id: 'msgs', 'aria-live': 'polite' }), h('div', { class: 'row', style: 'margin-top:10px' }, h('textarea', { id: 'chat-in', placeholder: t('cmdph'), 'aria-label': t('cmdph') })), h('div', { class: 'row', style: 'margin-top:8px' }, h('button', { class: 'btn p', id: 'chat-send', disabled: chat.busy, text: t('send') }), h('button', { class: 'btn', id: 'chat-stop', hidden: !chat.busy, text: '■ ' + t('stop') }), h('button', { class: 'btn', id: 'mic-go', 'aria-label': 'Voice', text: '🎤' }), h('button', { class: 'btn', id: 'mute-go', 'aria-label': 'Mute', text: (window.CANAI_VOICE && window.CANAI_VOICE.muted()) ? '🔇' : '🔊' })));
    box.appendChild(h('div', { class: 'chat' }, side, main)); drawMsgs(); loadConvs();
  }
  function drawMsgs() {
    var m = $('#msgs'); if (!m) return; m.textContent = '';
    if (!chat.msgs.length) m.appendChild(h('span', { class: 'mut', text: 'Say something to start.' }));
    chat.msgs.forEach(function (x) { m.appendChild(h('div', { class: 'bub ' + (x.role === 'user' ? 'u' : 'a') }, md(x.text || (x.role !== 'user' && chat.busy ? '…' : '')))); });
    m.scrollTop = m.scrollHeight;
  }
  function loadConvs() {
    cloud('GET', 'chat/conversations' + (chat.q ? '?q=' + encodeURIComponent(chat.q) : '')).then(function (r) {
      var c = $('#chat-list'); if (!c) return; c.textContent = '';
      if (!r.ok) { c.appendChild(h('span', { class: 'mut', text: cloudError(r) })); return; }
      (r.json.items || []).forEach(function (x) { c.appendChild(h('button', { class: 'item', 'data-conv': x.id, 'aria-current': String(x.id === chat.conv), text: x.title || x.id.slice(0, 8) })); });
      if (!(r.json.items || []).length) c.appendChild(h('span', { class: 'mut', text: t('none') }));
    });
  }
  var chatAbort = null;
  function chatButtons() { var st = $('#chat-stop'), sd = $('#chat-send'); if (st) st.hidden = !chat.busy; if (sd) sd.disabled = chat.busy; }
  function liveBubble(m) {                                    // repaint only the last bubble while the answer grows
    var box = $('#msgs'); if (!box) return; var last = box.lastElementChild;
    if (!last || !last.classList.contains('a')) { drawMsgs(); return; }
    last.textContent = ''; last.appendChild(md(m.text)); box.scrollTop = box.scrollHeight;
  }
  /* Streams the answer from the local relay (server-sent events).  Resolves { fallback: true } ONLY when the server cannot stream at all (nothing was sent, nothing was billed),
     otherwise { error } or {}.  A request that produced any event is never repeated: that could charge twice. */
  function streamReply(body, m) {
    chatAbort = (typeof AbortController === 'function') ? new AbortController() : null;
    if (!chatAbort || !window.ReadableStream) return Promise.resolve({ fallback: true });
    var gotEvent = false, finished = false, err = null;
    function onEvent(kind, d) {
      gotEvent = true;
      if (kind === 'meta') { if (d && d.conversation_id) chat.conv = d.conversation_id; }
      else if (kind === 'delta') { if (d && typeof d.text === 'string') { m.text += d.text; liveBubble(m); } }
      else if (kind === 'done') finished = true;
      else if (kind === 'error') { finished = true; err = (d && (d.message || d.code)) || 'error'; }
    }
    return fetch('/api/cloud/chat/stream', { method: 'POST', headers: { 'X-Agent-Token': TOKEN, 'Content-Type': 'application/json' }, body: JSON.stringify(body), signal: chatAbort.signal }).then(function (r) {
      if (!r.ok) return r.json().catch(function () { return {}; }).then(function (j) { return (r.status === 501 || r.status === 404) ? { fallback: true } : { error: cloudError({ status: r.status, json: j }) }; });
      var reader = r.body.getReader(), dec = new TextDecoder('utf-8'), buf = '';
      function pump() {
        return reader.read().then(function (x) {
          if (x.done) return {};
          buf += dec.decode(x.value, { stream: true });
          var parts = buf.split('\n\n'); buf = parts.pop();
          parts.forEach(function (blk) {
            var kind = '', data = null;
            blk.split('\n').forEach(function (ln) { if (ln.indexOf('event:') === 0) kind = ln.slice(6).trim(); else if (ln.indexOf('data:') === 0) { try { data = JSON.parse(ln.slice(5)); } catch (e) { data = null; } } });
            if (kind) onEvent(kind, data);
          });
          return pump();
        });
      }
      return pump().then(function () { return err ? { error: err } : (finished ? {} : (m.text ? { error: 'The connection was lost; the answer may be incomplete.' } : { error: t('offline') })); });
    }).catch(function (e) {
      if (e && e.name === 'AbortError') { m.stopped = true; return {}; }
      return gotEvent ? { error: 'The connection was lost; the answer may be incomplete.' } : { error: t('offline') };
    });
  }
  function sendChat(text, opts) {
    opts = opts || {}; text = (text || '').trim(); if (!text || chat.busy) return Promise.resolve(null);
    var m = { role: 'assistant', text: '' };
    chat.msgs.push({ role: 'user', text: text }); chat.msgs.push(m); chat.busy = true; emit('thinking'); if (view !== 'chat') { view = 'chat'; render(); } else drawMsgs();
    chatButtons();
    var body = { message: text, conversation_id: chat.conv || null, model_id: (S.prefs.model_id || null), persona: S.prefs.character_personality || null, source: opts.fromVoice ? 'voice' : 'text' };
    function finish(error) {
      chat.busy = false; chatAbort = null; var reply = m.text || null;
      if (error) { m.text = (m.text ? m.text + '\n\n' : '') + '⚠ ' + error; emit('error'); setTimeout(function () { emit('idle'); }, 1800); }
      else {
        if (m.stopped) m.text += (m.text ? ' ' : '') + '■';
        if (!opts.silent && S.prefs.voice_reply && window.CANAI_VOICE && reply) window.CANAI_VOICE.speak(reply); else if (!opts.silent) emit('idle');
        if (opts.silent && !(S.prefs.voice_reply || (window.CANAI_VOICE && window.CANAI_VOICE.handsFree()))) emit('idle');
      }
      chatButtons(); drawMsgs(); loadConvs(); return error ? null : reply;
    }
    return streamReply(body, m).then(function (res) {
      if (!res.fallback) return finish(res.error);
      return cloud('POST', 'chat', body).then(function (r) {                       // a server that cannot stream: the ordinary one-piece answer
        if (!r.ok) return finish(cloudError(r));
        chat.conv = r.json.conversation_id || chat.conv; m.text = r.json.reply || ''; return finish(null);
      });
    });
  }
  function vAgents(box) {
    box.appendChild(h('section', { class: 'card' }, h('h1', { text: t('agents') }), h('div', { id: 'alist', class: 'mut', text: '…' })));
    cloud('GET', 'agents').then(function (r) {
      var c = $('#alist'); if (!c) return; c.textContent = '';
      if (!r.ok) { c.textContent = cloudError(r); return; }
      var items = r.json.items || []; if (!items.length) { c.textContent = ('No agent yet — create one on the website (Agent Builder).'); return; }
      items.forEach(function (a) {
        c.appendChild(h('div', { class: 'card', style: 'margin-bottom:10px' }, h('div', { class: 'row sb' }, h('b', { text: a.name }), h('span', { class: 'chip ' + (a.status === 'active' ? 'ok' : 'warn'), text: a.status })), h('p', { class: 'mut', text: a.description || '' }),
          h('div', { class: 'row' }, h('input', { id: 'ain-' + a.id, placeholder: 'Task for this agent', 'aria-label': a.name, style: 'flex:1' }), h('button', { class: 'btn p', 'data-arun': a.id, text: t('run') })), h('div', { id: 'ares-' + a.id, class: 'mut' })));
      });
    });
  }
  function vWorkflows(box) {
    box.appendChild(h('section', { class: 'card' }, h('h1', { text: t('workflows') }), h('div', { id: 'wlist', class: 'mut', text: '…' })));
    box.appendChild(h('section', { class: 'card' }, h('h2', { text: t('recent') }), h('div', { id: 'wruns', class: 'mut', text: '…' })));
    cloud('GET', 'workflows').then(function (r) {
      var c = $('#wlist'); if (!c) return; c.textContent = '';
      if (!r.ok) { c.textContent = cloudError(r); return; }
      var items = r.json.items || []; if (!items.length) { c.textContent = 'No workflow yet — add one on the website.'; return; }
      items.forEach(function (w) { c.appendChild(h('div', { class: 'row sb', style: 'padding:8px 0;border-top:1px solid var(--line)' }, h('span', null, h('b', { text: w.name }), ' ', h('span', { class: 'chip ' + (w.status === 'active' ? 'ok' : 'warn'), text: w.status })), h('button', { class: 'btn p', 'data-wrun': w.id, disabled: w.status !== 'active', text: t('run') }))); });
    });
    cloud('GET', 'workflows/runs').then(function (r) {
      var c = $('#wruns'); if (!c) return; c.textContent = '';
      if (!r.ok) { c.textContent = cloudError(r); return; }
      var items = r.json.items || []; if (!items.length) { c.textContent = t('none'); return; }
      items.forEach(function (x) { c.appendChild(h('div', { class: 'row sb', style: 'padding:6px 0;border-top:1px solid var(--line)' }, h('span', { text: x.name + ' · ' + when(x.started) }), h('span', null, h('span', { class: 'chip ' + (x.status === 'done' ? 'ok' : x.status === 'failed' ? 'bad' : 'warn'), text: x.status }), (x.status === 'running' || x.status === 'waiting') ? h('button', { class: 'btn d', 'data-wcancel': x.id, text: t('stop') }) : null))); });
    });
  }
  function vMemory(box) {
    box.appendChild(h('section', { class: 'card' }, h('h1', { text: t('memory') }), h('div', { class: 'row' }, h('input', { id: 'mem-q', placeholder: t('search'), 'aria-label': t('search'), style: 'flex:1' }), h('button', { class: 'btn', id: 'mem-search', text: t('search') })),
      h('div', { class: 'row', style: 'margin-top:10px' }, h('input', { id: 'mem-new', placeholder: t('memph'), 'aria-label': t('memph'), style: 'flex:1' }), h('button', { class: 'btn p', id: 'mem-add', text: t('add') })), h('div', { id: 'mlist', style: 'margin-top:12px', class: 'mut', text: '…' })));
    loadMem('');
  }
  function loadMem(q) {
    cloud('GET', 'memory' + (q ? '?q=' + encodeURIComponent(q) : '')).then(function (r) {
      var c = $('#mlist'); if (!c) return; c.textContent = '';
      if (!r.ok) { c.textContent = cloudError(r); return; }
      var items = r.json.items || []; if (!items.length) { c.textContent = t('none'); return; }
      items.forEach(function (m) { c.appendChild(h('div', { class: 'row sb', style: 'padding:6px 0;border-top:1px solid var(--line)' }, h('span', { text: m.text }), h('button', { class: 'btn d', 'data-mdel': m.id, 'aria-label': t('delete'), text: '×' }))); });
    });
  }

  var RENDER = { home: vHome, chat: vChat, agents: vAgents, workflows: vWorkflows, computer: vComputer, browser: vBrowser, files: vFiles, memory: vMemory, tools: vTools, pairing: vPairing, approvals: vApprovals, hist: vHist, monitor: vMonitor, rules: vRules };
  var LIVE = { home: 1, computer: 1, browser: 1, pairing: 1, monitor: 1, hist: 1, rules: 1, tools: 1 };
  function applyPrefs() {
    var P = S.prefs;
    document.documentElement.setAttribute('data-theme', P.theme); document.documentElement.lang = LANG;
    var rm = P.reduced_motion === 'on' || P.performance === 'low' || (P.reduced_motion === 'auto' && window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
    document.body.classList.toggle('rm', rm); document.body.classList.toggle('compact', !!P.compact);
  }
  function render() {
    if (!S) return; applyPrefs(); drawShell();
    var v = $('#view'); v.textContent = ''; (RENDER[view] || vHome)(v);
    var stage = $('#stage'); if (stage) stage.style.display = S.prefs.character_visible ? '' : 'none';
  }
  var STEP_MARK = { completed: '✓', failed: '✗', skipped: '–', not_needed: '○', cancelled: '■', running: '…', verifying: '…', awaiting_approval: '?', queued: '·' };
  function kindLabel(k) { var f = ((S && S.kinds) || []).filter(function (x) { return x.id === k; })[0]; return f ? f.label : k; }
  function drawLive() {           // the real engine state: step by step, with the evidence it checked; updated in place
    var box = $('#live'), pb = $('#pause-go'); if (!box) return;
    api('/api/live').then(function (r) {
      box = $('#live'); pb = $('#pause-go'); if (!box) return;
      var cur = r.current;
      if (pb) { pb.textContent = r.paused ? ('Continue') : ('Pause'); pb.setAttribute('aria-pressed', String(!!r.paused)); pb.disabled = !cur && !r.paused; }
      var t0 = cur || r.last; box.textContent = '';
      if (!t0) { box.appendChild(h('span', { text: 'Nothing is running.' })); return; }
      box.appendChild(h('p', null, (cur ? (r.paused ? ('Paused') : ('Running')) : ('Last task: ') + t0.state), ' · ', h('span', { class: 'mono', text: t0.task.slice(0, 14) })));
      t0.steps.forEach(function (x) { box.appendChild(h('div', { class: 'row', 'data-step-state': x.state }, h('span', { class: 'mono', text: (STEP_MARK[x.state] || '?') + ' ' + x.id }), h('span', { text: ' ' + kindLabel(x.kind) + (x.note ? ' — ' + x.note : '') }))); });
    }).catch(function () {});
  }
  function refreshLive() {
    if (view === 'computer') drawLive();      // live numbers are updated in place: nothing is rebuilt, so a click can never land on a vanished button
    Array.prototype.forEach.call(document.querySelectorAll('.meters'), function (w) { meterRows(w, S.metrics); });
    if (view === 'home') api('/api/tasks').then(function (r) {
      var c = $('#curtask'); if (!c) return; var cur = r.tasks[0];
      c.textContent = cur ? (cur.state === 'running' ? t('running2') : cur.state === 'done' ? t('done') : cur.state === 'stopped' ? t('stoppedst') : t('failed')) + ' · ' + cur.task.slice(0, 12) + ' · ' + cur.steps + ' step' : t('none');
    }).catch(function () {});
  }
  function hasDraft() {          // never redraw under someone who is typing (focused or not)
    var a = document.activeElement;
    if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return true;
    return Array.prototype.some.call(document.querySelectorAll('#view input[type=text],#view input:not([type]),#view textarea'), function (x) { return x.value; });
  }
  function load(force) {
    return api('/api/state').then(function (s) {
      var first = !S, flip = S && S.paired !== s.paired; S = s;
      if (first) { lastSeen = Math.floor(Date.now() / 1000); }
      if (S.paired && (first || !boot)) cloud('GET', 'bootstrap').then(function (r) { if (r.ok) { boot = r.json; drawShell(); } });
      var sig = JSON.stringify([s.running, s.emergency, s.paired, s.mode, s.roots, s.hosts, s.apps, s.commands, s.kinds, s.prefs, s.history.length, s.error, view]);
      if (first || force || flip || (LIVE[view] && !hasDraft() && sig !== lastSig)) render(); else { drawShell(); refreshLive(); }
      lastSig = sig;
    });
  }
  function go(v) { view = v; if (v !== 'computer') kept.goal = null; if (v !== 'pairing') kept.conn = null; cwd = v === 'files' ? '' : cwd; render(); var m = $('#main'); if (m) m.focus({ preventScroll: true }); }
  function setPref(k, v) { return api('/api/prefs', { key: k, value: v }).then(function (r) { S.prefs = r.prefs; render(); }); }

  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('button,a') : e.target, d = el && el.dataset; if (!el || !d) return;
    var run = function (p) { Promise.resolve(p).catch(function () {}); };
    if (d.v) return go(d.v);
    if (d.go) return go(d.go);
    if (d.mode) return run(api('/api/mode', { mode: d.mode }).then(function () { msg('Saved'); return load(true); }));
    if (el.id === 'go') return run(api('/api/run', { on: true }).then(function () { msg('Started'); return load(true); }));
    if (el.id === 'stop') return run(api('/api/run', { on: false }).then(function () { msg('Stopping…'); setTimeout(load, 1500); }));
    if (el.id === 'pause-go') return api(el.getAttribute('aria-pressed') === 'true' ? '/api/continue' : '/api/pause', {}).then(drawLive);
    if (el.id === 'estop' || el.id === 'estop-top') return run(api(S.emergency ? '/api/resume' : '/api/stop', {}).then(function () { msg(S.emergency ? ('Resumed') : ('Emergency stop is on')); return load(true); }));
    if (el.id === 'pair') return run(api('/api/pair', { server: $('#srv').value, code: $('#cod').value }).then(function () { boot = null; msg('Paired'); return load(true); }));
    if (el.id === 'rotate-go') return run(api('/api/rotate', {}).then(function (r) { msg(r.how === 'recovered' ? ('Token already rotated — confirmed') : ('New token active; the old one no longer works')); }));
    if (el.id === 'unpair') return run(api('/api/unpair', {}).then(function () { boot = null; msg('Unpaired on this computer'); return load(true); }));
    if (el.id === 'conn-test') return cloud('GET', 'bootstrap').then(function (r) { kept.conn = { ok: r.ok, text: r.ok ? ('Connected ✓') : cloudError(r) }; var c = $('#conn-res'); if (c) { c.textContent = kept.conn.text; c.style.color = r.ok ? 'var(--ok)' : 'var(--bad)'; } });
    if (d.add) { var inp = $('#in-' + d.add); return run(api('/api/policy', { action: 'add-' + d.add, value: inp.value }).then(function () { inp.value = ''; msg('Saved'); return load(true).then(function () { if (view === 'files') loadFolder(cwd); }); })); }
    if (d.rm) return run(api('/api/policy', { action: 'remove-' + d.rm, value: d.val }).then(function () { msg('Removed'); return load(true); }));
    if (d.pref) { var v = d.pv === 'true' ? true : d.pv === 'false' ? false : d.pv; return run(setPref(d.pref, v)); }
    if (d.dir !== undefined) { cwd = d.dir; return loadFolder(cwd); }
    if (el.id === 'audit-sync') {
      el.disabled = true;
      return void raw('POST', '/api/audit/sync', {}).then(function (r) {
        var st = r.ok ? r.json : { status: r.status === 409 ? 'unpaired' : r.status === 401 ? 'error' : 'offline' }, bad = st.status !== 'synced';
        var sp = $('#audit-sync-state'); if (sp) { sp.className = 'chip ' + (bad ? 'bad' : 'ok'); sp.textContent = t('as_' + st.status) + (st.server_rows != null ? ' (' + st.local_rows + '/' + st.server_rows + ')' : ''); }
        el.disabled = false;
      });
    }
    if (el.id === 'chat-stop') return void (chatAbort && chatAbort.abort());
    if (el.id === 'chat-send') return sendChat($('#chat-in').value), void ($('#chat-in').value = '');
    if (el.id === 'chat-new') { chat.conv = ''; chat.msgs = []; drawMsgs(); return loadConvs(); }
    if (d.conv) return cloud('GET', 'chat/conversations/' + d.conv).then(function (r) { if (r.ok) { chat.conv = d.conv; chat.msgs = (r.json.messages || []).map(function (m) { return { role: m.role, text: m.text }; }); drawMsgs(); loadConvs(); } else msg(cloudError(r), true); });
    if (el.id === 'cmd-go') { var c = $('#cmd'), tx = c.value.trim(); if (!tx) return; c.value = ''; return S.mode === 'companion' ? sendChat(tx) : planGoal(tx, true); }
    if (el.id === 'goal-go') return planGoal($('#goal').value, false);
    if (d.arun) { var out = $('#ares-' + d.arun); out.textContent = '…'; emit('thinking'); return cloud('POST', 'agents/' + d.arun + '/run', { input: $('#ain-' + d.arun).value, model_id: S.prefs.model_id || null }).then(function (r) { out.textContent = ''; out.appendChild(r.ok ? md(r.json.output || r.json.status || 'ok') : h('span', { class: 'chip bad', text: cloudError(r) })); emit('idle'); }); }
    if (d.wrun) return cloud('POST', 'workflows/' + d.wrun + '/run', {}).then(function (r) { msg(r.ok ? ('Started') : cloudError(r), !r.ok); if (r.ok) go('workflows'); });
    if (d.wcancel) return cloud('POST', 'workflows/runs/' + d.wcancel + '/cancel', {}).then(function (r) { msg(r.ok ? ('Cancelled') : cloudError(r), !r.ok); if (r.ok) go('workflows'); });
    if (el.id === 'mem-search') return loadMem($('#mem-q').value);
    if (el.id === 'mem-add') { var mt = $('#mem-new'); return cloud('POST', 'memory', { text: mt.value }).then(function (r) { msg(r.ok ? ('Saved') : cloudError(r), !r.ok); if (r.ok) { mt.value = ''; loadMem(''); } }); }
    if (d.mdel) return cloud('POST', 'memory/' + d.mdel + '/delete', {}).then(function (r) { msg(r.ok ? ('Deleted') : cloudError(r), !r.ok); if (r.ok) loadMem(''); });
    if (el.id === 'bell' && S.prefs.notifications && window.Notification && Notification.permission === 'default') { try { Notification.requestPermission(); } catch (x) {} }
  });
  function planGoal(text, fromHome) {
    text = (text || '').trim(); if (!text) return; emit('thinking');
    if (fromHome) { view = 'computer'; render(); var g = $('#goal'); if (g) g.value = text; }
    cloud('POST', 'plan', { goal: text }).then(function (r) {
      var st = r.json && r.json.status; emit('idle');
      kept.goal = !r.ok ? { cls: 'bad', text: cloudError(r) } : { cls: st === 'ok' ? 'ok' : 'bad', text: st === 'ok' ? ('Plan drafted — approve it on the website') : (r.json.message || st) };
      var o = $('#goalres'); if (!o) return; o.textContent = ''; o.appendChild(h('span', { class: 'chip ' + kept.goal.cls, text: kept.goal.text }));
    });
  }
  document.addEventListener('change', function (e) {
    var t0 = e.target, d = t0.dataset;
    if (d && d.kind) return api('/api/policy', { action: t0.checked ? 'enable' : 'disable', value: d.kind }).then(function () { msg('Saved'); return load(true); }).catch(function () { load(); });
    if (t0.id === 'model') return setPref('model_id', t0.value);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && e.target.id === 'chat-in') { e.preventDefault(); var i = $('#chat-in'), v = i.value; i.value = ''; sendChat(v); }
    if (e.key === 'Enter' && !e.shiftKey && e.target.id === 'cmd') { e.preventDefault(); $('#cmd-go').click(); }
    if (e.key === 'Enter' && e.target.id === 'chat-q') { chat.q = e.target.value; loadConvs(); }
  });
  window.CANAI = { state: function () { return S; }, cloud: cloud, refreshShell: function () { if (S) drawShell(); }, sendChat: sendChat, setPref: setPref, msg: msg, emit: emit, lang: function () { return LANG; }, t: t, h: h };
  load().then(function () { timer = setInterval(function () { load().catch(function () {}); }, (S && S.prefs.performance === 'low') ? 10000 : 5000); }).catch(function () { msg('No connection to the agent', true); });
})();
