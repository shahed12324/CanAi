<x-layouts.app title="YouTube Settings">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="social" aria-label="YouTube Settings">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SOCIAL</div>
        <h1>YouTube Settings</h1><p>Publish content, monitor channels and manage social activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Social Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('social.show', $p['id']) }}">← Post</a></p>
    <h2 class="mt-1 text-xl font-semibold">YouTube Settings — {{ $p['title'] }}</h2>
    @if (session('error'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800"> of the video <b>Title</b> = Post name (max 100 characters), <b>Description</b> = caption; video = the created video picked in the post. Fix the rest here. After approval it uploads at the right time; by default <b> private</b>. Because of YouTube’s quota at most {{ $cap }} videos go per day — extras go the next day. To set a thumbnail the channel must be verified on YouTube; if not, the video will go, the thumbnail will not be set, and the post will carry a note.</p>
    <p class="mt-2 text-sm">Name: <b id="cur-title">{{ $p['title'] }}</b> <span class="text-xs {{ mb_strlen($p['title']) > 100 ? 'text-red-700' : 'text-gray-500' }}">({{ mb_strlen($p['title']) }}/100)</span> · Video: <b>{{ $p['media_kind'] === 'video' ? 'Picked' : 'Not picked!' }}</b></p>

    @if ($editable)
    <fieldset class="mt-3 rounded border bg-indigo-50 p-3 text-sm"><legend class="px-1 font-medium">AI with title, description, tags and thumbnail ideas (optional, uses a little text credit)</legend>
        <textarea id="brief" rows="3" maxlength="1500" placeholder="What the video is about, for whom, which true facts exist" class="w-full rounded border px-2 py-1"></textarea>
        <div class="mt-1 flex flex-wrap items-center gap-2"><label>Tone <select id="tone" class="rounded border px-2 py-1">@foreach ($tones as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
            <button type="button" id="seo" class="rounded border bg-white px-3 py-1">Get suggestions</button><span id="seo-msg" class="text-xs text-gray-600"></span></div>
        <div id="seo-out" class="mt-2 hidden grid gap-2 rounded bg-white p-3">
            <p><b>Title suggestions:</b> <span id="s-title"></span> <span class="text-xs text-gray-500">(If you wish, put it in the post name)</span></p>
            <div><b>Description suggestions:</b><pre id="s-desc" class="whitespace-pre-wrap font-sans"></pre><span class="text-xs text-gray-500">(If you wish, put it in the post caption)</span></div>
            <p><b>Thumbnail text:</b> <span id="s-ttext"></span> · <b>Image idea:</b> <span id="s-tidea"></span> <a class="text-indigo-600 underline" href="{{ route('studio.index') }}">Design studio</a></p>
            <p><b>Tag:</b> <span id="s-tags"></span> <span class="text-xs text-gray-500">(It has been placed in the tag box below — check and fix)</span></p></div>
        <p class="text-xs text-gray-500">AI Writes only from the information you give; it is told not to invent prices/claims. Nothing is saved or uploaded on its own.</p></fieldset>
    @endif

    <form method="POST" action="{{ route('social.youtube.save', $p['id']) }}" class="mt-3 grid gap-3 rounded-lg bg-white p-4 text-sm shadow">@csrf
        <label class="grid gap-1">YouTube Channels<select name="account_id" class="rounded border px-2 py-1" @disabled(! $editable)><option value="">— Choose —</option>@foreach ($channels as $c)<option value="{{ $c['id'] }}" @selected($p['account_id'] === $c['id'])>{{ $c['name'] }}</option>@endforeach</select>
            <span class="text-xs text-gray-500">If there is no channel <a class="underline" href="{{ route('social.accounts') }}">Page and channel connections</a> add from.</span></label>
        <label class="grid gap-1">Privacy<select name="privacy" class="rounded border px-2 py-1" @disabled(! $editable)>@foreach ($privacy as $k => $l)<option value="{{ $k }}" @selected(old('privacy', $p['yt_privacy']) === $k)>{{ $l }}</option>@endforeach</select></label>
        <label class="grid gap-1">Tags (comma-separated, max 15, each 30 characters)<textarea name="tags" id="tags" rows="2" maxlength="1500" class="rounded border px-2 py-1" @disabled(! $editable)>{{ old('tags', $p['yt_tags']) }}</textarea></label>
        <label class="grid gap-1">Thumbnail (from your design, optional — 16:9 cover size is good)
            <select name="thumb" class="rounded border px-2 py-1" @disabled(! $editable)><option value="">— None (YouTube will pick) —</option>@foreach ($designs as $d)@foreach ($d['sizes'] as $s)<option value="{{ $d['id'] }}|{{ $s }}" @selected($p['thumb_design_id'] === $d['id'] && $p['thumb_size'] === $s)>{{ $d['title'] }} — {{ ['post' => 'Post', 'portrait' => 'Portrait', 'story' => 'Story', 'cover' => 'Cover', 'ad' => 'Ad'][$s] ?? $s }}</option>@endforeach @endforeach</select></label>
        @if ($editable)<div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div>@else<p class="text-xs text-gray-500">Only the author can change their own draft/pending posts.</p>@endif
    </form>
    @if ($editable)
    <script>
    (function () {
        var $ = function (i) { return document.getElementById(i); }, rid = @json($rid), csrf = document.querySelector('input[name=_token]').value;
        $('seo').addEventListener('click', function () {
            var b = $('seo'), m = $('seo-msg'); if (!$('brief').value.trim()) { m.textContent = 'Write what the video is about.'; return; }
            b.disabled = true; m.textContent = 'Writing…';
            fetch(@json(route('social.youtube.suggest')), {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({rid: rid, brief: $('brief').value, title: $('cur-title').textContent, tone: $('tone').value})})
                .then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
                .then(function (x) {
                    if (x[0] && x[1].ok) {
                        var j = x[1]; $('s-title').textContent = j.title; $('s-desc').textContent = j.description; $('s-ttext').textContent = j.thumbnail_text; $('s-tidea').textContent = j.thumbnail_idea; $('s-tags').textContent = j.tags.join(', ');
                        $('tags').value = j.tags.join(', '); $('seo-out').classList.remove('hidden'); m.textContent = 'Suggestions have arrived.';
                        rid = Array.from({length: 26}, function () { return '0123456789abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 36)]; }).join('');
                    } else { m.textContent = (x[1] && x[1].message) || 'Could not be done.'; }
                }).catch(function () { m.textContent = 'Could not be reached.'; }).then(function () { b.disabled = false; });
        });
    })();
    </script>
    @endif
</x-layouts.app>
