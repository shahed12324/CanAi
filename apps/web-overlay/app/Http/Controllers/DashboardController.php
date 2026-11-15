<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Credits\CreditService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DashboardController extends Controller
{
    public function index(Request $request, TenantContext $context, CreditService $credits)
    {
        $current = null;
        $overview = null;
        if (($id = $request->session()->get('tenant_id')) !== null && TenantContext::isValidId((string) $id)) {
            $userId = $request->user()->getAuthIdentifier();
            // Only trust the session value if the user really is an active member of an active workspace.
            $current = DB::table('tenants as t')->join('tenant_users as tu', 'tu.tenant_id', '=', 't.id')
                ->where('t.id', $id)->where('tu.user_id', $userId)->where('tu.status', 'active')
                ->where('t.status', 'active')->whereNull('t.deleted_at')->first(['t.id', 't.name', 't.type']);
            if ($current !== null) {
                $overview = $context->run($current->id, fn () => [
                    'wallet' => $credits->wallet($current->id),
                    'spent' => $credits->spentThisMonth($current->id),
                    'recent' => DB::table('conversations')->where('user_id', $userId)->whereNull('deleted_at')->orderByDesc('last_message_at')->limit(5)->get(['id', 'title', 'last_message_at']),
                    'questions' => (int) DB::table('messages as m')->join('conversations as c', 'c.id', '=', 'm.conversation_id')
                        ->where('c.user_id', $userId)->where('m.role', 'user')->where('m.created_at', '>=', now()->startOfMonth())->count(),
                ]);
            }
        }

        return view('dashboard', ['current' => $current, 'overview' => $overview]);
    }
}
