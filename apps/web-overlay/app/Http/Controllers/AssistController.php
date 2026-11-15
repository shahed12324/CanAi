<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Assist\AssistPrompt;
use App\Assist\AssistService;
use App\Assist\AssistText;
use App\Productivity\TaskInput;
use App\Productivity\TaskRepo;
use App\Tenancy\TenantContext;
use App\Voice\AudioInput;
use App\Voice\VoiceService;
use App\Voice\VoiceText;
use DateTimeImmutable;
use Illuminate\Http\Request;

/** The writing assistant: paste a meeting transcript or an email, get minutes / a summary / a draft. Nothing is stored or sent; action items can become tasks. */
final class AssistController extends Controller
{
    public function __construct(private readonly AssistService $service, private readonly TaskRepo $tasks, private readonly TenantContext $tenant, private readonly VoiceService $voice) {}

    private static function tz(Request $r): string
    {
        return (string) ($r->user()->timezone ?: 'Asia/Dhaka');
    }

    public function index()
    {
        return view('assist.index', ['modes' => AssistPrompt::MODES, 'tones' => AssistPrompt::TONES, 'max' => AssistPrompt::MAX]);
    }

    public function run(Request $request)
    {
        $d = $request->validate(['mode' => ['required', 'in:meeting,summary,draft'], 'text' => ['nullable', 'string', 'max:30000'], 'points' => ['nullable', 'string', 'max:5000'], 'tone' => ['nullable', 'in:formal,friendly,short'], 'audio' => ['nullable', 'file', 'max:5200'], 'lang' => ['nullable', 'string', 'max:3']]);
        $today = (new DateTimeImmutable('now', TaskInput::zone(self::tz($request))))->format('Y-m-d');
        $text = (string) ($d['text'] ?? '');
        $paid = '';
        $f = $request->file('audio');
        if ($f !== null && $d['mode'] === 'meeting') {
            // A meeting recording: turned into text first (charged by size), then minuted like pasted text.  The recording itself is not kept.
            $bytes = $f->isValid() && $f->getSize() <= AudioInput::MAX_BYTES ? (string) file_get_contents($f->getRealPath()) : '';
            $l = $this->voice->listen($this->tenant->idOrFail(), (string) $request->user()->id, $bytes, $d['lang'] ?? null, CreateWorkspace::newId());
            if ($l['status'] !== 'ok') {
                return back()->withErrors(['assist' => VoiceText::message($l['status'])])->withInput();
            }
            $text = (string) $l['text'];
            $paid = ' (Text from the recording: '.$l['credits'].' credits)';
        }
        $r = $this->service->run($this->tenant->idOrFail(), (string) $request->user()->id, $d['mode'], $text, (string) ($d['points'] ?? ''), $d['tone'] ?? 'formal', $today, CreateWorkspace::newId());
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['assist' => AssistText::run($r['status'])])->withInput();
        }

        return back()->withInput()->with('assist', ['mode' => $d['mode'], 'result' => $r['result'], 'heard' => $paid === '' ? null : $text, 'note' => $paid]);
    }

    public function addTasks(Request $request)
    {
        $d = $request->validate(['items' => ['required', 'array', 'min:1', 'max:20'], 'items.*.task' => ['required', 'string', 'max:200'], 'items.*.due' => ['nullable', 'string', 'max:10'], 'items.*.add' => ['nullable', 'in:1']]);
        $tz = self::tz($request);
        $uid = (string) $request->user()->id;
        $done = 0;
        $failed = 0;
        foreach ($d['items'] as $it) {
            if (($it['add'] ?? null) !== '1') {
                continue;
            }
            $in = TaskInput::parse(['title' => $it['task'], 'due_on' => $it['due'] ?? ''], $tz);
            if ($in['error'] !== null || $this->tasks->add($this->tenant->idOrFail(), $uid, CreateWorkspace::newId(), $in['title'], '', 2, $in['due'], null) !== 'ok') {
                $failed++;
            } else {
                $done++;
            }
        }
        if ($done === 0 && $failed === 0) {
            return back()->withErrors(['assist' => 'No item was ticked.']);
        }

        return back()->with('status', "{$done} tasks were added".($failed > 0 ? " ({$failed} were not added — limit or data was not valid)" : '').'. "Task" See on the page.');
    }
}
