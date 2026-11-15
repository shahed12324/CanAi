<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Exceptions\InvitationException;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRoles;
use InvalidArgumentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ManageInvitations
{
    public const TTL_DAYS = 7;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Creates (or replaces the pending) invitation for $email in the ACTIVE tenant.
     * Caller must already have authorised the inviter. Returns the raw token for the email
     * link; only its SHA-256 is stored.
     */
    public function create(string $email, string $role, User $inviter): string
    {
        if (! in_array($role, TenantRoles::INVITABLE, true)) {
            throw new InvalidArgumentException('Role cannot be granted by invitation.');
        }
        $email = mb_strtolower(trim($email));
        $tenantId = $this->context->idOrFail();
        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($tenantId, $email, $role, $inviter, $token): void {
            DB::table('invitations')
                ->where('tenant_id', $tenantId)->where('email', $email)->whereNull('accepted_at')
                ->delete();
            DB::table('invitations')->insert([
                'id' => CreateWorkspace::newId(),
                'tenant_id' => $tenantId,
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->getKey(),
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ]);
        });

        return $token;
    }

    /** Safe to call without a tenant context (e.g. when the invitee opens the link). */
    public function preview(string $token): ?object
    {
        return DB::selectOne('select * from invitation_preview(?)', [hash('sha256', $token)]);
    }

    /**
     * @return array{tenant_id: string, role: string}
     *
     * @throws InvitationException
     */
    public function accept(User $user, string $token): array
    {
        try {
            $row = DB::selectOne('select * from invitation_accept(?, ?)', [hash('sha256', $token), $user->getKey()]);
        } catch (QueryException $e) {
            foreach (InvitationException::CODES as $code) {
                if (str_contains($e->getMessage(), $code)) {
                    throw new InvitationException($code);
                }
            }
            throw $e;
        }

        return ['tenant_id' => $row->out_tenant_id, 'role' => $row->out_role];
    }
}
