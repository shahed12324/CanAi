<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Tenancy\CreateWorkspace;
use App\Admin\AdminAudit;
use App\Admin\AssistantParser;
use App\Admin\Broadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The admin AI assistant: ask in a sentence ("how many shops", "open tickets", "revenue this month") or give a command
 * ("suspend shop acme because unpaid", "start trial for acme 14 days", "announce: …"). Questions are answered at once;
 * every change shows what it will do and waits for a Confirm click. It runs under the signed-in admin's own permissions.
 */
final class AssistantController extends AdminController
{
    private const NEED = [
        'stats' => 'tenants.view', 'find_shop' => 'tenants.view', 'usage' => 'tenants.view',
        'revenue' => 'billing.manage', 'tickets' => 'users.manage', 'trials' => 'plans.manage',
        'suspend_shop' => 'tenants.manage', 'activate_shop' => 'tenants.manage',
        'start_trial' => 'plans.manage', 'extend_trial' => 'plans.manage', 'announce' => 'settings.manage',
    ];

    private const HELP = [
        'Ask: "how many shops", "revenue this month", "open tickets", "running trials", "top shops by usage", "find shop acme".',
        'Do (needs your confirmation): "suspend shop acme because unpaid", "activate shop acme", "start trial for acme 14 days on pro", "extend trial acme by 7 days", "announce: Maintenance tonight at 2am".',
    ];

    public function index(Request $request)
    {
        $this->allow('admin.access');

        return view('admin.assistant', ['log' => $request->session()->get('assistant_log', []), 'pending' => $request->session()->get('assistant_pending')]);
    }

    public function ask(Request $request)
    {
        $this->allow('admin.access');
        $text = (string) $request->validate(['text' => ['required', 'string', 'max:300']])['text'];
        $i = AssistantParser::parse($text);
        $name = $i['name'];
        $request->session()->forget('assistant_pending');

        if ($name === 'help') {
            return $this->say($request, $text, self::HELP);
        }
        if ($name === 'unknown') {
            return $this->say($request, $text, ['I did not understand that.', ...self::HELP]);
        }
        if (! $request->user()->can(self::NEED[$name])) {
            return $this->say($request, $text, ['Your admin role does not allow that.']);
        }
        if (! AssistantParser::isAction($name)) {
            return $this->say($request, $text, $this->answer($name, $i['args']));
        }

        $plan = $this->plan($name, $i['args']);
        if (isset($plan['error'])) {
            return $this->say($request, $text, [$plan['error']]);
        }
        $request->session()->put('assistant_pending', ['id' => Str::random(20), 'name' => $name, 'args' => $plan['args'], 'summary' => $plan['summary']]);

        return $this->say($request, $text, ['Please confirm: '.$plan['summary']], keepPending: true);
    }

    public function confirm(Request $request, string $id)
    {
        $this->allow('admin.access');
        $p = $request->session()->pull('assistant_pending');
        abort_unless(is_array($p) && hash_equals((string) $p['id'], $id) && $request->user()->can(self::NEED[$p['name']]), 403);
        $lines = $this->execute($p['name'], $p['args'], $request);
        $this->log($request, 'confirmed: '.$p['summary'], $lines);

        return redirect()->route('admin.assistant.index');
    }

    public function cancel(Request $request)
    {
        $this->allow('admin.access');
        $request->session()->forget('assistant_pending');
        $this->log($request, 'cancelled', ['Cancelled. Nothing was changed.']);

        return redirect()->route('admin.assistant.index');
    }

    // ------------------------------------------------------------------ answers (read only)

