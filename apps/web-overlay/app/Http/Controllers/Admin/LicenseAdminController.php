<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAudit;
use Illuminate\Http\Request;

/** Platform admins give a workspace an Enterprise licence (seats + expiry), replace it, or remove it. */
final class LicenseAdminController extends AdminController
{
    private const ERR = ['bad_input' => 'The input is not valid.', 'not_found' => 'This workspace was not found.', 'below_used' => 'Seats cannot be fewer than the current active members.'];

    public function index()
    {
        $this->allow('licenses.manage');
        $rows = $this->db()->select("select l.tenant_id, t.name, t.slug, l.seats, l.expires_at, l.note,
            (select count(*) from tenant_users u where u.tenant_id = l.tenant_id and u.status = 'active') as used
            from licenses l join tenants t on t.id = l.tenant_id order by l.expires_at limit 200");

        return view('admin.licenses.index', ['rows' => $rows]);
    }

    public function set(Request $request)
    {
        $this->allow('licenses.manage');
        $d = $request->validate(['slug' => ['required', 'string', 'max:100'], 'seats' => ['required', 'integer', 'min:1', 'max:100000'], 'expires' => ['required', 'date', 'after:now'], 'note' => ['nullable', 'string', 'max:300']]);
        $id = $this->db()->table('tenants')->whereNull('deleted_at')->where('slug', $d['slug'])->value('id');
        if ($id === null) {
            return back()->withErrors(['slug' => self::ERR['not_found']])->withInput();
        }
        $id = trim((string) $id);
        $r = (string) $this->db()->selectOne('select license_set(?, ?, ?::timestamptz, ?) as r', [$id, (int) $d['seats'], \Illuminate\Support\Carbon::parse($d['expires'])->endOfDay()->toIso8601String(), (string) ($d['note'] ?? '')])->r;
        if ($r !== 'ok') {
            return back()->withErrors(['slug' => self::ERR[$r] ?? self::ERR['bad_input']])->withInput();
        }
        AdminAudit::record('admin.license.set', 'tenant', $id, null, ['seats' => $d['seats'], 'expires' => $d['expires']], $id);

        return back()->with('status', 'License saved.');
    }

    public function remove(string $id)
    {
        $this->allow('licenses.manage');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $r = (string) $this->db()->selectOne('select license_remove(?) as r', [$id])->r;
        if ($r === 'ok') {
            AdminAudit::record('admin.license.removed', 'tenant', $id, null, null, $id);
        }

        return back()->with('status', $r === 'ok' ? 'License removed.' : 'Not found.');
    }
}
