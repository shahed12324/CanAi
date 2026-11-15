<x-layouts.app title="Memory center">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="memory" aria-label="Memory center">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> MEMORY</div>
        <h1>Memory center</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Memory Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Memory center</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">Only what is here goes to the AI chat "Background information" as. You can add, change, turn off or delete. The AI does not save anything on its own — it only suggests, and only with your approval. Passwords, PINs, OTPs, API keys, card or ID numbers are not remembered.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <form method="POST" action="{{ route('memory.setting') }}" class="mt-3 flex items-center gap-3 rounded-lg bg-white p-3 text-sm shadow">@csrf
        <input type="hidden" name="use" value="{{ $useInChat ? 0 : 1 }}">
        <span>Memory use in chat: <b class="{{ $useInChat ? 'text-green-700' : 'text-gray-500' }}">{{ $useInChat ? 'Active' : 'Closed' }}</b></span>
        <button class="rounded bg-gray-100 px-3 py-1">{{ $useInChat ? 'Deactivate' : 'Activate' }}</button></form>

    <form method="POST" action="{{ route('memory.store') }}" class="mt-3 grid gap-2 rounded-lg bg-white p-4 text-sm shadow sm:grid-cols-4">@csrf
        <label class="sm:col-span-4">Remember something new<input name="content" maxlength="500" required value="{{ old('content') }}" placeholder="For example: I like short answers / Our shop is in Bashundhara" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Type<select name="category" class="mt-1 w-full rounded border px-2 py-1">@foreach ($categories as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></label>
        @if ($canBusiness)<label>For whom<select name="scope" class="mt-1 w-full rounded border px-2 py-1"><option value="user">Only me</option><option value="business">Whole business (in everyone’s chat)</option></select></label>@endif
        <label class="self-end"><input type="checkbox" name="pinned" value="1"> Important (goes first)</label>
        <div class="self-end"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Remember</button></div></form>

    @if ($pending !== [])
    <section class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm"><p class="font-semibold text-indigo-900">AI-suggestions — nothing was saved without your approval</p>
        @foreach ($pending as $s)<div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded bg-white p-2"><span>{{ $s['content'] }} <span class="text-xs text-gray-500">({{ $categories[$s['category']] ?? $s['category'] }})</span></span>
            <span class="flex gap-1"><form method="POST" action="{{ route('memory.accept', $s['id']) }}">@csrf<button class="rounded bg-indigo-600 px-2 py-0.5 text-xs text-white">Remember</button></form>
            <form method="POST" action="{{ route('memory.dismiss', $s['id']) }}">@csrf<button class="rounded bg-gray-100 px-2 py-0.5 text-xs">Dismissed</button></form></span></div>@endforeach</section>
    @endif

    @if ($chats !== [])
    <form method="POST" action="{{ route('memory.suggest') }}" class="mt-3 flex flex-wrap items-end gap-2 rounded-lg bg-white p-3 text-sm shadow">@csrf
        <label>Which chat to suggest from<select name="conversation_id" class="mt-1 block max-w-xs rounded border px-2 py-1">@foreach ($chats as $c)<option value="{{ $c['id'] }}">{{ \Illuminate\Support\Str::limit($c['title'], 50) }}</option>@endforeach</select></label>
        <button class="rounded bg-gray-100 px-3 py-1">AI Suggest with (costs credits)</button>
        <span class="text-xs text-gray-500">Only your own chats are read.</span></form>
    @endif

    <section class="mt-3 overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Remembered</th><th class="px-3 py-2">Type</th><th class="px-3 py-2">Whose</th><th class="px-3 py-2">Status</th><th class="px-3 py-2"></th></tr></thead><tbody>
        @forelse ($memories as $m)
        @php($editable = $m['scope'] === 'user' || $canBusiness)
        <tr class="border-t {{ $m['active'] ? '' : 'text-gray-400' }}"><td class="px-3 py-2">@if ($editable)<form method="POST" action="{{ route('memory.update', $m['id']) }}" class="flex gap-1">@csrf<input name="content" maxlength="500" value="{{ $m['content'] }}" class="w-full min-w-[12rem] rounded border px-1 py-0.5"><button class="rounded bg-gray-100 px-2 text-xs">Save</button></form>@else{{ $m['content'] }}@endif
            @if ($m['source'] === 'suggested')<span class="text-xs text-indigo-600">(AI Suggested, you approved)</span>@endif</td>
            <td class="px-3 py-2">{{ $categories[$m['category']] ?? $m['category'] }}</td><td class="px-3 py-2">{{ $m['scope'] === 'business' ? 'Business' : 'Mine' }}</td>
            <td class="px-3 py-2">{{ $m['pinned'] ? '★ ' : '' }}{{ $m['active'] ? 'Active' : 'Closed' }}</td>
            <td class="px-3 py-2"><div class="flex gap-1">@if ($editable)
                <form method="POST" action="{{ route('memory.update', $m['id']) }}">@csrf<input type="hidden" name="pinned" value="{{ $m['pinned'] ? 0 : 1 }}"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ $m['pinned'] ? 'Unpin' : 'Pin' }}</button></form>
                <form method="POST" action="{{ route('memory.update', $m['id']) }}">@csrf<input type="hidden" name="active" value="{{ $m['active'] ? 0 : 1 }}"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ $m['active'] ? 'Closed' : 'Active' }}</button></form>
                <form method="POST" action="{{ route('memory.destroy', $m['id']) }}" onsubmit="return confirm('Delete?')">@csrf<button class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">Delete</button></form>@endif</div></td></tr>
        @empty<tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">Nothing is remembered yet.</td></tr>@endforelse</tbody></table></section>

    <form method="POST" action="{{ route('memory.clear') }}" class="mt-3 text-sm" onsubmit="return confirm('All your personal memories and suggestions will be permanently deleted. Sure?')">@csrf<input type="hidden" name="confirm" value="yes">
        <button class="rounded bg-red-100 px-3 py-1 text-red-800">Forget all my personal memories</button> <span class="text-xs text-gray-500">(Business memories are not deleted)</span></form>
</x-layouts.app>
