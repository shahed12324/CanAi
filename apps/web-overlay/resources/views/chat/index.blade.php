<x-layouts.app title="AI Chat">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="chat" aria-label="AI Chat">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> CHAT</div>
        <h1>AI Chat</h1><p>Choose a model, explore conversations and continue your work.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Chat Workspace</span>
    </header>

    @php($fmt = fn ($v) => \App\Credits\CreditMath::format($v))
    <div id="chat" class="grid gap-4 md:grid-cols-[16rem_1fr]"
         data-send-url="{{ route('chat.send') }}"
         data-show-url="{{ url('/app/chat') }}"
         data-csrf="{{ csrf_token() }}"
         data-conversation="{{ $current->id ?? '' }}"
         data-max-files="{{ $maxFiles }}">
        <aside class="space-y-2">
            <a href="{{ route('chat.index') }}" class="block rounded-md bg-indigo-600 px-3 py-2 text-center text-sm font-medium text-white hover:bg-indigo-700">+ New chat</a>
            <p class="text-xs text-gray-500">Credits: <strong id="chat-balance">{{ $fmt($wallet?->available ?? 0) }}</strong></p>
            <nav id="chat-list" class="max-h-[60vh] space-y-1 overflow-y-auto" aria-label="Chat list">
                @foreach ($conversations as $c)
                    <a href="{{ route('chat.show', $c->id) }}" class="block truncate rounded px-2 py-1.5 text-sm {{ ($current->id ?? null) === $c->id ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-gray-700 hover:bg-gray-100' }}">@if (($c->mode ?? 'general') === 'code')<span class="mr-1 rounded bg-gray-200 px-1 text-xs" title="Coding assistant">&lt;/&gt;</span>@endif{{ $c->title }}</a>
                @endforeach
            </nav>
        </aside>

        <section class="flex min-h-[70vh] flex-col rounded-lg bg-white shadow">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2">
                <h1 id="chat-title" class="truncate text-sm font-medium">{{ $current->title ?? 'New chat' }}</h1>
                <div class="flex items-center gap-2">
                    <label for="chat-mode" class="sr-only">Mode</label>
                    <select id="chat-mode" class="rounded border border-gray-300 px-2 py-1 text-sm" @disabled($current !== null) title="The mode is set when the chat starts">
                        <option value="general" @selected(($current->mode ?? 'general') === 'general')>General</option>
                        <option value="code" @selected(($current->mode ?? 'general') === 'code')>Coding assistant</option>
                    </select>
                    @if (count($characters) > 0 || ($current->character_id ?? null))
                        <label for="chat-character" class="sr-only">Characters</label>
                        <select id="chat-character" class="rounded border border-gray-300 px-2 py-1 text-sm" @disabled($current !== null)>
                            <option value="">Without character</option>
                            @foreach ($characters as $ch)<option value="{{ $ch['id'] }}" @selected(trim((string) ($current->character_id ?? '')) === $ch['id'])>{{ $ch['name'] }}</option>@endforeach
                        </select>
                    @endif
                    <label for="chat-model" class="sr-only">Model</label>
                    <select id="chat-model" class="rounded border border-gray-300 px-2 py-1 text-sm">
                        <option value="">Automatic (best pick)</option>
                        @foreach ($models as $m)
                            @if (in_array('text', $m['capabilities'], true))
                                <option value="{{ $m['id'] }}" @selected(($current->model_id ?? null) === $m['id'])>{{ $m['name'] }}@if ($m['credit_multiplier'] !== null) (×{{ rtrim(rtrim(number_format($m['credit_multiplier'], 2), '0'), '.') }})@endif</option>
                            @endif
                        @endforeach
                    </select>
                    @if ($current)
                        <form method="POST" action="{{ route('chat.destroy', $current->id) }}" onsubmit="return confirm('Delete the chat?')">@csrf @method('DELETE')<x-button variant="ghost">Delete</x-button></form>
                    @endif
                </div>
            </header>

            <div id="chat-thread" class="flex-1 space-y-4 overflow-y-auto px-4 py-4" aria-live="polite">
                @forelse ($messages as $m)
                    <div class="msg {{ $m->role === 'user' ? 'flex justify-end' : '' }}" data-id="{{ $m->id }}" data-role="{{ $m->role }}">
                        <div class="max-w-[85%] rounded-lg px-3 py-2 text-sm {{ $m->role === 'user' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-900' }}">
                            <div class="md break-words" data-md="{{ $m->content }}"></div>
                            @if ($m->role === 'assistant')
                                @if (in_array($m->status, ['error', 'partial'], true))
                                    <p class="mt-1 text-xs text-red-600">{{ $errors_text[$m->error_code] ?? 'The reply is incomplete.' }}</p>
                                @endif
                                @php($src = $sources[trim((string) $m->id)] ?? [])
                                @if ($src !== [])
                                    <details class="mt-2 text-xs text-gray-600"><summary class="cursor-pointer select-none">Sources ({{ count($src) }}) — Knowledge base</summary>
                                        @foreach ($src as $s)
                                            <p class="mt-1"><b>[{{ $s['n'] }}]</b> {{ $s['title'] }} · Part {{ $s['seq'] }}@if (! $s['cited']) <span class="text-gray-400">(not mentioned in the answer)</span>@endif<br><span class="text-gray-500">{{ $s['excerpt'] }}</span></p>
                                        @endforeach
                                    </details>
                                @endif
                                <div class="mt-1 flex items-center gap-3 text-xs text-gray-500">
                                    <button type="button" class="copy hover:text-gray-800">Copy</button>
                                    @if ((float) $m->credits_charged > 0)<span>{{ $fmt($m->credits_charged) }} Credits</span>@endif
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p id="chat-empty" class="py-16 text-center text-sm text-gray-500">Ask anything. Credits are deducted for the answer; the amount is shown below each answer.</p>
                @endforelse
            </div>

            <form id="chat-form" class="border-t p-3" autocomplete="off">
                <p id="chat-error" class="mb-2 hidden rounded bg-red-50 p-2 text-sm text-red-700" role="alert"></p>
                <label class="mb-2 inline-flex items-center gap-1 text-xs text-gray-600" title="When on, relevant parts of company documents are found and answers come with sources"><input id="chat-kb" type="checkbox" checked> Answer from knowledge base (with sources)</label>
                <div id="chat-files" class="mb-2 flex flex-wrap gap-1 text-xs"></div>
                <div class="flex items-end gap-2">
                    <label class="cursor-pointer rounded border border-gray-300 px-2 py-2 text-sm text-gray-600 hover:bg-gray-50" title="Attach a text file or image (AI reads the image — costs credits)">📎
                        <input id="chat-attach" type="file" class="hidden" multiple accept="{{ $accept }}">
                    </label>
                    <textarea id="chat-input" rows="2" maxlength="20000" required placeholder="Type your question… (Enter = send, Shift+Enter = new line)" class="min-h-[2.5rem] flex-1 resize-y rounded-md border border-gray-300 px-3 py-2 text-sm"></textarea>
                    <button id="chat-stop" type="button" class="hidden rounded-md border border-red-300 px-3 py-2 text-sm text-red-700 hover:bg-red-50">Stop</button>
                    <button id="chat-regen" type="button" class="{{ $messages->isNotEmpty() && $messages->last()->role === 'assistant' ? '' : 'hidden' }} rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Regenerate</button>
                    <button id="chat-send" type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Send</button>
                </div>
            </form>
        </section>
    </div>
    <script src="{{ asset('js/chat.js') }}" defer></script>
</x-layouts.app>
