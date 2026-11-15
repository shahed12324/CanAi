<x-layouts.app :title="$post ? 'Change post' : 'New post'">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="social" aria-label="Social — Form">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SOCIAL</div>
        <h1>Social — Form</h1><p>Publish content, monitor channels and manage social activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Social Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('social.index') }}">← Social planner</a></p>
    <h2 class="mt-1 text-xl font-semibold">{{ $post ? 'Change post' : 'New post' }}</h2>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ $post ? route('social.update', $post['id']) : route('social.store') }}" class="mt-3 grid gap-3 rounded-lg bg-white p-4 text-sm shadow">@csrf
        <label class="grid gap-1">Name (internal, 120 characters)<input name="title" maxlength="120" required value="{{ old('title', $post['title'] ?? '') }}" class="rounded border px-2 py-1"></label>
        <label class="grid gap-1">Platform<select name="platform" id="platform" class="rounded border px-2 py-1">@foreach ($platforms as $k => $l)<option value="{{ $k }}" @selected(old('platform', $post['platform'] ?? 'facebook') === $k)>{{ $l }}</option>@endforeach</select></label>

        <fieldset class="rounded border bg-indigo-50 p-3"><legend class="px-1 font-medium">AI Caption + hashtags with (optional, uses a little text credit)</legend>
            <textarea id="brief" rows="2" maxlength="1500" placeholder="Post topic, for example: new cashew nuts arrived, 500 g for 650 taka, free delivery in Dhaka" class="w-full rounded border px-2 py-1"></textarea>
            <div class="mt-1 flex flex-wrap items-center gap-2"><label>Tone <select id="tone" class="rounded border px-2 py-1">@foreach ($tones as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <button type="button" id="write-cap" class="rounded border bg-white px-3 py-1">Write caption</button><span id="cap-msg" class="text-xs text-gray-600"></span></div>
            <p class="text-xs text-gray-500">AI Writes only from the information you give. The text goes into the box below (replacing earlier text) — check and fix it.</p></fieldset>

        <label class="grid gap-1">Caption <textarea name="caption" id="caption" rows="6" maxlength="3000" required class="rounded border px-2 py-1">{{ old('caption', $post['caption'] ?? '') }}</textarea>
            <span class="text-xs text-gray-500"><span id="count">0</span> / <span id="limit">3000</span> characters</span></label>

        <fieldset class="rounded border p-3"><legend class="px-1 font-medium">Image or video (optional — from your own created work)</legend>
            @php($kind = old('media_kind', $post['media_kind'] ?? 'none'))
            <label class="flex items-center gap-2"><input type="radio" name="media_kind" value="none" @checked($kind === 'none')> None</label>
            <label class="mt-1 flex flex-wrap items-center gap-2"><input type="radio" name="media_kind" value="design" @checked($kind === 'design')> Design
                <select id="design" class="rounded border px-2 py-1"><option value="">— Choose —</option>@foreach ($designs as $d)<option value="{{ $d['id'] }}" data-sizes='@json($d['sizes'])' @selected(($post['media_id'] ?? '') === $d['id'] && $kind === 'design')>{{ $d['title'] }}</option>@endforeach</select>
                <select id="dsize" class="rounded border px-2 py-1"></select></label>
            <label class="mt-1 flex flex-wrap items-center gap-2"><input type="radio" name="media_kind" value="video" @checked($kind === 'video')> Video
                <select id="video" class="rounded border px-2 py-1"><option value="">— Choose —</option>@foreach ($videos as $v)<option value="{{ $v['id'] }}" @selected(($post['media_id'] ?? '') === $v['id'] && $kind === 'video')>{{ $v['title'] }}</option>@endforeach</select></label>
            <input type="hidden" name="media_id" id="media_id" value="{{ old('media_id', $post['media_id'] ?? '') }}"><input type="hidden" name="media_size" id="media_size" value="{{ old('media_size', $post['media_size'] ?? '') }}">
            <p class="mt-1 text-xs text-gray-500">Only your own designs and created videos can be chosen. <a class="underline" href="{{ route('studio.index') }}">Design</a> · <a class="underline" href="{{ route('video.index') }}">Video</a></p></fieldset>

        <label class="grid gap-1">Facebook Auto-post to page (Facebook posts only)
            <select name="account_id" class="rounded border px-2 py-1"><option value="">No — I will post myself</option>@foreach ($pages as $pg)<option value="{{ $pg['id'] }}" @selected(old('account_id', $post['account_id'] ?? '') === $pg['id'])>{{ $pg['name'] }}</option>@endforeach</select>
            <span class="text-xs text-gray-500">If you pick a page it posts by itself at the right time after approval. To add a page <a class="underline" href="{{ route('social.accounts') }}">Page connection</a>.</span></label>
        <label class="grid gap-1">When to post ({{ $tz }} time, optional)<input type="datetime-local" name="scheduled" value="{{ old('scheduled', $scheduled) }}" class="rounded border px-2 py-1"></label>
        <p class="text-xs text-gray-500">Time is needed to send for approval. If saved it stays as a draft.</p>
        <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div>
    </form>
    <script>
    (function () {
        var $ = function (i) { return document.getElementById(i); }, LIM = @json($limits), rid = @json($rid), csrf = document.querySelector('input[name=_token]').value;
        function count() { var p = $('platform').value; $('count').textContent = Array.from($('caption').value).length; $('limit').textContent = LIM[p] || 3000; $('count').style.color = Array.from($('caption').value).length > (LIM[p] || 3000) ? '#b91c1c' : ''; }
        $('caption').addEventListener('input', count); $('platform').addEventListener('change', count); count();
        var LBL = {post: 'Post', portrait: 'Portrait', story: 'Story', cover: 'Cover', ad: 'Ad'};
        function sizes() { var o = $('design').selectedOptions[0], list = o && o.dataset.sizes ? JSON.parse(o.dataset.sizes) : [], cur = $('media_size').value; $('dsize').innerHTML = list.map(function (s) { return '<option value="' + s + '"' + (s === cur ? ' selected' : '') + '>' + (LBL[s] || s) + '</option>'; }).join(''); }
        function sync() {
            var k = document.querySelector('input[name=media_kind]:checked').value;
            if (k === 'design') { $('media_id').value = $('design').value; $('media_size').value = $('dsize').value; }
            else if (k === 'video') { $('media_id').value = $('video').value; $('media_size').value = ''; }
            else { $('media_id').value = ''; $('media_size').value = ''; }
        }
        $('design').addEventListener('change', function () { $('media_size').value = ''; sizes(); sync(); });
        ['dsize', 'video'].forEach(function (i) { $(i).addEventListener('change', sync); });
        document.querySelectorAll('input[name=media_kind]').forEach(function (r) { r.addEventListener('change', sync); });
        sizes(); sync();
        $('write-cap').addEventListener('click', function () {
            var b = $('write-cap'), m = $('cap-msg'); if (!$('brief').value.trim()) { m.textContent = 'Write a topic.'; return; }
            b.disabled = true; m.textContent = 'Writing…';
            fetch(@json(route('social.caption')), {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({rid: rid, brief: $('brief').value, platform: $('platform').value, tone: $('tone').value})})
                .then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
                .then(function (x) { if (x[0] && x[1].ok) { $('caption').value = x[1].caption; count(); m.textContent = 'has been placed — check and fix.'; rid = Array.from({length: 26}, function () { return '0123456789abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 36)]; }).join(''); } else { m.textContent = (x[1] && x[1].message) || 'Could not be done.'; } })
                .catch(function () { m.textContent = 'Could not be reached.'; }).then(function () { b.disabled = false; });
        });
    })();
    </script>
</x-layouts.app>
