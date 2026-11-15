<x-layouts.app title="Design studio">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="studio" aria-label="Design studio">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> STUDIO</div>
        <h1>Design studio</h1><p>Create, organize and refine your creative assets.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Studio Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Design studio — post, story, cover and ad images</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">Text (including Bengali), logo and colors <b> we place them ourselves, precisely</b> — AI The AI does not draw text in images, so there are no spelling mistakes. AI makes only the background image (per image <b>{{ $price }}</b> credits). If you upload your own image or use only brand colors <b> free</b>. Designs are only yours.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @unless ($brand['has_kit'])<p class="mt-2 rounded bg-blue-50 p-3 text-sm text-blue-800">There is no brand kit yet — <a class="underline" href="{{ route('brand.show') }}">Brand kit</a> if you make one, your logo, colors and phone/website are placed in every design automatically.</p>@endunless

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">New design</h3>
        <p class="mt-1 text-xs text-gray-600">Start from a ready sample (you can change the text):
            @foreach ($templates as $k => $t)<a href="{{ route('studio.index', ['t' => $k]) }}" class="ml-1 rounded border px-2 py-0.5 {{ ($tplKey ?? null) === $k ? 'bg-indigo-50 border-indigo-400' : '' }}">{{ $t['name'] }}</a>@endforeach</p>
        <form method="POST" action="{{ route('studio.store') }}" enctype="multipart/form-data" class="mt-2 grid gap-3" onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').textContent = 'Making… (AI images can take 1 minute)'">@csrf
            <input type="hidden" name="rid" value="{{ $rid }}">
            <label class="grid gap-1">Name (only for you)<input name="title" maxlength="120" required value="{{ old('title') }}" placeholder="Eid offer post" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Headline (large text)<input name="headline" maxlength="200" value="{{ old('headline', $tpl['headline'] ?? '') }}" placeholder="30% off all cashews" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Subtitle<input name="subline" maxlength="400" value="{{ old('subline', $tpl['subline'] ?? '') }}" placeholder="Free delivery in Dhaka" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Button text<input name="cta" maxlength="100" value="{{ old('cta', $tpl['cta'] ?? '') }}" placeholder="Order now" class="rounded border px-2 py-1"></label>
            <fieldset><legend class="font-medium">Size</legend><div class="mt-1 flex flex-wrap gap-3">@foreach ($sizes as $k => [$label, $hint])
                <label class="flex items-center gap-1"><input type="checkbox" name="sizes[]" value="{{ $k }}" @checked(in_array($k, old('sizes', $tpl['sizes'] ?? ['post', 'story']), true))> {{ $label }} <span class="text-xs text-gray-500">{{ $hint }}</span></label>@endforeach</div></fieldset>
            <label class="grid gap-1">Theme<select name="theme" class="rounded border px-2 py-1">@foreach ($themes as $k => $l)<option value="{{ $k }}" @selected(old('theme', $tpl['theme'] ?? 'dark') === $k)>{{ $l }}</option>@endforeach</select></label>
            <fieldset><legend class="font-medium">Background image</legend>
                <label class="mt-1 flex items-center gap-1"><input type="radio" name="source" value="none" @checked(old('source', 'none') === 'none')> Brand colors only (free)</label>
                <label class="flex items-center gap-1"><input type="radio" name="source" value="upload" @checked(old('source') === 'upload')> Upload your own image (free) <input type="file" name="photo" accept="image/png,image/jpeg,image/webp" class="text-xs"></label>
                <label class="flex items-center gap-1"><input type="radio" name="source" value="ai" @checked(old('source') === 'ai')> AI Make image with ({{ $price }} credits)</label>
                <textarea name="prompt" rows="2" maxlength="1500" placeholder="AI-Describe the image, for example: a bowl of cashew nuts on a wooden table, soft light, leaving empty space" class="mt-1 w-full rounded border px-2 py-1">{{ old('prompt', $tpl['prompt'] ?? '') }}</textarea>
                <p class="text-xs text-gray-500">Do not ask for text inside the image — we place text separately.</p></fieldset>
            <label class="grid gap-1">Note (optional, for yourself)<input name="brief" maxlength="1500" value="{{ old('brief') }}" class="rounded border px-2 py-1"></label>
            <div><button type="submit" class="rounded bg-indigo-600 px-3 py-1 text-white">Make design</button></div>
        </form>
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Logo idea (AI, {{ $price }} credits)</h3>
        <p class="mt-1 text-xs text-gray-600">A simple logo mark on a white ground is made and downloaded. It has no text (AI misspells Bengali) and it is not saved or placed in the brand kit — if you like it, download it and upload it on the brand kit page. It is a draft idea; there is no guarantee about trademark/rights.</p>
        <form method="POST" action="{{ route('studio.logo') }}" class="mt-2 flex flex-wrap items-end gap-2">@csrf<input type="hidden" name="rid" value="{{ \App\Actions\Tenancy\CreateWorkspace::newId() }}">
            <label class="grid flex-1 gap-1 text-xs">What kind of logo do you want<input name="idea" maxlength="400" required minlength="3" value="{{ old('idea') }}" placeholder="A cashew leaf and golden color" class="rounded border px-2 py-1 text-sm"></label>
            <button class="rounded bg-indigo-600 px-3 py-1 text-white">Make and download</button></form>
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Your designs ({{ count($designs) }}/40)</h3>
        <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">@forelse ($designs as $d)
            <a href="{{ route('studio.show', $d['id']) }}" class="block rounded border p-2 hover:bg-gray-50">
                <img src="{{ route('studio.image', [$d['id'], $d['sizes'][0]]) }}" alt="" loading="lazy" class="mb-1 h-32 w-full rounded object-cover">
                <b>{{ $d['title'] }}</b><div class="text-xs text-gray-500">{{ $d['headline'] }}</div></a>
            @empty<p class="col-span-full text-gray-500">No designs yet.</p>@endforelse</div>
    </section>
</x-layouts.app>
