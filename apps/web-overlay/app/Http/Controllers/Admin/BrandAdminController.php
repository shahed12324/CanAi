<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAudit;
use Illuminate\Http\Request;

/** Platform admins switch white-label on or off for a workspace (found by its slug). */
final class BrandAdminController extends AdminController
{
    public function index()
    {
        $this->allow('brands.manage');
        $rows = $this->db()->select('select b.tenant_id, t.name, t.slug, b.brand_name, b.color from tenant_brands b join tenants t on t.id = b.tenant_id where b.enabled order by b.updated_at desc limit 200');

        return view('admin.brands.index', ['rows' => $rows]);
    }

    public function enable(Request $request)
    {
        $this->allow('brands.manage');
        $d = $request->validate(['slug' => ['required', 'string', 'max:100']]);
        $id = $this->db()->table('tenants')->whereNull('deleted_at')->where('slug', $d['slug'])->value('id');
        if ($id === null) {
            return back()->withErrors(['slug' => 'No workspace with this slug.'])->withInput();
        }

        return $this->set(trim((string) $id), true);
    }

    public function disable(string $id)
    {
        $this->allow('brands.manage');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $this->set($id, false);
    }

    private function set(string $id, bool $on)
    {
        $r = (string) $this->db()->selectOne('select brand_set_enabled(?, ?) as r', [$id, $on ? 'true' : 'false'])->r;
        if ($r !== 'ok') {
            return back()->withErrors(['slug' => 'Could not be done.']);
        }
        AdminAudit::record($on ? 'admin.brand.enabled' : 'admin.brand.disabled', 'tenant', $id, null, null, $id);

        return back()->with('status', $on ? 'White-label was turned on.' : 'Turned off (the brand was removed).');
    }
}
