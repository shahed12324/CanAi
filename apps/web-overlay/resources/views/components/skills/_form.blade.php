@props(['action', 'v', 'button' => 'Save', 'method' => null])
<form method="POST" action="{{ $action }}" class="grid max-w-2xl gap-3 rounded-lg bg-white p-4 text-sm shadow">
    @csrf
    @if ($method) @method($method) @endif
    @if (! empty($v['source']))<input type="hidden" name="source" value="{{ $v['source'] }}">@endif
    <label class="grid gap-1"><span class="font-medium">Name</span><input name="name" maxlength="80" required value="{{ $v['name'] ?? '' }}" class="rounded border px-2 py-1"></label>
    <label class="grid gap-1"><span class="font-medium">When to use it</span><input name="description" maxlength="300" value="{{ $v['description'] ?? '' }}" class="rounded border px-2 py-1"></label>
    <label class="grid gap-1"><span class="font-medium">Steps the AI should follow</span><textarea name="instructions" rows="10" minlength="20" maxlength="4000" required class="rounded border px-2 py-1 font-mono text-xs">{{ $v['instructions'] ?? '' }}</textarea>
        <span class="text-xs text-gray-500">Write generic steps. Use placeholders like {customer} or {month} instead of real names, phone numbers and amounts.</span></label>
    <div><button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">{{ $button }}</button></div>
</form>
