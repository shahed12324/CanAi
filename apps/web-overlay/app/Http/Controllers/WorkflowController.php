<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\ModelCatalog;
use App\Companion\ModeRepo;
use App\Pc\PcRepo;
use App\Tenancy\TenantContext;
use App\Workflow\Risk;
use App\Workflow\Templates;
use App\Workflow\WorkflowCatalog;
use App\Workflow\ToolRegistry;
use App\Workflow\WorkflowDefinition;
use App\Workflow\WorkflowRepo;
use App\Workflow\WorkflowService;
use Illuminate\Http\Request;

/** Agents / workflows: templates by group, my workflows (search / filters / status), a detail page with the visual builder, test mode, history, approvals.  Everything is the signed-in person's own. */
final class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowService $service, private readonly WorkflowRepo $repo, private readonly ToolRegistry $tools, private readonly TenantContext $tenant, private readonly ModelCatalog $catalog, private readonly PcRepo $pc, private readonly ModeRepo $modes) {}

    private static function id(string $v): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $v) === 1, 404);

        return $v;
    }

    private function uid(Request $r): string
    {
        return (string) $r->user()->id;
    }

    private static function back(string $code, string $ok)
    {
        return $code === 'ok' ? back()->with('status', $ok) : back()->withErrors(['wf' => WorkflowService::message($code)]);
    }

    private static function qs(Request $r, string $k, array $allowed): string
    {
        $v = $r->query($k);

        return is_string($v) && in_array($v, $allowed, true) ? $v : '';
    }

    /** @return list<array<string, mixed>> text models the plan allows */
    private function models(): array
    {
        return array_values(array_filter($this->catalog->candidates($this->tenant->idOrFail()), fn ($m) => in_array('text', $m['capabilities'], true)));
    }

    public function index(Request $request)
    {
        $u = $this->uid($request);
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $tq = mb_substr(trim((string) $request->query('tq', '')), 0, 80);
        $group = self::qs($request, 'group', array_keys(Templates::GROUPS));
        $status = self::qs($request, 'status', array_keys(WorkflowCatalog::STATUSES));
        $tgroup = self::qs($request, 'tgroup', array_keys(Templates::GROUPS));
        $all = Templates::all();
        $runs = $this->repo->runs($u, 15);
        $open = $request->query('run');
        $steps = is_string($open) && preg_match('/\A[0-9a-z]{26}\z/', $open) === 1 ? $this->repo->steps($u, $open) : [];
        $cards = $this->service->cards($u, $q, $group, $status);

        return view('workflow.index', [
            'cards' => $cards, 'total' => count($this->repo->workflows($u)), 'runs' => $runs, 'pending' => $this->repo->pendingApprovals($u), 'openRun' => $steps === [] ? null : $open, 'steps' => $steps,
            'templates' => WorkflowCatalog::filterTemplates($all, $tq, $tgroup), 'templateCounts' => WorkflowCatalog::templateCounts($all), 'groups' => Templates::GROUPS, 'statuses' => WorkflowCatalog::STATUSES,
            'f' => ['q' => $q, 'group' => $group, 'status' => $status, 'tq' => $tq, 'tgroup' => $tgroup], 'categories' => WorkflowService::EDITABLE,
            'inputs' => array_column(array_map(fn ($c) => ['id' => $c['id'], 'in' => WorkflowDefinition::inputs($c['definition'])], $cards), 'in', 'id'),
        ]);
    }

    public function create(Request $request)
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:1000'], 'category' => ['required', 'string', 'max:20']]);
        $r = $this->service->createBlank($this->tenant->idOrFail(), $this->uid($request), $d['name'], (string) ($d['description'] ?? ''), $d['category']);

        return $r['status'] === 'ok' ? redirect()->route('agents.show', $r['id'])->with('status', 'Created. Build the steps below.') : back()->withErrors(['wf' => WorkflowService::message($r['status'])])->withInput();
    }

    public function show(Request $request, string $id)
    {
        $u = $this->uid($request);
        $t = $this->tenant->idOrFail();
        $w = $this->repo->workflow($u, self::id($id));
        abort_if($w === null, 404);
        $runs = $this->repo->runsFor($u, $id, 30);
        $open = $request->query('run');
        $steps = is_string($open) && preg_match('/\A[0-9a-z]{26}\z/', $open) === 1 ? $this->repo->steps($u, $open) : [];
        $last = null;
        foreach ($runs as $r) {
            if ($r['trigger'] !== 'test') {
                $last = $r['status'];

                break;
            }
        }
        $tools = [];
        foreach ($this->tools->all() as $k => $tool) {
            $tools[$k] = ['label' => $tool->label(), 'risk' => $tool->risk(), 'args' => array_map(fn ($a, $l) => ['name' => rtrim((string) $a, '?'), 'optional' => str_ends_with((string) $a, '?'), 'label' => $l], array_keys($tool->args()), array_values($tool->args())), 'desktop' => str_starts_with($k, 'pc.')];
        }
        $devices = $this->pc->devices($t, $u);

        return view('workflow.show', [
            'w' => $w, 'status' => WorkflowCatalog::status($w, $last), 'group' => WorkflowCatalog::groupOfWorkflow($w), 'groups' => Templates::GROUPS, 'categories' => WorkflowService::EDITABLE, 'allCategories' => WorkflowService::CATEGORIES,
            'inputs' => WorkflowDefinition::inputs($w['definition']), 'runs' => $runs, 'openRun' => $steps === [] ? null : $open, 'steps' => $steps, 'models' => $this->models(), 'toolSpecs' => $tools,
            'desktopTools' => WorkflowService::desktopTools($w['definition']), 'devices' => $devices, 'userMode' => $this->modes->get($t, $u)['mode'], 'templateName' => $w['template_key'] !== null ? (Templates::get($w['template_key'])['name'] ?? null) : null,
        ]);
    }

    public function rename(Request $request, string $id)
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:400'], 'description' => ['nullable', 'string', 'max:1000']]);

        return self::back($this->service->rename($this->tenant->idOrFail(), $this->uid($request), self::id($id), $d['name'], (string) ($d['description'] ?? '')), 'Renamed.');
    }

    public function settings(Request $request, string $id)
    {
        $d = $request->validate(['category' => ['required', 'string', 'max:20'], 'schedule' => ['nullable', 'string', 'max:5'], 'max_credits' => ['nullable', 'string', 'max:10'], 'model_id' => ['nullable', 'string', 'max:40']]);
        $allowed = array_map(fn ($m) => (string) $m['id'], $this->models());
        $r = $this->service->updateSettings($this->tenant->idOrFail(), $this->uid($request), self::id($id), $d['category'], (string) ($d['schedule'] ?? ''), (string) ($d['max_credits'] ?? ''), (string) ($d['model_id'] ?? ''), $allowed);

        return self::back($r, 'Settings saved.');
    }

    public function steps(Request $request, string $id)
    {
        $d = $request->validate(['definition' => ['required', 'string', 'max:100000']]);
        $r = $this->service->saveSteps($this->tenant->idOrFail(), $this->uid($request), self::id($id), $d['definition']);
        if ($r['status'] === 'invalid') {
            return back()->withErrors(['wf' => implode(' | ', array_slice($r['errors'], 0, 5))]);
        }

        return self::back($r['status'], 'Steps saved.');
    }

    public function state(Request $request, string $id)
    {
        $d = $request->validate(['action' => ['required', 'in:pause,resume,enable,disable']]);
        $t = $this->tenant->idOrFail();
        $u = $this->uid($request);
        $id = self::id($id);
        $r = match ($d['action']) {
            'pause' => $this->service->pause($t, $u, $id), 'resume' => $this->service->resume($t, $u, $id), 'enable' => $this->service->enable($t, $u, $id), default => $this->service->disable($t, $u, $id),
        };

        return self::back($r, ['pause' => 'Paused: the daily schedule is suspended (you can still run it by hand).', 'resume' => 'Resumed.', 'enable' => 'Switched on.', 'disable' => 'Switched off: it cannot run until you switch it on.'][$d['action']]);
    }

    public function duplicate(Request $request, string $id)
    {
        $r = $this->service->duplicate($this->tenant->idOrFail(), $this->uid($request), self::id($id));

        return $r['status'] === 'ok' ? redirect()->route('agents.show', $r['id'])->with('status', 'Copied. The copy is switched off and has no schedule.') : back()->withErrors(['wf' => WorkflowService::message($r['status'])]);
    }

    public function test(Request $request, string $id)
    {
        $simulate = $request->boolean('simulate_ai');
        $r = $this->service->startTest($this->tenant->idOrFail(), $this->uid($request), self::id($id), (array) $request->input('in', []), $simulate);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['wf' => WorkflowService::message($r['status'])]);
        }

        return redirect()->route('agents.show', ['id' => $id, 'run' => $r['run_id']])->with('status', 'The test was queued — it runs within a minute. Refresh to see each step.');
    }

    public function fromTemplate(Request $request)
    {
        $d = $request->validate(['template' => ['required', 'string', 'max:40']]);
        $r = $this->service->fromTemplate($this->tenant->idOrFail(), $this->uid($request), $d['template']);

        return self::back($r['status'], 'The workflow was added.');
    }

    public function edit(Request $request, string $id)
    {
        $w = $this->repo->workflow($this->uid($request), self::id($id));
        abort_if($w === null, 404);

        return view('workflow.edit', ['w' => $w, 'categories' => WorkflowService::CATEGORIES, 'tools' => $this->tools->all(), 'risks' => Risk::LABELS]);
    }

    public function save(Request $request, string $id)
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'category' => ['required', 'string', 'max:20'], 'definition' => ['required', 'string', 'max:100000'],
            'schedule' => ['nullable', 'string', 'max:5'], 'max_credits' => ['nullable', 'string', 'max:10'], 'enabled' => ['nullable', 'in:1']]);
        $r = $this->service->save($this->tenant->idOrFail(), $this->uid($request), self::id($id), $d['name'], (string) ($d['description'] ?? ''), $d['category'], $d['definition'], ($d['enabled'] ?? null) === '1', (string) ($d['schedule'] ?? ''), (string) ($d['max_credits'] ?? ''));
        if ($r['status'] === 'invalid') {
            return back()->withErrors(['wf' => implode(' | ', array_slice($r['errors'], 0, 5))])->withInput();
        }

        return self::back($r['status'], 'Saved.')->withInput($r['status'] === 'ok' ? [] : $request->all());
    }

    public function toggle(Request $request, string $id)
    {
        $d = $request->validate(['enabled' => ['required', 'in:0,1']]);

        $t = $this->tenant->idOrFail();
        $u = $this->uid($request);

        return self::back($d['enabled'] === '1' ? $this->service->enable($t, $u, self::id($id)) : $this->service->disable($t, $u, self::id($id)), 'Updated.');
    }

    public function destroy(Request $request, string $id)
    {
        return self::back($this->service->delete($this->tenant->idOrFail(), $this->uid($request), self::id($id)), 'Deleted.');
    }

    public function run(Request $request, string $id)
    {
        $r = $this->service->start($this->tenant->idOrFail(), $this->uid($request), self::id($id), (array) $request->input('in', []));
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['wf' => WorkflowService::message($r['status'])]);
        }

        return redirect()->route('agents.show', ['id' => $id, 'run' => $r['run_id']])->with('status', 'The run was queued — it will run within a minute. Refresh the page to see the steps.');
    }

    public function decide(Request $request, string $runId)
    {
        $d = $request->validate(['approve' => ['required', 'in:0,1']]);

        return self::back($this->service->decide($this->tenant->idOrFail(), $this->uid($request), self::id($runId), $d['approve'] === '1'), $d['approve'] === '1' ? 'Approved — it will run soon.' : 'Cancelled.');
    }

    public function cancel(Request $request, string $runId)
    {
        return self::back($this->service->cancel($this->tenant->idOrFail(), $this->uid($request), self::id($runId)), 'The run was stopped.');
    }
}
