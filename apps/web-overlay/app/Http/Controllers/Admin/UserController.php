<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Tenancy\CreateWorkspace;
use App\Admin\AdminAudit;
use App\Admin\PlatformRoles;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UserController extends AdminController
{
    private const AUDITED = ['name', 'email', 'locale', 'timezone', 'status', 'is_platform_admin', 'deleted_at'];

    public function index(Request $request)
    {
        $this->allow('users.view');
        $q = $this->db()->table('users');
        $request->boolean('trashed') ? $q->whereNotNull('deleted_at') : $q->whereNull('deleted_at');
        if (($term = trim((string) $request->query('q'))) !== '') {
            $like = $this->like($term);
            $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
        }
        if (in_array($status = $request->query('status'), ['active', 'suspended', 'pending'], true)) {
            $q->where('status', $status);
        }

        return view('admin.users.index', [
            'users' => $q->orderByDesc('created_at')->paginate(25, ['id', 'name', 'email', 'status', 'is_platform_admin', 'email_verified_at', 'created_at', 'deleted_at'])->withQueryString(),
        ]);
    }

    public function show(string $id)
    {
        $this->allow('users.view');
        $user = $this->find($id, withTrashed: true);

        return view('admin.users.show', [
            'user' => $user,
            'platformRole' => PlatformRoles::roleOf($id),
            'platformRoles' => PlatformRoles::names(),
            'memberships' => $this->db()->table('tenant_users as tu')->join('tenants as t', 't.id', '=', 'tu.tenant_id')
                ->where('tu.user_id', $id)->orderBy('t.name')->get(['t.id', 't.name', 't.type', 'tu.status']),
            'logins' => $this->db()->table('login_logs')->where('user_id', $id)->orderByDesc('id')->limit(10)->get(['result', 'ip', 'user_agent', 'created_at']),
            'sessionCount' => $this->db()->table('sessions')->where('user_id', $id)->count(),
            'tokenCount' => $this->db()->table('personal_access_tokens')->where('tokenable_id', $id)->count(),
        ]);
    }

    public function store(Request $request)
    {
        $this->allow('users.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'locale' => ['required', Rule::in(['bn', 'en'])],
        ]);
        $id = CreateWorkspace::newId();
        $email = mb_strtolower($data['email']);

        $this->db()->transaction(function () use ($id, $data, $email): void {
            // Random unusable password: the person sets their own through the reset link below.
            $this->db()->table('users')->insert([
                'id' => $id, 'name' => $data['name'], 'email' => $email, 'locale' => $data['locale'],
                'password' => Hash::make(Str::random(48)), 'created_at' => now(), 'updated_at' => now(),
            ]);
            AdminAudit::record('admin.user.created', 'user', $id, null, ['email' => $email, 'name' => $data['name']]);
        });

        app(CreateWorkspace::class)->personal(User::findOrFail($id));
        Password::sendResetLink(['email' => $email]);

        return redirect()->route('admin.users.show', $id)->with('status', 'The user was created; a link to set the password was sent.');
    }

    public function update(Request $request, string $id)
    {
        $this->allow('users.manage');
        $user = $this->find($id);
        $this->guardTarget($user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $this->db()->transaction(function () use ($id, $user, $data): void {
            $this->db()->table('users')->where('id', $id)->update($data + ['updated_at' => now()]);
            AdminAudit::record('admin.user.updated', 'user', $id, $this->snapshot($user, array_keys($data)), $data);
        });

        return back()->with('status', 'The user was updated.');
    }

    public function suspend(Request $request, string $id)
    {
        return $this->setStatus($id, 'suspended', 'admin.user.suspended', true);
    }

    public function activate(Request $request, string $id)
    {
        return $this->setStatus($id, 'active', 'admin.user.activated', false);
    }

    public function revokeAccess(string $id)
    {
        $this->allow('users.manage');
        $user = $this->find($id);
        $this->guardTarget($user, allowSelf: true);

        $this->db()->transaction(function () use ($id): void {
            $this->killAccess($id);
            AdminAudit::record('admin.user.access_revoked', 'user', $id);
        });

        return back()->with('status', 'All sessions and API tokens were revoked.');
    }

    public function sendReset(string $id)
    {
        $this->allow('users.manage');
        $user = $this->find($id);
        $this->guardTarget($user, allowSelf: true);
        Password::sendResetLink(['email' => $user->email]);
        $this->db()->transaction(fn () => AdminAudit::record('admin.user.reset_link_sent', 'user', $id));

        return back()->with('status', 'A password reset link was sent.');
    }

    public function destroy(string $id)
    {
        $this->allow('users.manage');
        $user = $this->find($id);
        $this->guardTarget($user);

        $this->db()->transaction(function () use ($id, $user): void {
            $this->db()->table('users')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            $this->killAccess($id);
            AdminAudit::record('admin.user.deleted', 'user', $id, $this->snapshot($user, self::AUDITED));
        });

        return redirect()->route('admin.users.index')->with('status', 'The user was deleted (can be restored).');
    }

    public function restore(string $id)
    {
        $this->allow('users.manage');
        $user = $this->find($id, withTrashed: true);
        abort_if($user->deleted_at === null, 404);
        abort_if($this->db()->table('users')->where('email', $user->email)->whereNull('deleted_at')->exists(), 422, 'Another active account exists with this email.');

        $this->db()->transaction(function () use ($id): void {
            $this->db()->table('users')->where('id', $id)->update(['deleted_at' => null, 'updated_at' => now()]);
            AdminAudit::record('admin.user.restored', 'user', $id);
        });

        return redirect()->route('admin.users.show', $id)->with('status', 'The user was restored.');
    }

    /** Super Admin only: grant, change or remove a platform role. */
    public function platformRole(Request $request, string $id)
    {
        $this->allow('roles.manage');
        $user = $this->find($id);
        $data = $request->validate(['role' => ['nullable', Rule::in(PlatformRoles::names())]]);
        $role = $data['role'] ?? null;
        $current = PlatformRoles::roleOf($id);

        if ($role === null || $role === '') {
            abort_if($id === $request->user()->getKey(), 422, 'You cannot remove your own admin rights.');
            abort_if($current === PlatformRoles::SUPER_ADMIN && PlatformRoles::superAdminCount() <= 1, 422, 'The last Super Admin cannot be removed.');
            PlatformRoles::revoke(User::findOrFail($id));
        } else {
            abort_if($user->status !== 'active' || $user->email_verified_at === null, 422, 'The user must be active and email-verified.');
            abort_if($id === $request->user()->getKey() && $role !== $current, 422, 'You cannot change your own role.');
            abort_if($current === PlatformRoles::SUPER_ADMIN && $role !== $current && PlatformRoles::superAdminCount() <= 1, 422, 'The last Super Admin’s role cannot be changed.');
            PlatformRoles::assign(User::findOrFail($id), $role);
        }

        $this->db()->transaction(function () use ($id, $role, $current): void {
            $this->db()->table('users')->where('id', $id)->update(['is_platform_admin' => $role !== null && $role !== '', 'updated_at' => now()]);
            AdminAudit::record('admin.user.platform_role', 'user', $id, ['role' => $current], ['role' => $role ?: null]);
        });

        return back()->with('status', 'The platform role was updated.');
    }

    private function setStatus(string $id, string $status, string $action, bool $killAccess)
    {
        $this->allow('users.manage');
        $user = $this->find($id);
        $this->guardTarget($user);

        $this->db()->transaction(function () use ($id, $user, $status, $action, $killAccess): void {
            $this->db()->table('users')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
            if ($killAccess) {
                $this->killAccess($id);
            }
            AdminAudit::record($action, 'user', $id, ['status' => $user->status], ['status' => $status]);
        });

        return back()->with('status', 'The user’s status was updated.');
    }

    private function killAccess(string $userId): void
    {
        $this->db()->table('sessions')->where('user_id', $userId)->delete();
        $this->db()->table('personal_access_tokens')->where('tokenable_id', $userId)->delete();
    }

    /** Platform admins can only be touched by someone who may manage roles; nobody can lock themselves out. */
    private function guardTarget(object $target, bool $allowSelf = false): void
    {
        abort_if(! $allowSelf && $target->id === request()->user()->getKey(), 422, 'You cannot do this on your own account.');
        abort_if($target->is_platform_admin && ! request()->user()->can('roles.manage'), 403, 'Only a Super Admin can change a platform admin.');
    }

    private function find(string $id, bool $withTrashed = false): object
    {
        $q = $this->db()->table('users')->where('id', $id);
        if (! $withTrashed) {
            $q->whereNull('deleted_at');
        }

        return $q->first() ?? abort(404);
    }
}
