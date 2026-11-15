<x-layouts.app title="Outreach">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="outreach" aria-label="Outreach">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> OUTREACH</div>
        <h1>Outreach</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Outreach Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Email outreach</h2>
    @include('outreach._nav')
    @if ($pendingTotal > 0)<p class="rounded bg-amber-50 p-3 text-sm text-amber-800"><a class="underline" href="{{ route('outreach.queue') }}">{{ $pendingTotal }} mails are awaiting your approval.</a> No mail goes out without approval.</p>@endif
    <section class="rounded-lg bg-white p-5 shadow">
        @forelse ($rows as $r)
            <a href="{{ route('outreach.campaign', trim($r->id)) }}" class="flex flex-wrap items-center justify-between gap-2 border-t py-2 text-sm first:border-0 hover:bg-gray-50">
                <span><strong>{{ $r->name }}</strong> <span class="text-gray-500">· {{ $r->from_email }}</span></span>
                <span class="text-xs text-gray-600">{{ ['draft' => 'draft', 'active' => 'Active', 'paused' => 'Pause', 'archived' => 'Archive'][$r->status] }} · Waiting {{ $r->pending }} · Sent {{ $r->sent }}</span>
            </a>
        @empty<p class="text-sm text-gray-500">No campaigns yet.</p>@endforelse
    </section>
    @if ($canManage)
    <section class="max-w-2xl space-y-2 rounded-lg bg-white p-5 shadow">
        <h3 class="font-medium">New campaign</h3>
        @if (count($accounts) === 0)<p class="text-sm text-gray-600">First <a class="text-indigo-600" href="{{ route('outreach.accounts') }}"> a sender address</a> Add.</p>
        @else
        <form method="POST" action="{{ route('outreach.campaigns.store') }}" class="space-y-2">@csrf
            <x-field name="name" label="Campaign name" :value="old('name')" required />
            <div><label for="account_id" class="block text-sm font-medium text-gray-700">From which address</label><select id="account_id" name="account_id" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach ($accounts as $a)<option value="{{ trim($a->id) }}">{{ $a->name }} — {{ $a->from_email }}</option>@endforeach</select></div>
            <x-field name="subject_tpl" label="Subject" :value="old('subject_tpl', $defaultSubject)" required />
            <div><label for="body_tpl" class="block text-sm font-medium text-gray-700">Mail text</label><textarea id="body_tpl" name="body_tpl" rows="7" maxlength="20000" required class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">{{ old('body_tpl', $defaultBody) }}</textarea>
                <p class="text-xs text-gray-500"> can be used: &#123;&#123;client_name&#125;&#125; &#123;&#123;domain&#125;&#125; &#123;&#123;brand_name&#125;&#125; &#123;&#123;gaps_list&#125;&#125;. An unsubscribe link is placed automatically at the bottom of every mail.</p></div>
            <div class="grid gap-2 sm:grid-cols-2"><x-field name="followup_after_days" type="number" label="Follow-up after how many days (optional, 1–30)" :value="old('followup_after_days')" /></div>
            <div><label for="followup_body_tpl" class="block text-sm font-medium text-gray-700">Follow-up text</label><textarea id="followup_body_tpl" name="followup_body_tpl" rows="3" maxlength="20000" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">{{ old('followup_body_tpl') }}</textarea></div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="whatsapp_button" value="1" @checked(old('whatsapp_button'))> In the mail "WhatsApp-say this" Add a link (from the brand kit phone number)</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="attach_proposal" value="1" @checked(old('attach_proposal'))> Attach the lead’s proposal PDF (a lead with no proposal will not be mailed; if the proposal changes after approval, the mail will not go out)</label>
            <x-button>Create</x-button>
        </form>
        @endif
    </section>
    @endif
</x-layouts.app>
