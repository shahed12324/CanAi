<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pc;

use App\Http\Controllers\Controller;
use App\Pc\PcPlanRepo;
use App\Pc\PcRepo;
use App\Pc\PcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the desktop agent calls. It only PULLS work: nothing here can make a PC do something that was not approved. */
final class AgentController extends Controller
{
    public function __construct(private readonly PcService $pc, private readonly PcRepo $repo, private readonly PcPlanRepo $plans) {}

    /** Unauthenticated: exchanges the one-time code for the device token (shown once). */
    public function pair(Request $request): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $r = $this->pc->redeem($d['code']);

        return $r === null ? response()->json(['error' => 'invalid_code'], 422) : response()->json(['device_id' => $r['device_id'], 'token' => $r['token']], 201);
    }

    /** The agent replaces its token with a fresh one it made itself (the old one stops working immediately). */
    public function rotate(Request $request): JsonResponse
    {
        $d = $request->validate(['new_token' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/']]);
        $old = substr((string) $request->header('Authorization', ''), 7);

        return $this->pc->rotate($old, $d['new_token']) ? response()->json(['ok' => true]) : response()->json(['error' => 'rotate_refused'], 409);
    }

    public function next(Request $request): JsonResponse
    {
        $a = $this->repo->actionNext((string) $request->attributes->get('pc_tenant'), (string) $request->attributes->get('pc_device'));

        return response()->json(['action' => $a]);
    }

    /** Asked by the agent while it runs a plan: has someone pressed the emergency stop on the website or the phone? */
    public function emergency(Request $request): JsonResponse
    {
        return response()->json(['active' => $this->repo->agentEmergency((string) $request->attributes->get('pc_tenant'), (string) $request->attributes->get('pc_device'))]);
    }

    public function report(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['ok' => ['required', 'boolean'], 'result' => ['nullable', 'string', 'max:20000']]);
        $r = $this->repo->actionReport((string) $request->attributes->get('pc_tenant'), (string) $request->attributes->get('pc_device'), $id, (bool) $d['ok'], (string) ($d['result'] ?? ''));

        return $r === 'ok' ? response()->json(['status' => 'ok']) : response()->json(['error' => $r], 409);
    }

    /** A plan the person approved, with signatures the agent verifies before running anything. */
    public function planNext(Request $request): JsonResponse
    {
        return response()->json(['plan' => $this->plans->next((string) $request->attributes->get('pc_tenant'), (string) $request->attributes->get('pc_device'))]);
    }

    public function planReport(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['state' => ['required', 'in:done,failed,stopped'], 'result' => ['nullable', 'string', 'max:8000']]);
        $r = $this->plans->report((string) $request->attributes->get('pc_tenant'), (string) $request->attributes->get('pc_device'), $id, $d['state'], (string) ($d['result'] ?? ''));

        return $r === 'ok' ? response()->json(['status' => 'ok']) : response()->json(['error' => $r], 409);
    }

    /** Where the stored copy of this computer's audit chain ends (so the agent can compare it with its own file). */
    public function auditHead(Request $request): JsonResponse
    {
        $r = $this->repo->auditHead((string) $request->attributes->get('pc_tenant'), (string) $request->attributes->get('pc_device'));

        return response()->json($r, ($r['status'] ?? '') === 'ok' ? 200 : 404);
    }

    /** Append a batch of the agent's audit rows.  409 = the chain does not continue the stored one (the body says where the server's copy ends). */
    public function auditSync(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 300_000) {
            return response()->json(['status' => 'bad_input'], 413);
        }
        $b = PcService::auditBatchFrom($request->json()->all());
        if ($b === null) {
            return response()->json(['status' => 'bad_input'], 422);
        }
        $r = $this->repo->auditIngest((string) $request->attributes->get('pc_tenant'), (string) $request->attributes->get('pc_device'), $b['start'], $b['rows']);
        $code = match ($r['status'] ?? '') {
            'ok' => 200, 'seq_mismatch', 'chain_broken' => 409, 'not_found' => 404, default => 422,
        };

        return response()->json($r, $code);
    }

    public function metrics(Request $request): JsonResponse
    {
        $m = PcService::metricsFrom($request->json()->all());
        if ($m === null) {
            return response()->json(['error' => 'bad_input'], 422);
        }
        $r = $this->repo->metricsAdd((string) $request->attributes->get('pc_tenant'), (string) $request->attributes->get('pc_device'), $m);

        return response()->json(['status' => $r], $r === 'ok' ? 200 : ($r === 'too_fast' ? 429 : 422));
    }
}
