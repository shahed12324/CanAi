<x-layouts.app title="Voice">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="voice" aria-label="Voice">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> VOICE</div>
        <h1>Voice</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Voice Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Voice — speak, Jarvis listens and replies</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">Turn on the mic and speak (or type below) — your words are turned into text and a short answer comes, and can be read aloud if you wish. <b>The recording is not stored anywhere</b> (goes to the voice provider only to be turned into text). Every step costs credits (listening: by size, speaking: by characters, answer: by tokens). Here Jarvis only converses — it does not take actions; do not speak passwords/card numbers. Hands-free calling ("Hey CanAI") is available below in Chrome/Edge; it is off until you switch it on, every visit. Recording is max 5 MB (~5 minutes). The browser must be given mic permission and the site must be on https.</p>
    <div class="mt-3 grid gap-2 rounded-lg bg-white p-4 text-sm shadow">
        <div class="flex flex-wrap gap-3">
            <label>Language<select id="lang" class="mt-1 block rounded border px-2 py-1">@foreach ($languages as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
            <label>Voice<select id="voice" class="mt-1 block rounded border px-2 py-1">@foreach ($voices as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
            <label class="flex items-end gap-1"><input type="checkbox" id="speak" checked> Read the answer aloud</label></div>
        <div class="flex flex-wrap items-center gap-3"><button id="rec" type="button" class="rounded bg-indigo-600 px-4 py-2 text-white">🎤 Start speaking</button><span id="state" class="text-xs text-gray-600"></span></div>
        <div class="flex gap-2"><input id="typed" maxlength="2000" placeholder="or type here…" class="flex-1 rounded border px-2 py-1"><button id="send" type="button" class="rounded border px-3 py-1">Send</button></div>
        <p id="err" class="hidden rounded bg-red-50 p-2 text-red-700"></p>
    </div>
    <section class="mt-3 grid gap-2 rounded-lg bg-white p-4 text-sm shadow" aria-label="Hands-free">
        <h3 class="font-semibold">Hands-free (say "Hey CanAI")</h3>
        <p class="text-xs text-gray-600">While this is on, your browser listens for the calling phrase. In Chrome/Edge that listening is done by the browser maker's speech service, so audio leaves your computer even before you say the phrase. Anything that is not the calling phrase is thrown away by this page, and the microphone indicator below shows whenever listening is on. Nothing starts by itself: you must switch it on again on every visit. Spoken words can only ask questions — they can never approve anything on your computer.</p>
        <label class="flex items-center gap-2"><input type="checkbox" id="hf-consent"> I understand, and I want hands-free listening while this page is open</label>
        <div class="flex flex-wrap items-center gap-3">
            <button id="hf-toggle" type="button" class="rounded bg-gray-800 px-3 py-1 text-white" disabled>Turn on hands-free</button>
            <button id="hf-stop" type="button" class="rounded bg-red-600 px-3 py-1 text-white">Stop everything</button>
            <span id="hf-mic" class="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-700">Microphone: off</span>
            @if (! empty($avatar))<img id="hf-avatar" src="{{ $avatar['src'] }}" data-base="{{ $avatar['src'] }}" alt="{{ $avatar['name'] }}" width="72" height="72" class="rounded bg-gray-50">@endif
            <span id="hf-state" class="text-xs text-gray-600"></span>
        </div>
        <p id="hf-note" class="hidden text-xs text-amber-700"></p>
    </section>
    <section id="log" class="mt-3 grid gap-2 text-sm"></section>
    <script src="{{ asset('js/voice-session.js') }}"></script>
    <script src="{{ asset('js/voice-browser.js') }}"></script>
    <script>
    (function () {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
        const $ = (id) => document.getElementById(id);
        const MAX = {{ $maxBytes }};
        let rec = null, chunks = [], stream = null, busy = false;
        function show(msg) { const e = $('err'); e.textContent = msg || ''; e.classList.toggle('hidden', !msg); }
        function add(who, text, cls) { const d = document.createElement('div'); d.className = 'rounded p-3 ' + cls; const b = document.createElement('b'); b.textContent = who + ': '; d.appendChild(b); d.appendChild(document.createTextNode(text)); $('log').prepend(d); return d; }
        async function post(url, fd) {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: fd });
            let j = null; try { j = await r.json(); } catch (e) {}
            return { ok: r.ok, j: j || { message: 'The right answer did not come from the server (' + r.status + ')' } };
        }
        function play(b64, mime) { try { const a = new Audio('data:' + mime + ';base64,' + b64); a.play().catch(() => {}); } catch (e) {} }
        async function ask(fd, quiet) {
            if (busy) return null; busy = true; show(''); $('state').textContent = 'Working…';
            let spoken = null;
            fd.append('lang', $('lang').value); fd.append('voice', $('voice').value); fd.append('speak', !quiet && $('speak').checked ? '1' : '0');
            try {
                const { ok, j } = await post('{{ route('voice.ask') }}', fd);
                if (j.transcript) add('You are', j.transcript, 'bg-gray-100');
                if (!ok) { show(j.message || 'Could not be done.'); }
                else { spoken = j.answer || null; add('Jarvis', j.answer || '', 'bg-indigo-50'); if (j.audio_b64) play(j.audio_b64, j.mime || 'audio/mpeg'); else if (j.speak_status && j.speak_status !== 'ok') show('The answer is in text, but it could not be read aloud.'); }
                if (j.credits && j.credits !== '0.0000') $('state').textContent = 'Credits spent: ' + j.credits; else $('state').textContent = '';
            } catch (e) { show('Network problem. Try again.'); $('state').textContent = ''; }
            busy = false;
            return spoken;
        }
        $('send').onclick = () => { const t = $('typed').value.trim(); if (!t) return; const fd = new FormData(); fd.append('text', t); $('typed').value = ''; ask(fd); };
        $('typed').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('send').click(); } });
        $('rec').onclick = async () => {
            if (rec) { rec.stop(); return; }
            if (!navigator.mediaDevices || !window.MediaRecorder) { show('Mic recording does not work in this browser. Send by typing.'); return; }
            try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); } catch (e) { show('Mic permission was not granted.'); return; }
            chunks = []; rec = new MediaRecorder(stream);
            rec.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
            rec.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                const blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' }); rec = null;
                $('rec').textContent = '🎤 Start speaking';
                if (blob.size > MAX) { show('The recording is too large — speak shorter.'); return; }
                const fd = new FormData(); fd.append('audio', blob, 'voice.webm'); ask(fd);
            };
            rec.start(); $('rec').textContent = '⏹ Stop and send'; $('state').textContent = 'Listening…';
        };

        // ---- hands-free: all decisions live in /js/voice-session.js (tested); the browser adapters are in /js/voice-browser.js (needs a real browser + mic)
        const hfNote = (m) => { const n = $('hf-note'); n.textContent = m || ''; n.classList.toggle('hidden', !m); };
        let session = null;
        const langTag = () => ({ bn: 'bn-BD', en: 'en-US' })[$('lang').value] || $('lang').value || 'en-US';
        function hfUpdate() { $('hf-toggle').disabled = !$('hf-consent').checked || !(window.VoiceBrowser && VoiceBrowser.supported()); }
        $('hf-consent').onchange = hfUpdate;
        if (!(window.VoiceBrowser && VoiceBrowser.supported())) { hfNote('Hands-free needs Chrome or Edge with a microphone. You can still use the button above or type.'); }
        hfUpdate();
        $('hf-toggle').onclick = () => {
            if (session) { session.revoke(); session = null; $('hf-toggle').textContent = 'Turn on hands-free'; return; }
            const names = { off: 'Off', armed: 'Waiting for "Hey CanAI"', listening: 'Listening…', thinking: 'Thinking…', speaking: 'Speaking (say "stop" to interrupt)', error: 'Something went wrong — still waiting' };
            session = VoiceSession.VoiceSession(
                { wake: VoiceBrowser.wakeAdapter(langTag), capture: VoiceBrowser.captureAdapter(), speaker: VoiceBrowser.speakerAdapter(langTag) },
                {
                    deliver: (u) => { const fd = new FormData(); if (u.text) { fd.append('text', u.text); } else if (u.blob && u.blob.size && u.blob.size <= MAX) { fd.append('audio', u.blob, 'voice.webm'); } else { return Promise.resolve(null); } return ask(fd, true); },
                    onState: (st) => { $('hf-state').textContent = names[st] || st; const av = $('hf-avatar'); if (av) { const a = VoiceSession.avatarFor(st); av.src = av.dataset.base + '?expression=' + encodeURIComponent(a.expression) + '&mouth=' + encodeURIComponent(a.mouth); } },
                    onIndicator: (on) => { const m = $('hf-mic'); m.textContent = on ? 'Microphone: ON' : 'Microphone: off'; m.className = 'rounded-full px-2 py-0.5 text-xs ' + (on ? 'bg-red-600 text-white' : 'bg-gray-200 text-gray-700'); },
                });
            session.grant(); $('hf-toggle').textContent = 'Turn off hands-free';
        };
        $('hf-stop').onclick = () => { if (session) { session.stopAll(); session = null; $('hf-toggle').textContent = 'Turn on hands-free'; } window.speechSynthesis && speechSynthesis.cancel(); };
        window.addEventListener('pagehide', () => { if (session) { session.stopAll(); } });
    })();
    </script>
</x-layouts.app>
