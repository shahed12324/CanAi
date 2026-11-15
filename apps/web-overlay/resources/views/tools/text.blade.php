<x-layouts.app :title="$tool['label']">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="tools" aria-label="Tools — Text">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> TOOLS</div>
        <h1>Tools — Text</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Tools Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 underline" href="{{ route('tools.show', $key) }}">← {{ $tool['label'] }}</a></p>
    <h2 class="mt-1 text-xl font-semibold">{{ $tool['label'] }}</h2>
    <textarea id="out" readonly rows="18" class="mt-3 w-full max-w-3xl rounded border bg-white p-3 text-sm shadow">{{ $text }}</textarea>
    <p class="mt-2 text-xs text-gray-500">Check the text against the original: handwriting reading can make mistakes.</p>
    <div class="mt-2 flex gap-2"><button type="button" class="rounded bg-indigo-600 px-3 py-1 text-sm text-white" onclick="var o=document.getElementById('out');o.select();document.execCommand('copy')">Copy</button></div>
</x-layouts.app>
