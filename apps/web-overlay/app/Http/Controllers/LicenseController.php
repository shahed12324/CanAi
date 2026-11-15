<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\License\LicenseService;
use App\Tenancy\TenantContext;

/** Read-only: the workspace's Enterprise licence (seats used, left, expiry). */
final class LicenseController extends Controller
{
    public function index(LicenseService $licenses, TenantContext $tenant)
    {
        return view('license.index', ['l' => $licenses->status($tenant->idOrFail())]);
    }
}