    /** @param array<string, mixed> $a @return list<string> */
    private function answer(string $name, array $a): array
    {
        $db = $this->db();
        $shops = $db->table('tenants')->whereNull('deleted_at')->where('type', 'organization');

        switch ($name) {
            case 'stats':
                return [
                    sprintf('Shops: %d total, %d active, %d inactive.', (clone $shops)->count(), (clone $shops)->where('status', 'active')->count(), (clone $shops)->whereIn('status', ['suspended', 'closed'])->count()),
                    sprintf('Users: %d. Running trials: %d. Open tickets: %d.', $db->table('users')->whereNull('deleted_at')->count(), $db->table('subscriptions')->where('status', 'trialing')->count(), $db->table('support_tickets')->whereIn('status', ['open', 'pending'])->count()),
                ];
            case 'revenue':
                $rows = $db->table('orders')->whereIn('status', ['paid', 'partially_refunded'])->where('paid_at', '>=', now()->startOfMonth())
                    ->selectRaw('currency, count(*) as n, sum(total_minor - refunded_minor) as minor')->groupBy('currency')->get();

                return $rows->isEmpty() ? ['No paid orders yet this month.'] : $rows->map(fn ($r) => sprintf('This month: %s %s from %d paid orders (after refunds).', trim($r->currency), number_format($r->minor / 100, 2), $r->n))->all();
            case 'tickets':
                $rows = $db->table('support_tickets as t')->join('tenants as w', 'w.id', '=', 't.tenant_id')->whereIn('t.status', ['open', 'pending'])
                    ->orderByRaw("case t.priority when 'high' then 0 when 'normal' then 1 else 2 end")->orderBy('t.last_message_at')->limit(8)->get(['t.subject', 't.priority', 't.status', 'w.name as shop']);

                return $rows->isEmpty() ? ['No open tickets.'] : ['Open tickets (most urgent first):', ...$rows->map(fn ($r) => "• [{$r->priority}] {$r->subject} — {$r->shop} ({$r->status})")->all()];
            case 'trials':
                $rows = $db->table('subscriptions as s')->join('tenants as t', 't.id', '=', 's.tenant_id')->join('plans as p', 'p.id', '=', 's.plan_id')
                    ->where('s.status', 'trialing')->orderBy('s.current_period_end')->limit(10)->get(['t.name as shop', 't.slug', 'p.name as plan', 's.current_period_end']);

                return $rows->isEmpty() ? ['No trials are running.'] : ['Running trials (soonest to end first):', ...$rows->map(fn ($r) => "• {$r->shop} ({$r->slug}) — {$r->plan}, ends ".($r->current_period_end ? Carbon::parse($r->current_period_end)->format('d M Y') : '—'))->all()];
            case 'usage':
                $rows = $db->table('credit_transactions as c')->join('tenants as t', 't.id', '=', 'c.tenant_id')->where('c.type', 'debit')->where('c.created_at', '>=', now()->subDays(30))
                    ->groupBy('t.id', 't.name')->orderByRaw('sum(-c.amount) desc')->limit(5)->get(['t.name', $db->raw('sum(-c.amount) as spent')]);

                return $rows->isEmpty() ? ['No AI usage in the last 30 days.'] : ['Top shops by AI credits (30 days):', ...$rows->map(fn ($r) => sprintf('• %s — %s', $r->name, number_format((float) $r->spent, 2)))->all()];
            case 'find_shop':
                $like = $this->like((string) $a['term']);
                $rows = $db->table('tenants as t')->join('users as u', 'u.id', '=', 't.owner_id')->whereNull('t.deleted_at')
                    ->where(fn ($w) => $w->where('t.name', 'ilike', $like)->orWhere('t.slug', 'ilike', $like)->orWhere('u.email', 'ilike', $like))->limit(6)->get(['t.name', 't.slug', 't.status', 'u.email']);

                return $rows->isEmpty() ? ['No shop matches that.'] : $rows->map(fn ($r) => "• {$r->name} ({$r->slug}) — {$r->status}, owner {$r->email}")->all();
        }

        return ['Done.'];
    }

    // ------------------------------------------------------------------ actions

    /** Resolve names to ids and describe the change. @param array<string, mixed> $a @return array{summary?: string, args?: array<string, mixed>, error?: string} */
    private function plan(string $name, array $a): array
    {
        if ($name === 'announce') {
            return ['args' => $a, 'summary' => 'send the notification "'.$a['title'].'" to every active shop member.'];
        }
        $shop = $this->shop((string) $a['shop']);
        if ($shop === null) {
            return ['error' => 'I could not find a shop "'.$a['shop'].'". Use its slug or id (try: find shop <name>).'];
        }
        $args = $a + ['shop_id' => $shop->id];

        switch ($name) {
            case 'suspend_shop':
                return $shop->status === 'suspended' ? ['error' => $shop->name.' is already suspended.']
                    : ['args' => $args, 'summary' => "suspend {$shop->name} ({$shop->slug}). Its members lose access until you activate it again.".(($a['reason'] ?? '') !== '' ? " Reason: {$a['reason']}" : '')];
            case 'activate_shop':
                return $shop->status === 'active' ? ['error' => $shop->name.' is already active.'] : ['args' => $args, 'summary' => "activate {$shop->name} ({$shop->slug})."];
            case 'start_trial':
                $plan = $this->trialPlan((string) ($a['plan'] ?? ''));
                if ($plan === null) {
                    return ['error' => 'Tell me which plan: "start trial for '.$shop->slug.' '.$a['days'].' days on <plan code>".'];
                }

                return ['args' => $args + ['plan_id' => $plan->id], 'summary' => "start a {$a['days']}-day free trial of {$plan->name} for {$shop->name}."];
            case 'extend_trial':
                return $this->db()->table('subscriptions')->where('tenant_id', $shop->id)->where('status', 'trialing')->exists()
                    ? ['args' => $args, 'summary' => "extend {$shop->name}'s trial by {$a['days']} days."]
                    : ['error' => $shop->name.' has no running trial.'];
        }

        return ['error' => 'Unsupported.'];
    }

