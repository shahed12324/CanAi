<x-layouts.app title="Lead research">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="leads" aria-label="Lead research">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> LEADS</div>
        <h1>Lead research</h1><p>Organize prospects, manage pipelines and track follow-ups.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Leads Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Website lead research</h2><a class="text-sm text-indigo-600" href="{{ route('leads.index') }}">Lead list →</a></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    @if ($canManage)
    <section class="max-w-2xl space-y-3 rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-gray-600">Enter website addresses (one per line, max {{ $maxUrls }}) or give a CSV. We read only public pages, obey robots.txt, and find contact details and site weaknesses.</p>
        <form method="POST" action="{{ route('leads.research.store') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <x-field name="name" label="Research name (optional)" :value="old('name')" />
            <div><label for="urls" class="block text-sm font-medium text-gray-700">Website address</label>
                <textarea id="urls" name="urls" rows="6" class="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm" placeholder="example.com.bd&#10;https://another-site.com">{{ old('urls') }}</textarea></div>
            <div><label for="csv" class="block text-sm font-medium text-gray-700"> or CSV (url/website/domain column)</label><input id="csv" name="csv" type="file" accept=".csv,.txt" class="text-sm"></div>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <label>Pages per site <select name="max_pages" class="rounded border-gray-300">@foreach ([2, 4, 6, 8] as $n)<option value="{{ $n }}" @selected(old('max_pages', 4) == $n)>{{ $n }}</option>@endforeach</select></label>
                <label class="flex items-center gap-1"><input type="checkbox" name="render_js" value="1" @checked(old('render_js'))> JavaScript-based sites (slow)</label>
            </div>
            <x-button>Start research</x-button>
        </form>
    </section>
    @endif

    <section class="rounded-lg bg-white p-5 shadow">
        <h3 class="mb-2 font-medium">Recent research</h3>
        @forelse ($jobs as $j)
            <a href="{{ route('leads.research.show', trim($j->id)) }}" class="flex flex-wrap items-center justify-between gap-2 border-t py-2 text-sm hover:bg-gray-50">
                <span><strong>{{ $j->name }}</strong> <span class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($j->created_at)->diffForHumans() }}</span></span>
                <span>{{ $j->processed }}/{{ $j->total }}@if ($j->failed > 0) <span class="text-red-700">· Failed {{ $j->failed }}</span>@endif · {{ ['queued' => 'Waiting', 'running' => 'Running', 'done' => 'End', 'cancelled' => 'Cancel'][$j->status] ?? $j->status }}</span>
            </a>
        @empty<p class="text-sm text-gray-500">No research yet.</p>@endforelse
    </section>
</x-layouts.app>
