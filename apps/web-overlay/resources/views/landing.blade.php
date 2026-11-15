@php
    use App\Support\SiteMeta;
    $name = SiteMeta::name();
    $groups = [
        ['Personal assistant', 'M8 10h.01M12 10h.01M16 10h.01M21 12a9 9 0 11-3.5-7.1L21 4v5', [
            'AI chat with live answers and voice',
            'Memory and your own knowledge base',
            'Calendar, tasks and meetings',
            'Coding assistant',
            'Computer control with approvals',
        ]],
        ['Marketing', 'M11 5.88V19.24a1.76 1.76 0 01-3.4.6l-2.1-5.6M5 14H3a1 1 0 01-1-1v-2a1 1 0 011-1h5.8L18 5v14l-9.2-4', [
            'Website lead finder and CRM pipeline',
            'Proposals as PDF or DOCX',
            'Email outreach, sent only after approval',
            'Facebook, Instagram and YouTube publishing',
            'Meta and Google Ads drafts and advice',
            'SEO audit, client reports, agency mode',
            'Creative studio: images, video, subtitles, music',
        ]],
        ['E-commerce', 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.3 2.3a1 1 0 00.7 1.7H17M17 17a2 2 0 100 4 2 2 0 000-4zM9 17a2 2 0 100 4 2 2 0 000-4z', [
            'Connect WooCommerce, Shopify and more',
            'Product manager and orders',
            'One inbox for every customer channel',
            'AI support and sales agent',
            'COD confirmation and courier booking',
        ]],
        ['Finance', 'M12 8c-1.66 0-3 .9-3 2s1.34 2 3 2 3 .9 3 2-1.34 2-3 2m0-8V7m0 10v-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z', [
            'Double-entry accounting',
            'Bill scanning and bank reconciliation',
            'VAT, tax, payroll and audit checks',
        ]],
        ['Platform', 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z', [
            'Workflows and automation agents',
            'Telegram, Slack and Discord links',
            'Plugin marketplace',
            'White-label and reseller mode',
            'Developer API keys and webhooks',
            'Phone app with approvals and emergency stop',
        ]],
    ];
    $menu = SiteMeta::links('site.menu_links');
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ SiteMeta::title() }}</title>
    {!! SiteMeta::tags() !!}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-950 text-slate-200 antialiased">
<header class="sticky top-0 z-30 border-b border-white/10 bg-slate-950/80 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
        <a href="{{ url('/') }}" class="flex items-center gap-2 text-lg font-bold text-white">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-gradient-to-br from-indigo-500 to-fuchsia-500 text-sm">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>{{ $name }}
        </a>
        <nav class="hidden items-center gap-6 text-sm md:flex" aria-label="Main">
            <a class="hover:text-white" href="#features">Features</a>
            <a class="hover:text-white" href="#how">How it works</a>
            @if ($plans !== [])<a class="hover:text-white" href="#plans">Plans</a>@endif
            @foreach ($menu as $l)<a class="hover:text-white" href="{{ $l['url'] }}" rel="noopener">{{ $l['label'] }}</a>@endforeach
        </nav>
        <div class="flex items-center gap-2 text-sm">
            <a href="{{ url('/login') }}" class="rounded-lg px-3 py-1.5 hover:text-white">Log in</a>
            @if ($signup)<a href="{{ url('/register') }}" class="rounded-lg bg-indigo-500 px-3 py-1.5 font-medium text-white hover:bg-indigo-400">Get started</a>@endif
        </div>
    </div>
</header>

<section class="relative overflow-hidden">
    <div class="pointer-events-none absolute inset-0 -z-0 bg-[radial-gradient(60%_50%_at_50%_0%,rgba(99,102,241,0.35),transparent),radial-gradient(40%_40%_at_85%_20%,rgba(217,70,239,0.22),transparent)]"></div>
    <div class="relative mx-auto max-w-4xl px-4 py-24 text-center sm:py-32">
        <p class="mx-auto mb-5 inline-block rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs text-indigo-200">AI assistant · Marketing · Shop · Accounts</p>
        <h1 class="text-4xl font-extrabold tracking-tight text-white sm:text-6xl">Your personal AI and <span class="bg-gradient-to-r from-indigo-400 to-fuchsia-400 bg-clip-text text-transparent">digital employee</span></h1>
        <p class="mx-auto mt-6 max-w-2xl text-lg text-slate-300">{{ $name }} answers customers, runs your marketing, keeps your books and controls your computer, and asks you before anything important happens.</p>
        <div class="mt-9 flex flex-wrap justify-center gap-3">
            @if ($signup)<a href="{{ url('/register') }}" class="rounded-xl bg-indigo-500 px-6 py-3 font-semibold text-white shadow-lg shadow-indigo-500/30 hover:bg-indigo-400">Start free</a>@endif
            <a href="{{ url('/login') }}" class="rounded-xl border border-white/20 px-6 py-3 font-semibold text-white hover:bg-white/10">Log in</a>
        </div>
        <dl class="mx-auto mt-14 grid max-w-3xl grid-cols-3 gap-4 text-center">
            <div class="rounded-xl border border-white/10 bg-white/5 p-4"><dt class="text-xs text-slate-400">Everything in one place</dt><dd class="mt-1 text-2xl font-bold text-white">30+ tools</dd></div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-4"><dt class="text-xs text-slate-400">You approve</dt><dd class="mt-1 text-2xl font-bold text-white">Before it acts</dd></div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-4"><dt class="text-xs text-slate-400">Works on</dt><dd class="mt-1 text-2xl font-bold text-white">Web · Phone · PC</dd></div>
        </dl>
    </div>
