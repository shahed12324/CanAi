<x-layouts.app :title="$d['title']">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="studio" aria-label="Studio details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> STUDIO</div>
        <h1>Studio details</h1><p>Create, organize and refine your creative assets.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Studio Workspace</span>
    </header>

    <p><a href="{{ route('studio.index') }}" class="text-sm text-indigo-700 underline">← All designs</a></p>
    <h2 class="text-xl font-semibold">{{ $d['title'] }}</h2>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($link)<div class="mt-2 rounded border-2 border-indigo-400 bg-indigo-50 p-3 text-sm"><b>Send this link to the client (shown only now):</b>
        <input readonly value="{{ $link }}" onfocus="this.select()" class="mt-1 w-full rounded border bg-white px-2 py-1 font-mono text-xs"></div>@endif

    <section class="mt-3 grid gap-4 sm:grid-cols-2">@foreach ($d['sizes'] as $s)
        <div class="rounded-lg bg-white p-3 text-sm shadow"><b>{{ $sizes[$s][0] }}</b> <span class="text-xs text-gray-500">{{ $sizes[$s][1] }}</span>
            <img src="{{ route('studio.image', [$d['id'], $s]) }}" alt="{{ $d['title'] }} — {{ $sizes[$s][0] }}" loading="lazy" class="mt-2 max-h-96 w-full rounded border object-contain">
            <div class="mt-2 flex gap-3 text-xs"><a class="text-indigo-700 underline" href="{{ route('studio.download', [$d['id'], $s]) }}">PNG Download</a><a class="text-indigo-700 underline" href="{{ route('studio.download', [$d['id'], $s, 'fmt' => 'jpeg']) }}">JPG Download</a></div></div>@endforeach</section>
    @if ($zip && count($d['sizes']) > 1)<p class="mt-2 text-sm"><a class="text-indigo-700 underline" href="{{ route('studio.zip', $d['id']) }}">All sizes together (ZIP)</a></p>@endif

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Change text and size</h3>
        <p class="mt-1 text-xs text-gray-600">The image background stays the same and no credits are needed. When changed, the earlier version is kept (max 10). The image in a link already sent to a client does not change.</p>
        <form method="POST" action="{{ route('studio.edit', $d['id']) }}" class="mt-2 grid gap-2">@csrf
            <label class="grid gap-1">Name<input name="title" maxlength="120" required value="{{ old('title', $d['title']) }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Title<input name="headline" maxlength="200" value="{{ old('headline', $d['headline']) }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Subtitle<input name="subline" maxlength="400" value="{{ old('subline', $d['subline']) }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Button text<input name="cta" maxlength="100" value="{{ old('cta', $d['cta']) }}" class="rounded border px-2 py-1"></label>
            <div class="flex flex-wrap gap-3">@foreach ($sizes as $k => [$label])<label class="flex items-center gap-1"><input type="checkbox" name="sizes[]" value="{{ $k }}" @checked(in_array($k, old('sizes', $d['sizes']), true))> {{ $label }}</label>@endforeach</div>
            <label class="grid gap-1">Theme<select name="theme" class="rounded border px-2 py-1">@foreach ($themes as $k => $l)<option value="{{ $k }}" @selected(old('theme', $d['theme']) === $k)>{{ $l }}</option>@endforeach</select></label>
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div></form>
        @if (count($versions) > 0)<h4 class="mt-3 font-semibold">Earlier versions</h4>
        @foreach ($versions as $v)
        <div class="mt-1 flex flex-wrap items-center justify-between gap-2 rounded border p-2"><span>#{{ $v['n'] }} · {{ $v['created_at'] }} · <b>{{ $v['headline'] !== '' ? $v['headline'] : $v['title'] }}</b></span>
            <form method="POST" action="{{ route('studio.restore', [$d['id'], $v['n']]) }}">@csrf<button class="text-xs text-indigo-700 underline">Restore this</button></form></div>
        @endforeach @endif
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Client approval</h3>
        <p class="mt-1 text-xs text-gray-600">Make a secret link. The client can view the image without logging in and "Approval" or "I want changes" can respond — only once. The image the client saw is kept exactly. The link is shown only once, at creation.</p>
        <form method="POST" action="{{ route('studio.share', $d['id']) }}" class="mt-2 flex flex-wrap items-end gap-2">@csrf
            <label class="grid text-xs">Size<select name="size" class="rounded border px-2 py-1 text-sm">@foreach ($d['sizes'] as $s)<option value="{{ $s }}">{{ $sizes[$s][0] }}</option>@endforeach</select></label>
            <label class="grid text-xs">Client name<input name="client" maxlength="80" class="rounded border px-2 py-1 text-sm"></label>
            <label class="grid text-xs">Validity (days)<input name="days" type="number" min="1" max="30" value="7" class="w-20 rounded border px-2 py-1 text-sm"></label>
            <button class="rounded bg-indigo-600 px-3 py-1 text-white">Make link</button></form>
        @foreach ($reviews as $r)
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded border p-2"><span><b>{{ $r['client_name'] !== '' ? $r['client_name'] : 'Client' }}</b> · {{ $sizes[$r['size_key']][0] }} ·
            @switch($r['status'])@case('approved')<span class="text-green-700">✔ Allowed</span>@break @case('changes')<span class="text-amber-700">✎ Asked for changes</span>@break @case('revoked')<span class="text-gray-500">Closed</span>@break @case('expired')<span class="text-gray-500">Expired</span>@break @default<span class="text-blue-700">Waiting</span>@endswitch
            @if ($r['comment'])<br><span class="text-xs text-gray-700">"{{ $r['comment'] }}"</span>@endif</span>
            @if ($r['status'] === 'pending')<form method="POST" action="{{ route('studio.revoke', [$d['id'], $r['id']]) }}">@csrf<button class="text-xs text-red-700 underline">Disable link</button></form>@endif</div>@endforeach
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <form method="POST" action="{{ route('studio.destroy', $d['id']) }}" onsubmit="return confirm('This design and all its links will be deleted. Sure?')">@csrf<button class="text-xs text-red-700 underline">Delete design</button></form>
    </section>
</x-layouts.app>
