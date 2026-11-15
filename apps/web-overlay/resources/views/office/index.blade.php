<x-layouts.app title="Document and file creation">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="office" aria-label="Document and file creation">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> OFFICE</div>
        <h1>Document and file creation</h1><p>Create and manage your documents.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Office Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Create documents, spreadsheets and presentations</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">Write what you want — AI will make a draft file and you download it as Word / Excel / PowerPoint. AI can make mistakes: <b>After downloading, check numbers, names and facts yourself</b>. Where information is unknown, the AI’s text may contain blanks such as [Name] / [Amount]. Every creation costs credits; downloading the same file again costs nothing. The files are only yours. Spreadsheets have no formulas — calculated values are placed.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <form method="POST" action="{{ route('office.store') }}" class="mt-3 grid gap-2 rounded-lg bg-white p-4 text-sm shadow" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Creating… (can take up to 1 minute)'">@csrf
        <label>Type<select name="kind" class="mt-1 w-full rounded border px-2 py-1 sm:w-80">@foreach ($kinds as $k => $label)<option value="{{ $k }}" @selected(old('kind') === $k)>{{ $label }}</option>@endforeach</select></label>
        <label>What do you want to make<textarea name="request" required maxlength="{{ $max }}" rows="5" class="mt-1 w-full rounded border px-2 py-1" placeholder="For example: a 1-page monthly sales report structure for our cloud kitchen / a 3-month budget sheet (rent, salary, raw materials) / an 8-slide pitch for investors">{{ old('request') }}</textarea></label>
        <div><button class="rounded bg-indigo-600 px-3 py-1 text-white disabled:opacity-60">Create</button> <span class="text-xs text-gray-500"> max {{ $max }} characters</span></div></form>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><p class="font-semibold">Files you created ({{ count($files) }}/100)</p>
        @forelse ($files as $f)
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded border border-gray-200 p-2">
            <span><b>{{ $f['title'] }}</b> <span class="text-xs text-gray-500">{{ $kinds[$f['kind']] ?? $f['kind'] }} · {{ $f['created_at'] }}</span><br><span class="text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($f['request'], 100) }}</span></span>
            <span class="flex gap-1"><a href="{{ route('office.download', $f['id']) }}" class="rounded bg-indigo-600 px-2 py-0.5 text-xs text-white">Download</a>
                <form method="POST" action="{{ route('office.destroy', $f['id']) }}" onsubmit="return confirm('Delete the file?')">@csrf<button class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">Delete</button></form></span></div>
        @empty<p class="mt-2 text-gray-500">Nothing created yet.</p>@endforelse</section>
</x-layouts.app>
