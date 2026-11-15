@php($fmt = fn ($v, $f = 'd M Y, H:i') => \Illuminate\Support\Carbon::parse($v)->setTimezone($tz)->format($f))
<x-layouts.app title="{{ $lead->name }}">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="leads" aria-label="Leads details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> LEADS</div>
        <h1>Leads details</h1><p>Organize prospects, manage pipelines and track follow-ups.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Leads Workspace</span>
    </header>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl font-semibold">{{ $lead->name }}</h2><div class="flex gap-3 text-sm"><a class="text-indigo-600" href="{{ route('leads.board') }}">Board</a><a class="text-indigo-600" href="{{ route('leads.index') }}">← All leads</a></div></div>
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Score</p><p class="text-3xl font-semibold">{{ $lead->score }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Stage</p>
            @if ($canManage)
                <form method="POST" action="{{ route('leads.stage', trim($lead->id)) }}" class="mt-1 flex gap-2">@csrf
                    <select name="stage" class="rounded border-gray-300 text-sm">@foreach ($stages as $k => $l)<option value="{{ $k }}" @selected($lead->stage === $k)>{{ $l }}</option>@endforeach</select>
                    <x-button variant="ghost">Change</x-button>
                </form>
            @else<p class="text-lg font-semibold">{{ $stages[$lead->stage] ?? $lead->stage }}</p>@endif
        </div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Website</p><p class="break-all text-sm">@if ($lead->website)<a class="text-indigo-600" href="{{ $lead->website }}" target="_blank" rel="noopener noreferrer nofollow">{{ $lead->domain }}</a>@else{{ $lead->domain }}@endif</p></div>
    </div>
    @if ($lead->description || $lead->address)<section class="rounded-lg bg-white p-5 text-sm shadow">@if ($lead->description)<p>{{ $lead->description }}</p>@endif @if ($lead->address)<p class="mt-1 text-gray-600">Address: {{ $lead->address }}</p>@endif</section>@endif

    <section class="rounded-lg bg-white p-5 shadow">
        <h3 class="mb-2 font-medium">Contact</h3>
        @forelse ($contacts as $c)
            <div class="flex flex-wrap justify-between border-t py-1 text-sm">
                <span>@if ($c->email){{ $c->email }} <span class="rounded bg-gray-100 px-1 text-xs">{{ ['mx_ok' => 'Email working', 'no_mx' => 'Email not working', 'syntax_ok' => 'Not verified', 'unverified' => 'Not verified', 'bounced' => 'Bounced', 'unsubscribed' => 'Unsubscribed'][$c->email_status] ?? $c->email_status }}</span>@else{{ $c->phone_e164 }} <span class="text-xs text-gray-500">{{ $c->phone_type }}</span>@endif</span>
                @if ($c->source_url)<a class="max-w-xs truncate text-xs text-gray-500" href="{{ $c->source_url }}" target="_blank" rel="noopener noreferrer nofollow">{{ $c->source_url }}</a>@endif
            </div>
        @empty<p class="text-sm text-gray-500">No contact details found.</p>@endforelse
        @foreach (($analysis['social'] ?? []) as $net => $url)<a class="mr-3 text-sm text-indigo-600" href="{{ $url }}" target="_blank" rel="noopener noreferrer nofollow">{{ $net }}</a>@endforeach
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h3 class="mb-2 font-medium">Site weaknesses and opportunities</h3>
        @forelse (($analysis['gaps'] ?? []) as $g)
            <div class="flex flex-wrap justify-between border-t py-1 text-sm"><span>{{ $g['label'] ?? $g['code'] ?? '' }}</span><span class="text-gray-600">→ {{ $g['service'] ?? '' }}</span></div>
        @empty<p class="text-sm text-green-700">No major weaknesses found.</p>@endforelse
        @if (! empty($analysis['tech']))<p class="mt-2 text-xs text-gray-500">Technology: {{ implode(', ', $analysis['tech']) }}</p>@endif
        @if ($lead->analyzed_at)<p class="mt-1 text-xs text-gray-500">Analysis: {{ \Illuminate\Support\Carbon::parse($lead->analyzed_at)->diffForHumans() }}</p>@endif
    </section>
    <section class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg bg-white p-5 shadow">
            <h3 class="mb-2 font-medium">Follow-up</h3>
            <p class="text-sm">@if ($lead->next_follow_up_at)<strong>{{ $fmt($lead->next_follow_up_at, 'd M Y') }}</strong>@else<span class="text-gray-500">Not set</span>@endif</p>
            @if ($canManage)
                <form method="POST" action="{{ route('leads.follow-up', trim($lead->id)) }}" class="mt-2 flex flex-wrap items-center gap-2 text-sm">@csrf
                    <input type="date" name="date" value="{{ $lead->next_follow_up_at ? $fmt($lead->next_follow_up_at, 'Y-m-d') : '' }}" class="rounded border-gray-300">
                    <x-button variant="ghost">Save</x-button><span class="text-xs text-gray-500">Leaving it empty will delete it</span>
                </form>
            @endif
        </div>
        <div class="rounded-lg bg-white p-5 shadow">
            <h3 class="mb-2 font-medium">Tags</h3>
            <p class="text-sm">@forelse ($tagList as $t)<a href="{{ route('leads.board', ['tag' => $t]) }}" class="mr-1 rounded bg-indigo-50 px-2 py-0.5 text-indigo-700">{{ $t }}</a>@empty<span class="text-gray-500">No tags</span>@endforelse</p>
            @if ($canManage)
                <form method="POST" action="{{ route('leads.tags', trim($lead->id)) }}" class="mt-2 flex flex-wrap items-center gap-2 text-sm">@csrf
                    <input name="tags" value="{{ implode(', ', $tagList) }}" maxlength="600" placeholder="vip, Dhaka, web-design" class="min-w-0 flex-1 rounded border-gray-300">
                    <x-button variant="ghost">Save</x-button>
                </form>
            @endif
        </div>
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <div class="mb-2 flex items-center justify-between"><h3 class="font-medium">Proposals</h3>@if ($canPropose)<a class="text-sm text-indigo-600" href="{{ route('proposals.create', ['lead' => trim($lead->id)]) }}">Create a new proposal →</a>@endif</div>
        @forelse ($proposals as $pp)<a href="{{ route('proposals.edit', trim($pp->id)) }}" class="flex justify-between border-t py-1 text-sm hover:bg-gray-50"><span>{{ $pp->title }}</span><span class="text-gray-500">{{ ['draft' => 'draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Rejected'][$pp->status] ?? $pp->status }}</span></a>
        @empty<p class="text-sm text-gray-500">No proposal for this lead yet.</p>@endforelse
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h3 class="mb-2 font-medium">Task</h3>
        @forelse ($tasks as $t)
            <div class="flex flex-wrap items-center justify-between gap-2 border-t py-1 text-sm">
                <span class="{{ $t->done_at ? 'text-gray-400 line-through' : '' }}">{{ $t->title }}@if ($t->due_at && ! $t->done_at) <span class="ml-1 text-xs {{ \Illuminate\Support\Carbon::parse($t->due_at)->isPast() ? 'text-red-700' : 'text-gray-500' }}">{{ $fmt($t->due_at) }}</span>@endif</span>
                @if (! $t->done_at && $canManage)<form method="POST" action="{{ route('leads.tasks.done', trim($t->id)) }}">@csrf<x-button variant="ghost">End</x-button></form>@endif
            </div>
        @empty<p class="text-sm text-gray-500">No tasks.</p>@endforelse
        @if ($canManage)
            <form method="POST" action="{{ route('leads.tasks.store', trim($lead->id)) }}" class="mt-3 flex flex-wrap items-center gap-2 border-t pt-3 text-sm">@csrf
                <input name="title" required maxlength="200" placeholder="for example: send proposal" class="min-w-0 flex-1 rounded border-gray-300">
                <input type="datetime-local" name="due" class="rounded border-gray-300">
                <x-button>Add task</x-button>
            </form>
        @endif
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h3 class="mb-2 font-medium">Notes and history</h3>
        @if ($canManage)
            <form method="POST" action="{{ route('leads.notes.store', trim($lead->id)) }}" class="mb-3 space-y-2">@csrf
                <textarea name="body" rows="3" required maxlength="2000" placeholder="Call/meeting notes…" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">{{ old('body') }}</textarea>
                <x-button>Add note</x-button>
            </form>
        @endif
        @forelse ($activities as $a)
            @php($m = json_decode((string) $a->meta, true) ?: [])
            <div class="border-t py-2 text-sm">
                <p class="text-xs text-gray-500">{{ $fmt($a->created_at) }} · {{ $a->user_name ?? 'System' }}</p>
                @switch($a->kind)
                    @case('note')<p class="whitespace-pre-line">{{ $a->body }}</p>@break
                    @case('stage')<p>Stage changed: {{ $stages[$m['from'] ?? ''] ?? ($m['from'] ?? '') }} → <strong>{{ $stages[$m['to'] ?? ''] ?? ($m['to'] ?? '') }}</strong></p>@break
                    @case('task')<p>Task {{ ($m['event'] ?? '') === 'done' ? 'End' : 'Add' }}: {{ $a->body }}</p>@break
                    @case('follow_up')<p>{{ ! empty($m['at']) ? 'Follow-up set: '.$fmt($m['at'], 'd M Y') : 'Follow-up removed' }}</p>@break
                    @case('proposal')<p>Proposal: {{ $a->body }} — {{ ['created' => 'Created', 'draft' => 'Return to draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Rejected'][$m['event'] ?? ''] ?? '' }}</p>@break
                    @case('tag')<p>Tag: {{ $a->body !== '' ? $a->body : '(Empty)' }}</p>@break
                    @default<p>{{ $a->body }}</p>
                @endswitch
            </div>
        @empty<p class="text-sm text-gray-500">Nothing yet.</p>@endforelse
    </section>
</x-layouts.app>
