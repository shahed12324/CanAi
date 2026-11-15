@php($unit = $unit ?? 'Credits')
<section class="rounded-lg bg-white p-5 shadow">
    <h2 class="mb-3 font-medium">{{ $title }}</h2>
    @forelse ($rows as $r)
        <div class="mb-2">
            <div class="flex justify-between text-sm"><span class="truncate">{{ $r['label'] }}</span><span class="tabular-nums text-gray-700">{{ \App\Credits\CreditMath::format($r['total']) }}</span></div>
            <div class="h-2 rounded bg-gray-100" role="img" aria-label="{{ $r['label'] }}: {{ \App\Credits\CreditMath::format($r['total']) }} {{ $unit }}"><div class="h-2 rounded bg-indigo-500" style="width: {{ (int) $r['pct'] }}%"></div></div>
        </div>
    @empty
        <p class="text-sm text-gray-500">No usage in this period.</p>
    @endforelse
</section>
