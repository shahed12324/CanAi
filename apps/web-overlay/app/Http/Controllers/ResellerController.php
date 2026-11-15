<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Reseller\ResellerService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** A workspace owner can type a reseller's code (once); a reseller sees their own customers and commission. */
final class ResellerController extends Controller
{
    public function __construct(private readonly ResellerService $service, private readonly TenantContext $tenant) {}

    public function index(Request $request)
    {
        $t = $this->tenant->idOrFail();
        $u = (string) $request->user()->id;

        return view('reseller.index', ['joined' => $this->service->joinedTo($t, $u), 'me' => $this->service->me($t, $u), 'report' => $this->service->report($t, $u)]);
    }

    public function join(Request $request)
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $r = $this->service->join($this->tenant->idOrFail(), (string) $request->user()->id, $d['code']);

        return $r === 'ok' ? back()->with('status', ResellerService::message($r)) : back()->withErrors(['code' => ResellerService::message($r)]);
    }
}
