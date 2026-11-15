<x-layouts.guest title="Two-step verification">
    <div class="space-y-4">
        <form method="POST" action="{{ url('/two-factor-challenge') }}" class="space-y-4">
            @csrf
            <div id="code-box">
                <x-field name="code" label="6-digit code from the authenticator app" autocomplete="one-time-code" inputmode="numeric" autofocus />
            </div>
            <details>
                <summary class="cursor-pointer text-sm text-indigo-600">Use a recovery code</summary>
                <div class="pt-3"><x-field name="recovery_code" label="Recovery code" autocomplete="one-time-code" /></div>
            </details>
            <x-button class="w-full">Verify</x-button>
        </form>
    </div>
</x-layouts.guest>
