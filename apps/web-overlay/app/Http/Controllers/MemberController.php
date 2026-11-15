<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MemberController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('members.view'), 403);
        $tenantId = $this->context->idOrFail();

        $members = DB::table('tenant_users as tu')
            ->join('users as u', 'u.id', '=', 'tu.user_id')
            ->where('tu.tenant_id', $tenantId)->where('tu.status', 'active')->whereNull('u.deleted_at')
            ->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.email', 'tu.joined_at']);

        $roles = DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.team_id', $tenantId)
            ->where('mr.model_type', (new User)->getMorphClass())
            ->pluck('r.name', 'mr.model_id');

        $pending = DB::table('invitations')->whereNull('accepted_at')->where('expires_at', '>', now())
            ->orderBy('email')->get(['email', 'role', 'expires_at']);

        return view('members.index', [
            'members' => $members,
            'roles' => $roles,
            'pending' => $pending,
            'invitable' => TenantRoles::INVITABLE,
            'ownerId' => DB::table('tenants')->where('id', $tenantId)->value('owner_id'),
            'canInvite' => $request->user()->can('members.invite'),
            'canRemove' => $request->user()->can('members.remove'),
        ]);
    }

    public function destroy(Request $request, string $userId)
    {
        abort_unless($request->user()->can('members.remove'), 403);
        $tenantId = $this->context->idOrFail();

        abort_if($userId === $request->user()->getKey(), 422, 'You cannot remove yourself.');
        abort_if(DB::table('tenants')->where('id', $tenantId)->value('owner_id') === $userId, 422, 'Owner-cannot be removed.');

        $updated = DB::table('tenant_users')
            ->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')
            ->update(['status' => 'removed', 'updated_at' => now()]);
        abort_if($updated === 0, 404);

        if (($member = User::find($userId)) !== null) {
            TenantRoles::revoke($member, $tenantId);
        }

        return back()->with('status', 'The member was removed.');
    }
}
