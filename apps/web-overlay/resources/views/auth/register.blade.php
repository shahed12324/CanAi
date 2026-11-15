<x-layouts.guest title="Register">
    <form method="POST" action="{{ url('/register') }}" class="space-y-4">
        @csrf
        <x-field name="name" label="Name" autocomplete="name" required autofocus />
        <x-field name="email" type="email" label="Email" autocomplete="username" required />
        <x-field name="password" type="password" label="Password (at least 10 characters, upper and lower case letters and a digit)" autocomplete="new-password" required />
        <x-field name="password_confirmation" type="password" label="Re-enter password" autocomplete="new-password" required />
        <x-button class="w-full">Create account</x-button>
    </form>
    <p class="text-sm"><a class="text-indigo-600" href="{{ url('/login') }}">Already have an account?</a></p>
</x-layouts.guest>
