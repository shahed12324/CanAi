<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkspaceController extends Controller
{
    public function store(Request $request, CreateWorkspace $workspaces)
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:100']]);
        $id = $workspaces->organization($request->user(), $data['name']);
        $request->session()->put('tenant_id', $id);

        return redirect()->route('shop.show')->with('status', 'Shop created. Add your address, phone and logo.');
    }

    public function switch(Request $request)
    {
        $data = $request->validate(['tenant_id' => ['required', 'string', 'size:26']]);
        abort_unless(TenantContext::isValidId($data['tenant_id']), 403);

        $isMember = DB::table('tenant_users as tu')
            ->join('tenants as t', 't.id', '=', 'tu.tenant_id')
            ->where('tu.user_id', $request->user()->getKey())
            ->where('tu.tenant_id', $data['tenant_id'])
            ->where('tu.status', 'active')->where('t.status', 'active')->whereNull('t.deleted_at')
            ->exists();
        abort_unless($isMember, 403);

        $request->session()->put('tenant_id', $data['tenant_id']);

        return redirect()->route('dashboard');
    }
}
