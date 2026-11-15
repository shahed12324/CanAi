<x-layouts.app title="Growth analysis">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="social" aria-label="Growth analysis">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SOCIAL</div>
        <h1>Growth analysis</h1><p>Publish content, monitor channels and manage social activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Social Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('social.index') }}">← Social planner</a></p>
    <h2 class="mt-1 text-xl font-semibold">Growth analysis</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">The numbers below are simple calculations (free, no AI) — only for posts auto-posted from the system to Facebook. Engagement = reactions + 2×comments + 3×shares. An average over few posts is not certain; it is only a hint for experiments. AI advice is optional and uses a little credit; it changes nothing and posts nothing.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <div class="mt-3 flex gap-2 text-sm">@foreach ([7, 30, 90] as $d)<a class="rounded border px-3 py-1 {{ $d === $days ? 'bg-indigo-600 text-white' : '' }}" href="{{ route('social.growth', ['days' => $d]) }}">{{ $d }} days</a>@endforeach</div>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <p>Posts with numbers: <b>{{ $a['n'] }}</b> · Average engagement: <b>{{ $a['avg'] }}</b></p>
        @if (! $a['enough'])<p class="mt-1 text-gray-600">Not enough posts yet to say which type is doing well (at least 5 are needed). Widen the days or post more.</p>@endif
    </section>

    @if ($a['n'] > 0)
    @foreach ($labels as $g => $gl)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">{{ $gl }}</h3>
        <table class="mt-1 w-full text-left"><thead><tr class="text-xs text-gray-500"><th>Type</th><th>Post</th><th>Avg response</th><th></th></tr></thead><tbody>
        @foreach ($a['groups'][$g] as $k => $b)<tr class="border-t"><td class="py-1">{{ \App\Social\SocialText::bucketLabel($g, (string) $k) }}</td><td>{{ $b['n'] }}@if ($b['n'] < 2) <span class="text-xs text-gray-400">(very few)</span>@endif</td><td>{{ $b['avg'] }}</td><td>@if ($a['best'][$g] !== null && (string) $a['best'][$g] === (string) $k)<span class="rounded bg-green-100 px-2 text-xs text-green-700">Ahead</span>@endif</td></tr>@endforeach
        </tbody></table></section>
    @endforeach
    @endif

    @if ($can_manage && $a['enough'])
    <form method="POST" action="{{ route('social.growth.advice') }}" class="mt-3 grid gap-2 rounded-lg bg-white p-4 text-sm shadow" onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'Thinking…'">@csrf
        <input type="hidden" name="rid" value="{{ $rid }}"><input type="hidden" name="days" value="{{ $days }}">
        <label class="grid gap-1">Your goal (optional)<input name="goal" maxlength="500" value="{{ $goal }}" placeholder="For example: increase orders in the inbox" class="rounded border px-2 py-1"></label>
        <div><button type="submit" class="rounded bg-indigo-600 px-3 py-1 text-white">AI Get advice</button></div>
    </form>
    @endif

    @if ($advice)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">AI Advice</h3><p class="mt-1">{{ $advice['summary'] }}</p>
        @foreach (['do' => 'What to do', 'avoid' => 'What to avoid', 'test_next' => 'What to test next'] as $k => $l)@if (count($advice[$k]) > 0)<h4 class="mt-2 font-medium">{{ $l }}</h4><ul class="list-disc pl-5">@foreach ($advice[$k] as $t)<li>{{ $t }}</li>@endforeach</ul>@endif @endforeach
    </section>@endif
</x-layouts.app>
