<x-layouts.app title="Profile">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="settings" aria-label="Profile">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SETTINGS</div>
        <h1>Profile</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Settings Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Profile</h2>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-gray-600">Email: <strong>{{ $user->email }}</strong></p>
        <form method="POST" action="{{ route('profile.update') }}" class="space-y-3">
            @csrf @method('PUT')
            <x-field name="name" label="Name" :value="old('name', $user->name)" required />
            <div><label for="locale" class="block text-sm font-medium text-gray-700">Language</label>
                <select id="locale" name="locale" class="w-full rounded-md border border-gray-300 px-3 py-2">
                    @foreach ($locales as $k => $l)<option value="{{ $k }}" @selected(old('locale', $user->locale) === $k)>{{ $l }}</option>@endforeach
                </select></div>
            <div><label for="timezone" class="block text-sm font-medium text-gray-700">Timezone</label>
                <select id="timezone" name="timezone" class="w-full rounded-md border border-gray-300 px-3 py-2">
                    @foreach ($zones as $z)<option value="{{ $z }}" @selected(old('timezone', $user->timezone) === $z)>{{ $z }}</option>@endforeach
                </select></div>
            <x-button>Save</x-button>
        </form>
        <p class="text-sm"><a class="text-indigo-600" href="{{ route('security.show') }}">Password and 2FA →</a></p>
    </section>
</x-layouts.app>
