<x-layouts.admin :title="$plan->name" :crumbs="['Plans' => route('admin.plans.index'), $plan->name => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Admin details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Admin details</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">{{ $plan->name }} <span class="text-sm font-normal text-gray-500">({{ $plan->code }})</span></h1>

    <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">Description</h2>
        <form method="POST" action="{{ route('admin.plans.update', $plan->id) }}" class="space-y-3">
            @csrf @method('PUT')
            <x-field name="name" label="Name" :value="$plan->name" required />
            <x-field name="description" label="Description" :value="$plan->description" />
            <div class="grid gap-3 sm:grid-cols-2">
                <x-field name="trial_days" type="number" min="0" label="Trial (days)" :value="$plan->trial_days" required />
                <x-field name="included_credits" type="number" min="0" step="any" label="Included credits" :value="$plan->included_credits" required />
                <x-field name="sort" type="number" min="0" label="Order" :value="$plan->sort" required />
                <div><label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select id="status" name="status" class="w-full rounded-md border border-gray-300 px-3 py-2">
                        @foreach (['active', 'hidden', 'retired'] as $s)<option value="{{ $s }}" @selected($plan->status === $s)>{{ $s }}</option>@endforeach
                    </select></div>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_public" value="1" @checked($plan->is_public)> Show on public pricing page</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="monthly_credit_refill" value="1" @checked($plan->monthly_credit_refill)> Credit refill per month</label>
            <x-button>Save</x-button>
        </form>
    </section>

    <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">Value</h2>
        <p class="text-xs text-gray-500">If left empty there is no price for that currency/interval.</p>
        <form method="POST" action="{{ route('admin.plans.prices', $plan->id) }}" class="space-y-2">
            @csrf @method('PUT')
            @php($i = 0)
            @foreach (['BDT', 'USD'] as $cur)
                @foreach (['month', 'year'] as $int)
                    @php($amount = optional($prices->first(fn ($p) => $p->currency === $cur && $p->interval === $int))->amount)
                    <div class="flex items-center gap-3 text-sm">
                        <input type="hidden" name="prices[{{ $i }}][currency]" value="{{ $cur }}">
                        <input type="hidden" name="prices[{{ $i }}][interval]" value="{{ $int }}">
                        <span class="w-28">{{ $cur }} / {{ $int }}</span>
                        <input name="prices[{{ $i }}][amount]" type="number" min="0" step="0.01" value="{{ $amount }}" class="w-40 rounded border border-gray-300 px-3 py-1.5" aria-label="{{ $cur }} {{ $int }}">
                    </div>
                    @php($i++)
                @endforeach
            @endforeach
            <x-button>Save prices</x-button>
        </form>
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Features and limits</h2>
        <form method="POST" action="{{ route('admin.plans.features', $plan->id) }}">
            @csrf @method('PUT')
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-gray-600"><tr><th class="py-1 pr-4">Included</th><th class="py-1 pr-4">Features</th><th class="py-1">Limit (empty = unlimited)</th></tr></thead>
                    <tbody>
                    @foreach ($features as $f)
                        <tr class="border-t">
                            <td class="py-1 pr-4"><input type="checkbox" name="features[{{ $f->key }}][included]" value="1" @checked($f->included) aria-label="{{ $f->key }}"></td>
                            <td class="py-1 pr-4">{{ $f->key }} <span class="text-xs text-gray-500">{{ $f->type }}</span></td>
                            <td class="py-1">@if ($f->type !== 'boolean')<input type="number" min="0" step="any" name="features[{{ $f->key }}][limit]" value="{{ $f->limit_value }}" class="w-36 rounded border border-gray-300 px-2 py-1" aria-label="{{ $f->key }} limit">@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3"><x-button>Save features</x-button></div>
        </form>
    </section>
</x-layouts.admin>
