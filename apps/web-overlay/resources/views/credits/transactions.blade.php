<x-layouts.app title="Transaction history">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="credits" aria-label="Transaction history">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> CREDITS</div>
        <h1>Transaction history</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Credits Workspace</span>
    </header>

    @php($fmt = fn ($v) => \App\Credits\CreditMath::format($v))
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-semibold">Transaction history</h1>
        <a class="text-sm text-indigo-600" href="{{ route('credits.index') }}">← Credits</a>
    </div>
    <form method="GET" class="flex items-center gap-2 text-sm">
        <label for="type" class="text-gray-600">Type</label>
        <select id="type" name="type" onchange="this.form.submit()" class="rounded border border-gray-300 px-2 py-1">
            <option value="">All</option>
            @foreach ($types as $k => $label)<option value="{{ $k }}" @selected($type === $k)>{{ $label }}</option>@endforeach
        </select>
        <noscript><x-button variant="ghost">Show</x-button></noscript>
    </form>
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Period</th><th class="px-4 py-2">Type</th><th class="px-4 py-2 text-right">Quantity</th><th class="px-4 py-2 text-right">Balance</th><th class="px-4 py-2">Note</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr class="border-t">
                    <td class="px-4 py-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-2">{{ $types[$r->type] ?? $r->type }}</td>
                    <td class="px-4 py-2 text-right tabular-nums {{ str_starts_with((string) $r->amount, '-') ? 'text-red-600' : 'text-green-700' }}">{{ $fmt($r->amount) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $fmt($r->balance_after) }}</td>
                    <td class="px-4 py-2 text-gray-600">{{ $r->note ?? $r->reference_type }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No transactions.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</x-layouts.app>
