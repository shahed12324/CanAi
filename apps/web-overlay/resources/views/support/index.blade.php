<x-layouts.app title="Support">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="support" aria-label="Support">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SUPPORT</div>
        <h1>Support</h1><p>Review tickets and help requests.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Support Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Support</h2>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    <section class="max-w-2xl space-y-3 rounded-lg bg-white p-5 shadow">
        <h3 class="font-medium">New ticket</h3>
        <form method="POST" action="{{ route('support.store') }}" class="space-y-3">
            @csrf
            <x-field name="subject" label="Subject" :value="old('subject')" required />
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block text-sm font-medium">Category
                    <select name="category" class="mt-1 w-full rounded border-gray-300 text-sm">
                        @foreach (['billing' => 'Billing', 'technical' => 'Technical', 'account' => 'Account', 'other' => 'Other'] as $k => $v)<option value="{{ $k }}" @selected(old('category') === $k)>{{ $v }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm font-medium">Priority
                    <select name="priority" class="mt-1 w-full rounded border-gray-300 text-sm">
                        @foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'] as $k => $v)<option value="{{ $k }}" @selected(old('priority', 'normal') === $k)>{{ $v }}</option>@endforeach
                    </select>
                </label>
            </div>
            <label class="block text-sm font-medium">How can we help?
                <textarea name="body" rows="4" maxlength="5000" required class="mt-1 w-full rounded border-gray-300 text-sm">{{ old('body') }}</textarea>
            </label>
            <x-button>Send ticket</x-button>
        </form>
    </section>

    <section class="max-w-2xl rounded-lg bg-white p-5 shadow">
        <h3 class="mb-2 font-medium">Your tickets</h3>
        @forelse ($tickets as $t)
            <a href="{{ route('support.show', $t->id) }}" class="flex items-center justify-between border-t py-2 text-sm hover:bg-gray-50">
                <span>{{ $t->subject }} <span class="text-gray-500">· {{ ucfirst($t->category) }}</span></span>
                <span class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ ucfirst($t->status) }}</span>
            </a>
        @empty<p class="text-sm text-gray-500">No tickets yet.</p>@endforelse
    </section>
</x-layouts.app>
