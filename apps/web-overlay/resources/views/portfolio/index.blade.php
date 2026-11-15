<x-layouts.app title="Portfolio">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="portfolio" aria-label="Portfolio">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> PORTFOLIO</div>
        <h1>Portfolio</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Portfolio Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Portfolio</h2><div class="flex gap-3 text-sm"><a class="text-indigo-600" href="{{ route('brand.show') }}">Brand kit</a><a class="text-indigo-600" href="{{ route('proposals.index') }}">Proposals</a></div></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @if ($canManage)
    <section class="max-w-2xl space-y-2 rounded-lg bg-white p-5 shadow">
        <h3 class="font-medium">Add a new task</h3>
        <form method="POST" action="{{ route('portfolio.store') }}" class="space-y-2">@csrf
            <x-field name="title" label="Work name" :value="old('title')" />
            <x-field name="url" label="Link (optional, https://…)" :value="old('url')" />
            <div><label for="description" class="block text-sm font-medium text-gray-700">Short description</label><textarea id="description" name="description" rows="2" maxlength="600" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">{{ old('description') }}</textarea></div>
            <x-field name="tags" label="Tags (comma-separated: e-commerce, Dhaka)" :value="old('tags')" />
            <x-button>Add</x-button>
        </form>
    </section>
    @endif
    <section class="space-y-2">
        @forelse ($items as $i)
            <details class="rounded-lg bg-white p-4 shadow">
                <summary class="cursor-pointer"><strong>{{ $i->title }}</strong> @if ($i->tags)<span class="ml-2 text-xs text-gray-500">{{ $i->tags }}</span>@endif</summary>
                <div class="mt-2 text-sm">
                    @if ($canManage)
                        <form method="POST" action="{{ route('portfolio.update', trim($i->id)) }}" class="space-y-2">@csrf
                            <input name="title" value="{{ $i->title }}" maxlength="160" required class="w-full rounded border-gray-300">
                            <input name="url" value="{{ $i->url }}" maxlength="300" placeholder="https://…" class="w-full rounded border-gray-300">
                            <textarea name="description" rows="2" maxlength="600" class="w-full rounded border-gray-300">{{ $i->description }}</textarea>
                            <input name="tags" value="{{ $i->tags }}" maxlength="300" class="w-full rounded border-gray-300">
                            <div class="flex gap-2"><x-button>Save</x-button></div>
                        </form>
                        <form method="POST" action="{{ route('portfolio.destroy', trim($i->id)) }}" class="mt-2">@csrf<x-button variant="ghost">Remove</x-button></form>
                    @else
                        @if ($i->url)<a class="text-indigo-600" href="{{ $i->url }}" target="_blank" rel="noopener noreferrer nofollow">{{ $i->url }}</a>@endif<p>{{ $i->description }}</p>
                    @endif
                </div>
            </details>
        @empty<p class="rounded-lg bg-white p-5 text-sm text-gray-500 shadow">Nothing added yet. Keep past work here to show in proposals.</p>@endforelse
    </section>
</x-layouts.app>
