<x-layouts.guest title="Password reset">
    <form method="POST" action="{{ url('/forgot-password') }}" class="space-y-4">
        @csrf
        <p class="text-sm text-gray-600">Enter your email; a reset link will be sent.</p>
        <x-field name="email" type="email" label="Email" required autofocus />
        <x-button class="w-full">Send link</x-button>
    </form>
</x-layouts.guest>
