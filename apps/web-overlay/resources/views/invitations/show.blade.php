<x-layouts.guest title="Invitation">
    <h2 class="text-lg font-medium">{{ $invitation->tenant_name }}</h2>
    <p class="text-sm text-gray-700">
        {{ $invitation->email }} The address <strong>{{ $invitation->role }}</strong> has been invited as.
    </p>

    @if ($invitation->accepted_at)
        <p class="rounded bg-yellow-50 p-3 text-sm text-yellow-800">This invitation has already been used.</p>
    @elseif (\Illuminate\Support\Carbon::parse($invitation->expires_at)->isPast())
        <p class="rounded bg-yellow-50 p-3 text-sm text-yellow-800">The invitation has expired. Ask for a new invitation.</p>
    @elseif (auth()->check())
        @if ($errors->any())
            <p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>
        @endif
        <p class="text-sm text-gray-600">You are logged in: {{ auth()->user()->email }}</p>
        <form method="POST" action="{{ route('invitations.accept', ['token' => $token]) }}">
            @csrf
            <x-button class="w-full">Accept invitation</x-button>
        </form>
    @else
        <p class="text-sm text-gray-600">To continue {{ $invitation->email }} log in or register with.</p>
        <div class="flex gap-3">
            <a class="flex-1 rounded-md bg-indigo-600 px-4 py-2 text-center text-sm font-medium text-white" href="{{ url('/login') }}">Log in</a>
            <a class="flex-1 rounded-md border border-gray-300 px-4 py-2 text-center text-sm font-medium" href="{{ url('/register') }}">Register</a>
        </div>
    @endif
</x-layouts.guest>
