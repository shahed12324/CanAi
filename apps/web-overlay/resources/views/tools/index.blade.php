<x-layouts.app title="Document & photo tools">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="tools" aria-label="Document & photo tools">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> TOOLS</div>
        <h1>Document & photo tools</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Tools Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Document &amp; photo tools</h2>
    <p class="mt-1 text-sm text-gray-600">Scans, PDFs and passport photos. Free. Files are processed and returned at once; nothing is kept on the server.</p>
    @foreach ($groups as $gk => $gl)
        <section class="mt-4">
            <h3 class="font-semibold">{{ $gl }}</h3>
            <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($tools as $k => $t)
                    @if ($t['group'] === $gk)
                        <a href="{{ route('tools.show', $k) }}" class="rounded-lg bg-white p-3 text-sm shadow hover:ring-2 hover:ring-indigo-300">
                            <span class="font-medium">{{ $t['label'] }}</span>
                            <span class="mt-1 block text-xs text-gray-500">{{ $t['help'] }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        </section>
    @endforeach
</x-layouts.app>
