<x-layouts.admin title="Settings" :crumbs="['Settings' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Settings">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Settings</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">System settings</h1>
    @foreach ($groups as $group => $def)
        <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
            <h2 class="font-medium">{{ $def['label'] }}</h2>
            <form method="POST" action="{{ route('admin.settings.update', $group) }}" class="space-y-3">
                @csrf @method('PUT')
                @foreach ($def['items'] as $key => $item)
                    @php($name = \App\Http\Controllers\Admin\SettingController::field($key))
                    @php($val = $values[$key] ?? null)
                    @if ($item['type'] === 'bool')
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $val))> {{ $item['label'] }}</label>
                    @elseif ($item['type'] === 'select')
                        <div><label for="{{ $name }}" class="block text-sm font-medium text-gray-700">{{ $item['label'] }}</label>
                            <select id="{{ $name }}" name="{{ $name }}" class="w-full rounded-md border border-gray-300 px-3 py-2">
                                @foreach ($item['options'] as $o)<option value="{{ $o }}" @selected(old($name, $val) === $o)>{{ $o }}</option>@endforeach
                            </select></div>
                    @elseif ($item['type'] === 'text')
                        <div><label for="{{ $name }}" class="block text-sm font-medium text-gray-700">{{ $item['label'] }}</label>
                            <textarea id="{{ $name }}" name="{{ $name }}" rows="3" maxlength="{{ $item['max'] ?? 500 }}" class="w-full rounded-md border border-gray-300 px-3 py-2">{{ old($name, $val) }}</textarea>
                            @error($name)<p class="text-sm text-red-600">{{ $message }}</p>@enderror</div>
                    @else
                        <x-field :name="$name" :label="$item['label']" :type="$item['type'] === 'color' ? 'text' : ($item['type'] === 'email' ? 'email' : 'text')" :value="$val" />
                    @endif
                @endforeach
                <x-button>Save</x-button>
            </form>
        </section>
    @endforeach
</x-layouts.admin>
