<x-layouts.app title="Knowledge base">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="knowledge" aria-label="Knowledge base">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> KNOWLEDGE</div>
        <h1>Knowledge base</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Knowledge Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Knowledge base</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">Keep company documents (price lists, policies, FAQs) here — the system splits them into small parts so they can be searched. Everyone in your workspace can search what you put here, so do not add secrets (passwords, card/ID numbers). Answers with sources from these documents in chat come in the next step.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    @if ($canManage)
    <form method="POST" action="{{ route('knowledge.store') }}" enctype="multipart/form-data" class="mt-3 grid gap-2 rounded-lg bg-white p-4 text-sm shadow sm:grid-cols-3">@csrf
        <label class="sm:col-span-2">File (PDF, DOCX, TXT, MD, CSV, JSON, HTML, image JPG/PNG/WebP — max 5 MB)<input type="file" name="file" required accept=".pdf,.docx,.txt,.md,.csv,.tsv,.json,.html,.htm,.log,.png,.jpg,.jpeg,.webp" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Name (file name if empty)<input name="title" maxlength="200" value="{{ old('title') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label class="sm:col-span-3"><input type="checkbox" name="ocr" value="1"> Read images/scans — for images or scanned PDFs AI will extract the text <span class="text-xs text-gray-500">(costs credits; no cost for PDFs with a text layer)</span></label>
        <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add</button></div></form>
    @endif

    <form method="GET" action="{{ route('knowledge.index') }}" class="mt-3 flex flex-wrap items-end gap-2 rounded-lg bg-white p-3 text-sm shadow">
        <label class="grow">Search documents<input name="q" value="{{ $q }}" maxlength="300" placeholder="for example: cashew nut price" class="mt-1 w-full rounded border px-2 py-1"></label>
        <button class="rounded bg-gray-100 px-3 py-1">Search</button></form>

    @if ($q !== '')
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><p class="font-semibold">"{{ $q }}" — {{ count($hits) }} matches</p>
        @forelse ($hits as $h)<div class="mt-2 rounded border p-2"><p class="text-xs text-gray-500">{{ $h['title'] }} · Part {{ $h['seq'] }}</p><p class="mt-1 whitespace-pre-line">{{ $h['content'] }}</p></div>
        @empty<p class="mt-2 text-gray-500">Nothing found. Try other words.</p>@endforelse</section>
    @endif

    <section class="mt-3 overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Name</th><th class="px-3 py-2">File</th><th class="px-3 py-2">Part</th><th class="px-3 py-2">Status</th><th class="px-3 py-2"></th></tr></thead><tbody>
        @forelse ($docs as $d)
        <tr class="border-t {{ $d['active'] ? '' : 'text-gray-400' }}"><td class="px-3 py-2">@if ($canManage)<form method="POST" action="{{ route('knowledge.update', $d['id']) }}" class="flex gap-1">@csrf<input name="title" maxlength="200" value="{{ $d['title'] }}" class="w-full min-w-[10rem] rounded border px-1 py-0.5"><button class="rounded bg-gray-100 px-2 text-xs">Save</button></form>@else{{ $d['title'] }}@endif</td>
            <td class="px-3 py-2">{{ $d['filename'] }} <span class="text-xs text-gray-500">({{ number_format($d['size_bytes'] / 1024, 0) }} KB)</span></td><td class="px-3 py-2">{{ $d['chunk_count'] }}</td>
            <td class="px-3 py-2">{{ $d['active'] ? 'Active' : 'Closed' }}</td>
            <td class="px-3 py-2">@if ($canManage)<div class="flex gap-1">
                <form method="POST" action="{{ route('knowledge.update', $d['id']) }}">@csrf<input type="hidden" name="active" value="{{ $d['active'] ? 0 : 1 }}"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ $d['active'] ? 'Closed' : 'Active' }}</button></form>
                <form method="POST" action="{{ route('knowledge.destroy', $d['id']) }}" onsubmit="return confirm('The document will be permanently deleted. Sure?')">@csrf<button class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">Delete</button></form></div>@endif</td></tr>
        @empty<tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">No documents yet.</td></tr>@endforelse</tbody></table></section>
</x-layouts.app>
