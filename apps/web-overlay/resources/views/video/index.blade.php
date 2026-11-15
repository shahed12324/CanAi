<x-layouts.app title="Video studio">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="video" aria-label="Video studio">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> VIDEO</div>
        <h1>Video studio</h1><p>Create and manage video projects.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Video Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Video studio — slide video (reel/story/post)</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">Text (including Bengali), logo and brand colors in every scene <b> we place them ourselves, precisely</b>, We make the video with slow zoom and fades; optionally the scene background image can be drawn by AI (AI image + motion — not a fully AI-moving video). Max 10 scenes, 60 seconds total. Price: per second <b>{{ $perSecond }}</b> credits (held at the start, fully refunded if it fails or is cancelled). A voice-over needs separate speech credits. It is not AI-drawn video yet — it is a slide video.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @unless ($brand['has_kit'])<p class="mt-2 rounded bg-blue-50 p-3 text-sm text-blue-800">There is no brand kit yet — <a class="underline" href="{{ route('brand.show') }}">Brand kit</a> if you make one, logo and colors are placed in the video automatically.</p>@endunless

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">New video</h3>
        <form method="POST" action="{{ route('video.store') }}" enctype="multipart/form-data" class="mt-2 grid gap-3" onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'Sending…'">@csrf
            <input type="hidden" name="rid" value="{{ $rid }}">
            <label class="grid gap-1">Name (only for you)<input name="title" maxlength="120" required value="{{ old('title') }}" placeholder="Eid offer reel" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Size<select name="size" class="rounded border px-2 py-1">@foreach ($sizes as $k => [$label, $hint])<option value="{{ $k }}" @selected(old('size', 'story') === $k)>{{ $label }} — {{ $hint }}</option>@endforeach</select></label>

            <fieldset class="rounded border bg-indigo-50 p-3"><legend class="px-1 font-medium">AI Write a script with (optional, uses a little text credit)</legend>
                <textarea id="brief" rows="2" maxlength="1500" placeholder="Video topic, for example: 30% off cashews for Eid, free delivery in Dhaka, inbox to order" class="w-full rounded border px-2 py-1"></textarea>
                <div class="mt-1 flex flex-wrap items-center gap-2"><label>Scenes <input id="brief-n" type="number" min="1" max="10" value="4" class="w-16 rounded border px-2 py-1"></label>
                    <button type="button" id="write-script" class="rounded border bg-white px-3 py-1">Write script</button><span id="script-msg" class="text-xs text-gray-600"></span></div>
                <p class="text-xs text-gray-500">AI Writes only from the information you give — the text goes into the form; you check and change it and then make the video.</p></fieldset>
            <div><div class="mb-1 text-xs text-gray-500">Timeline — click a block to go to that scene; drag scenes (⠿) or ↑ ↓ arrange with buttons</div>
                <div id="timeline" class="flex h-8 w-full overflow-hidden rounded border bg-gray-100"></div></div>
            <div id="scenes" class="grid gap-3"></div>
            <div><button type="button" id="add-scene" class="rounded border px-3 py-1">+ Add scene</button> <span id="total" class="ml-2 text-xs text-gray-500"></span></div>

            <fieldset class="rounded border p-3"><legend class="px-1 font-medium">Voice-over (optional)</legend>
                <textarea name="narration" rows="3" maxlength="2000" placeholder="Write what you want said — AI will read it aloud. If left empty the video is silent." class="w-full rounded border px-2 py-1">{{ old('narration') }}</textarea>
                <label class="mt-1 flex items-center gap-2">Voice <select name="voice" class="rounded border px-2 py-1">@foreach ($voices as $k => $l)<option value="{{ $k }}" @selected(old('voice', 'nova') === $k)>{{ $l }}</option>@endforeach</select></label>
                <label class="mt-1 flex items-center gap-2"><input type="checkbox" name="captions" value="1" @checked(old('captions'))> Add subtitles (the voice-over text appears under the video in Bengali letters)</label>
                <p class="text-xs text-gray-500">If the voice is longer than the video it is cut at the end. Subtitle timing splits the text by voice length — it does not match word by word.</p></fieldset>
            <label class="grid gap-1">Background music (optional, free)<select name="music" class="rounded border px-2 py-1"><option value="">None</option>@foreach ($music as $k => $l)<option value="{{ $k }}" @selected(old('music') === $k)>{{ $l }}</option>@endforeach</select>
                <span class="text-xs text-gray-500">A soft tune we made ourselves — no copyright; plays quietly if there is a voice-over. A calm background tune, not a song.</span></label>
            <div><button type="submit" class="rounded bg-indigo-600 px-3 py-1 text-white">Make video</button></div>
        </form>
        <template id="scene-tpl">
            <fieldset class="scene rounded border p-3"><legend class="px-1 font-medium"><span class="drag cursor-grab select-none" title="Drag to move" draggable="true">⠿</span> Scenes <span class="n"></span>
                    <button type="button" class="up ml-2 text-xs" title="Up">↑</button> <button type="button" class="down text-xs" title="Down">↓</button>
                    <button type="button" class="dup ml-1 text-xs" title="Copy (without image)">Copy</button> <button type="button" class="rm ml-1 text-xs text-red-600">Remove</button></legend>
                <label class="grid gap-1">Title<input data-f="headline" maxlength="200" class="rounded border px-2 py-1"></label>
                <label class="mt-1 grid gap-1">Subtitle<input data-f="subline" maxlength="400" class="rounded border px-2 py-1"></label>
                <label class="mt-1 grid gap-1">Button text<input data-f="cta" maxlength="100" class="rounded border px-2 py-1"></label>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <label>Theme <select data-f="theme" class="rounded border px-2 py-1">@foreach ($themes as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                    <label>Seconds <input data-f="seconds" type="number" min="2" max="8" step="0.5" value="4" class="w-20 rounded border px-2 py-1"></label>
                    <label>Image (optional) <input data-f="photo" type="file" accept="image/png,image/jpeg,image/webp" class="text-xs"></label></div>
                <label class="mt-1 grid gap-1"> or make an image with AI (per image {{ $imagePrice }} credits, in at most 5 scenes; not used if you give your own image)<input data-f="ai_prompt" maxlength="500" placeholder="For example: a bowl of cashew nuts on a wooden table, soft light" class="rounded border px-2 py-1"></label>
            </fieldset>
        </template>
        <script src="{{ asset('js/video-timeline.js') }}"></script>
        <script>
            (function () {
                var T = window.VideoTimeline, box = document.getElementById('scenes'), tpl = document.getElementById('scene-tpl'), total = document.getElementById('total'), bar = document.getElementById('timeline');
                var colors = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#f97316', '#84cc16', '#ec4899'];
                function secs(el) { return T.clampSeconds(el.querySelector('[data-f=seconds]').value); }
                function renumber() {
                    var items = Array.prototype.slice.call(box.querySelectorAll('.scene')), list = items.map(secs);
                    items.forEach(function (el, i) {
                        el.querySelector('.n').textContent = i + 1;
                        el.querySelectorAll('[data-f]').forEach(function (inp) { inp.name = 'scenes[' + i + '][' + inp.getAttribute('data-f') + ']'; });
                    });
                    var s = T.summary(list);
                    total.textContent = s.text; total.style.color = s.ok ? '' : '#b91c1c';
                    bar.innerHTML = '';
                    T.segments(list).forEach(function (seg, i) {
                        var b = document.createElement('button');
                        b.type = 'button'; b.style.cssText = 'width:' + seg.pct + '%;background:' + colors[i % colors.length] + ';color:#fff;font-size:11px;border-right:1px solid #fff';
                        b.textContent = (i + 1); b.title = 'Scenes ' + (i + 1) + ' · ' + seg.start + 's–' + (Math.round((seg.start + seg.len) * 10) / 10) + 's';
                        b.addEventListener('click', function () { items[i].scrollIntoView({behavior: 'smooth', block: 'center'}); });
                        bar.appendChild(b);
                    });
                    document.getElementById('add-scene').disabled = items.length >= T.MAX_SCENES;
                    items.forEach(function (el, i) {
                        el.querySelector('.up').disabled = i === 0; el.querySelector('.down').disabled = i === items.length - 1;
                        el.querySelector('.rm').disabled = items.length === 1;
                    });
                }
                function moveTo(el, to) {
                    var items = Array.prototype.slice.call(box.children), from = items.indexOf(el), idx = T.order(items.length, from, to);
                    idx.forEach(function (k) { box.appendChild(items[k]); });   // files typed into a scene travel with it
                    renumber();
                }
                function wire(el) {
                    el.querySelector('.rm').addEventListener('click', function () { if (box.children.length > 1) { el.remove(); renumber(); } });
                    el.querySelector('.up').addEventListener('click', function () { moveTo(el, Array.prototype.indexOf.call(box.children, el) - 1); });
                    el.querySelector('.down').addEventListener('click', function () { moveTo(el, Array.prototype.indexOf.call(box.children, el) + 1); });
                    el.querySelector('.dup').addEventListener('click', function () {
                        if (box.children.length >= T.MAX_SCENES) return;
                        var c = add(); ['headline', 'subline', 'cta', 'theme', 'seconds', 'ai_prompt'].forEach(function (f) { c.querySelector('[data-f=' + f + ']').value = el.querySelector('[data-f=' + f + ']').value; });
                        moveTo(c, Array.prototype.indexOf.call(box.children, el) + 1);
                    });
                    el.querySelector('[data-f=seconds]').addEventListener('input', renumber);
                    var h = el.querySelector('.drag');
                    h.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', 'scene'); e.dataTransfer.effectAllowed = 'move'; box._drag = el; el.style.opacity = '0.5'; });
                    h.addEventListener('dragend', function () { el.style.opacity = ''; box._drag = null; });
                    el.addEventListener('dragover', function (e) { if (box._drag && box._drag !== el) e.preventDefault(); });
                    el.addEventListener('drop', function (e) { e.preventDefault(); if (box._drag && box._drag !== el) moveTo(box._drag, Array.prototype.indexOf.call(box.children, el)); });
                }
                function add() {
                    var el = tpl.content.firstElementChild.cloneNode(true);
                    wire(el); box.appendChild(el); renumber(); return el;
                }
                document.getElementById('add-scene').addEventListener('click', add);
                add();
                var rid = @json($rid) , csrf = document.querySelector('input[name=_token]').value;
                document.getElementById('write-script').addEventListener('click', function () {
                    var btn = this, msg = document.getElementById('script-msg'), brief = document.getElementById('brief').value.trim();
                    if (!brief) { msg.textContent = 'Write the topic first.'; return; }
                    btn.disabled = true; msg.textContent = 'Writing…';
                    fetch(@json(route('video.script')), {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                        body: JSON.stringify({rid: rid, brief: brief, scenes: parseInt(document.getElementById('brief-n').value, 10) || 4})})
                    .then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
                    .then(function (x) {
                        if (!x[0] || !x[1].ok) { msg.textContent = (x[1] && x[1].message) || 'Could not be done.'; return; }
                        var s = x[1].script; box.innerHTML = '';
                        s.scenes.forEach(function (sc) {
                            add(); var el = box.lastElementChild;
                            el.querySelector('[data-f=headline]').value = sc.headline; el.querySelector('[data-f=subline]').value = sc.subline;
                            el.querySelector('[data-f=cta]').value = sc.cta; el.querySelector('[data-f=seconds]').value = sc.seconds;
                        });
                        renumber();
                        if (s.title && !document.querySelector('[name=title]').value) document.querySelector('[name=title]').value = s.title;
                        document.querySelector('[name=narration]').value = s.narration;
                        msg.textContent = 'done — check and change. (Writing again for this script costs credits again.)';
                        rid = Array.from({length: 26}, function () { return 'abcdefghijklmnopqrstuvwxyz0123456789'[Math.floor(Math.random() * 36)]; }).join('');
                    }).catch(function () { msg.textContent = 'Network problem.'; }).finally(function () { btn.disabled = false; });
                });
            })();
        </script>
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Clip editor — trim your own video, cut silences, fit to reel/story size</h3>
        <p class="mt-1 text-xs text-gray-500">MP4/MOV/WEBM, Max 25 MB and 2 minutes. The part after trimming must be 2–60 seconds. Same rate (per second {{ $perSecond }} credits, based on the trimmed length). Filling the frame may crop the edges.</p>
        <form method="POST" action="{{ route('video.clip') }}" enctype="multipart/form-data" class="mt-2 grid gap-3" onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'Uploading…'">@csrf
            <input type="hidden" name="rid" value="{{ $rid }}">
            <label class="grid gap-1">Name<input name="title" maxlength="120" required class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Video file<input type="file" name="video" required accept="video/mp4,video/quicktime,video/webm" class="text-xs"></label>
            <div class="flex flex-wrap items-center gap-3">
                <label>Start (seconds) <input name="trim_start" type="number" min="0" max="600" step="0.1" value="0" class="w-24 rounded border px-2 py-1"></label>
                <label>End (seconds, empty = to the end) <input name="trim_end" type="number" min="0" max="600" step="0.1" class="w-24 rounded border px-2 py-1"></label>
                <label>Size <select name="size" class="rounded border px-2 py-1">@foreach ($sizes as $k => [$label, $hint])<option value="{{ $k }}" @selected($k === 'story')>{{ $label }}</option>@endforeach</select></label></div>
            <label class="flex items-center gap-2"><input type="checkbox" name="remove_silence" value="1"> Cut silent parts automatically (long pauses in speech)</label>
            <div><button type="submit" class="rounded bg-indigo-600 px-3 py-1 text-white">Make clip</button></div>
        </form>
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Your videos ({{ count($jobs) }}/30)</h3>
        <ul class="mt-2 divide-y">@forelse ($jobs as $j)
            <li class="flex items-center justify-between py-2"><a class="font-medium hover:underline" href="{{ route('video.show', $j['id']) }}">{{ $j['title'] }}</a>
                <span class="text-xs text-gray-500">{{ $j['seconds'] }} seconds · {{ $status[$j['status']] ?? $j['status'] }}</span></li>
            @empty<li class="py-2 text-gray-500">No videos yet.</li>@endforelse</ul>
    </section>
</x-layouts.app>
