<x-layouts.admin :title="$t->subject" :crumbs="['Support tickets' => route('admin.support.index'), 'Ticket' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Admin details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Admin details</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    <h1 class="text-xl font-semibold">{{ $t->subject }}</h1>
    <p class="text-sm text-gray-500">Shop: <a class="text-indigo-600" href="{{ route('admin.tenants.show', $t->tenant_id) }}">{{ $t->shop }}</a> · {{ $t->user_name }} ({{ $t->email }}) · {{ ucfirst($t->category) }} · {{ ucfirst($t->priority) }} priority · <strong>{{ ucfirst($t->status) }}</strong></p>

    <section class="max-w-3xl space-y-3">
        @foreach ($messages as $m)
            <div class="rounded-lg p-3 text-sm shadow {{ $m->author_kind === 'shop' ? 'mr-10 bg-white' : 'ml-10 bg-indigo-50' }}">
                <p class="mb-1 text-xs text-gray-500">{{ $m->author_kind === 'shop' ? ($m->name ?? 'Shop') : ($m->author_kind === 'ai' ? 'AI assistant' : 'Staff'.($m->name ? ' · '.$m->name : '')) }} · {{ \Illuminate\Support\Carbon::parse($m->created_at)->diffForHumans() }}</p>
                <p class="whitespace-pre-line">{{ $m->body }}</p>
            </div>
        @endforeach
    </section>

    <section class="max-w-3xl space-y-3 rounded-lg bg-white p-5 shadow">
        <form method="POST" action="{{ route('admin.support.reply', $t->id) }}" class="space-y-3">
            @csrf
            <textarea name="body" rows="4" maxlength="5000" required placeholder="Write a reply to the shop…" class="w-full rounded border-gray-300 text-sm"></textarea>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <label>After sending, set status:
                    <select name="status" class="rounded border-gray-300 text-sm">
                        @foreach (['pending' => 'Pending (waiting for shop)', 'solved' => 'Solved', 'open' => 'Open', 'closed' => 'Closed'] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                    </select>
                </label>
                <x-button>Send reply</x-button>
            </div>
        </form>
        <form method="POST" action="{{ route('admin.support.status', $t->id) }}" class="flex flex-wrap items-center gap-3 border-t pt-3 text-sm">
            @csrf
            <select name="status" class="rounded border-gray-300 text-sm">@foreach (['open', 'pending', 'solved', 'closed'] as $s)<option value="{{ $s }}" @selected($t->status === $s)>{{ ucfirst($s) }}</option>@endforeach</select>
            <select name="priority" class="rounded border-gray-300 text-sm">@foreach (['low', 'normal', 'high'] as $s)<option value="{{ $s }}" @selected($t->priority === $s)>{{ ucfirst($s) }}</option>@endforeach</select>
            <x-button variant="ghost">Update without replying</x-button>
        </form>
    </section>
</x-layouts.admin>
