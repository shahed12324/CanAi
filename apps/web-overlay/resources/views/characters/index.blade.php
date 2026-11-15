<x-layouts.app title="AI Characters">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="characters" aria-label="AI Characters">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> CHARACTERS</div>
        <h1>AI Characters</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Characters Workspace</span>
    </header>

    <div class="space-y-6">
        <header>
            <h1 class="text-lg font-semibold">AI Characters</h1>
            <p class="text-sm text-gray-600">Create your own original characters (max {{ $max }}). Pick a character when starting a new chat. Do not imitate characters from existing films/comics/games — a character always identifies itself as an AI.</p>
        </header>
        @if (session('status'))<p class="rounded bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ session('status') }}</p>@endif
        @error('character')<p class="rounded bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p>@enderror

        <section class="space-y-3">
            @forelse ($characters as $c)
                <details class="rounded-lg bg-white p-4 shadow">
                    <summary class="cursor-pointer text-sm font-medium">{{ $c['name'] }} <span class="text-xs text-gray-500">({{ ['male' => 'Male', 'female' => 'Female', 'custom' => 'Custom'][$c['kind']] ?? '' }})</span></summary>
                    @if (($c['avatar_style'] ?? 'none') === '2d')
                        <div class="mt-3 flex flex-wrap items-center gap-3" data-avatar-box data-cues="{{ route('characters.cues', $c['id']) }}" data-src="{{ route('characters.avatar', $c['id']) }}" data-speak="{{ route('voice.speak') }}" data-voice="{{ \App\Character\CharacterVoice::pick($c['voice'] ?? null, (string) $c['kind']) }}">
                            <img src="{{ route('characters.avatar', $c['id']) }}" alt="{{ $c['name'] }}-avatar of" width="96" height="96" class="rounded bg-gray-50" data-avatar-img>
                            <input type="text" maxlength="400" class="min-w-0 flex-1 rounded border px-2 py-1 text-sm" placeholder="Write a sentence, the avatar 'says' will show" data-avatar-text>
                            <label class="flex items-center gap-1 text-xs"><input type="checkbox" data-avatar-voice> With sound (costs credits)</label>
                            <button type="button" class="rounded border px-3 py-1 text-sm" data-avatar-go>Try saying</button>
                            <p class="w-full text-xs text-gray-500">Only a preview of mouth and expression (guessed from the text) — no sound yet.</p>
                        </div>
                    @endif
                    @if ($c['created_by'] === $me)
                        <form method="POST" action="{{ route('characters.update', $c['id']) }}" class="mt-3 grid gap-2 text-sm">
                            @csrf @method('PUT')
                            @include('characters._fields', ['v' => $c])
                            <div class="flex gap-2"><x-button>Save</x-button></div>
                        </form>
                        <form method="POST" action="{{ route('characters.destroy', $c['id']) }}" class="mt-2" onsubmit="return confirm('Delete the character?')">@csrf @method('DELETE')<x-button variant="ghost">Delete</x-button></form>
                    @else
                        <p class="mt-2 text-sm text-gray-700">{{ $c['personality'] }}</p>
                    @endif
                </details>
            @empty
                <p class="text-sm text-gray-500">No characters yet. Start from the form or a template below.</p>
            @endforelse
        </section>

        <section class="rounded-lg bg-white p-4 shadow">
            <h2 class="mb-2 text-sm font-semibold">New character</h2>
            <div class="mb-3 flex flex-wrap gap-2 text-xs">
                @foreach ($starters as $s)
                    <button type="button" class="rounded border px-2 py-1 hover:bg-gray-50" data-fill="{{ json_encode($s, JSON_UNESCAPED_UNICODE) }}">Template: {{ $s['name'] }}</button>
                @endforeach
            </div>
            <form id="char-new" method="POST" action="{{ route('characters.store') }}" class="grid gap-2 text-sm">
                @csrf
                @include('characters._fields', ['v' => ['name' => old('name'), 'kind' => old('kind', 'custom'), 'personality' => old('personality'), 'speaking_style' => old('speaking_style'), 'language' => old('language', 'bn'), 'voice' => old('voice'), 'avatar_style' => old('avatar_style', 'none'), 'greeting' => old('greeting')]])
                <div><x-button>Add</x-button></div>
            </form>
        </section>
    </div>
    <script src="{{ asset('js/avatar-player.js') }}"></script>
    <script>
        document.querySelectorAll('[data-avatar-box]').forEach(function (box) {
            var img = box.querySelector('[data-avatar-img]'), txt = box.querySelector('[data-avatar-text]'), stop = null;
            box.querySelector('[data-avatar-go]').addEventListener('click', function () {
                var t = txt.value.trim(); if (!t) { return; }
                if (stop) { stop(); stop = null; }
                var tok = (document.querySelector('input[name=_token]') || {}).value || '';
                function post(url, body) { return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': tok }, body: JSON.stringify(body) }).then(function (r) { return r.json(); }); }
                if (!box.querySelector('[data-avatar-voice]').checked) {
                    post(box.dataset.cues, { text: t }).then(function (j) { if (j && j.data) { stop = AvatarPlayer.play(img, box.dataset.src, j.data); } });
                    return;
                }
                post(box.dataset.speak, { text: t, voice: box.dataset.voice }).then(function (s) {
                    if (!s || s.status !== 'ok') { alert((s && s.message) || 'Sound could not be generated.'); return; }
                    var audio = new Audio('data:' + s.mime + ';base64,' + s.audio_b64);
                    audio.addEventListener('loadedmetadata', function () {
                        post(box.dataset.cues, { text: t, ms: Math.max(1, Math.round(audio.duration * 1000)) }).then(function (j) { if (j && j.data) { stop = AvatarPlayer.playSynced(img, box.dataset.src, j.data, audio); } });
                    });
                });
            });
        });
        document.querySelectorAll('[data-fill]').forEach(function (b) {
            b.addEventListener('click', function () {
                var d = JSON.parse(b.dataset.fill), f = document.getElementById('char-new');
                Object.keys(d).forEach(function (k) { if (f.elements[k]) f.elements[k].value = d[k]; });
            });
        });
    </script>
</x-layouts.app>
