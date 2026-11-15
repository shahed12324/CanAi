<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \App\Support\SiteMeta::title($title ?? null) }}</title>
    {!! \App\Support\SiteMeta::tags($title ?? null) !!}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 text-gray-900">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-6 px-4 py-10">
        <h1 class="text-center text-3xl font-bold text-white">{{ \App\Support\SiteMeta::name() }}</h1>
        <div class="space-y-4 rounded-2xl bg-white p-7 shadow-2xl">
            @if (session('status'))
                <p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>
            @endif
            {{ $slot }}
        </div>
    </main>
    <footer class="mx-auto max-w-md px-4 pb-8 text-center text-xs text-slate-400">
        <p>@foreach (\App\Support\SiteMeta::links('site.footer_links') as $l)<a class="underline" href="{{ $l['url'] }}" rel="noopener">{{ $l['label'] }}</a> · @endforeach</p>
        <p>{{ \App\Support\SiteMeta::footerText() }}</p>
    </footer>
</body>
</html>
