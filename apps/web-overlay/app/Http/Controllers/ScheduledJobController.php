<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Automation\JobRunner;
use App\Automation\NextRun;
use App\Automation\ScheduleParser;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Scheduled AI jobs: "every day at 9 send me a sales summary". Personal to the person who made them. Each run is a normal paid chat turn. */
final class ScheduledJobController extends Controller
{
    private const MAX_JOBS = 20;

    public function __construct(private readonly TenantContext $tenant, private readonly JobRunner $runner) {}

    private function mine(Request $r)
    {
        return DB::table('scheduled_jobs')->where('tenant_id', $this->tenant->idOrFail())->where('user_id', (string) $r->user()->getKey());
    }

    private function find(Request $r, string $id): object
    {
        abort_unless(preg_match('/\A[0-9A-Za-z]{26}\z/', $id) === 1, 404);

        return $this->mine($r)->where('id', $id)->first() ?? abort(404);
    }

    public function index(Request $request)
    {
        $jobs = $this->mine($request)->orderByDesc('created_at')->get()->map(function ($j) {
            $j->when = ScheduleParser::describe(json_decode($j->schedule, true));

            return $j;
        });

        return view('jobs.index', ['jobs' => $jobs, 'examples' => ScheduleParser::EXAMPLES, 'max' => self::MAX_JOBS, 'tz' => old('tz', 'Asia/Dhaka')]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'title' => ['nullable', 'string', 'max:120'], 'prompt' => ['required', 'string', 'max:1500'], 'when' => ['nullable', 'string', 'max:200'],
            'deliver' => ['required', 'in:inbox,channel,both'], 'continuity' => ['nullable', 'boolean'], 'tz' => ['required', 'string', 'timezone:all'],
        ]);
        if ($this->mine($request)->where('status', '!=', 'done')->count() >= self::MAX_JOBS) {
            return back()->withErrors(['prompt' => 'You already have '.self::MAX_JOBS.' scheduled jobs. Delete one first.'])->withInput();
        }
        $text = trim((string) ($d['when'] ?? '')) !== '' ? (string) $d['when'] : (string) $d['prompt'];
        $p = ScheduleParser::parse($text);
        if (isset($p['error'])) {
            return back()->withErrors(['when' => $p['error']])->withInput();
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $spec = NextRun::resolve($p['spec'], $d['tz'], $now);
        $next = NextRun::after($spec, $d['tz'], $now);
        if ($next === null) {
            return back()->withErrors(['when' => 'That time has already passed.'])->withInput();
        }
        $id = CreateWorkspace::newId();
        DB::table('scheduled_jobs')->insert([
            'id' => $id, 'tenant_id' => $this->tenant->idOrFail(), 'user_id' => (string) $request->user()->getKey(),
            'title' => trim((string) ($d['title'] ?? '')) !== '' ? trim($d['title']) : Str::limit(trim(preg_replace('/\s+/u', ' ', $d['prompt'])), 60, '…'),
            'prompt' => trim($d['prompt']), 'schedule' => json_encode($spec, JSON_THROW_ON_ERROR), 'schedule_text' => Str::limit($text, 190, ''), 'tz' => $d['tz'],
            'deliver' => $d['deliver'], 'continuity' => (bool) ($d['continuity'] ?? false), 'next_run_at' => $next->format('c'),
        ]);

        return redirect()->route('jobs.index')->with('status', ScheduleParser::describe($spec).' — next run '.$next->setTimezone(new DateTimeZone($d['tz']))->format('D d M, H:i').'.');
    }

    public function toggle(Request $request, string $id)
    {
        $j = $this->find($request, $id);
        if ($j->status === 'active') {
            $upd = ['status' => 'paused', 'updated_at' => now()];
        } else {
            $next = NextRun::after(json_decode($j->schedule, true), $j->tz, new DateTimeImmutable('now', new DateTimeZone('UTC')));
            if ($next === null) {
                return back()->withErrors(['jobs' => 'This one-time job is in the past; make a new one.']);
            }
            $upd = ['status' => 'active', 'next_run_at' => $next->format('c'), 'fail_streak' => 0, 'updated_at' => now()];
        }
        $this->mine($request)->where('id', $id)->update($upd);

        return back();
    }

    public function destroy(Request $request, string $id)
    {
        $this->find($request, $id);
        $this->mine($request)->where('id', $id)->delete();

        return redirect()->route('jobs.index')->with('status', 'Deleted.');
    }

    /** Runs the job once now (a real paid chat turn); the schedule is not changed. */
    public function runNow(Request $request, string $id)
    {
        $j = $this->find($request, $id);
        $r = $this->runner->execute($j);

        return redirect()->route('jobs.show', $id)->with('status', $r['status'] === 'ok' ? 'Done — the answer is below.' : 'The run failed; see the message below.');
    }

    public function show(Request $request, string $id)
    {
        $j = $this->find($request, $id);
        $j->when = ScheduleParser::describe(json_decode($j->schedule, true));
        $runs = DB::table('scheduled_job_runs')->where('job_id', $id)->where('tenant_id', $this->tenant->idOrFail())->orderByDesc('started_at')->limit(10)->get();

        return view('jobs.show', ['job' => $j, 'runs' => $runs]);
    }
}