    /** @param array<string, mixed> $a @return list<string> */
    private function execute(string $name, array $a, Request $request): array
    {
        $db = $this->db();
        $me = (string) $request->user()->getKey();

        if ($name === 'announce') {
            $n = Broadcaster::send($db, (string) $a['title'], '', 'all', null, $me);

            return ["Sent to $n people."];
        }
        $id = (string) $a['shop_id'];
        $shop = $db->table('tenants')->where('id', $id)->whereNull('deleted_at')->first();
        if ($shop === null) {
            return ['That shop no longer exists.'];
        }

        switch ($name) {
            case 'suspend_shop':
            case 'activate_shop':
                $to = $name === 'suspend_shop' ? 'suspended' : 'active';
                $db->transaction(function () use ($db, $id, $shop, $to, $a): void {
                    $db->table('tenants')->where('id', $id)->update(['status' => $to, 'updated_at' => now()]);
                    AdminAudit::record($to === 'suspended' ? 'admin.tenant.suspended' : 'admin.tenant.activated', 'tenant', $id, ['status' => $shop->status], ['status' => $to, 'via' => 'assistant', 'reason' => $a['reason'] ?? null], $id);
                });

                return ["{$shop->name} is now $to."];
            case 'start_trial':
                $live = $db->table('subscriptions')->where('tenant_id', $id)->whereIn('status', ['active', 'trialing', 'past_due', 'grace'])->first();
                if ($live !== null && $live->status !== 'trialing') {
                    return ["{$shop->name} already has a paid or running subscription; I did not change it."];
                }
                $db->transaction(function () use ($db, $id, $a, $live): void {
                    if ($live !== null) {
                        $db->table('subscriptions')->where('id', $live->id)->update(['status' => 'cancelled', 'cancel_at' => now(), 'updated_at' => now()]);
                    }
                    $db->table('subscriptions')->insert([
                        'id' => CreateWorkspace::newId(), 'tenant_id' => $id, 'plan_id' => $a['plan_id'], 'status' => 'trialing',
                        'current_period_start' => now(), 'current_period_end' => now()->addDays((int) $a['days']), 'gateway' => 'manual', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    AdminAudit::record('admin.trial.granted', 'tenant', $id, null, ['days' => (int) $a['days'], 'via' => 'assistant'], $id);
                });

                return ["Trial started for {$shop->name} ({$a['days']} days)."];
            case 'extend_trial':
                $s = $db->table('subscriptions')->where('tenant_id', $id)->where('status', 'trialing')->first();
                if ($s === null) {
                    return ['That trial is gone; nothing changed.'];
                }
                $base = $s->current_period_end !== null && $s->current_period_end > now() ? Carbon::parse($s->current_period_end) : now();
                $db->table('subscriptions')->where('id', $s->id)->update(['current_period_end' => $base->copy()->addDays((int) $a['days']), 'updated_at' => now()]);
                AdminAudit::record('admin.trial.extended', 'tenant', $id, ['period_end' => $s->current_period_end], ['days' => (int) $a['days'], 'via' => 'assistant'], $id);

                return ["Trial extended by {$a['days']} days."];
        }

        return ['Nothing to do.'];
    }

    private function shop(string $ref): ?object
    {
        return $this->db()->table('tenants')->whereNull('deleted_at')->where(fn ($w) => $w->where('id', $ref)->orWhere('slug', $ref))->first(['id', 'name', 'slug', 'status']);
    }

    private function trialPlan(string $code): ?object
    {
        $q = $this->db()->table('plans')->where('status', '!=', 'retired');

        return $code !== '' ? $q->where(fn ($w) => $w->where('code', mb_strtolower($code))->orWhere('id', $code))->first(['id', 'name'])
            : $q->where('trial_days', '>', 0)->orderBy('sort')->first(['id', 'name']);
    }

    // ------------------------------------------------------------------ chat log (this admin's session only)

    /** @param list<string> $lines */
    private function say(Request $request, string $question, array $lines, bool $keepPending = false)
    {
        $this->log($request, $question, $lines);

        return redirect()->route('admin.assistant.index');
    }

    /** @param list<string> $lines */
    private function log(Request $request, string $q, array $lines): void
    {
        $log = $request->session()->get('assistant_log', []);
        $log[] = ['q' => mb_substr($q, 0, 300), 'a' => $lines, 'at' => now()->format('H:i')];
        $request->session()->put('assistant_log', array_slice($log, -20));
    }
}
