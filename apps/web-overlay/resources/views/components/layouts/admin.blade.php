@props(['title' => 'Admin', 'crumbs' => []])
@php($me = auth()->user())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $title }} · Admin · {{ \App\Support\SiteMeta::name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('components.layouts.premium-theme')
</head>
<body class="jarvis-premium min-h-screen bg-gray-100 text-gray-900">
<div class="jarvis-overlay" data-jarvis-close aria-hidden="true"></div>
<div class="jarvis-shell flex min-h-screen flex-col md:flex-row">
    <aside id="jarvis-sidebar" class="jarvis-sidebar bg-gray-900 p-4 text-sm text-gray-200 md:w-56 md:shrink-0">
        <a href="{{ route('admin.dashboard') }}" class="jarvis-brand mb-4 block text-base font-semibold text-white">{{ config('brand.short_name') }} Admin</a>
        <button type="button" class="jarvis-sidebar-close" data-jarvis-close aria-label="Close navigation">✕ Close</button>
        <label class="sr-only" for="jarvis-nav-search">Search navigation</label><input id="jarvis-nav-search" class="jarvis-nav-search" type="search" placeholder="⌕  Search menus..." autocomplete="off" aria-controls="jarvis-sidebar-nav"><p class="jarvis-nav-empty" role="status">No matching menu</p>
        <nav id="jarvis-sidebar-nav" class="flex flex-wrap gap-x-4 gap-y-1 md:block md:space-y-1">
            <a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.dashboard') }}">Dashboard</a>
            <a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.assistant.index') }}">✨ AI assistant</a>
            @if ($me->can('users.view'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.users.index') }}">Users</a>@endif
            @if ($me->can('tenants.view'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.tenants.index') }}">Workspace</a>@endif
            @if ($me->can('roles.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.roles.index') }}">Roles and permissions</a>@endif
            @if ($me->can('plans.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.plans.index') }}">Plans</a>@endif
            @if ($me->can('features.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.features.index') }}">Features</a>@endif
            @if ($me->can('providers.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.providers.index') }}">AI Provider</a><a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.models.index') }}">AI Models</a><a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.routing.index') }}">Routing</a>@endif
            @if ($me->can('providers.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.chat.index') }}">Chat</a>@endif
            @if ($me->can('providers.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.channels.index') }}">Chat channels (Telegram / WhatsApp)</a>@endif
            @if ($me->can('credits.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.credits.index') }}">Credits</a>@endif
            @if ($me->can('billing.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.billing.index') }}">Billing and revenue</a>@endif
            @if ($me->can('flags.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.flags.index') }}">Feature flags</a>@endif
            @if ($me->can('licenses.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.licenses.index') }}">License</a>@endif
            @if ($me->can('brands.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.brands.index') }}">White-label</a>@endif
            @if ($me->can('resellers.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.resellers.index') }}">Reseller</a>@endif
            @if ($me->can('plugins.review'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.plugins.index') }}">Plugin review</a>@endif
            @if ($me->can('users.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.support.index') }}">Support tickets</a>@endif
            @if ($me->can('plans.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.trials.index') }}">Free trials</a>@endif
            @if ($me->can('tenants.view'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.usage.index') }}">Usage monitoring</a>@endif
            @if ($me->can('settings.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.broadcasts.index') }}">Announcements</a>@endif
            @if ($me->can('settings.manage'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.settings.index') }}">Settings</a>@endif
            @if ($me->can('audit.view'))<a class="block rounded px-2 py-1 hover:bg-gray-800" href="{{ route('admin.audit.index') }}">Audit log</a>@endif
            <a class="block rounded px-2 py-1 text-gray-400 hover:bg-gray-800" href="{{ route('dashboard') }}">← Back to app</a>
        </nav>
    </aside>
    <div class="min-w-0 flex-1">
        <header class="jarvis-topbar flex flex-wrap items-center justify-between gap-3 border-b bg-white px-4 py-3">
<button type="button" class="jarvis-mobile-toggle" data-jarvis-toggle aria-expanded="false" aria-controls="jarvis-sidebar">☰ Menu</button>
            <form method="GET" action="{{ route('admin.search') }}" class="flex-1 sm:max-w-sm">
                <input name="q" value="{{ request('q') }}" placeholder="Search users or workspaces…" class="w-full rounded border border-gray-300 px-3 py-1.5 text-sm" aria-label="Search">
            </form>
            <div class="flex items-center gap-3 text-sm">
                <span class="text-gray-600">{{ $me->email }}</span>
                <form method="POST" action="{{ url('/logout') }}">@csrf<x-button variant="ghost">Log out</x-button></form>
            </div>
        </header>
        <main class="jarvis-main space-y-4 p-4">
<div class="jarvis-page-heading"><div><small>JARVIS ONE / Administration</small><h1>{{ $title ?? 'Admin' }}</h1><p>Platform management and operational controls.</p></div><a class="jarvis-home" href="{{ route('admin.dashboard') }}">⌂ Admin overview</a></div>
            @if (count($crumbs))
                <nav class="text-sm text-gray-500" aria-label="Breadcrumb">
                    <a href="{{ route('admin.dashboard') }}" class="hover:underline">Admin</a>
                    @foreach ($crumbs as $label => $url)
                        <span> / </span>
                        @if ($url)<a href="{{ $url }}" class="hover:underline">{{ $label }}</a>@else<span class="text-gray-800">{{ $label }}</span>@endif
                    @endforeach
                </nav>
            @endif
            @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
            @if ($errors->any())
                <div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            {{ $slot }}
        </main>
    </div>
</div>
<script>
(function(){
 const root=document.body;
 const toggle=document.querySelector('[data-jarvis-toggle]');
 const close=()=>{root.classList.remove('jarvis-menu-open');toggle?.setAttribute('aria-expanded','false');};
 toggle?.addEventListener('click',()=>{const active=root.classList.toggle('jarvis-menu-open');toggle.setAttribute('aria-expanded',String(active));});
 document.querySelectorAll('[data-jarvis-close]').forEach(el=>el.addEventListener('click',close));
 document.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
 document.querySelectorAll('.jarvis-sidebar nav a').forEach(a=>{
  if(a.getAttribute('href') && a.href.split('#')[0]===window.location.href.split('#')[0])a.setAttribute('aria-current','page');
  a.addEventListener('click',()=>{if(window.innerWidth<1024)close();});
 });
})();
</script>
<script>
(function(){
 const search=document.getElementById('jarvis-nav-search');const nav=document.getElementById('jarvis-sidebar-nav');if(!search||!nav)return;
 const links=[...nav.querySelectorAll('a')];const labels=[...nav.querySelectorAll('p')];const empty=document.querySelector('.jarvis-nav-empty');
 search.addEventListener('input',()=>{const term=search.value.trim().toLocaleLowerCase();let count=0;
 links.forEach(a=>{const match=!term||a.textContent.toLocaleLowerCase().includes(term);a.hidden=!match;if(match)count++});
 labels.forEach(p=>{let node=p.nextElementSibling,has=false;while(node&&node.tagName!=='P'){if(node.tagName==='A'&&!node.hidden)has=true;node=node.nextElementSibling}p.hidden=!!term&&!has});
 if(empty)empty.classList.toggle('is-visible',count===0);
 });
 document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'&&!e.shiftKey){e.preventDefault();search.focus();search.select()}if(e.key==='Escape'&&document.activeElement===search){search.value='';search.dispatchEvent(new Event('input'));search.blur()}});
})();
</script>
</body>
</html>
