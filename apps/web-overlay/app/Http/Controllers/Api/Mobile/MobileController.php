<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Mobile\MobileRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** Shared helpers for the phone API. Every route below it sits behind Sanctum + ResolveTenant (X-Tenant-Id). */
abstract class MobileController extends Controller
{
    public function __construct(protected readonly MobileRepo $repo, protected readonly TenantContext $tenant) {}

    protected function userId(Request $r): string
    {
        return (string) $r->user()->getAuthIdentifier();
    }

    protected function tokenId(Request $r): int
    {
        $t = $r->user()->currentAccessToken();

        return $t instanceof PersonalAccessToken ? (int) $t->getKey() : 0;
    }

    /** @return array{id: string, has_pin: bool, pin_hash: ?string, locked_until: ?string, push_token: ?string} */
    protected function device(Request $r): array
    {
        $d = $this->repo->device($this->tenant->idOrFail(), $this->userId($r), $this->tokenId($r));
        if ($d === null) {
            abort(response()->json(['message' => 'This phone is not registered yet.', 'code' => 'device_not_registered'], 428));
        }

        return $d;
    }

    protected function need(Request $r, string $permission): void
    {
        abort_unless($r->user()->can($permission), 403, 'You do not have permission for this action.');
    }

    protected function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
