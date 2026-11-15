<x-layouts.guest title="Confirm password">
    <form method="POST" action="{{ url('/user/confirm-password') }}" class="space-y-4">
        @csrf
        <p class="text-sm text-gray-600">This is a sensitive area. Enter your password to continue.</p>
        <x-field name="password" type="password" label="Password" autocomplete="current-password" required autofocus />
        <x-button class="w-full">Confirm</x-button>
    </form>
</x-layouts.guest>
