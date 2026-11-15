<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Messaging\ChatBridge;
use App\Skills\SkillAuthor;
use App\Skills\SkillStore;
use App\Skills\SkillText;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Skills: reusable procedures. Made by hand or drafted by the AI from a task that worked (always shown to you first), used as "/name your request"
 * in the chat, Telegram/WhatsApp and scheduled jobs, and improved from your feedback (a suggestion waits until you approve it). Personal to the owner.
 */
final class SkillController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly SkillStore $store, private readonly SkillAuthor $author, private readonly ChatBridge $chat) {}

    private function t(): string
    {
        return $this->tenant->idOrFail();
    }

    private function u(Request $r): string
    {
        return (string) $r->user()->getKey();
    }

    private function skill(Request $r, string $id): object
    {
        return $this->store->find($this->t(), $this->u($r), $id) ?? abort(404);
    }

    public function index(Request $request)
    {
        $skills = $this->store->mine($this->t(), $this->u($request))->orderBy('status')->orderByDesc('updated_at')->get();
        $convs = DB::table('conversations')->where('tenant_id', $this->t())->where('user_id', $this->u($request))->whereNull('deleted_at')->orderByDesc('last_message_at')->limit(15)->get(['id', 'title']);

        return view('skills.index', ['skills' => $skills, 'convs' => $convs, 'max' => SkillStore::MAX_SKILLS, 'draft' => $request->session()->get('skill_draft')]);
    }

    /** Drafts a skill with the AI from one of your chats or from text you paste. Nothing is saved yet. */
    public function learn(Request $request)
    {
        $d = $request->validate(['conversation_id' => ['nullable', 'string', 'size:26'], 'source' => ['nullable', 'string', 'max:8000']]);
        $text = trim((string) ($d['source'] ?? ''));
        if (($d['conversation_id'] ?? '') !== '') {
            $c = DB::table('conversations')->where('id', $d['conversation_id'])->where('tenant_id', $this->t())->where('user_id', $this->u($request))->whereNull('deleted_at')->first(['id', 'title']);
            abort_if($c === null, 404);
            $msgs = DB::table('messages')->where('conversation_id', $c->id)->where('tenant_id', $this->t())->where('status', 'complete')->orderBy('seq')->limit(40)->get(['role', 'content']);
            $text = $msgs->map(fn ($m) => ($m->role === 'user' ? 'USER: ' : 'ASSISTANT: ').Str::limit($m->content, 1500, '…'))->implode("\n\n");
        }
        if (mb_strlen($text) < 20) {
            return back()->withErrors(['source' => 'Pick a chat or describe the task (at least a sentence).'])->withInput();
        }
        $r = $this->author->learn($this->t(), $this->u($request), $text);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['source' => SkillAuthor::MESSAGES[$r['status']] ?? 'Write a little more about the task.'])->withInput();
        }
        $request->session()->put('skill_draft', $r['draft'] + ['source' => 'learned']);

        return redirect()->route('skills.index')->with('status', 'Here is the draft. Read it, change what you want, then save. Nothing is saved until you press Save.');
    }

    public function store(Request $request)
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:'.SkillText::MAX_NAME], 'description' => ['nullable', 'string', 'max:'.SkillText::MAX_DESC], 'instructions' => ['required', 'string', 'min:20', 'max:'.SkillText::MAX_INSTR], 'source' => ['nullable', 'in:manual,learned']]);
        $r = $this->store->create($this->t(), $this->u($request), ['name' => SkillText::clean($d['name'], 80), 'description' => SkillText::clean((string) ($d['description'] ?? ''), 300), 'instructions' => SkillText::clean($d['instructions'], 4000)], $d['source'] ?? 'manual');
        if (is_array($r)) {
            return back()->withErrors(['name' => $r['error']])->withInput();
        }
        $request->session()->forget('skill_draft');

        return redirect()->route('skills.show', $r)->with('status', 'Saved. Use it by typing “/'.SkillText::slug($d['name']).'” before your request.');
    }

    public function show(Request $request, string $id)
    {
        $s = $this->skill($request, $id);
        $versions = DB::table('skill_versions')->where('skill_id', $id)->orderByDesc('version')->limit(15)->get();
        $fb = DB::table('skill_feedback')->where('skill_id', $id)->orderByDesc('created_at')->limit(8)->get();

        return view('skills.show', ['s' => $s, 'versions' => $versions, 'feedback' => $fb, 'answer' => $request->session()->get('skill_answer_'.$id)]);
    }

    public function update(Request $request, string $id)
    {
        $s = $this->skill($request, $id);
        $d = $request->validate(['name' => ['required', 'string', 'max:'.SkillText::MAX_NAME], 'description' => ['nullable', 'string', 'max:'.SkillText::MAX_DESC], 'instructions' => ['required', 'string', 'min:20', 'max:'.SkillText::MAX_INSTR]]);
        $this->store->revise($s, ['name' => SkillText::clean($d['name'], 80), 'description' => SkillText::clean((string) ($d['description'] ?? ''), 300), 'instructions' => SkillText::clean($d['instructions'], 4000)], 'Edited by you');

        return redirect()->route('skills.show', $id)->with('status', 'Saved as a new version.');
    }

    /** Runs the skill now as a normal paid chat turn ("/slug input"). */
    public function run(Request $request, string $id)
    {
        $s = $this->skill($request, $id);
        abort_unless($s->status === 'active', 404);
        $input = trim((string) $request->validate(['input' => ['nullable', 'string', 'max:1500']])['input'] ?? '');
        $out = $this->chat->ask($this->t(), $this->u($request), null, '/'.$s->slug.($input !== '' ? ' '.$input : ''), []);
        $request->session()->put('skill_answer_'.$id, $out['error'] === null ? ['ok' => true, 'text' => $out['text'], 'credits' => $out['credits']] : ['ok' => false, 'text' => $out['error']]);

        return redirect()->route('skills.show', $id);
    }

    public function feedback(Request $request, string $id)
    {
        $this->skill($request, $id);
        $d = $request->validate(['rating' => ['required', 'in:1,-1'], 'note' => ['nullable', 'string', 'max:500']]);
        $a = $request->session()->get('skill_answer_'.$id);
        DB::table('skill_feedback')->insert([
            'id' => CreateWorkspace::newId(), 'skill_id' => $id, 'tenant_id' => $this->t(), 'user_id' => $this->u($request), 'rating' => (int) $d['rating'],
            'note' => SkillText::clean((string) ($d['note'] ?? ''), 500) ?: null, 'context' => is_array($a) && $a['ok'] ? Str::limit($a['text'], 590, '') : null,
        ]);

        return back()->with('status', 'Thanks. Press “Suggest an improvement” when you have given some feedback.');
    }

    /** Asks the AI for a better version from your recent feedback. The suggestion waits for your approval. */
    public function improve(Request $request, string $id)
    {
        $s = $this->skill($request, $id);
        $fb = DB::table('skill_feedback')->where('skill_id', $id)->orderByDesc('created_at')->limit(8)->get(['rating', 'note', 'context'])->map(fn ($f) => ['rating' => (int) $f->rating, 'note' => $f->note, 'context' => $f->context])->all();
        if (! array_filter($fb, fn ($f) => $f['rating'] < 0 || ($f['note'] ?? '') !== '')) {
            return back()->withErrors(['improve' => 'Give some feedback first (a 👎 or a note about what to change).']);
        }
        $r = $this->author->improve($this->t(), $this->u($request), ['name' => $s->name, 'description' => $s->description, 'instructions' => $s->instructions], $fb);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['improve' => SkillAuthor::MESSAGES[$r['status']] ?? 'Try again.']);
        }
        $this->store->propose($s, $r['draft'], 'Suggested from your feedback');

        return back()->with('status', 'A suggested version is below. It is not used until you approve it.');
    }

    public function decide(Request $request, string $id, string $version, string $action)
    {
        $s = $this->skill($request, $id);
        abort_unless(preg_match('/\A[0-9A-Za-z]{26}\z/', $version) === 1, 404);
        $ok = match ($action) {
            'approve' => $this->store->approve($s, $version),
            'restore' => $this->store->rollback($s, $version),
            default => ($this->store->reject($s, $version) ?? true),
        };

        return back()->with('status', $ok ? ($action === 'reject' ? 'Dismissed.' : 'Done. The skill now uses that version.') : 'That version is no longer available.');
    }

    public function archive(Request $request, string $id)
    {
        $this->skill($request, $id);
        $this->store->mine($this->t(), $this->u($request))->where('id', $id)->update(['status' => 'archived', 'updated_at' => now()]);

        return redirect()->route('skills.index')->with('status', 'Archived. Its name is free to use again.');
    }
}
