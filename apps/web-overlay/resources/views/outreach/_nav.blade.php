<div class="flex flex-wrap gap-3 text-sm">
    <a class="text-indigo-600" href="{{ route('outreach.campaigns') }}">Campaign</a>
    <a class="text-indigo-600" href="{{ route('outreach.queue') }}">Approval queue</a>
    <a class="text-indigo-600" href="{{ route('outreach.accounts') }}">Sender addresses</a>
    <a class="text-indigo-600" href="{{ route('outreach.suppressions') }}">Blocklist</a>
</div>
@if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
@if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
