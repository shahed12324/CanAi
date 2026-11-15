<x-layouts.app title="CSV Import">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="leads" aria-label="CSV Import">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> LEADS</div>
        <h1>CSV Import</h1><p>Organize prospects, manage pipelines and track follow-ups.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Leads Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Import leads CSV</h2><a class="text-sm text-indigo-600" href="{{ route('leads.index') }}">← Lead list</a></div>
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <section class="max-w-2xl space-y-3 rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-gray-600">The first row must contain column names. <strong>website</strong> (or url/domain) required; optional: <code>name, email, phone, tags, stage, note</code> (max {{ $max }} rows). The file must be UTF-8.</p>
        <ul class="list-disc pl-5 text-sm text-gray-600">
            <li>If the same website already exists, no new lead is created; only new emails/phones are added. The earlier stage and tags are not changed.</li>
            <li>To put multiple emails/phones in one cell <code>;</code> or separate with commas. Phones can be given in any form such as 017… or +880….</li>
            <li><code>stage</code>: new, contacted, qualified, proposal, won, lost (or a Bengali name).</li>
        </ul>
        <form method="POST" action="{{ route('leads.import.store') }}" enctype="multipart/form-data" class="space-y-3">@csrf
            <input type="file" name="csv" accept=".csv,.txt" required class="text-sm">
            <div><x-button>Import</x-button></div>
        </form>
        <p class="text-xs text-gray-500">Imported leads have a score of 0 (the site has not been analyzed). If you wish, you can run website research to add a score and weaknesses.</p>
    </section>
</x-layouts.app>
