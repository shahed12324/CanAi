<x-layouts.app title="Client">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="clients" aria-label="Client">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> CLIENTS</div>
        <h1>Client</h1><p>Manage customer relationships and account details.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Clients Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Clients (agency mode)</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-900">Each client has its own brand (name, color, logo, contact) and you can tie their Meta/Google ad accounts and Facebook pages to their name. Then in <a class="text-indigo-600" href="{{ route('reports.index') }}"> reports</a> only that client’s numbers and brand appear. Nothing is published here and no money is spent — just a label.</p>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    @foreach ($clients as $c)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <div class="flex flex-wrap items-center gap-2"><span class="inline-block h-4 w-4 rounded" style="background: {{ $c['primary_color'] ?? '#1f3a5f' }}"></span><span class="inline-block h-4 w-4 rounded" style="background: {{ $c['accent_color'] ?? '#e07a1f' }}"></span><h3 class="font-semibold">{{ $c['name'] }}</h3>@if ($c['has_logo'])<span class="text-xs text-gray-500">Has logo</span>@endif</div>
        @if ($c['notes'])<p class="mt-1 text-gray-600">{{ $c['notes'] }}</p>@endif
        <ul class="mt-2 list-disc pl-5 text-gray-700">
            @forelse (array_filter($accounts, fn ($a) => $a['client_id'] === $c['id']) as $a)<li>{{ $a['name'] }} <span class="text-xs text-gray-500">({{ $a['detail'] }})</span></li>@empty<li class="list-none text-gray-500">No account is linked.</li>@endforelse
        </ul>
        @if ($canManage)
        <details class="mt-2"><summary class="cursor-pointer text-indigo-600">Edit</summary>
            <form method="POST" action="{{ route('clients.save') }}" enctype="multipart/form-data" class="mt-2 grid gap-2">@csrf<input type="hidden" name="id" value="{{ $c['id'] }}">
                @include('clients._fields', ['v' => $c])
                <div class="flex gap-2"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div>
            </form>
            <form method="POST" action="{{ route('clients.archive', $c['id']) }}" class="mt-2" onsubmit="return confirm('If archived, its accounts will belong to no one (the connection stays). Sure?')">@csrf<button class="rounded border border-red-300 px-3 py-1 text-red-700">Archive</button></form>
        </details>
        @endif
    </section>
    @endforeach
    @if ($clients === [])<p class="mt-3 text-sm text-gray-500">No clients yet.</p>@endif

    @if ($canManage)
    <section class="mt-4 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">New client</h3>
        <form method="POST" action="{{ route('clients.save') }}" enctype="multipart/form-data" class="mt-2 grid gap-2">@csrf
            @include('clients._fields', ['v' => ['name' => old('name'), 'notes' => old('notes'), 'primary_color' => old('primary_color', '#1f3a5f'), 'accent_color' => old('accent_color', '#e07a1f'), 'email' => old('email'), 'phone' => old('phone'), 'website' => old('website'), 'address' => old('address'), 'has_logo' => false]])
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add client</button></div>
        </form>
    </section>

    <section class="mt-4 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Whose client the account is</h3>
        <table class="mt-2 w-full text-left"><thead><tr class="text-xs text-gray-500"><th>Account</th><th>Type</th><th>Client</th><th></th></tr></thead><tbody>
        @forelse ($accounts as $a)
            <tr class="border-t"><td class="py-1">{{ $a['name'] }}<form id="as-{{ $a['id'] }}" method="POST" action="{{ route('clients.assign') }}">@csrf<input type="hidden" name="kind" value="{{ $a['kind'] }}"><input type="hidden" name="account_id" value="{{ $a['id'] }}"></form></td><td>{{ $a['detail'] }}</td>
                <td><select form="as-{{ $a['id'] }}" name="client_id" class="rounded border px-2 py-1"><option value="">— No one (own) —</option>@foreach ($clients as $c)<option value="{{ $c['id'] }}" @selected($a['client_id'] === $c['id'])>{{ $c['name'] }}</option>@endforeach</select></td>
                <td><button form="as-{{ $a['id'] }}" class="rounded border px-2 py-1">Save</button></td></tr>
        @empty<tr><td colspan="4" class="py-2 text-gray-500">No account connected yet.</td></tr>@endforelse
        </tbody></table>
    </section>
    @endif
</x-layouts.app>
