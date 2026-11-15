<x-layouts.app title="Photoshop AI agent">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="photoshop" aria-label="Photoshop AI agent">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> PHOTOSHOP</div>
        <h1>Photoshop AI agent</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Photoshop Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Photoshop AI agent</h2>
    <p class="mt-1 text-sm text-gray-600">Write what you want (Bangla or English) and attach the files. The agent picks the right tool and settings for you: passport photos, background removal, NID/passport scans, PDF tools, OCR, translation, QR codes, watermarks and more. Prefer a form? <a class="text-indigo-600 underline" href="{{ route('tools.index') }}">All tools</a>.</p>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    <form method="POST" action="{{ route('photoshop.run') }}" enctype="multipart/form-data" class="mt-3 grid max-w-2xl gap-3 rounded-lg bg-white p-4 text-sm shadow"
          onsubmit="var b=this.querySelector('button[value=run]');setTimeout(function(){b.disabled=true;b.textContent='Working…';setTimeout(function(){b.disabled=false;b.textContent='Do it';},8000);},0)">
        @csrf
        <label class="grid gap-1"><span class="font-medium">What should I do?</span>
            <textarea name="request" rows="3" maxlength="500" required placeholder="e.g. Passport size photo, white background, 4R sheet" class="rounded border px-2 py-1">{{ old('request') }}</textarea></label>
        <div class="grid gap-1"><span class="font-medium">Upload your file(s) <span class="text-xs font-normal text-gray-500">(optional; up to 40, 25 MB each — images or PDFs)</span></span><x-tools.uploader accept="image/*,.pdf,.doc,.docx,.xlsx" :min="0" :max="40" :labels="null" id="ps" /></div>
        <div class="flex gap-2">
            <button type="submit" name="mode" value="run" class="rounded bg-indigo-600 px-4 py-2 text-white">Do it</button>
            <button type="submit" name="mode" value="plan" class="rounded border border-gray-300 px-4 py-2 text-gray-700">Just show the plan</button>
        </div>
        <p class="text-xs text-gray-500">Handwriting and translation use AI credits; everything else is free. Nothing is stored.</p>
    </form>

    <section class="mt-4 max-w-2xl">
        <h3 class="text-sm font-semibold">Try saying</h3>
        <ul class="mt-1 flex flex-wrap gap-2 text-xs">
            @foreach ($examples as $e)<li><button type="button" class="rounded-full border px-3 py-1 hover:bg-indigo-50" onclick="document.querySelector('[name=request]').value=this.textContent.trim()">{{ $e }}</button></li>@endforeach
        </ul>
    </section>
</x-layouts.app>
