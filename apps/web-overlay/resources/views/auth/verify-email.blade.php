<x-layouts.guest title="Verify email">
    <p class="text-sm text-gray-700">Verify your account by clicking the link sent to your email.</p>
    @if (session('status') === 'verification-link-sent')
        <p class="rounded bg-green-50 p-3 text-sm text-green-700">A new verification link has been sent.</p>
    @endif
    <div class="flex items-center justify-between">
        <form method="POST" action="{{ url('/email/verification-notification') }}">
            @csrf
            <x-button>Resend</x-button>
        </form>
        <form method="POST" action="{{ url('/logout') }}">
            @csrf
            <x-button variant="ghost">Log out</x-button>
        </form>
    </div>
</x-layouts.guest>
