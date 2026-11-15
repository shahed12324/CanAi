<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Mobile\MobileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmergencyController extends MobileController
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->repo->emergencyState($this->tenant->idOrFail())]);
    }

    /** Stop is one tap (no PIN); resume needs a fresh PIN. */
    public function set(Request $request, MobileService $svc): JsonResponse
    {
        $this->need($request, 'emergency.control');
        $d = $request->validate(['action' => ['required', 'in:stop,resume'], 'reason' => ['nullable', 'string', 'max:300']]);
        $tenant = $this->tenant->idOrFail();
        $r = $svc->emergency($tenant, $this->device($request), $this->userId($request), $d['action'] === 'stop', (string) ($d['reason'] ?? ''));
        if ($r === 'step_up_required') {
            return $this->fail('step_up_required', 'Confirm with your PIN to turn it back on.', 403);
        }

        return response()->json(['data' => ['result' => $r] + $this->repo->emergencyState($tenant)]);
    }
}
