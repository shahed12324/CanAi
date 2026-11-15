<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Credits\CreditService;
use App\Services\AiEngine\AiEngineClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Dashboard, usage, automation monitor and workspace health for the phone. Read-only. */
final class OverviewController extends MobileController
{
    public function dashboard(Request $request, CreditService $credits): JsonResponse
    {
        $tenant = $this->tenant->idOrFail();
        $user = $this->userId($request);
        $w = $credits->wallet($tenant);

        return response()->json(['data' => [
            'balance' => $w === null ? '0' : (string) $w->available,
            'low_balance' => $w !== null && $w->low_balance_threshold !== null && (float) $w->available <= (float) $w->low_balance_threshold,
            'spent_this_month' => $credits->spentThisMonth($tenant),
            'pending_approvals' => (int) DB::table('outreach_messages')->where('status', 'pending_approval')->count(),
            'leads' => (int) DB::table('leads')->whereNull('deleted_at')->count(),
            'unread' => $this->repo->unreadCount($user),
            'emergency' => $this->repo->emergencyState($tenant),
            'recent_chats' => DB::table('conversations')->where('user_id', $user)->whereNull('deleted_at')->orderByDesc('last_message_at')->limit(5)->get(['id', 'title', 'last_message_at']),
        ]]);
    }

    public function usage(Request $request, CreditService $credits): JsonResponse
    {
        $tenant = $this->tenant->idOrFail();
        $days = DB::select("select (created_at at time zone 'Asia/Dhaka')::date::text as day, sum(credits_charged) as credits, count(*) as calls from usage_records
            where tenant_id = ? and created_at >= now() - interval '30 days' group by 1 order by 1", [$tenant]);
        $w = $credits->wallet($tenant);

        return response()->json(['data' => [
            'balance' => $w === null ? '0' : (string) $w->available,
            'spent_this_month' => $credits->spentThisMonth($tenant),
            'monthly_limit' => $w?->spending_limit_monthly,
            'daily' => array_map(fn ($r) => ['day' => $r->day, 'credits' => (string) $r->credits, 'calls' => (int) $r->calls], $days),
        ]]);
    }

    /** What is running on its own in this workspace: outreach campaigns and lead-research jobs. (Agents and workflows arrive in a later phase.) */
    public function automations(Request $request): JsonResponse
    {
        $this->need($request, 'outreach.view');
        $campaigns = DB::select("select c.id, c.name, c.status,
              count(m.*) filter (where m.status = 'pending_approval') as pending, count(m.*) filter (where m.status = 'approved') as queued,
              count(m.*) filter (where m.status in ('sent', 'replied', 'bounced')) as sent, count(m.*) filter (where m.status = 'replied') as replied,
              count(m.*) filter (where m.status = 'failed') as failed
            from outreach_campaigns c left join outreach_messages m on m.campaign_id = c.id group by c.id order by c.created_at desc limit 30");
        $jobs = DB::select("select id, name, status, total, created_at::text as created_at from lead_research_jobs order by created_at desc limit 10");

        return response()->json(['data' => [
            'campaigns' => array_map(fn ($r) => ['id' => trim($r->id), 'name' => $r->name, 'status' => $r->status, 'pending' => (int) $r->pending, 'queued' => (int) $r->queued, 'sent' => (int) $r->sent, 'replied' => (int) $r->replied, 'failed' => (int) $r->failed], $campaigns),
            'research_jobs' => array_map(fn ($r) => ['id' => trim($r->id), 'name' => $r->name, 'status' => $r->status, 'total' => (int) $r->total, 'created_at' => $r->created_at], $jobs),
        ]]);
    }

    /** Owner / Admin only: is everything that keeps this workspace working actually working? */
    public function health(Request $request, AiEngineClient $engine): JsonResponse
    {
        $this->need($request, 'workspace.manage');
        $tenant = $this->tenant->idOrFail();
        try {
            $e = $engine->health($tenant);
            $engineOk = ($e['status'] ?? null) === 'ok';
        } catch (\Throwable) {
            $engineOk = false;
        }
        $accounts = DB::select("select id, name, from_email, provider, last_error from email_accounts where status = 'active' and last_error is not null limit 20");
        $failed = (int) DB::table('outreach_messages')->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();

        return response()->json(['data' => [
            'ai_engine' => $engineOk,
            'emergency' => $this->repo->emergencyState($tenant),
            'pending_approvals' => (int) DB::table('outreach_messages')->where('status', 'pending_approval')->count(),
            'failed_mails_24h' => $failed,
            'email_accounts_with_errors' => array_map(fn ($a) => ['id' => trim($a->id), 'name' => $a->name, 'from' => $a->from_email, 'provider' => $a->provider, 'error' => $a->last_error], $accounts),
            'ok' => $engineOk && $accounts === [] && $failed === 0,
        ]]);
    }
}
