<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Companion\ModeRepo;
use App\Companion\ModeRules;
use App\Mobile\MobileRepo;
use App\Pc\PcPlanRepo;
use App\Pc\PcRepo;
use App\Pc\PcService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The phone's side of Computer Control: the person's own pending computer actions, and approve / reject (approving needs the PIN step-up; high / critical actions can ONLY be approved here). */
final class PcActionController extends MobileController
{
    public function __construct(MobileRepo $repo, TenantContext $tenant, private readonly PcRepo $pc, private readonly PcService $svc, private readonly PcPlanRepo $plans, private readonly ModeRepo $modes)
    {
        parent::__construct($repo, $tenant);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->pc->mobilePending($this->device($request)['id'], $this->userId($request))]);
    }

    /** The person's own live computers (to pick one for a hand-off). */
    public function devices(Request $request): JsonResponse
    {
        $this->device($request);

        return response()->json(['data' => array_map(fn ($d) => ['id' => trim($d['id']), 'name' => $d['name'], 'platform' => $d['platform'], 'last_seen_at' => $d['last_seen_at']],
            $this->pc->devices($this->tenant->idOrFail(), $this->userId($request)))]);
    }

    /** Hand a small job to my computer: it lands in "pending" and shows up in the list above for approval. */
    public function request(Request $request): JsonResponse
    {
        $this->device($request);
        $d = $request->validate(['device_id' => ['required', 'string', 'size:26'], 'kind' => ['required', 'string', 'max:30'], 'target' => ['required', 'string', 'max:520']]);
        $r = $this->svc->mobileRequest($this->tenant->idOrFail(), $this->userId($request), $d['device_id'], $d['kind'], $d['target']);

        return $r['ok'] ? response()->json(['data' => ['id' => $r['id'], 'status' => $r['status'], 'message' => $r['message']]], 201) : $this->fail($r['code'], $r['message'], $r['code'] === 'bad_input' ? 422 : 409);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $o = PcService::mobileOutcome($this->pc->mobileDecide($this->device($request)['id'], $this->userId($request), $id, $d['decision']));

        return $o['status'] === 200 ? response()->json(['data' => ['status' => $d['decision'] === 'approve' ? 'approved' : 'rejected']]) : $this->fail($o['code'], $o['message'], $o['status']);
    }

    /** Plans waiting for this person (with the steps that need their own approval). */
    public function plans(Request $request): JsonResponse
    {
        $this->device($request);

        return response()->json(['data' => array_values(array_filter($this->plans->recent($this->tenant->idOrFail(), $this->userId($request), 20), static fn ($p) => in_array($p['status'], ['pending', 'approved'], true)))]);
    }

    /** Approve ONE risky step of a plan (the only place high / critical steps can be approved). Needs the PIN step-up. */
    public function planStep(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['step' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]{0,23}\z/']]);
        $r = $this->plans->mobileStepApprove($this->device($request)['id'], $this->userId($request), $id, $d['step']);
        $o = $r === 'not_needed' || $r === 'not_open' ? ['status' => 409, 'code' => $r, 'message' => PcService::planDecisionMessage($r)] : PcService::mobileOutcome($r);

        return $o['status'] === 200 ? response()->json(['data' => ['status' => 'step_approved']]) : $this->fail($o['code'], $o['message'], $o['status']);
    }

    /** Companion (chat only) or Agent (computer jobs need approval). No row = agent. */
    public function mode(Request $request): JsonResponse
    {
        $this->device($request);
        $m = $this->modes->get($this->tenant->idOrFail(), $this->userId($request));

        return response()->json(['data' => ['mode' => $m['mode'], 'character_id' => $m['character_id']]]);
    }

    public function setMode(Request $request): JsonResponse
    {
        $this->device($request);
        $d = $request->validate(['mode' => ['required', 'string', 'in:'.implode(',', ModeRules::MODES)]]);
        $m = $this->modes->get($this->tenant->idOrFail(), $this->userId($request));
        $r = $this->modes->set($this->tenant->idOrFail(), $this->userId($request), $d['mode'], $m['character_id']);

        return $r === 'ok' ? response()->json(['data' => ['mode' => $d['mode']]]) : $this->fail($r, $r === 'bad_input' ? 'The companion character was not found.' : 'You are not a member of this workspace.', $r === 'bad_input' ? 422 : 403);
    }
}
