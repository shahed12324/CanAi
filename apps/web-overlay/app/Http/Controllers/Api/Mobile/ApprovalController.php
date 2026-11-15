<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Mobile\MobileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApprovalController extends MobileController
{
    /** What a person needs to decide each kind of item. An unknown kind falls back to the strictest existing one. */
    private const APPROVE_PERMISSION = ['outreach_message' => 'outreach.approve', 'product' => 'catalog.approve'];

    public function index(Request $request): JsonResponse
    {
        $can = $request->user();
        $see = array_filter([
            'outreach_message' => $can->can('outreach.view'),
            'product' => $can->can('catalog.view'),
        ]);
        abort_if($see === [], 403, 'You do not have permission for this action.');

        return response()->json(['data' => array_values(array_filter($this->repo->approvals(100), fn ($a) => isset($see[$a['kind']])))]);
    }

    public function decide(Request $request, string $kind, string $ref, MobileService $svc): JsonResponse
    {
        abort_unless(preg_match('/\A[a-z_]{3,40}\z/', $kind) === 1 && preg_match('/\A[0-9a-z]{26}\z/', $ref) === 1, 404);
        $this->need($request, self::APPROVE_PERMISSION[$kind] ?? 'outreach.approve');
        $d = $request->validate(['decision' => ['required', 'in:approve,reject']]);

        return match ($svc->decide($this->device($request), $this->userId($request), $kind, $ref, $d['decision'])) {
            'approved' => response()->json(['data' => ['status' => 'approved']]),
            'rejected' => response()->json(['data' => ['status' => 'rejected']]),
            'not_pending' => $this->fail('not_pending', 'This is no longer pending (someone else has decided).', 409),
            'suppressed' => $this->fail('suppressed', 'This address is on the unsubscribe/blocklist, so it could not be approved.', 422),
            'step_up_required' => $this->fail('step_up_required', 'Confirm with your PIN for approval.', 403),
            'emergency' => $this->fail('emergency_stop', 'The emergency stop is on. Turn jobs back on first.', 423),
            default => $this->fail('unknown_kind', 'Unknown item type.', 422),
        };
    }
}
