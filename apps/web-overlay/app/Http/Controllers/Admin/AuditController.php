<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;

final class AuditController extends AdminController
{
    public function index(Request $request)
    {
        $this->allow('audit.view');
        $q = $this->db()->table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->leftJoin('tenants as t', 't.id', '=', 'a.tenant_id');

        if (($v = trim((string) $request->query('action'))) !== '') {
            $q->where('a.action', 'ilike', $this->like($v));
        }
        if (($v = trim((string) $request->query('actor'))) !== '') {
            $q->where(fn ($w) => $w->where('u.email', 'ilike', $this->like($v))->orWhere('a.actor_id', $v));
        }
        if (($v = trim((string) $request->query('tenant'))) !== '') {
            $q->where(fn ($w) => $w->where('t.name', 'ilike', $this->like($v))->orWhere('a.tenant_id', $v));
        }
        if (in_array($v = $request->query('channel'), ['admin', 'cli', 'web', 'api'], true)) {
            $q->where('a.channel', $v);
        }
        foreach (['from' => '>=', 'to' => '<='] as $name => $op) {
            if (($v = $request->query($name)) && strtotime((string) $v) !== false) {
                $q->where('a.created_at', $op, $name === 'to' ? date('Y-m-d 23:59:59', strtotime((string) $v)) : date('Y-m-d 00:00:00', strtotime((string) $v)));
            }
        }

        return view('admin.audit.index', [
            'logs' => $q->orderByDesc('a.id')->paginate(50, ['a.id', 'a.action', 'a.subject_type', 'a.subject_id', 'a.before', 'a.after', 'a.ip', 'a.channel', 'a.created_at', 'u.email as actor_email', 't.name as tenant_name'])->withQueryString(),
        ]);
    }
}
