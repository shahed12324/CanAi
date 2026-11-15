<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Plugins\PluginManifest;
use App\Plugins\PluginRepo;
use App\Plugins\PluginService;
use App\Tenancy\TenantContext;
use App\Workflow\ToolRegistry;
use Illuminate\Http\Request;

/** The plugin store: browse published plugins, install / update / remove them, and submit your own for review. */
final class PluginController extends Controller
{
    public function __construct(private readonly PluginService $plugins, private readonly PluginRepo $repo, private readonly TenantContext $tenant, private readonly ToolRegistry $tools) {}

    private static function slug(string $v): string
    {
        abort_unless(preg_match(PluginManifest::SLUG, $v) === 1, 404);

        return $v;
    }

    public function index(Request $request)
    {
        $uid = (string) $request->user()->id;
        $installs = $this->repo->installs($uid);
        $list = array_map(fn ($p) => $p + ['permissions' => PluginManifest::permissions($p['tools'], $this->tools), 'installed' => $installs[$p['slug']]['version'] ?? null], $this->repo->published());

        return view('plugins.index', ['plugins' => $list, 'mine' => $this->repo->mine()]);
    }

    public function install(Request $request, string $slug)
    {
        $r = $this->plugins->install($this->tenant->idOrFail(), (string) $request->user()->id, self::slug($slug));

        return $r === 'ok' ? back()->with('status', 'Installed — the workflows are in a disabled state; review and "Agent" turn them on from the page.') : back()->withErrors(['plugin' => PluginService::message($r)]);
    }

    public function uninstall(Request $request, string $slug)
    {
        $r = $this->plugins->uninstall($this->tenant->idOrFail(), (string) $request->user()->id, self::slug($slug));

        return $r === 'ok' ? back()->with('status', 'The plugin and its workflows were deleted.') : back()->withErrors(['plugin' => PluginService::message($r)]);
    }

    public function submit(Request $request)
    {
        $d = $request->validate(['slug' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:400'], 'manifest' => ['required', 'string', 'max:60000']]);
        $r = $this->plugins->submit($this->tenant->idOrFail(), (string) $request->user()->id, $d['slug'], $d['name'], (string) ($d['description'] ?? ''), $d['manifest']);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['submit' => PluginService::message($r['status']).($r['errors'] === [] ? '' : ' '.implode(' ', array_slice($r['errors'], 0, 5)))])->withInput();
        }

        return back()->with('status', 'Submitted — it will come to the store when an admin reviews and approves.');
    }

    public function withdraw(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $this->plugins->withdraw($this->tenant->idOrFail(), (string) $request->user()->id, $id);

        return back()->with('status', 'The version was withdrawn (installs by others keep running).');
    }
}
