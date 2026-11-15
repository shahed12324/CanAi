<x-layouts.app :title="$tool['label']">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="tools" aria-label="{{ $tool['label'] }}">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> TOOLS</div>
        <h1>{{ $tool['label'] }}</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Tools Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 underline" href="{{ route('tools.index') }}">← All tools</a></p>
    <p class="mt-1 text-sm text-gray-600">{{ $tool['help'] }}</p>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    <form method="POST" action="{{ route('tools.run', $key) }}" enctype="multipart/form-data" class="mt-3 grid max-w-xl gap-3 rounded-lg bg-white p-4 text-sm shadow"
          onsubmit="var b=this.querySelector('button[type=submit]');setTimeout(function(){b.disabled=true;b.textContent='Working…';setTimeout(function(){b.disabled=false;b.textContent='Make file';},8000);},0)">
        @csrf
        @if ($tool['max'] > 0)
            <x-tools.uploader :accept="$accept" :min="$tool['min']" :max="$tool['max']" :camera="! empty($tool['camera'])" :labels="$tool['slots'] ?? null" id="files" />
        @endif
        @foreach ($tool['fields'] as $f)
            @if ($f['type'] === 'select')
                <label class="grid gap-1">{{ $f['label'] }}<select name="{{ $f['name'] }}" class="rounded border px-2 py-1">@foreach ($f['options'] as $v => $l)<option value="{{ $v }}" @selected((string) old($f['name'], $f['default']) === (string) $v)>{{ $l }}</option>@endforeach</select></label>
            @elseif ($f['type'] === 'textarea')
                <label class="grid gap-1">{{ $f['label'] }}<textarea name="{{ $f['name'] }}" rows="3" maxlength="{{ $f['max'] ?? 1000 }}" class="rounded border px-2 py-1">{{ old($f['name'], $f['default']) }}</textarea></label>
            @elseif ($f['type'] === 'checkbox')
                <label class="flex items-center gap-2"><input type="checkbox" name="{{ $f['name'] }}" value="1" @checked(old($f['name'], $f['default']))> {{ $f['label'] }}</label>
            @elseif ($f['type'] === 'number')
                <label class="grid gap-1">{{ $f['label'] }}<input type="number" name="{{ $f['name'] }}" value="{{ old($f['name'], $f['default']) }}" min="{{ $f['min'] ?? '' }}" max="{{ $f['max'] ?? '' }}" step="{{ $f['step'] ?? 1 }}" class="rounded border px-2 py-1"></label>
            @else
                <label class="grid gap-1">{{ $f['label'] }}<input type="text" name="{{ $f['name'] }}" maxlength="{{ $f['max'] ?? 40 }}" value="{{ old($f['name'], $f['default']) }}" class="rounded border px-2 py-1"></label>
            @endif
        @endforeach
        <div><button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">Make file</button></div>
        <p class="text-xs text-gray-500">The result downloads automatically. Files up to 25 MB each.</p>
    </form>
</x-layouts.app>
