<x-layouts.admin title="Routing" :crumbs="['Routing' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Routing">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Routing</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">Routing rules</h1>
    <p class="text-sm text-gray-600">Customers "Balanced" In this mode this rule applies: first the first available model in the preferred list, otherwise the rule’s mode. If the customer picks another mode or a default model, that one is used.</p>
    @foreach ($rules as $r)
        <form method="POST" action="{{ route('admin.routing.update', $r->id) }}" class="space-y-2 rounded-lg bg-white p-4 text-sm shadow">
            @csrf @method('PUT')
            <div class="flex flex-wrap items-end gap-3">
                <div><label class="block text-xs text-gray-600">Task</label><input name="task_type" value="{{ $r->task_type }}" class="w-32 rounded border border-gray-300 px-2 py-1" required></div>
                <div><label class="block text-xs text-gray-600">Mode</label><select name="mode" class="rounded border border-gray-300 px-2 py-1">@foreach (array_diff($modes, ['manual']) as $mo)<option value="{{ $mo }}" @selected($r->mode === $mo)>{{ $mo }}</option>@endforeach</select></div>
                <div><label class="block text-xs text-gray-600">Priority</label><input name="priority" type="number" value="{{ $r->priority }}" class="w-24 rounded border border-gray-300 px-2 py-1" required></div>
                <div><label class="block text-xs text-gray-600">Status</label><select name="status" class="rounded border border-gray-300 px-2 py-1">@foreach (['active', 'disabled'] as $s)<option value="{{ $s }}" @selected($r->status === $s)>{{ $s }}</option>@endforeach</select></div>
            </div>
            <div><label class="block text-xs text-gray-600">Preferred models (pick several; order = list order)</label>
                <select name="preferred_model_ids[]" multiple size="4" class="w-full rounded border border-gray-300 px-2 py-1">
                    @foreach ($models as $m)<option value="{{ $m->id }}" @selected(in_array($m->id, $r->models, true))>{{ $m->provider }} — {{ $m->display_name }}</option>@endforeach
                </select></div>
            <div class="flex gap-2"><x-button variant="ghost">Save</x-button></div>
        </form>
        <form method="POST" action="{{ route('admin.routing.destroy', $r->id) }}" onsubmit="return confirm('Delete the rule?')">@csrf @method('DELETE')<x-button variant="danger">{{ $r->task_type }} Delete</x-button></form>
    @endforeach
    <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">New rule</h2>
        <form method="POST" action="{{ route('admin.routing.store') }}" class="space-y-3">
            @csrf
            <x-field name="task_type" label="Task (for example chat, summarize)" required />
            <div><label for="mode" class="block text-sm font-medium text-gray-700">Mode</label><select id="mode" name="mode" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach (array_diff($modes, ['manual']) as $mo)<option value="{{ $mo }}">{{ $mo }}</option>@endforeach</select></div>
            <x-field name="priority" type="number" label="Priority" value="100" required />
            <input type="hidden" name="status" value="active">
            <x-button>Add</x-button>
        </form>
    </section>
</x-layouts.admin>
