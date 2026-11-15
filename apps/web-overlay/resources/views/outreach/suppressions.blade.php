<x-layouts.app title="Blocklist">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="outreach" aria-label="Blocklist">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> OUTREACH</div>
        <h1>Blocklist</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Outreach Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Blocklist</h2>
    @include('outreach._nav')
    <p class="text-sm text-gray-600">Mail never goes to these addresses — unsubscribed, bounced, or added by you by hand. The option to remove from the list is intentionally not provided.</p>
    @if ($canManage)<form method="POST" action="{{ route('outreach.suppress') }}" class="flex max-w-lg gap-2">@csrf<input name="email" type="email" required placeholder="name@company.com" class="flex-1 rounded border-gray-300"><x-button>Add</x-button></form>@endif
    <section class="rounded-lg bg-white p-5 shadow text-sm">
        @forelse ($rows as $r)<div class="flex justify-between border-t py-1 first:border-0"><span>{{ $r->email }}</span><span class="text-xs text-gray-500">{{ ['unsubscribed' => 'Unsubscribed', 'bounced' => 'Bounced', 'complaint' => 'Complaint', 'manual' => 'Added by hand'][$r->reason] }} · {{ \Illuminate\Support\Carbon::parse($r->created_at)->diffForHumans() }}</span></div>
        @empty<p class="text-gray-500">The list is empty.</p>@endforelse
    </section>
</x-layouts.app>
