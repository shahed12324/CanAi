<x-layouts.app :title="$c->name">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="outreach" aria-label="Outreach — Campaign">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> OUTREACH</div>
        <h1>Outreach — Campaign</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Outreach Workspace</span>
    </header>
    <h2 class="text-xl font-semibold">{{ $c->name }} <span class="text-sm font-normal text-gray-500">· {{ $c->from_email }}</span></h2>
    @include('outreach._nav')
    <section class="space-y-2 rounded-lg bg-white p-5 shadow text-sm">
        <p><strong>Subject:</strong> {{ $c->subject_tpl }}</p>
        <pre class="whitespace-pre-wrap rounded bg-gray-50 p-3">{{ $c->body_tpl }}</pre>
        @if ($c->followup_after_days)<p class="text-gray-600">Follow-up: {{ $c->followup_after_days }} days later a draft will be made (this also needs approval).</p>@endif
        @if ($canManage)
        <div class="flex flex-wrap gap-2">
            @foreach (['active' => 'Activate', 'paused' => 'Pause', 'archived' => 'Archive'] as $st => $label)
                @if ($c->status !== $st)<form method="POST" action="{{ route('outreach.campaign.status', trim($c->id)) }}">@csrf<input type="hidden" name="status" value="{{ $st }}"><x-button variant="ghost">{{ $label }}</x-button></form>@endif
            @endforeach
        </div>
        <form method="POST" action="{{ route('outreach.campaign.leads', trim($c->id)) }}" class="grid gap-2 border-t pt-3 sm:grid-cols-4">@csrf
            <div><label class="block text-xs text-gray-600" for="stage">Lead stage</label><select id="stage" name="stage" class="w-full rounded border-gray-300"><option value="">All</option><option value="new">New</option><option value="contacted">Contacted</option><option value="qualified">Qualified</option><option value="proposal">Proposals</option></select></div>
            <div><label class="block text-xs text-gray-600" for="min_score">Minimum score</label><input id="min_score" name="min_score" type="number" min="0" max="100" value="50" class="w-full rounded border-gray-300"></div>
            <div><label class="block text-xs text-gray-600" for="limit">How many (max 200)</label><input id="limit" name="limit" type="number" min="1" max="200" value="20" class="w-full rounded border-gray-300"></div>
            <div class="flex items-end"><x-button>Make drafts from leads</x-button></div>
        </form>
        <p class="text-xs text-gray-500">Making drafts sends nothing — all mails go to the <a class="text-indigo-600" href="{{ route('outreach.queue') }}"> approval queue</a> .</p>
        @endif
    </section>
    <section class="rounded-lg bg-white p-5 shadow">
        <h3 class="mb-2 font-medium">Mails</h3>
        @forelse ($messages as $m)
            <div class="flex flex-wrap items-center justify-between gap-2 border-t py-2 text-sm first:border-0">
                <span>{{ $m->to_email }} <span class="text-gray-500">· {{ $m->step === 2 ? 'Follow-up' : 'First' }} · {{ $m->subject }}</span></span>
                <span class="flex items-center gap-2 text-xs text-gray-600">{{ $statusBn[$m->status] ?? $m->status }}@if ($m->error_code) ({{ $m->error_code }})@endif
                    @if ($canManage && $m->status === 'sent')
                        <form method="POST" action="{{ route('outreach.mark', trim($m->id)) }}">@csrf<input type="hidden" name="as" value="replied"><button class="text-indigo-600">Reply received</button></form>
                        <form method="POST" action="{{ route('outreach.mark', trim($m->id)) }}">@csrf<input type="hidden" name="as" value="bounced"><button class="text-red-600">Bounced</button></form>
                    @endif</span>
            </div>
        @empty<p class="text-sm text-gray-500">No mails yet.</p>@endforelse
    </section>
</x-layouts.app>
