/* JARVIS website chat widget. Embed: <script src="https://YOUR-JARVIS/js/jarvis-widget.js" data-key="CHANNEL_KEY" async></script>
   No cookies; the visitor id + secret token live in localStorage for this site only. */
(function () {
  var s = document.currentScript || document.querySelector('script[data-key]');
  if (!s) return;
  var key = s.getAttribute('data-key');
  if (!/^[0-9a-f]{32}$/.test(key || '')) return;
  var base = new URL(s.src).origin + '/widget/v1/' + key;
  var LS = 'jarvis-widget-' + key, st = {}, last = 0, timer = null, open = false, unread = 0;
  try { st = JSON.parse(localStorage.getItem(LS) || '{}') || {}; } catch (e) { st = {}; }

  var el = function (t, css, txt) { var e = document.createElement(t); if (css) e.style.cssText = css; if (txt) e.textContent = txt; return e; };
  var box = el('div', 'position:fixed;right:16px;bottom:16px;z-index:2147483000;font:14px system-ui,sans-serif');
  var btn = el('button', 'width:56px;height:56px;border-radius:50%;border:0;background:#4f46e5;color:#fff;font-size:24px;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.3)', '💬');
  btn.setAttribute('aria-label', 'Chat');
  var panel = el('div', 'display:none;width:min(340px,calc(100vw - 32px));height:min(460px,calc(100vh - 100px));background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.3);margin-bottom:12px;flex-direction:column;overflow:hidden');
  var head = el('div', 'background:#4f46e5;color:#fff;padding:10px 14px;font-weight:600', 'Chat');
  var list = el('div', 'flex:1;overflow-y:auto;padding:10px;background:#f9fafb');
  var form = el('form', 'display:flex;gap:6px;padding:8px;border-top:1px solid #e5e7eb');
  var inp = el('input', 'flex:1;border:1px solid #d1d5db;border-radius:8px;padding:8px;font:inherit'); inp.maxLength = 2000; inp.placeholder = '…';
  var go = el('button', 'border:0;background:#4f46e5;color:#fff;border-radius:8px;padding:0 14px;cursor:pointer', '➤'); go.type = 'submit';
  form.appendChild(inp); form.appendChild(go);
  panel.appendChild(head); panel.appendChild(list); panel.appendChild(form);
  box.appendChild(panel); box.appendChild(btn); document.body.appendChild(box);

  function bubble(text, mine) {                      /* textContent only: never HTML from the server */
    var b = el('div', 'max-width:80%;margin:4px 0;padding:7px 10px;border-radius:10px;white-space:pre-wrap;word-break:break-word;' +
      (mine ? 'margin-left:auto;background:#4f46e5;color:#fff' : 'background:#fff;border:1px solid #e5e7eb'), text);
    list.appendChild(b); list.scrollTop = list.scrollHeight;
  }
  function api(method, path, body) {
    var h = { 'Content-Type': 'application/json' };
    if (st.token) h.Authorization = 'Bearer ' + st.token;
    return fetch(base + path, { method: method, headers: h, body: body ? JSON.stringify(body) : undefined }).then(function (r) {
      return r.json().then(function (j) { return { status: r.status, body: j }; });
    });
  }
  function take(msgs) {
    msgs.forEach(function (m) { if (m.seq > last) { last = m.seq; bubble(m.text, false); if (!open) { unread++; btn.textContent = '💬' + unread; } } });
  }
  function start() {
    return api('POST', '/start', { visitor: st.visitor }).then(function (r) {
      if (r.status !== 200) { head.textContent = '✕'; return false; }
      st = { visitor: r.body.visitor, token: r.body.token };
      try { localStorage.setItem(LS, JSON.stringify(st)); } catch (e) {}
      head.textContent = r.body.name || 'Chat';
      list.textContent = ''; last = 0;
      if (r.body.greeting) bubble(r.body.greeting, false);
      take(r.body.messages || []);
      return true;
    }).catch(function () { return false; });
  }
  function poll() {
    if (!st.token) return;
    api('GET', '/messages?visitor=' + encodeURIComponent(st.visitor) + '&after=' + last).then(function (r) {
      if (r.status === 200) take(r.body.messages || []);
      else if (r.status === 401) { st = {}; start(); }
    }).catch(function () {});
  }
  btn.onclick = function () {
    open = !open; panel.style.display = open ? 'flex' : 'none'; unread = 0; btn.textContent = open ? '✕' : '💬';
    if (open) { inp.focus(); if (!st.token) start(); }
  };
  form.onsubmit = function (e) {
    e.preventDefault();
    var t = inp.value.trim(); if (!t) return;
    inp.value = ''; bubble(t, true);
    var cid = 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    var send = function () { return api('POST', '/messages', { visitor: st.visitor, text: t, client_id: cid }); };
    (st.token ? Promise.resolve(true) : start()).then(function () { return send(); }).then(function (r) {
      if (r.status === 401) { st = {}; return start().then(send); }
      return r;
    }).then(function (r) { if (r && r.status === 429) bubble('…', false); }).catch(function () {});
  };
  if (st.token) start();
  timer = setInterval(function () { if (!document.hidden) poll(); }, 4000);
})();
