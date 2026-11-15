<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \App\Support\SiteMeta::title($title ?? null) }}</title>
    {!! \App\Support\SiteMeta::tags($title ?? null) !!}
    @if (! empty($navBrand['css']))<style>{!! $navBrand['css'] !!}</style>@endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('components.layouts.premium-theme')
</head>
<body class="jarvis-premium min-h-screen bg-gray-50 text-gray-900">
<div class="jarvis-overlay" data-jarvis-close aria-hidden="true"></div>
<div class="jarvis-shell flex min-h-screen flex-col lg:flex-row">
    <aside id="jarvis-sidebar" class="jarvis-sidebar bg-gradient-to-b from-slate-900 to-indigo-950 text-gray-200 lg:sticky lg:top-0 lg:h-screen lg:w-60 lg:shrink-0 lg:overflow-y-auto">
        <div class="flex items-center justify-between px-4 py-3">
            <a href="{{ route('dashboard') }}" class="jarvis-brand text-base font-semibold text-white">{{ ($navBrand['name'] ?? null) ?? \App\Support\SiteMeta::name() }}</a>
        </div>
        <button type="button" class="jarvis-sidebar-close" data-jarvis-close aria-label="Close navigation">✕ Close</button>
        <details class="group lg:open" open>
            <summary class="cursor-pointer px-4 pb-2 text-sm text-gray-400 lg:hidden">Menu</summary>
            <label class="sr-only" for="jarvis-nav-search">Search navigation</label><input id="jarvis-nav-search" class="jarvis-nav-search" type="search" placeholder="⌕  Search menus..." autocomplete="off" aria-controls="jarvis-sidebar-nav"><p class="jarvis-nav-empty" role="status">No matching menu</p>
        <nav id="jarvis-sidebar-nav" class="px-2 pb-4" aria-label="Main">
            @if (auth()->user()?->is_platform_admin)
                <p class="mb-1 mt-4 px-3 text-xs font-semibold uppercase tracking-wide text-amber-300">Super admin</p>
                @foreach ([['Admin dashboard', '/admin'], ['Providers', '/admin/providers'], ['AI Models', '/admin/models'], ['Plans (subscriptions)', '/admin/plans'], ['Features', '/admin/features'], ['Settings', '/admin/settings'], ['Users', '/admin/users']] as [$al, $au])
                    <a href="{{ url($au) }}" class="block rounded px-3 py-1.5 text-sm text-gray-300 hover:bg-white/10 hover:text-white">{{ $al }}</a>
                @endforeach
            @endif
            <p class="mb-1 mt-4 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Workspace</p>
            <a href="{{ route('dashboard') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('dashboard.*') || request()->routeIs('dashboard'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('dashboard.*') || request()->routeIs('dashboard'))])>Dashboard</a>
            <a href="{{ route('chat.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('chat.*') || request()->routeIs('chat.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('chat.*') || request()->routeIs('chat.index'))])>AI Chat</a>
            <a href="{{ route('memory.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('memory.*') || request()->routeIs('memory.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('memory.*') || request()->routeIs('memory.index'))])>Memory</a>
            <a href="{{ route('tasks.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('tasks.*') || request()->routeIs('tasks.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('tasks.*') || request()->routeIs('tasks.index'))])>Tasks</a>
            <a href="{{ route('journey.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('journey.*'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! request()->routeIs('journey.*')])>🧭 Journey</a>
            <a href="{{ route('skills.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('skills.*'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! request()->routeIs('skills.*')])>🧠 Skills</a>
            <a href="{{ route('jobs.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('jobs.*'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! request()->routeIs('jobs.*')])>⏰ Scheduled jobs</a>
            <a href="{{ route('notes.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('notes.*') || request()->routeIs('notes.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('notes.*') || request()->routeIs('notes.index'))])>Notes</a>
            <a href="{{ route('knowledge.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('knowledge.*') || request()->routeIs('knowledge.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('knowledge.*') || request()->routeIs('knowledge.index'))])>Knowledge Base</a>
            <p class="mb-1 mt-4 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Create</p>
            <a href="{{ route('office.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('office.*') || request()->routeIs('office.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('office.*') || request()->routeIs('office.index'))])>Documents</a>
            <a href="{{ route('assist.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('assist.*') || request()->routeIs('assist.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('assist.*') || request()->routeIs('assist.index'))])>Writing Assistant</a>
            <a href="{{ route('studio.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('studio.*') || request()->routeIs('studio.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('studio.*') || request()->routeIs('studio.index'))])>Design</a>
            <a href="{{ route('photoshop.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('photoshop.*'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! request()->routeIs('photoshop.*')])>✨ Photoshop AI agent</a>
            <a href="{{ route('tools.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('tools.*'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! request()->routeIs('tools.*')])>Doc & photo tools</a>
            <a href="{{ route('video.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('video.*') || request()->routeIs('video.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('video.*') || request()->routeIs('video.index'))])>Video</a>
            <a href="{{ route('voice.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('voice.*') || request()->routeIs('voice.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('voice.*') || request()->routeIs('voice.index'))])>Voice</a>
            <a href="{{ route('characters.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('characters.*') || request()->routeIs('characters.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('characters.*') || request()->routeIs('characters.index'))])>Characters</a>
            <a href="{{ route('agents.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('agents.*') || request()->routeIs('agents.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('agents.*') || request()->routeIs('agents.index'))])>Agents</a>
            <p class="mb-1 mt-4 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Marketing</p>
            @can('social.view')<a href="{{ route('social.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('social.*') || request()->routeIs('social.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('social.*') || request()->routeIs('social.index'))])>Social</a>@endcan
            @can('ads.view')<a href="{{ route('ads.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('ads.*') || request()->routeIs('ads.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('ads.*') || request()->routeIs('ads.index'))])>Ads</a>@endcan
            @can('ads.view')<a href="{{ route('gads.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('gads.*') || request()->routeIs('gads.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('gads.*') || request()->routeIs('gads.index'))])>Google Ads</a>@endcan
            @can('ads.view')<a href="{{ route('reports.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('reports.*') || request()->routeIs('reports.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('reports.*') || request()->routeIs('reports.index'))])>Reports</a>@endcan
            @can('ads.view')<a href="{{ route('seo.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('seo.*') || request()->routeIs('seo.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('seo.*') || request()->routeIs('seo.index'))])>SEO</a>@endcan
            <a href="{{ route('outreach.campaigns') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('outreach.*') || request()->routeIs('outreach.campaigns'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('outreach.*') || request()->routeIs('outreach.campaigns'))])>Outreach</a>
            <p class="mb-1 mt-4 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Business</p>
            <a href="{{ route('leads.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('leads.*') || request()->routeIs('leads.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('leads.*') || request()->routeIs('leads.index'))])>Leads</a>
            <a href="{{ route('proposals.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('proposals.*') || request()->routeIs('proposals.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('proposals.*') || request()->routeIs('proposals.index'))])>Proposals</a>
            @can('proposals.view')<a href="{{ route('clients.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('clients.*') || request()->routeIs('clients.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('clients.*') || request()->routeIs('clients.index'))])>Clients</a>@endcan
            <a href="{{ route('catalog.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('catalog.*') || request()->routeIs('catalog.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('catalog.*') || request()->routeIs('catalog.index'))])>Products</a>
            <a href="{{ route('inbox.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('inbox.*') || request()->routeIs('inbox.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('inbox.*') || request()->routeIs('inbox.index'))])>Inbox</a>
            <a href="{{ route('orders.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('orders.*') || request()->routeIs('orders.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('orders.*') || request()->routeIs('orders.index'))])>Orders</a>
            @can('accounting.view')<a href="{{ route('accounting.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('accounting.*') || request()->routeIs('accounting.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('accounting.*') || request()->routeIs('accounting.index'))])>Accounting</a>@endcan
            <p class="mb-1 mt-4 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Control</p>
            <a href="{{ route('pc.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('pc.*') || request()->routeIs('pc.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('pc.*') || request()->routeIs('pc.index'))])>My Computer</a>
            <a href="{{ route('agentbuilder.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('agentbuilder.*'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! request()->routeIs('agentbuilder.*')])>Agent Builder</a>
            <a href="{{ route('connect.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('connect.*') || request()->routeIs('connect.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('connect.*') || request()->routeIs('connect.index'))])>Connections</a>
            <a href="{{ route('plugins.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('plugins.*') || request()->routeIs('plugins.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('plugins.*') || request()->routeIs('plugins.index'))])>Plugins</a>
            <a href="{{ route('developer.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('developer.*') || request()->routeIs('developer.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('developer.*') || request()->routeIs('developer.index'))])>Developer</a>
            <a href="{{ route('reseller.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('reseller.*') || request()->routeIs('reseller.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('reseller.*') || request()->routeIs('reseller.index'))])>Reseller</a>
            @if (($navBrand ?? null) !== null)<a href="{{ route('brand.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('brand.*') || request()->routeIs('brand.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('brand.*') || request()->routeIs('brand.index'))])>Brand</a>@endif
            <a href="{{ route('telegram.show') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('telegram.*') || request()->routeIs('telegram.show'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('telegram.*') || request()->routeIs('telegram.show'))])>Telegram</a>
            <a href="{{ route('whatsapp.show') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('whatsapp.*') || request()->routeIs('whatsapp.show'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('whatsapp.*') || request()->routeIs('whatsapp.show'))])>WhatsApp</a>
            <a href="{{ route('mobile.show') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('mobile.*') || request()->routeIs('mobile.show'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('mobile.*') || request()->routeIs('mobile.show'))])>Mobile app</a>
            <a href="{{ route('shop.show') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('shop.*') || request()->routeIs('shop.show'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('shop.*') || request()->routeIs('shop.show'))])>Shop profile</a>
            <a href="{{ route('support.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('support.*') || request()->routeIs('support.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('support.*') || request()->routeIs('support.index'))])>Support</a>
            <p class="mb-1 mt-4 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Account</p>
            <a href="{{ route('members.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('members.*') || request()->routeIs('members.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('members.*') || request()->routeIs('members.index'))])>Members</a>
            <a href="{{ route('models.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('models.*') || request()->routeIs('models.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('models.*') || request()->routeIs('models.index'))])>AI Models</a>
            <a href="{{ route('credits.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('credits.*') || request()->routeIs('credits.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('credits.*') || request()->routeIs('credits.index'))])>Credits</a>
            <a href="{{ route('billing.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('billing.*') || request()->routeIs('billing.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('billing.*') || request()->routeIs('billing.index'))])>Billing</a>
            <a href="{{ route('license.index') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('license.*') || request()->routeIs('license.index'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('license.*') || request()->routeIs('license.index'))])>License</a>
            <a href="{{ route('profile.show') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('profile.*') || request()->routeIs('profile.show'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('profile.*') || request()->routeIs('profile.show'))])>Profile</a>
            <a href="{{ route('security.show') }}" @class(['block rounded px-3 py-1.5 text-sm', 'bg-indigo-500/30 text-white' => request()->routeIs('security.*') || request()->routeIs('security.show'), 'text-gray-300 hover:bg-white/10 hover:text-white' => ! (request()->routeIs('security.*') || request()->routeIs('security.show'))])>Security</a>
            {{-- Every page of every module, grouped. Collapsed by default; opens while you search the menu. --}}
            <p class="mb-1 mt-4 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">All pages</p>
            @php
                $allPages = [
                    'Accounting' => [['accounting.index', 'Overview'], ['accounting.create', 'New entry'], ['accounting.accounts', 'Chart of accounts'], ['accounting.ledger', 'Ledger'], ['accounting.trial', 'Trial balance'], ['accounting.parties', 'Customers & suppliers'], ['accounting.aging', 'Aging'], ['accounting.cash', 'Cash & bank'], ['accounting.bank', 'Bank reconcile'], ['accounting.report.pl', 'Profit & loss'], ['accounting.report.balance', 'Balance sheet'], ['accounting.report.cashflow', 'Cash flow'], ['accounting.vat', 'VAT'], ['accounting.vat.books', 'VAT books'], ['accounting.tax', 'Withholding tax'], ['accounting.cal', 'Tax calendar'], ['accounting.payroll', 'Payroll'], ['accounting.payroll.employees', 'Employees'], ['accounting.assets', 'Fixed assets'], ['accounting.inventory', 'Inventory'], ['accounting.billscan', 'Bill scan'], ['accounting.autopost', 'Auto-post'], ['accounting.analyst', 'AI analyst'], ['accounting.audit', 'Audit checks'], ['accounting.settings', 'Books lock']],
                    'Marketing' => [['social.index', 'Social posts'], ['social.create', 'New post'], ['social.plan', 'Content plan'], ['social.growth', 'Growth'], ['social.insights', 'Insights'], ['social.comments', 'Comments'], ['social.accounts', 'Connected pages'], ['ads.index', 'Meta ads'], ['ads.drafts', 'Ad drafts'], ['ads.draft.create', 'New ad draft'], ['gads.index', 'Google Ads'], ['gads.drafts', 'Google Ads drafts'], ['gads.draft.create', 'New Google Ads draft'], ['reports.index', 'Reports'], ['seo.index', 'SEO'], ['outreach.campaigns', 'Email campaigns'], ['outreach.accounts', 'Sending accounts'], ['outreach.queue', 'Send queue'], ['outreach.suppressions', 'Unsubscribed']],
                    'Leads' => [['leads.index', 'All leads'], ['leads.board', 'Board'], ['leads.tasks', 'Follow-ups'], ['leads.import', 'Import'], ['leads.research.index', 'Lead research']],
                    'Shop & orders' => [['orders.index', 'Orders'], ['orders.create', 'New order'], ['orders.report', 'Order report'], ['orders.settings', 'Order settings'], ['couriers.index', 'Couriers'], ['shipments.index', 'Shipments'], ['catalog.index', 'Products'], ['catalog.create', 'New product'], ['catalog.import', 'Import products'], ['catalog.stores', 'Connected stores'], ['inbox.index', 'Inbox'], ['inbox.channels', 'Inbox channels'], ['inbox.agent', 'Inbox AI agent']],
                    'Billing & credits' => [['credits.index', 'Credits'], ['credits.transactions', 'Credit history'], ['billing.index', 'Plan'], ['billing.credits', 'Buy credits'], ['billing.orders', 'Payments'], ['billing.invoices', 'Invoices'], ['billing.profile', 'Billing details']],
                ];
            @endphp
            @foreach ($allPages as $group => $pages)
                <details class="jarvis-sub" @if (collect($pages)->contains(fn ($p) => request()->routeIs(explode('.', $p[0])[0].'.*'))) open @endif>
                    <summary class="block cursor-pointer rounded px-3 py-1.5 text-sm text-gray-300 hover:bg-white/10 hover:text-white">{{ $group }}</summary>
                    <div class="ml-3 border-l border-white/10 pl-1">
                        @foreach ($pages as [$pr, $pl])
                            @if (\Illuminate\Support\Facades\Route::has($pr))<a href="{{ route($pr) }}" @class(['block rounded px-3 py-1 text-xs', 'bg-indigo-500/30 text-white' => request()->routeIs($pr), 'text-gray-400 hover:bg-white/10 hover:text-white' => ! request()->routeIs($pr)])>{{ $pl }}</a>@endif
                        @endforeach
                    </div>
                </details>
            @endforeach
            @php($extraMenu = \App\Support\SiteMeta::links('site.menu_links'))
            @if ($extraMenu !== [])
                <p class="mb-1 mt-4 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">More</p>
                @foreach ($extraMenu as $l)<a href="{{ $l['url'] }}" rel="noopener" class="block rounded px-3 py-1.5 text-sm text-gray-300 hover:bg-white/10 hover:text-white">{{ $l['label'] }}</a>@endforeach
            @endif
            </nav>
        </details>
    </aside>
    <div class="min-w-0 flex-1">
        <header class="jarvis-topbar flex flex-wrap items-center justify-end gap-3 border-b bg-white px-4 py-3 text-sm shadow-sm">
