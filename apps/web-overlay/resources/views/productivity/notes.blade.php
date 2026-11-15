<x-layouts.app title="Note">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="productivity" aria-label="Note">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> PRODUCTIVITY</div>
        <h1>Note</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Productivity Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Note</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">Private notes — only you can see them. They do not go to AI chat (if you want the AI to remember, use "Memory" ). It is best not to keep passwords or card numbers here.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <form method="POST" action="{{ route('notes.store') }}" class="mt-3 grid gap-2 rounded-lg bg-white p-4 text-sm shadow">@csrf
        <label>Title<input name="title" maxlength="200" value="{{ old('title') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Text<textarea name="body" maxlength="20000" rows="4" class="mt-1 w-full rounded border px-2 py-1">{{ old('body') }}</textarea></label>
        <div class="flex items-center gap-3"><label><input type="checkbox" name="pinned" value="1"> Pin to top</label><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div></form>

    @forelse ($notes as $n)
    <details class="mt-3 rounded-lg bg-white p-3 text-sm shadow"><summary class="cursor-pointer"><b>{{ $n['pinned'] ? '📌 ' : '' }}{{ $n['title'] !== '' ? $n['title'] : \Illuminate\Support\Str::limit($n['body'], 60) }}</b> <span class="text-xs text-gray-500">{{ $n['updated_at'] }}</span></summary>
        <form method="POST" action="{{ route('notes.update', $n['id']) }}" class="mt-2 grid gap-2">@csrf
            <input name="title" maxlength="200" value="{{ $n['title'] }}" class="w-full rounded border px-2 py-1">
            <textarea name="body" maxlength="20000" rows="6" class="w-full rounded border px-2 py-1">{{ $n['body'] }}</textarea>
            <div><button class="rounded bg-gray-100 px-3 py-1">Save</button></div></form>
        <div class="mt-2 flex gap-2">
            <form method="POST" action="{{ route('notes.update', $n['id']) }}">@csrf<input type="hidden" name="title" value="{{ $n['title'] }}"><input type="hidden" name="body" value="{{ $n['body'] }}"><input type="hidden" name="pinned" value="{{ $n['pinned'] ? 0 : 1 }}"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ $n['pinned'] ? 'Unpin' : 'Pin' }}</button></form>
            <form method="POST" action="{{ route('notes.destroy', $n['id']) }}" onsubmit="return confirm('Delete the note?')">@csrf<button class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">Delete</button></form></div></details>
    @empty<p class="mt-3 text-center text-gray-500">No notes yet.</p>@endforelse
</x-layouts.app>
