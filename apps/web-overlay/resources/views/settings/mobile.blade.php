<x-layouts.app title="Mobile app">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="settings" aria-label="Mobile app">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SETTINGS</div>
        <h1>Mobile app</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Settings Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Connect the mobile app</h2>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
        <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
            <li>Install the CANAI app on your phone and open it.</li>
            <li>Tap <strong>Scan QR code</strong> on the login screen.</li>
            <li>Press the button below and scan the QR shown here.</li>
        </ol>
        @if ($payload)
            <div class="flex flex-col items-center gap-2 rounded border border-indigo-200 bg-indigo-50 p-4">
                <div id="qr" class="h-64 w-64 bg-white p-2" data-payload="{{ $payload }}"></div>
                <p class="text-xs text-gray-600">Valid for {{ $minutes }} minutes and works once. Do not share this picture.</p>
                <details class="w-full text-xs text-gray-600"><summary class="cursor-pointer">Can't scan? Copy the link</summary><code class="block select-all break-all">{{ $payload }}</code></details>
            </div>
            <script src="/js/qrcode.js"></script>
            <script>
                (function () {
                    var el = document.getElementById('qr');
                    var q = qrcode(0, 'M');
                    q.addData(el.getAttribute('data-payload'));
                    q.make();
                    el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
                    var svg = el.querySelector('svg');
                    if (svg) { svg.style.width = '100%'; svg.style.height = '100%'; }
                })();
            </script>
        @endif
        <form method="POST" action="{{ route('mobile.pair') }}">@csrf <x-button>{{ $payload ? 'Make a new QR' : 'Show QR code' }}</x-button></form>
    </section>
    <section class="max-w-xl space-y-2 rounded-lg bg-white p-5 shadow">
        <h3 class="font-medium">Connected devices</h3>
        @forelse ($devices as $d)
            <div class="flex items-center justify-between border-t py-2 text-sm">
                <span>{{ $d->name }} <span class="text-gray-500">· {{ $d->last_used_at ? \Illuminate\Support\Carbon::parse($d->last_used_at)->diffForHumans() : 'never used' }}</span></span>
                <form method="POST" action="{{ route('mobile.revoke', $d->id) }}" onsubmit="return confirm('Disconnect this device?')">@csrf @method('DELETE') <x-button>Disconnect</x-button></form>
            </div>
        @empty<p class="text-sm text-gray-500">No devices yet.</p>@endforelse
    </section>
</x-layouts.app>
