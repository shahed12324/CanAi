<x-layouts.app title="Leads">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="leads" aria-label="Leads">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> LEADS</div>
        <h1>Leads</h1><p>Organize prospects, manage pipelines and track follow-ups.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Leads Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Leads</h2><div class="flex flex-wrap gap-3 text-sm"><a class="text-indigo-600" href="{{ route('leads.board') }}">Board</a><a class="text-indigo-600" href="{{ route('leads.tasks') }}">Task</a><a class="text-indigo-600" href="{{ route('leads.import') }}">CSV Import</a><a class="text-indigo-600" href="{{ route('leads.export') }}">CSV Export</a><a class="text-indigo-600" href="{{ route('leads.research.index') }}">New research →</a></div></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <form method="GET" class="flex flex-wrap items-end gap-2 text-sm">
        <label>Search <input name="q" value="{{ $filters['q'] ?? '' }}" class="rounded border-gray-300"></label>
        <label>Stage <select name="stage" class="rounded border-gray-300"><option value="">All</option>@foreach ($stages as $k => $l)<option value="{{ $k }}" @selected(($filters['stage'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></label>
        <label>Minimum score <input name="min" type="number" min="0" max="100" value="{{ $filters['min'] ?? '' }}" class="w-20 rounded border-gray-300"></label>
        <x-button>Filter</x-button>
    </form>
    <section class="rounded-lg bg-white p-5 shadow">
        <table class="min-w-full text-sm"><thead class="text-left text-gray-600"><tr><th class="py-1">Company</th><th>Score</th><th>Stage</th><th>Contact</th><th>Main opportunity</th></tr></thead><tbody>
            @forelse ($leads as $l)
                @php($gaps = (json_decode((string) $l->analysis, true) ?: [])['gaps'] ?? [])
                <tr class="border-t"><td class="py-1"><a class="text-indigo-600" href="{{ route('leads.show', trim($l->id)) }}">{{ $l->name }}</a><div class="text-xs text-gray-500">{{ $l->domain }}@foreach (array_filter(explode(',', (string) $l->tags)) as $t) <span class="ml-1 rounded bg-indigo-50 px-1 text-indigo-700">{{ $t }}</span>@endforeach</div></td>
                    <td class="tabular-nums">{{ $l->score }}</td><td>{{ $stages[$l->stage] ?? $l->stage }}</td><td>{{ $l->contacts }}</td>
                    <td class="text-gray-600">{{ $gaps[0]['service'] ?? '—' }}</td></tr>
            @empty<tr><td colspan="5" class="py-4 text-gray-500">No leads. <a class="text-indigo-600" href="{{ route('leads.research.index') }}">Start research</a>.</td></tr>@endforelse
        </tbody></table>
        <div class="mt-3 flex gap-3 text-sm">@if ($page > 1)<a class="text-indigo-600" href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}">← Earlier</a>@endif @if ($more)<a class="text-indigo-600" href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}">Next →</a>@endif</div>
    </section>
</x-layouts.app>
