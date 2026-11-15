<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Brand\BrandService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** The workspace owner's white-label settings (only usable once a platform admin has switched branding on). */
final class BrandController extends Controller
{
    public function __construct(private readonly BrandService $brands, private readonly TenantContext $tenant) {}

    public function index()
    {
        return view('brand.index', ['b' => $this->brands->settings($this->tenant->idOrFail())]);
    }

    public function save(Request $request)
    {
        $d = $request->validate(['brand_name' => ['nullable', 'string', 'max:40'], 'color' => ['nullable', 'string', 'max:7'], 'support_email' => ['nullable', 'string', 'max:254'], 'hide_powered_by' => ['nullable', 'boolean']]);
        $r = $this->brands->save($this->tenant->idOrFail(), (string) $request->user()->id, (string) ($d['brand_name'] ?? ''), (string) ($d['color'] ?? ''), (string) ($d['support_email'] ?? ''), (bool) ($d['hide_powered_by'] ?? false));

        return $r === 'ok' ? back()->with('status', BrandService::message($r)) : back()->withErrors(['brand' => BrandService::message($r)])->withInput();
    }
}
