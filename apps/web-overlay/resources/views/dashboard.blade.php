<x-layouts.app title="Dashboard">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="workspace" aria-label="Dashboard">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> WORKSPACE</div>
        <h1>Dashboard</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Workspace</span>
    </header>

    <section class="rounded-2xl border border-violet-500/30 bg-gradient-to-r from-indigo-950 via-slate-900 to-violet-950 p-6 sm:p-8">
        <p class="mb-2 text-xs font-bold uppercase tracking-widest text-violet-300">Your AI-powered workspace</p>
        <h2 class="mb-2 text-2xl font-bold text-white sm:text-3xl">Welcome to JARVIS ONE ✦</h2>
        <p class="mb-5 max-w-xl text-sm text-slate-300">Everything you need to create, automate and grow — in one place.</p>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('chat.index') }}" class="rounded-xl bg-violet-600 px-5 py-3 text-sm font-semibold text-white hover:bg-violet-500">✦ Start AI Chat</a>
            <a href="{{ route('agents.index') }}" class="rounded-xl border border-violet-400/40 px-5 py-3 text-sm font-semibold text-violet-100 hover:bg-white/10">Explore Agents →</a>
        </div>
    </section>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      @foreach ([['AI Chat','chat.index','✦'],['Tasks','tasks.index','✓'],['Documents','office.index','▤'],['Agents','agents.index','⚡']] as [$label,$route,$symbol])
        <a href="{{ route($route) }}" class="flex items-center justify-between rounded-xl border border-slate-700 bg-slate-900/80 p-5 hover:border-violet-500"><span class="font-medium">{{ $label }}</span><span class="text-2xl text-violet-300">{{ $symbol }}</span></a>
      @endforeach
    </div>
    @if ($current)
        <h2 class="text-xl font-semibold">{{ $current->name }}</h2>
        <p class="text-sm text-gray-600">Type: {{ $current->type === 'personal' ? 'Personal' : 'Organization' }}</p>
    @else
        <p class="text-gray-700">You have no active workspace. Create a new one below.</p>
    @endif

    @if ($overview)
        @php($fmt = fn ($v) => \App\Credits\CreditMath::format($v))
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Usable credits</p><p class="text-2xl font-semibold tabular-nums">{{ $fmt($overview['wallet']?->available ?? 0) }}</p><a class="text-xs text-indigo-600" href="{{ route('credits.index') }}">Usage and history →</a></div>
            <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Spent this month</p><p class="text-2xl font-semibold tabular-nums">{{ $fmt($overview['spent']) }}</p></div>
            <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Your questions this month</p><p class="text-2xl font-semibold">{{ number_format($overview['questions']) }}</p></div>
        </div>
        <section class="rounded-lg bg-white p-5 shadow">
            <div class="mb-2 flex items-center justify-between"><h3 class="font-medium">Recent chats</h3><a class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700" href="{{ route('chat.index') }}">AI-Ask</a></div>
            @forelse ($overview['recent'] as $c)
                <a href="{{ route('chat.show', $c->id) }}" class="block border-t py-2 text-sm hover:bg-gray-50">{{ $c->title }} <span class="text-xs text-gray-500">· {{ \Illuminate\Support\Carbon::parse($c->last_message_at)->diffForHumans() }}</span></a>
            @empty
                <p class="text-sm text-gray-500">No chats yet.</p>
            @endforelse
        </section>
    @endif

    <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
        <h3 class="font-medium">New organization workspace</h3>
        <form method="POST" action="{{ route('workspaces.store') }}" class="space-y-3">
            @csrf
            <x-field name="name" label="Organization name" required />
            <x-button>Create</x-button>
        </form>
    </section>
</x-layouts.app>