<button type="button" class="jarvis-mobile-toggle" data-jarvis-toggle aria-expanded="false" aria-controls="jarvis-sidebar">☰ Menu</button>
            @if (auth()->user()?->is_platform_admin)<a href="{{ url('/admin') }}" class="rounded bg-amber-500 px-3 py-1 font-medium text-white hover:bg-amber-400">Admin panel</a>@endif
            @php($navWorkspaces = $navWorkspaces ?? collect())
            @if ($navWorkspaces->count() > 0)
                <form method="POST" action="{{ route('workspaces.switch') }}" class="flex items-center gap-2">
                    @csrf
                    <select name="tenant_id" onchange="this.form.submit()" class="rounded border border-gray-300 px-2 py-1" aria-label="Workspace">
                        @foreach ($navWorkspaces as $w)
                            <option value="{{ $w->id }}" @selected($w->id === ($navCurrent ?? null))>{{ $w->name }}</option>
                        @endforeach
                    </select>
                    <noscript><x-button variant="ghost">Switch</x-button></noscript>
                </form>
            @endif
            <form method="POST" action="{{ url('/logout') }}">
                @csrf
                <x-button variant="ghost">Log out</x-button>
            </form>
        </header>
        <main class="jarvis-main mx-auto max-w-6xl space-y-4 px-4 py-6">
