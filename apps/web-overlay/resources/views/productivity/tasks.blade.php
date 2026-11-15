<x-layouts.app title="Tasks and daily plan">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="productivity" aria-label="Tasks and daily plan">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> PRODUCTIVITY</div>
        <h1>Tasks and daily plan</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Productivity Workspace</span>
    </header>

    @php
        $prio = [1 => 'Urgent', 2 => 'General', 3 => 'Later'];
        $tzo = fn (?string $s) => \App\Productivity\TaskInput::toLocalInput($s, $tz);
        $label = ['overdue' => 'Overdue', 'today' => 'Today’s', 'reminders' => 'Reminder today', 'soon' => 'In the next 3 days', 'anytime' => 'No date, but urgent'];
    @endphp
    <h2 class="text-xl font-semibold">Tasks and daily plan</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">These are only yours — no one else in your workspace can see them. When a reminder is due, a notification goes to the app and (if you have the mobile app) your phone. Time is taken in your timezone: <b>{{ $tz }}</b>. No credits are spent here.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <section class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm"><p class="font-semibold text-indigo-900">Today’s plan — {{ $today }}</p>
        @if ($plan['focus'] === [])<p class="mt-2 text-gray-600">Nothing urgent today. Add a new task, or rest.</p>
        @else
        <p class="mt-1 text-xs text-gray-600">Do these first (max {{ \App\Productivity\DailyPlan::FOCUS }}):</p>
        <ol class="mt-1 list-decimal space-y-1 pl-5">@foreach ($plan['focus'] as $t)<li><b>{{ $t['title'] }}</b> <span class="text-xs text-gray-500">({{ $prio[$t['priority']] }}@if ($t['due_on']), Due date {{ $t['due_on'] }}@endif)</span></li>@endforeach</ol>
        @endif
        @foreach (['overdue', 'today', 'reminders', 'soon', 'anytime'] as $g)@if ($plan[$g] !== [])<p class="mt-2 text-xs"><b>{{ $label[$g] }}:</b> {{ count($plan[$g]) }} items</p>@endif @endforeach</section>

    <form method="POST" action="{{ route('tasks.store') }}" class="mt-3 grid gap-2 rounded-lg bg-white p-4 text-sm shadow sm:grid-cols-4">@csrf
        <label class="sm:col-span-4">New task<input name="title" maxlength="200" required value="{{ old('title') }}" placeholder="For example: Monday send money to supplier" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label class="sm:col-span-4">Description (optional)<textarea name="details" maxlength="2000" rows="2" class="mt-1 w-full rounded border px-2 py-1">{{ old('details') }}</textarea></label>
        <label>Importance<select name="priority" class="mt-1 w-full rounded border px-2 py-1">@foreach ($prio as $k => $v)<option value="{{ $k }}" @selected((string) old('priority', '2') === (string) $k)>{{ $v }}</option>@endforeach</select></label>
        <label>Due date<input type="date" name="due_on" value="{{ old('due_on') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Reminder<input type="datetime-local" name="remind_at" value="{{ old('remind_at') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <div class="self-end"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add</button></div></form>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><p class="font-semibold">Ongoing ({{ count($open) }})</p>
        @forelse ($open as $t)
        <details class="mt-2 rounded border {{ $t['due_on'] !== null && $t['due_on'] < $today ? 'border-red-300 bg-red-50' : 'border-gray-200' }}"><summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 p-2">
            <span>{{ $t['priority'] === 1 ? '🔴 ' : '' }}<b>{{ $t['title'] }}</b> <span class="text-xs text-gray-500">{{ $prio[$t['priority']] }}@if ($t['due_on']) · End {{ $t['due_on'] }}@endif @if ($t['remind_at']) · ⏰ {{ $tzo($t['remind_at']) }}{{ $t['reminded'] ? ' (Notified)' : '' }}@endif</span></span>
            <form method="POST" action="{{ route('tasks.done', $t['id']) }}">@csrf<input type="hidden" name="done" value="1"><button class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-800">✔ Done</button></form></summary>
            <form method="POST" action="{{ route('tasks.update', $t['id']) }}" class="grid gap-2 border-t p-2 sm:grid-cols-4">@csrf
                <label class="sm:col-span-4">Title<input name="title" maxlength="200" required value="{{ $t['title'] }}" class="mt-1 w-full rounded border px-2 py-1"></label>
                <label class="sm:col-span-4">Description<textarea name="details" maxlength="2000" rows="2" class="mt-1 w-full rounded border px-2 py-1">{{ $t['details'] }}</textarea></label>
                <label>Importance<select name="priority" class="mt-1 w-full rounded border px-2 py-1">@foreach ($prio as $k => $v)<option value="{{ $k }}" @selected($t['priority'] === $k)>{{ $v }}</option>@endforeach</select></label>
                <label>Due date<input type="date" name="due_on" value="{{ $t['due_on'] }}" class="mt-1 w-full rounded border px-2 py-1"></label>
                <label>Reminder<input type="datetime-local" name="remind_at" value="{{ $tzo($t['remind_at']) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
                <div class="self-end"><button class="rounded bg-gray-100 px-3 py-1">Save</button></div></form>
            <form method="POST" action="{{ route('tasks.destroy', $t['id']) }}" class="border-t p-2" onsubmit="return confirm('Delete?')">@csrf<button class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">Delete</button></form></details>
        @empty<p class="mt-2 text-gray-500">No ongoing tasks.</p>@endforelse</section>

    @if ($done !== [])
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><div class="flex items-center justify-between"><p class="font-semibold">Finished (recent {{ count($done) }})</p>
        <form method="POST" action="{{ route('tasks.clear') }}" onsubmit="return confirm('All finished tasks will be deleted. Sure?')">@csrf<button class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">Delete finished ones</button></form></div>
        @foreach ($done as $t)<div class="mt-1 flex items-center justify-between gap-2 text-gray-500"><span class="line-through">{{ $t['title'] }}</span>
            <form method="POST" action="{{ route('tasks.done', $t['id']) }}">@csrf<input type="hidden" name="done" value="0"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">Reopen</button></form></div>@endforeach</section>
    @endif
</x-layouts.app>
