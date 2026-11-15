<x-layouts.admin title="Announcements" :crumbs="['Announcements' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Announcements">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Announcements</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    <h1 class="text-xl font-semibold">Announcements</h1>

    <section class="max-w-2xl space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">Send a notification</h2>
        <form method="POST" action="{{ route('admin.broadcasts.store') }}" class="space-y-3" onsubmit="return confirm('Send this announcement now? It cannot be recalled.')">
            @csrf
            <x-field name="title" label="Title" :value="old('title')" required />
            <label class="block text-sm font-medium">Message (optional)<textarea name="body" rows="3" maxlength="500" class="mt-1 w-full rounded border-gray-300 text-sm">{{ old('body') }}</textarea></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block text-sm font-medium">Send to
                    <select name="audience" id="aud" class="mt-1 w-full rounded border-gray-300 text-sm" onchange="document.getElementById('refrow').style.display = this.value === 'all' ? 'none' : 'block'">
                        <option value="all">Every shop</option><option value="plan">Shops on a plan</option><option value="tenant">One shop</option>
                    </select>
                </label>
                <label class="block text-sm font-medium" id="refrow" style="display:none">Plan or shop
                    <input name="ref" list="planlist" placeholder="Plan id, or shop slug/id" class="mt-1 w-full rounded border-gray-300 text-sm">
                    <datalist id="planlist">@foreach ($plans as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</datalist>
                </label>
            </div>
            <x-button>Send</x-button>
        </form>
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Sent</h2>
        @forelse ($items as $b)
            <p class="border-t py-2 text-sm"><strong>{{ $b->title }}</strong> <span class="text-gray-500">· {{ $b->audience }} · {{ $b->recipients }} people · {{ \Illuminate\Support\Carbon::parse($b->created_at)->diffForHumans() }}</span></p>
        @empty<p class="text-sm text-gray-500">Nothing sent yet.</p>@endforelse
    </section>
</x-layouts.admin>
