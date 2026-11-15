/* JARVIS ONE chat client: streaming over fetch (SSE), safe Markdown-lite rendering, stop / regenerate / copy.
   No build step and no dependencies. Model output is ALWAYS escaped before any formatting is applied. */
(function () {
  'use strict';
  var root = document.getElementById('chat');
  if (!root) return;

  var $ = function (id) { return document.getElementById(id); };
  var thread = $('chat-thread'), form = $('chat-form'), input = $('chat-input'), sendBtn = $('chat-send'),
      stopBtn = $('chat-stop'), regenBtn = $('chat-regen'), errBox = $('chat-error'), modelSel = $('chat-model'), modeSel = $('chat-mode'), charSel = $('chat-character'),
      attach = $('chat-attach'), filesBox = $('chat-files');
  var conversationId = root.dataset.conversation || '';
  var csrf = root.dataset.csrf, maxFiles = parseInt(root.dataset.maxFiles || '3', 10);
  var files = [], controller = null, busy = false;

  function esc(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Markdown-lite on already-escaped text: ``` blocks, `code`, **bold**, *italic*, - lists, line breaks. No links/images/HTML.
  function render(text) {
    var parts = esc(text).split('```');
    return parts.map(function (p, i) {
      if (i % 2 === 1) { return '<pre class="my-2 overflow-x-auto rounded bg-gray-900 p-2 text-xs text-gray-100"><code>' + p.replace(/^[a-zA-Z0-9_+-]*\n/, '') + '</code></pre>'; }
      return p.replace(/`([^`\n]+)`/g, '<code class="rounded bg-black/10 px-1">$1</code>')
              .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
              .replace(/(^|[\s(])\*([^*\n]+)\*/g, '$1<em>$2</em>')
              .replace(/^\s*[-*] (.+)$/gm, '<span class="block pl-4">• $1</span>')
              .replace(/\n/g, '<br>');
    }).join('');
  }

  function fillStatic() {
    thread.querySelectorAll('[data-md]').forEach(function (el) { el.innerHTML = render(el.getAttribute('data-md')); el.removeAttribute('data-md'); });
    thread.scrollTop = thread.scrollHeight;
  }

  function bubble(role) {
    var empty = $('chat-empty'); if (empty) empty.remove();
    var wrap = document.createElement('div');
    wrap.className = 'msg' + (role === 'user' ? ' flex justify-end' : '');
    wrap.dataset.role = role;
    var box = document.createElement('div');
    box.className = 'max-w-[85%] rounded-lg px-3 py-2 text-sm ' + (role === 'user' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-900');
    var body = document.createElement('div'); body.className = 'md break-words';
    var foot = document.createElement('div'); foot.className = 'mt-1 flex items-center gap-3 text-xs text-gray-500';
    box.appendChild(body); if (role === 'assistant') box.appendChild(foot);
    wrap.appendChild(box); thread.appendChild(wrap); thread.scrollTop = thread.scrollHeight;
    return { wrap: wrap, body: body, foot: foot, box: box, text: '' };
  }

  function showError(msg) { errBox.textContent = msg; errBox.classList.toggle('hidden', !msg); }

  function setBusy(b) {
    busy = b; sendBtn.disabled = b; stopBtn.classList.toggle('hidden', !b);
    if (b) regenBtn.classList.add('hidden');
  }

  function renderFiles() {
    filesBox.innerHTML = '';
    files.forEach(function (f, i) {
      var chip = document.createElement('span');
      chip.className = 'inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5';
      chip.textContent = f.name;
      var x = document.createElement('button'); x.type = 'button'; x.textContent = '×'; x.setAttribute('aria-label', 'Remove file');
      x.onclick = function () { files.splice(i, 1); renderFiles(); };
      chip.appendChild(x); filesBox.appendChild(chip);
    });
  }

  attach.addEventListener('change', function () {
    Array.prototype.forEach.call(attach.files, function (f) { if (files.length < maxFiles) files.push(f); });
    attach.value = ''; renderFiles();
  });

  // ---- SSE reader over fetch ----
  async function stream(url, body, ui) {
    controller = new AbortController();
    var res = await fetch(url, {
      method: 'POST', body: body, signal: controller.signal, credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'text/event-stream', 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!res.ok) {
      var msg = 'The request failed (' + res.status + ')';
      try { var j = await res.json(); msg = j.message || (j.errors && Object.values(j.errors)[0][0]) || msg; } catch (e) {}
      if (res.status === 419) msg = 'The session expired. Refresh the page.';
      if (res.status === 429) msg = 'You are sending too fast. Wait a little.';
      throw new Error(msg);
    }
    var reader = res.body.getReader(), dec = new TextDecoder(), buf = '', pending = false;
    function paint() { pending = false; ui.body.innerHTML = render(ui.text); thread.scrollTop = thread.scrollHeight; }
    for (;;) {
      var r = await reader.read();
      if (r.done) break;
      buf += dec.decode(r.value, { stream: true }).replace(/\r\n/g, '\n');
      var idx;
      while ((idx = buf.indexOf('\n\n')) !== -1) {
        var block = buf.slice(0, idx); buf = buf.slice(idx + 2);
        var ev = 'message', data = [];
        block.split('\n').forEach(function (l) {
          if (l.indexOf('event:') === 0) ev = l.slice(6).trim(); else if (l.indexOf('data:') === 0) data.push(l.slice(5).replace(/^ /, ''));
        });
        var d = {}; try { d = JSON.parse(data.join('\n')); } catch (e) {}
        if (ev === 'meta') onMeta(d, ui);
        else if (ev === 'delta') { ui.text += d.text || ''; if (!pending) { pending = true; requestAnimationFrame(paint); } }
        else if (ev === 'done') { paint(); onDone(d, ui); }
        else if (ev === 'error') { paint(); onError(d, ui); }
      }
    }
  }

  function onMeta(d, ui) {
    if (d.conversation_id && !conversationId) {
      conversationId = d.conversation_id;
      history.pushState({}, '', root.dataset.showUrl + '/' + conversationId);
      var a = document.createElement('a');
      a.href = root.dataset.showUrl + '/' + conversationId; a.textContent = d.title || 'New chat';
      a.className = 'block truncate rounded px-2 py-1.5 text-sm bg-indigo-50 font-medium text-indigo-700';
      $('chat-list').prepend(a); $('chat-title').textContent = a.textContent;
    }
    ui.messageId = d.message_id;
  }

  function addCopy(ui) {
    var b = document.createElement('button'); b.type = 'button'; b.className = 'hover:text-gray-800'; b.textContent = 'Copy';
    b.onclick = function () { navigator.clipboard.writeText(ui.text).then(function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); }); };
    ui.foot.appendChild(b);
  }

  function addSources(list, ui) {
    if (!list || !list.length) return;
    var det = document.createElement('details'); det.className = 'mt-2 text-xs text-gray-600';
    var sum = document.createElement('summary'); sum.className = 'cursor-pointer select-none'; sum.textContent = 'Sources (' + list.length + ') — Knowledge base';
    det.appendChild(sum);
    list.forEach(function (s) {
      var p = document.createElement('p'); p.className = 'mt-1';
      var b = document.createElement('b'); b.textContent = '[' + s.n + ']';
      p.appendChild(b); p.appendChild(document.createTextNode(' ' + s.title + ' · Part ' + s.seq));
      if (!s.cited) { var u = document.createElement('span'); u.className = 'text-gray-400'; u.textContent = ' (not mentioned in the answer)'; p.appendChild(u); }
      p.appendChild(document.createElement('br'));
      var e = document.createElement('span'); e.className = 'text-gray-500'; e.textContent = s.excerpt; p.appendChild(e);
      det.appendChild(p);
    });
    ui.box.insertBefore(det, ui.foot);
  }

  function useKb() { var c = $('chat-kb'); return !c || c.checked; }
  (function () {
    var c = $('chat-kb'); if (!c) return;
    try { if (localStorage.getItem('chat-kb') === '0') c.checked = false; } catch (e) {}
    c.addEventListener('change', function () { try { localStorage.setItem('chat-kb', c.checked ? '1' : '0'); } catch (e) {} });
  })();

  function onDone(d, ui) {
    addSources(d.sources, ui);
    addCopy(ui);
    if (d.credits && parseFloat(d.credits) > 0) { var s = document.createElement('span'); s.textContent = trim(d.credits) + ' Credits'; ui.foot.appendChild(s); }
    if (d.stopped) { var p = document.createElement('p'); p.className = 'mt-1 text-xs text-gray-500'; p.textContent = 'You stopped it.'; ui.box.insertBefore(p, ui.foot); }
    refreshBalance();
  }

  function onError(d, ui) {
    if (ui.text) addCopy(ui);
    var p = document.createElement('p'); p.className = 'mt-1 text-xs text-red-600'; p.textContent = d.message || 'The reply could not be generated.';
    ui.box.insertBefore(p, ui.foot);
    if (d.credits && parseFloat(d.credits) > 0) { var s = document.createElement('span'); s.textContent = trim(d.credits) + ' Credits'; ui.foot.appendChild(s); }
    refreshBalance();
  }

  function trim(v) { return String(v).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, ''); }

  function refreshBalance() {
    fetch(window.location.pathname, { headers: { 'Accept': 'text/html' }, credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (h) {
      var m = h.match(/id="chat-balance">([^<]*)</); if (m) $('chat-balance').textContent = m[1];
    }).catch(function () {});
  }

  async function run(url, fd, userText, onSettled) {
    showError(''); setBusy(true);
    if (userText !== null) { var u = bubble('user'); u.body.innerHTML = render(userText); }
    var ui = bubble('assistant'); ui.body.innerHTML = '<span class="animate-pulse text-gray-400">…</span>';
    ui.body.dataset.first = '1';
    try { await stream(url, fd, ui); }
    catch (e) { if (e.name !== 'AbortError') { showError(e.message || 'Connection problem'); if (!ui.text) ui.wrap.remove(); } }
    finally { controller = null; setBusy(false); regenBtn.classList.remove('hidden'); input.focus(); if (onSettled) onSettled(!!ui.text && ui.wrap.isConnected); }
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (busy || (!text && !files.length)) return;
    var fd = new FormData();
    fd.append('text', text);
    fd.append('kb', useKb() ? '1' : '0');
    if (conversationId) fd.append('conversation_id', conversationId);
    if (modelSel.value && !conversationId) fd.append('model_id', modelSel.value);
    if (modeSel && !conversationId) fd.append('mode', modeSel.value);
    if (charSel && charSel.value && !conversationId) fd.append('character_id', charSel.value);
    files.forEach(function (f) { fd.append('files[]', f); });
    var shown = text || '(File)'; if (files.length) shown += '\n📎 ' + files.map(function (f) { return f.name; }).join(', ');
    input.value = ''; files = []; renderFiles();
    run(root.dataset.sendUrl, fd, shown);
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
  });

  stopBtn.addEventListener('click', function () { if (controller) controller.abort(); });

  regenBtn.addEventListener('click', function () {
    if (busy || !conversationId) return;
    // Keep the old answer (hidden) until the new one arrives: the server only replaces it on success.
    var all = thread.querySelectorAll('.msg[data-role="assistant"]'), old = all.length ? all[all.length - 1] : null;
    if (old) old.style.display = 'none';
    run(root.dataset.showUrl + '/' + conversationId + '/regenerate', (function () { var f = new FormData(); f.append('kb', useKb() ? '1' : '0'); return f; })(), null, function (ok) {
      if (!old) return;
      if (ok) old.remove(); else old.style.display = '';
    });
  });

  modelSel.addEventListener('change', function () {
    if (!conversationId) return;
    fetch(root.dataset.showUrl + '/' + conversationId, {
      method: 'PATCH', credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ model_id: modelSel.value || null })
    }).then(function (r) { if (!r.ok) showError('The model could not be changed.'); });
  });

  thread.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('button.copy'); if (!b) return;
    var body = b.closest('.msg').querySelector('.md');
    navigator.clipboard.writeText(body.innerText).then(function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); });
  });

  fillStatic();
})();
