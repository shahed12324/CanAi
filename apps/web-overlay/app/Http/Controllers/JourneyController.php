<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Journey\JourneyBuilder;
use App\Memory\MemoryRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Journey: one timeline of what the assistant has learned about you and been taught (memories, skills, scheduled jobs), what it is told in every chat,
 * and an export so your data is yours. Read-only: changes are made on the Memory, Skills and Scheduled jobs pages.
 */
final class JourneyController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly MemoryRepo $memories) {}

    public function index(Request $request)
    {
        $uid = (string) $request->user()->getKey();
        $t = $this->tenant->idOrFail();
        $d = $request->validate(['kind' => ['nullable', 'in:memory,skill,job'], 'q' => ['nullable', 'string', 'max:100']]);

        $items = [];
        foreach ($this->memories->list($uid) as $m) {
            $items[] = ['kind' => 'memory', 'at' => (string) $m['updated_at'], 'title' => $m['content'], 'url' => route('memory.index'),
                'detail' => ($m['scope'] === 'business' ? 'business · ' : '').$m['category'].($m['pinned'] ? ' · important' : '').(! $m['active'] ? ' · turned off' : '').($m['source'] === 'suggested' ? ' · suggested by AI, you approved' : '')];
        }
        $versions = DB::table('skill_versions as v')->join('skills as s', 's.id', '=', 'v.skill_id')->where('s.tenant_id', $t)->where('s.user_id', $uid)->whereIn('v.state', ['current', 'old', 'proposed'])->orderByDesc('v.created_at')->limit(200)
            ->get(['v.id', 'v.version', 'v.state', 'v.note', 'v.created_at', 's.id as skill_id', 's.slug', 's.name']);
        foreach ($versions as $v) {
            $items[] = ['kind' => 'skill', 'at' => (string) $v->created_at, 'title' => "Skill /{$v->slug} — version {$v->version}".($v->state === 'proposed' ? ' (waiting for your approval)' : ''), 'detail' => (string) $v->note, 'url' => route('skills.show', $v->skill_id)];
        }
        $jobs = DB::table('scheduled_jobs')->where('tenant_id', $t)->where('user_id', $uid)->orderByDesc('created_at')->limit(100)->get(['id', 'title', 'schedule_text', 'status', 'run_count', 'created_at', 'last_run_at']);
        foreach ($jobs as $j) {
            $items[] = ['kind' => 'job', 'at' => (string) $j->created_at, 'title' => 'Scheduled: '.$j->title, 'detail' => $j->schedule_text.' · '.$j->status, 'url' => route('jobs.show', $j->id)];
            if ($j->last_run_at !== null) {
                $items[] = ['kind' => 'job', 'at' => (string) $j->last_run_at, 'title' => 'Ran: '.$j->title, 'detail' => $j->run_count.' run(s) so far', 'url' => route('jobs.show', $j->id)];
            }
        }

        return view('journey.index', [
            'items' => JourneyBuilder::timeline($items, (string) ($d['kind'] ?? ''), (string) ($d['q'] ?? ''), 150), 'kinds' => JourneyBuilder::KINDS,
            'kind' => (string) ($d['kind'] ?? ''), 'q' => (string) ($d['q'] ?? ''), 'inChat' => $this->memories->forChat($uid, 50), 'useInChat' => $this->memories->useInChat($uid),
        ]);
    }

    public function export(Request $request, string $format)
    {
        abort_unless(in_array($format, ['md', 'json'], true), 404);
        $uid = (string) $request->user()->getKey();
        $mem = $this->memories->list($uid);
        $skills = DB::table('skills')->where('tenant_id', $this->tenant->idOrFail())->where('user_id', $uid)->where('status', 'active')->orderBy('name')->get(['slug', 'name', 'description', 'instructions', 'version'])->map(fn ($s) => (array) $s)->all();
        $when = now()->format('Y-m-d H:i');
        $body = $format === 'md' ? JourneyBuilder::markdown($mem, $skills, $when) : JourneyBuilder::json($mem, $skills, $when);

        return response($body, 200, [
            'Content-Type' => $format === 'md' ? 'text/markdown; charset=utf-8' : 'application/json', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store',
            'Content-Disposition' => 'attachment; filename="my-assistant-'.Str::slug($when, '-').'.'.$format.'"',
        ]);
    }
}