<div class="jarvis-page-heading"><div><small>JARVIS ONE / Workspace</small><h1>{{ $title ?? 'Workspace' }}</h1><p>Manage your work from one intelligent workspace.</p></div><a class="jarvis-home" href="{{ route('dashboard') }}">⌂ Overview</a></div>
            @if (session('status'))
                <p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <div class="rounded bg-red-50 p-3 text-sm text-red-700">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif
            {{ $slot }}
        </main>
        <footer class="jarvis-footer mx-auto max-w-5xl space-y-1 px-4 pb-6 text-xs text-gray-500">
            <p>@foreach (\App\Support\SiteMeta::links('site.footer_links') as $l)<a class="underline" href="{{ $l['url'] }}" rel="noopener">{{ $l['label'] }}</a> · @endforeach
            @if (! empty($navBrand['support_email']))Support: <a class="underline" href="mailto:{{ $navBrand['support_email'] }}">{{ $navBrand['support_email'] }}</a>@endif</p>
            <p>{{ \App\Support\SiteMeta::footerText() }}@if (empty($navBrand['hide_powered_by'])) · Powered by {{ \App\Support\SiteMeta::name() }}@endif</p>
        </footer>
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
<script>
(function(){var s=document.getElementById('jarvis-nav-search');if(!s)return;s.addEventListener('input',function(){document.querySelectorAll('#jarvis-sidebar-nav details.jarvis-sub').forEach(function(d){if(s.value.trim())d.open=true;});});})();
</script>
</body>
</html>
