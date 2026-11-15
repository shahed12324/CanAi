<x-layouts.app title="Analyst">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Analyst">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Analyst</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Financial analyst</h2>
    @include('accounting._nav')
    <p class="rounded bg-amber-50 p-3 text-sm text-amber-800">The numbers come straight from your books. AI only arranges them in plain language, and any text with a number not in the books is dropped. <b>The forecast is only an estimate that extends past trends</b> — It does not account for seasons, festivals or new contracts; check with your accountant before deciding.</p>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if ($canRun)
    <form method="POST" action="{{ route('accounting.analyst.run') }}" class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 text-sm shadow">@csrf
        <label>Up to which month<select name="month" class="mt-1 block rounded border px-2 py-1">@foreach ($months as $m)<option value="{{ $m }}">{{ \App\Payroll\PayrollText::month($m) }}</option>@endforeach</select></label>
        <label class="flex items-center gap-1"><input type="checkbox" name="ai" value="1" checked> AI Explain with AI (uses credits)</label>
        <button class="rounded bg-indigo-600 px-3 py-1 text-white">Analyze</button>
        <span class="text-xs text-gray-500">AI If turned off or unavailable, a fixed summary is shown at no cost.</span></form>
    @endif
    <section class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2"> month(s)</th><th class="px-3 py-2">Type</th><th class="px-3 py-2">Created</th><th class="px-3 py-2"></th></tr></thead><tbody>
        @forelse ($recent as $r)<tr class="border-t"><td class="px-3 py-2">{{ \App\Payroll\PayrollText::month($r['month_to']) }}</td><td class="px-3 py-2">{{ $r['source'] === 'ai' ? 'AI-Written' : 'Fixed summary' }}</td><td class="px-3 py-2">{{ $r['created_at'] }}</td><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.analyst.show', $r['id']) }}">View</a></td></tr>
        @empty<tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">No analysis yet.</td></tr>@endforelse</tbody></table></section>
</x-layouts.app>
