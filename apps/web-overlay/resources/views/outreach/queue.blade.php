<x-layouts.app title="Approval queue">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="outreach" aria-label="Approval queue">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> OUTREACH</div>
        <h1>Approval queue</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Outreach Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Approval queue</h2>
    @include('outreach._nav')
    <p class="text-sm text-gray-600">Only approved mails go out here, following the daily limit and warm-up, slowly. Read every mail before sending.</p>
    @if ($canApprove)<form method="POST" action="{{ route('outreach.decide') }}" class="space-y-3">@csrf @endif
    @forelse ($rows as $r)
        <article class="rounded-lg bg-white p-4 shadow">
            <label class="flex items-start gap-2">@if ($canApprove)<input type="checkbox" name="ids[]" value="{{ trim($r->id) }}" class="mt-1">@endif
                <span class="text-sm"><strong>{{ $r->to_email }}</strong> <span class="text-gray-500">· {{ $r->campaign }} · {{ $r->from_email }} {{ $r->step === 2 ? '· Follow-up' : '' }}</span><br><span class="font-medium">{{ $r->subject }}</span></span></label>
            <pre class="mt-2 whitespace-pre-wrap rounded bg-gray-50 p-3 text-sm">{{ $r->body }}</pre>
        </article>
    @empty<p class="rounded-lg bg-white p-5 text-sm text-gray-500 shadow">No mails awaiting approval.</p>@endforelse
    @if ($canApprove && count($rows))
        <div class="flex gap-2"><button name="action" value="approve" class="rounded bg-indigo-600 px-3 py-2 text-sm text-white">Approve selected</button><button name="action" value="reject" class="rounded border px-3 py-2 text-sm">Cancel selected</button></div></form>
    @endif
</x-layouts.app>
