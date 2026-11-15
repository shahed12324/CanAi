<x-layouts.app title="Bill scan (AI)">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Bill scan (AI)">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Bill scan (AI)</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Draft from a bill photo/PDF</h2>
    @include('accounting._nav')
    <section class="rounded-lg bg-white p-5 text-sm shadow">
        <p>Provide a photo (JPG/PNG/WebP) or PDF of a supplier bill or expense receipt (max 5 MB). AI reads it and prepares a <b> draft</b> — <b> nothing is posted to the books until you check and confirm</b>. Each scan uses credits for the AI cost; scanning the same file again costs nothing new.</p>
        @if ($canManage)
            <form method="POST" action="{{ route('accounting.billscan.upload') }}" enctype="multipart/form-data" class="mt-3 flex flex-wrap items-center gap-2">@csrf
                <input type="file" name="bill" required accept="image/jpeg,image/png,image/webp,application/pdf" class="text-sm">
                <button class="rounded bg-indigo-600 px-3 py-1 text-white">Scan</button>
                <span class="text-xs text-gray-500">It can take a few seconds to a minute.</span>
            </form>
            @error('bill')<p class="mt-2 text-red-700">{{ $message }}</p>@enderror
        @endif
    </section>
    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">Recent scans</p><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">File</th><th class="px-3 py-2">Status</th><th class="px-3 py-2 text-right">Credits</th><th class="px-3 py-2">When</th></tr></thead><tbody>
        @forelse ($recent as $r)
            <tr class="border-t"><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.billscan.show', $r['id']) }}">{{ $r['file_name'] }}</a></td>
                <td class="px-3 py-2">@switch($r['status'])@case('ready')<span class="text-amber-700">Awaiting review</span>@break @case('used')<span class="text-green-700">Posted to books</span>@break @case('failed')<span class="text-red-700">Failed</span><br><span class="text-xs">{{ \App\Accounting\AccText::billScanFailure($r['error']) }}</span>@break @case('discarded') Cancel @break @default In progress… @endswitch</td>
                <td class="px-3 py-2 text-right">{{ $r['credits'] ?? '—' }}</td><td class="px-3 py-2 text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($r['created_at'])->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}</td></tr>
        @empty<tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">No bills have been scanned yet.</td></tr>@endforelse</tbody></table></section>
</x-layouts.app>
