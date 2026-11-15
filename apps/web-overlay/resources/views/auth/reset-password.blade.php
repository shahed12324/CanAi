<x-layouts.guest title="New password">
    <form method="POST" action="{{ url('/reset-password') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-field name="email" type="email" label="Email" :value="$request->query('email')" required />
        <x-field name="password" type="password" label="New password" autocomplete="new-password" required />
        <x-field name="password_confirmation" type="password" label="Re-enter password" autocomplete="new-password" required />
        <x-button class="w-full">Change password</x-button>
    </form>
</x-layouts.guest>
