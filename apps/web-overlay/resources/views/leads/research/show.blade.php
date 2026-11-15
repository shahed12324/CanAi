<x-layouts.app title="Research">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="leads" aria-label="Research">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> LEADS</div>
        <h1>Research</h1><p>Organize prospects, manage pipelines and track follow-ups.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Leads Workspace</span>
    </header>

    @if (in_array($job->status, ['queued', 'running'], true))<meta http-equiv="refresh" content="6">@endif
    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl font-semibold">{{ $job->name }}</h2><a class="text-sm text-indigo-600" href="{{ route('leads.research.index') }}">← All research</a></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @php($done = collect($items)->whereIn('status', ['done', 'failed', 'skipped'])->count())
    <div class="rounded-lg bg-white p-4 shadow text-sm">
        <p>Status: <strong>{{ ['queued' => 'Waiting', 'running' => 'Running', 'done' => 'End', 'cancelled' => 'Cancel'][$job->status] ?? $job->status }}</strong> · {{ $done }}/{{ $job->total }} Sites processed</p>
        <div class="mt-2 h-2 w-full overflow-hidden rounded bg-gray-100"><div class="h-2 bg-indigo-600" style="width: {{ $job->total > 0 ? round(100 * $done / $job->total) : 0 }}%"></div></div>
        @if ($canManage && in_array($job->status, ['queued', 'running'], true))
            <form method="POST" action="{{ route('leads.research.cancel', trim($job->id)) }}" class="mt-3" onsubmit="return confirm('Cancel?')">@csrf <x-button>Cancel</x-button></form>
        @endif
    </div>
    <section class="rounded-lg bg-white p-5 shadow">
        <table class="min-w-full text-sm"><thead class="text-left text-gray-600"><tr><th class="py-1">Site</th><th>Status</th><th>Results</th><th>Score</th></tr></thead><tbody>
            @foreach ($items as $i)
                <tr class="border-t"><td class="py-1">{{ $i->domain }}</td>
                    <td>{{ ['queued' => 'Waiting', 'running' => 'Running', 'done' => 'Done', 'failed' => 'Failed', 'skipped' => 'Dismissed'][$i->status] ?? $i->status }}</td>
                    <td><code>{{ $i->result_code }}</code></td>
                    <td>@if ($i->lead_id)<a class="text-indigo-600" href="{{ route('leads.show', trim($i->lead_id)) }}">{{ $i->score }}</a>@endif</td></tr>
            @endforeach
        </tbody></table>
    </section>
</x-layouts.app>
