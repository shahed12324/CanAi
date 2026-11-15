<x-layouts.guest title="Log in">
    <form method="POST" action="{{ url('/login') }}" class="space-y-4">
        @csrf
        <x-field name="email" type="email" label="Email" autocomplete="username" required autofocus />
        <x-field name="password" type="password" label="Password" autocomplete="current-password" required />
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1"> Remember me</label>
        <x-button class="w-full">Log in</x-button>
    </form>
    <div class="flex justify-between text-sm">
        <a class="text-indigo-600" href="{{ url('/forgot-password') }}">Forgot your password?</a>
        <a class="text-indigo-600" href="{{ url('/register') }}">New account</a>
    </div>
</x-layouts.guest>
