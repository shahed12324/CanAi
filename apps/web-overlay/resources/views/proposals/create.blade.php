<x-layouts.app title="New proposal">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="proposals" aria-label="New proposal">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> PROPOSALS</div>
        <h1>New proposal</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Proposals Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">New proposal</h2><a class="text-sm text-indigo-600" href="{{ route('proposals.index') }}">← All proposals</a></div>
    <section class="max-w-2xl space-y-3 rounded-lg bg-white p-5 shadow">
        @if ($lead)<p class="text-sm">Lead: <strong>{{ $lead->name }}</strong> ({{ $lead->domain }}) — Site weaknesses will be placed in the proposal automatically.</p>@endif
        <form method="POST" action="{{ route('proposals.store') }}" class="space-y-3">@csrf
            @if ($lead)<input type="hidden" name="lead_id" value="{{ trim($lead->id) }}">@endif
            <div><label class="block text-sm font-medium text-gray-700">Choose a template</label>
                @foreach ($templates as $t)
                    <label class="mt-1 flex items-center gap-2 rounded border p-2 text-sm"><input type="radio" name="template_id" value="{{ trim($t->id) }}" @checked($loop->first) required> <span>{{ $t->name }}</span><span class="ml-auto rounded bg-gray-100 px-1 text-xs">{{ $t->language === 'bn' ? 'Bengali' : 'English' }}</span></label>
                @endforeach</div>
            <x-field name="title" label="Title (optional)" :value="old('title')" />
            <x-button>Create</x-button>
        </form>
    </section>
</x-layouts.app>
