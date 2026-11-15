<x-layouts.app title="Page and channel connections">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="social" aria-label="Page and channel connections">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SOCIAL</div>
        <h1>Page and channel connections</h1><p>Publish content, monitor channels and manage social activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Social Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('social.index') }}">← Social planner</a></p>
    <h2 class="mt-1 text-xl font-semibold">Page and channel connections (auto-post)</h2>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</p>@endif

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Connected pages</h3>
        @forelse ($accounts as $a)
            <div class="mt-2 flex flex-wrap items-center gap-2 border-t pt-2"><b>{{ $a['name'] }}</b> <span class="rounded bg-gray-100 px-2 text-xs">{{ $a['platform'] === 'youtube' ? 'YouTube' : 'Facebook' }}</span> <span class="text-xs text-gray-500">ID {{ $a['external_id'] }}</span>
                <span class="rounded px-2 text-xs {{ $a['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $a['status'] === 'active' ? 'Active' : 'Connection problem — reconnect' }}</span>
                @if ($can_connect)<form method="POST" action="{{ route('social.disconnect', $a['id']) }}" onsubmit="return confirm('Remove the page? Posts picked for it will remain without auto-post.')" class="ml-auto">@csrf<button class="rounded border px-2 py-1 text-red-700">Remove</button></form>@endif</div>
        @empty<p class="mt-1 text-gray-500">No page connected yet.</p>@endforelse
    </section>

    @if ($can_connect)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">YouTube Channel connections</h3>
        @if ($yt_ready)<form method="POST" action="{{ route('social.youtube.start') }}" class="mt-2">@csrf<button class="rounded bg-red-600 px-3 py-1 text-white">Google Connect a YouTube channel with</button></form>
        @else<p class="mt-1 rounded bg-amber-50 p-3 text-amber-900">The YouTube connection key has not been set on this server yet (<code>SOCIAL_YOUTUBE_CLIENT_ID</code>, <code>SOCIAL_YOUTUBE_CLIENT_SECRET</code>). ENVIRONMENT_SETUP see.</p>@endif
        <p class="mt-2 text-xs text-gray-600">Google-On its screen permission will be asked to upload videos to YouTube and view channel information. Videos are uploaded by default as <b> private</b> ; making them public is your decision. We keep only a sealed refresh token — we never get your Google password. There is a limit on how many videos can go each day (YouTube’s quota).</p></section>
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Connect a new page (or new token)</h3>
        <form method="POST" action="{{ route('social.connect') }}" autocomplete="off" class="mt-2 grid gap-3">@csrf
            <label class="grid gap-1">Facebook Page ID (digits only)<input name="page_id" inputmode="numeric" maxlength="30" required value="{{ old('page_id') }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Page Access Token<input type="password" name="token" maxlength="700" required autocomplete="off" class="rounded border px-2 py-1"></label>
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Verify and connect</button></div>
        </form>
        <div class="mt-3 rounded bg-amber-50 p-3 text-amber-900"><b>How to get a token</b>
            <ol class="ml-5 list-decimal">
                <li>developers.facebook.com-Create an App (Business type) in and open Graph API Explorer with your page’s admin account.</li>
                <li>Grant permissions: <code>pages_manage_posts</code>, <code>pages_read_engagement</code>, <code>pages_show_list</code>; Then create a User Token.</li>
                <li>With that token get the page’s <b>Page Access Token</b> take (in Explorer "Page Access Token" choose your page from. For a long-lived token first make the User Token long-lived.</li>
                <li>If the app is not in Live mode, posting to other people’s pages may not work; on your own page it works as developer/admin.</li>
            </ol>
            We verify the token and seal it; it is not shown again. When it expires, posts will fail and "Token problem" will be shown here.
        </div>
    </section>
    @endif
</x-layouts.app>