</section>

<section id="features" class="mx-auto max-w-6xl px-4 py-20">
    <h2 class="text-center text-3xl font-bold text-white">Everything your business needs</h2>
    <p class="mx-auto mt-3 max-w-2xl text-center text-slate-400">One account, one login, one bill.</p>
    <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($groups as [$title, $icon, $items])
            <article class="rounded-2xl border border-white/10 bg-gradient-to-b from-white/[0.07] to-white/[0.02] p-6 transition hover:border-indigo-400/50">
                <div class="mb-4 grid h-11 w-11 place-items-center rounded-xl bg-indigo-500/20 text-indigo-300">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                </div>
                <h3 class="text-lg font-semibold text-white">{{ $title }}</h3>
                <ul class="mt-3 space-y-2 text-sm text-slate-300">
                    @foreach ($items as $i)<li class="flex gap-2"><svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>{{ $i }}</li>@endforeach
                </ul>
            </article>
        @endforeach
    </div>
</section>

<section id="how" class="border-y border-white/10 bg-white/[0.03]">
    <div class="mx-auto max-w-6xl px-4 py-20">
        <h2 class="text-center text-3xl font-bold text-white">How it works</h2>
        <ol class="mt-12 grid gap-6 md:grid-cols-3">
            @foreach ([['1', 'Create your workspace', 'Sign up, name your business and connect your shop, pages and email.'], ['2', 'Tell it what you need', 'Chat or speak. It drafts replies, posts, proposals and entries for you.'], ['3', 'Approve and relax', 'Important actions wait for your approval on the web or your phone.']] as [$n, $t, $d])
                <li class="rounded-2xl border border-white/10 p-6"><span class="grid h-9 w-9 place-items-center rounded-full bg-gradient-to-br from-indigo-500 to-fuchsia-500 font-bold text-white">{{ $n }}</span><h3 class="mt-4 font-semibold text-white">{{ $t }}</h3><p class="mt-2 text-sm text-slate-400">{{ $d }}</p></li>
            @endforeach
        </ol>
    </div>
</section>

@if ($plans !== [])
<section id="plans" class="mx-auto max-w-6xl px-4 py-20">
    <h2 class="text-center text-3xl font-bold text-white">Plans</h2>
    <div class="mt-12 grid gap-5 sm:grid-cols-2 {{ count($plans) >= 3 ? 'lg:grid-cols-3' : '' }} {{ count($plans) >= 4 ? 'xl:grid-cols-4' : '' }}">
        @foreach ($plans as $p)
            <article class="flex flex-col rounded-2xl border border-white/10 bg-white/[0.04] p-6">
                <h3 class="text-lg font-semibold text-white">{{ $p['name'] }}</h3>
                <p class="mt-3 text-2xl font-bold text-white">{{ $p['free'] ? 'Free' : ($p['price'] ?? 'Contact us') }}</p>
                @if ($p['description'])<p class="mt-2 text-sm text-slate-400">{{ $p['description'] }}</p>@endif
                <ul class="mt-4 flex-1 space-y-1 text-sm text-slate-300">
                    @if ($p['credits'] !== '0')<li>{{ $p['credits'] }} credits included</li>@endif
                    @if ($p['trial'] > 0)<li>{{ $p['trial'] }}-day free trial</li>@endif
                </ul>
                @if ($signup)<a href="{{ url('/register') }}" class="mt-6 rounded-lg bg-indigo-500 py-2 text-center text-sm font-medium text-white hover:bg-indigo-400">Choose {{ $p['name'] }}</a>@endif
            </article>
        @endforeach
    </div>
</section>
@endif

<section class="mx-auto max-w-4xl px-4 pb-24 text-center">
    <div class="rounded-3xl bg-gradient-to-br from-indigo-600 to-fuchsia-600 px-6 py-14">
        <h2 class="text-3xl font-bold text-white">Ready to put {{ $name }} to work?</h2>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            @if ($signup)<a href="{{ url('/register') }}" class="rounded-xl bg-white px-6 py-3 font-semibold text-indigo-700 hover:bg-indigo-50">Create your account</a>@endif
            <a href="{{ url('/login') }}" class="rounded-xl border border-white/40 px-6 py-3 font-semibold text-white hover:bg-white/10">Log in</a>
        </div>
    </div>
</section>

<footer class="border-t border-white/10">
    <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-8 text-sm text-slate-400 sm:flex-row">
        <p>{{ SiteMeta::footerText() }}</p>
        <p class="flex flex-wrap justify-center gap-4">@foreach (SiteMeta::links('site.footer_links') as $l)<a class="hover:text-white" href="{{ $l['url'] }}" rel="noopener">{{ $l['label'] }}</a>@endforeach</p>
    </div>
</footer>
</body>
</html>
