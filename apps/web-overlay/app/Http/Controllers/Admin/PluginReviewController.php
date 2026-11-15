<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAudit;
use App\Plugins\PluginManifest;
use App\Workflow\ToolRegistry;
use Illuminate\Http\Request;

/** Platform admins read each submitted plugin version - what it does and which tools it uses - and publish or reject it. Nothing is published without this. */
final class PluginReviewController extends AdminController
{
    public function index(ToolRegistry $tools)
    {
        $this->allow('plugins.review');
        $rows = $this->db()->select("select v.id, v.slug, v.version, v.name, v.description, v.manifest::text as manifest, v.tools::text as tools, v.created_at, t.name as tenant_name
            from plugin_versions v join tenants t on t.id = v.tenant_id where v.status = 'pending' order by v.created_at limit 50");
        $items = array_map(function ($r) use ($tools) {
            $keys = $r->tools === '{}' ? [] : explode(',', trim((string) $r->tools, '{}'));

            return ['id' => trim((string) $r->id), 'slug' => (string) $r->slug, 'version' => (int) $r->version, 'name' => (string) $r->name, 'description' => (string) $r->description,
                'author' => (string) $r->tenant_name, 'created_at' => (string) $r->created_at, 'permissions' => PluginManifest::permissions($keys, $tools),
                'manifest' => (string) json_encode(json_decode((string) $r->manifest, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }, $rows);

        return view('admin.plugins.index', ['items' => $items]);
    }

    public function decide(Request $request, string $id)
    {
        $this->allow('plugins.review');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['decision' => ['required', 'in:published,rejected'], 'note' => ['nullable', 'string', 'max:300']]);
        $r = (string) $this->db()->selectOne('select plugin_review(?, ?, ?) as r', [$id, $d['decision'], (string) ($d['note'] ?? '')])->r;
        if ($r !== 'ok') {
            return back()->withErrors(['plugin' => $r === 'bad_input' ? 'If rejecting, write a reason (max 300 characters).' : 'This is no longer pending.']);
        }
        AdminAudit::record('admin.plugin.'.$d['decision'], 'plugin_version', $id, null, ['note' => $d['note'] ?? null]);

        return back()->with('status', $d['decision'] === 'published' ? 'Published.' : 'Cancelled.');
    }
}
