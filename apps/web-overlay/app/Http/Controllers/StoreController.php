<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalog\Stores\StoreConnector;
use App\Catalog\Stores\StoreException;
use App\Catalog\Stores\StoreFactory;
use App\Catalog\Stores\StoreGuard;
use App\Catalog\Stores\StoreRepo;
use App\Catalog\Stores\StoreSync;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Connected WooCommerce / Shopify stores. Secrets are write-only: they are never shown again. */
final class StoreController extends Controller
{
    public const PLATFORM_BN = ['woocommerce' => 'WooCommerce', 'shopify' => 'Shopify', 'jarvis_connector' => 'JARVIS Connector'];

    public function __construct(private readonly StoreRepo $stores, private readonly TenantContext $tenant) {}

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    private function need(Request $r, string $permission): void
    {
        abort_unless($r->user()->can($permission), 403);
    }

    /** @return array<string, mixed> */
    private function mine(string $id): array
    {
        $s = $this->stores->store(self::id($id)) ?? abort(404);
        abort_if($s['platform'] === 'native', 404);

        return $s;
    }

    public function index(Request $request)
    {
        $this->need($request, 'catalog.view');
        $stores = $this->stores->stores();

        return view('catalog.stores', [
            'stores' => array_map(fn ($s) => $s + ['runs' => $this->stores->runs($s['id'], 3)], $stores), 'platformBn' => self::PLATFORM_BN,
            'canManage' => $request->user()->can('stores.manage'), 'canSync' => $request->user()->can('catalog.manage'),
        ]);
    }

    public function store(Request $request, StoreConnector $connector)
    {
        $this->need($request, 'stores.manage');
        $d = $request->validate([
            'platform' => ['required', 'in:woocommerce,shopify,jarvis_connector'], 'name' => ['required', 'string', 'max:120'], 'address' => ['required', 'string', 'max:300'],
            'key' => ['nullable', 'string', 'max:300'], 'secret' => ['nullable', 'string', 'max:300'], 'token' => ['nullable', 'string', 'max:300'], 'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $r = $connector->connect($this->tenant->idOrFail(), $d['platform'], $d['name'], $d['address'], ['key' => $d['key'] ?? '', 'secret' => $d['secret'] ?? '', 'token' => $d['token'] ?? ''], $d['currency'] ?? 'BDT');
        if (isset($r['error'])) {
            return back()->withErrors(['address' => $r['error']])->withInput($request->except(['key', 'secret', 'token']));
        }

        return redirect()->route('catalog.stores')->with('status', 'The store was connected. Now "Sync now" press — the store’s products will come in as drafts.');
    }

    public function update(Request $request, string $id, StoreFactory $factory)
    {
        $this->need($request, 'stores.manage');
        $s = $this->mine($id);
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'address' => ['required', 'string', 'max:300'], 'key' => ['nullable', 'string', 'max:300'], 'secret' => ['nullable', 'string', 'max:300'],
            'token' => ['nullable', 'string', 'max:300'], 'currency' => ['required', 'string', 'size:3']]);
        $currency = strtoupper($d['currency']);
        abort_unless(preg_match('/\A[A-Z]{3}\z/', $currency) === 1, 422);
        try {
            $base = StoreGuard::normalise($s['platform'], $d['address']);
            $creds = $s['platform'] === 'shopify' ? ['token' => trim((string) ($d['token'] ?? ''))] : ($s['platform'] === 'jarvis_connector' ? ['secret' => trim((string) ($d['secret'] ?? ''))] : ['key' => trim((string) ($d['key'] ?? '')), 'secret' => trim((string) ($d['secret'] ?? ''))]);
            $changed = array_filter($creds, fn ($v) => $v !== '') !== [];
            $sealed = null;
            if ($changed) {
                if ($s['platform'] === 'jarvis_connector' && strlen($creds['secret']) < 32) {
                    return back()->withErrors(['address' => 'The secret must be at least 32 characters.']);
                }
                if (in_array('', $creds, true) || preg_match('/\s/', implode('', $creds)) === 1) {
                    return back()->withErrors(['address' => 'When giving a new key, fill in all fields (without spaces).']);
                }
                $factory->build($s['platform'], $base, $creds, $currency)->ping();
                $sealed = $factory->seal($creds);
            }
        } catch (StoreException $e) {
            return back()->withErrors(['address' => $e->userMessage()]);
        }
        $this->stores->updateStore($s['id'], trim($d['name']), $base, $sealed['cipher'] ?? null, $sealed['version'] ?? null, $currency);

        return back()->with('status', 'Saved.');
    }

    public function toggle(Request $request, string $id)
    {
        $this->need($request, 'stores.manage');
        $s = $this->mine($id);
        $this->stores->setStatus($s['id'], $s['status'] === 'paused' ? 'active' : 'paused', null, false);

        return back()->with('status', $s['status'] === 'paused' ? 'Sync is on again.' : 'Sync was kept off.');
    }

    public function sync(Request $request, string $id, StoreSync $sync)
    {
        $this->need($request, 'catalog.manage');
        $s = $this->mine($id);
        @set_time_limit(120);
        $r = $sync->run($this->tenant->idOrFail(), $s['id'], 'manual', (string) $request->user()->getAuthIdentifier());
        if ($r['note'] === 'already_running') {
            return back()->with('error', 'This store’s sync is running now — look again shortly.');
        }
        if ($r['note'] === 'paused') {
            return back()->with('error', 'Sync is kept off.');
        }
        $msg = sprintf('Sync finished: read %d, new %d, updated %d, sent to store %d, failed %d.', $r['pulled'], $r['created'], $r['updated'], $r['pushed'], $r['failed']);
        if (str_starts_with((string) $r['note'], 'emergency')) {
            $msg .= ' The emergency stop is on — nothing was sent to the store.';
        }

        return back()->with($r['ok'] ? 'status' : 'error', $r['ok'] ? $msg : ($this->stores->store($s['id'])['last_error'] ?? 'Sync failed.'));
    }
}
