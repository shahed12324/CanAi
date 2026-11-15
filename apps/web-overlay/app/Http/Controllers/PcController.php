<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Pc\MonitorAdvisor;
use App\Companion\ModeRepo;
use App\Pc\PcPlanRepo;
use App\Pc\PcRepo;
use App\Pc\PcService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** "My computer": pair a PC, set what it may touch, approve its actions, watch its health. Everything is per user's own devices. */
final class PcController extends Controller
{
    public function __construct(private readonly PcService $pc, private readonly PcRepo $repo, private readonly TenantContext $tenant, private readonly PcPlanRepo $plans, private readonly ModeRepo $modes) {}

    public function index(Request $request)
    {
        $t = $this->tenant->idOrFail();
        $u = (string) $request->user()->getAuthIdentifier();
        $devices = [];
        foreach ($this->repo->devices($t, $u) as $d) {
            $m = $this->repo->latestMetric($t, $d['id']);
            $devices[] = $d + ['policy' => $this->repo->policy($t, $d['id']) ?? ['roots' => [], 'apps' => [], 'commands' => [], 'hosts' => [], 'modes' => []],
                'metric' => $m, 'alerts' => $m === null ? [] : MonitorAdvisor::alerts($m)];
        }

        return view('pc.index', ['devices' => $devices, 'actions' => $this->repo->actions($t, $u, 30), 'plans' => $this->plans->recent($t, $u, 20), 'userMode' => $this->modes->get($t, $u)['mode'], 'kinds' => PcService::KINDS, 'pairCode' => session('pair_code')]);
    }

    public function pair(Request $request)
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:200'], 'platform' => ['required', 'string', 'max:10']]);
        $r = $this->pc->startPairing($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $d['name'], $d['platform']);

        return $r['ok'] ? back()->with('pair_code', $r['code']) : back()->withErrors(['pc' => $r['error']])->withInput();
    }

    public function revoke(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $r = $this->repo->deviceRevoke($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id);

        return $r === 'ok' ? back()->with('status', 'The device was removed; open jobs were stopped.') : back()->withErrors(['pc' => PcService::REASONS['not_found']]);
    }

    public function policy(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $in = $request->validate(['roots' => ['nullable', 'string', 'max:20000'], 'apps' => ['nullable', 'string', 'max:20000'], 'commands' => ['nullable', 'string', 'max:20000'],
            'hosts' => ['nullable', 'string', 'max:20000'], 'modes' => ['nullable', 'array'], 'modes.*' => ['nullable', 'string', 'max:5']]);
        $r = $this->pc->savePolicy($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id, $in);

        return $r['ok'] ? back()->with('status', $r['message']) : back()->withErrors(['pc' => $r['message']])->withInput();
    }

    public function request(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['kind' => ['required', 'string', 'max:30'], 'target' => ['required', 'string', 'max:2000']]);
        $r = $this->pc->request($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id, $d['kind'], PcService::paramsFor($d['kind'], $d['target']));

        return $r['ok'] ? back()->with('status', $r['message']) : back()->withErrors(['pc' => $r['message']]);
    }

    public function decide(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $r = $this->repo->actionDecide($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id, $d['decision']);

        return $r === 'ok' ? back()->with('status', PcService::decisionMessage($r)) : back()->withErrors(['pc' => PcService::decisionMessage($r)]);
    }

    /** Paste or upload a plan (the planner's reply or a plan file); every step is checked against the device rules by the database. */
    public function planSubmit(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['goal' => ['required', 'string', 'max:2000'], 'plan' => ['required', 'string', 'max:60000']]);
        $raw = json_decode($d['plan'], true);
        $plan = is_array($raw) && isset($raw['plan']) && is_array($raw['plan']) && ! isset($raw['steps']) ? $raw['plan'] : $raw;      // the planner's whole reply is fine
        if (! is_array($plan) || ! isset($plan['steps']) || ! is_array($plan['steps'])) {
            return back()->withErrors(['pc' => 'The plan is not valid JSON with a "steps" list.'])->withInput();
        }
        $r = $this->plans->submit($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id, $d['goal'], ['steps' => $plan['steps']]);
        if ($r['status'] === 'pending') {
            return back()->with('status', 'The plan is waiting for your approval.');
        }

        return back()->withErrors(['pc' => 'The plan was not accepted: '.\App\Pc\PcService::planReason((string) ($r['reason'] ?? 'bad_input'))])->withInput();
    }

    /** "Tell the computer what you want": the model drafts a plan (credits are charged), the plan waits for the person's approval like any other. */
    public function planDraft(Request $request, string $id, \App\Pc\PcPlanner $planner)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['goal' => ['required', 'string', 'min:3', 'max:2000']]);
        $tenant = $this->tenant->idOrFail();
        $user = (string) $request->user()->getAuthIdentifier();
        if ($this->modes->get($tenant, $user)['mode'] === 'companion') {
            return back()->withErrors(['pc' => PcService::REASONS['companion_mode']])->withInput();
        }
        $r = $planner->draft($tenant, $user, $id, $d['goal'], \App\Actions\Tenancy\CreateWorkspace::newId());
        if ($r['status'] === 'ok') {
            return back()->with('status', 'A draft plan was made and is waiting for your approval below. Read every step first.');
        }

        return back()->withErrors(['pc' => PcService::plannerMessage($r['status'], $r['reason'])])->withInput();
    }

    public function planDecide(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $r = $this->plans->decide($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id, $d['decision']);

        return $r === 'ok' ? back()->with('status', $d['decision'] === 'approve' ? 'Plan approved. High-risk steps still need your phone.' : 'Plan cancelled.') : back()->withErrors(['pc' => PcService::planDecisionMessage($r)]);
    }

    public function planStep(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['step' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]{0,23}\z/']]);
        $r = $this->plans->stepApprove($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id, $d['step']);

        return $r === 'ok' ? back()->with('status', 'Step approved.') : back()->withErrors(['pc' => PcService::planDecisionMessage($r)]);
    }
}
