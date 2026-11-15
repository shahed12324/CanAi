<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Credits\CreditService;
use App\Models\User;
use App\Tenancy\TenantRoles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a workspace (tenant) and makes $owner an active member.
 * `tenants` and `tenant_users` are global tables, so no tenant context is needed here.
 */
final class CreateWorkspace
{
    public function personal(User $owner): string
    {
        return $this->create($owner, $owner->name."'s workspace", 'personal');
    }

    public function organization(User $owner, string $name): string
    {
        return $this->create($owner, $name, 'organization');
    }

    private function create(User $owner, string $name, string $type): string
    {
        $name = trim(mb_substr($name, 0, 255));
        $tenantId = self::newId();

        // The slug carries a random suffix, so a collision is rare; retry a few times anyway.
        for ($attempt = 1; ; $attempt++) {
            try {
                $id = DB::transaction(function () use ($owner, $name, $type, $tenantId): string {
                    DB::table('tenants')->insert([
                        'id' => $tenantId,
                        'name' => $name,
                        'slug' => self::slug($name),
                        'type' => $type,
                        'owner_id' => $owner->getKey(),
                        'locale' => $owner->locale ?? 'bn',
                        'timezone' => $owner->timezone ?? 'Asia/Dhaka',
                    ]);
                    DB::table('tenant_users')->insert([
                        'id' => self::newId(),
                        'tenant_id' => $tenantId,
                        'user_id' => $owner->getKey(),
                        'status' => 'active',
                        'joined_at' => now(),
                    ]);
                    TenantRoles::assign($owner, $tenantId, 'Owner');

                    return $tenantId;
                });
                $this->welcomeCredits($id);

                return $id;
            } catch (UniqueConstraintViolationException $e) {
                // A second personal workspace for the same owner is a real error, not a slug clash.
                if ($attempt >= 3 || str_contains($e->getMessage(), 'tenants_one_personal_per_owner')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Free credits for the new workspace. Runs AFTER the workspace transaction has committed, and a failure here
     * must never undo a registration: the grant is idempotent (key "signup:<tenant>"), so it can be repaired later.
     */
    private function welcomeCredits(string $tenantId): void
    {
        try {
            app(CreditService::class)->grantSignup($tenantId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function newId(): string
    {
        return strtolower((string) Str::ulid());
    }

    private static function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';

        return Str::limit($base, 40, '').'-'.Str::lower(Str::random(6));
    }
}
