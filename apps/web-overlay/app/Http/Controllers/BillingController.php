<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\BillingException;
use App\Billing\CheckoutResult;
use App\Billing\Customer;
use App\Billing\GatewayFailure;
use App\Billing\GatewayRegistry;
use App\Billing\PaymentService;
use App\Billing\PgBillingRepo;
use App\Billing\Urls;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Customer billing: plans, credit packs, checkout, orders, invoices, cancel / resume / downgrade, invoice details. */
final class BillingController extends Controller
{
    public function index(Request $request, TenantContext $tenant, GatewayRegistry $gateways)
    {
        $this->authorizeView($request);
        $currencies = $this->currencies($gateways);
        $currency = $this->currency($request, $currencies);
        $plans = DB::select("select p.id, p.code, p.name, p.description, p.included_credits, p.sort from plans p where p.status = 'active' and not p.is_lifetime_free and p.is_public order by p.sort");
        $prices = [];
        foreach (DB::select('select plan_id, interval, amount from plan_prices where currency = ?', [$currency]) as $r) {
            $prices[trim($r->plan_id)][$r->interval] = (int) round(((float) $r->amount) * 100);
        }
        $sub = DB::selectOne("select s.status, s.interval, s.current_period_start, s.current_period_end, s.cancel_at, s.grace_ends_at, s.pending_interval, p.id as plan_id, p.name as plan_name, p.sort, pp.name as pending_name
            from subscriptions s join plans p on p.id = s.plan_id left join plans pp on pp.id = s.pending_plan_id
            where s.status in ('active', 'trialing', 'past_due', 'grace') limit 1");

        return view('billing.index', [
            'sub' => $sub, 'plans' => $plans, 'prices' => $prices, 'currency' => $currency, 'currencies' => $currencies,
            'orders' => DB::select('select id, item_name, currency, total_minor, status, gateway, created_at from orders order by created_at desc limit 8'),
            'canManage' => $request->user()->can('billing.manage'), 'result' => $request->query('result'),
            'gatewaysReady' => count($gateways->all()) > 0,
        ]);
    }

    public function credits(Request $request, GatewayRegistry $gateways)
    {
        $this->authorizeView($request);
        $currency = $this->currency($request, $this->currencies($gateways));

        return view('billing.credits', [
            'packages' => DB::select("select id, name, credits, bonus_credits, price, currency, expires_after_days from credit_packages where status = 'active' and currency = ? order by sort, price", [$currency]),
            'currency' => $currency, 'canManage' => $request->user()->can('billing.manage'),
        ]);
    }

    /** Price breakdown before paying. Everything shown comes from billing_quote(): the browser sends only what it wants, never what it costs. */
    public function checkout(Request $request, TenantContext $tenant, PgBillingRepo $repo, GatewayRegistry $gateways)
    {
        abort_unless($request->user()->can('billing.manage'), 403);
        $data = $this->validatedPurchase($request);
        $quote = null;
        $error = null;
        try {
            $quote = $repo->quote($tenant->idOrFail(), $data['kind'], $data['ref'], $data['interval'], $data['currency'], $data['coupon']);
        } catch (BillingException $e) {
            $error = $e->userMessage();
            if (in_array($e->errorCode, ['coupon_invalid', 'coupon_exhausted', 'coupon_used'], true)) {      // keep the page usable: show the price without the coupon
                try {
                    $quote = $repo->quote($tenant->idOrFail(), $data['kind'], $data['ref'], $data['interval'], $data['currency'], null);
                    $data['coupon'] = null;
                } catch (BillingException) {
                }
            }
        }

        return view('billing.checkout', ['quote' => $quote, 'error' => $error, 'data' => $data, 'gateways' => $gateways->forCurrency($data['currency'])]);
    }

    public function pay(Request $request, TenantContext $tenant, PaymentService $payments, GatewayRegistry $gateways)
    {
        abort_unless($request->user()->can('billing.manage'), 403);
        $data = $this->validatedPurchase($request);
        $gateway = (string) $request->input('gateway', '');
        $user = $request->user();
        $urls = fn (string $g) => new Urls(route('billing.return', $g), route('billing.ipn', $g), route('billing.cancelled'));

        try {
            if ($gateway !== 'free') {
                $request->validate(['gateway' => ['required', Rule::in(array_map(fn ($g) => $g->code(), $gateways->all()))]]);
            }
            /** @var CheckoutResult $r */
            $r = $payments->checkout($tenant->idOrFail(), (string) $user->id, new Customer((string) $user->name, (string) $user->email),
                $data['kind'], $data['ref'], $data['interval'], $data['currency'], $data['coupon'], $gateway, $urls($gateway));
        } catch (BillingException $e) {
            return back()->withInput()->withErrors(['billing' => $e->userMessage()]);
        } catch (GatewayFailure $e) {
            report($e);

            return back()->withInput()->withErrors(['billing' => 'The payment gateway is not responding right now. Try again after some time.']);
        }

        return $r->settled || $r->redirectUrl === null
            ? redirect()->route('billing.index', ['result' => 'applied'])
            : redirect()->away($r->redirectUrl);
    }

    public function cancelled()
    {
        return redirect()->route('billing.index', ['result' => 'cancelled']);
    }

    public function orders(Request $request)
    {
        $this->authorizeView($request);

        return view('billing.orders', ['rows' => DB::table('orders')->orderByDesc('created_at')->paginate(20, ['id', 'item_name', 'currency', 'total_minor', 'status', 'gateway', 'created_at', 'paid_at'])]);
    }

    public function invoices(Request $request)
    {
        $this->authorizeView($request);

        return view('billing.invoices', ['rows' => DB::table('invoices')->orderByDesc('issued_at')->paginate(20, ['id', 'number', 'kind', 'currency', 'total_minor', 'issued_at'])]);
    }

    public function invoice(Request $request, string $id)
    {
        $this->authorizeView($request);
        $inv = DB::table('invoices')->where('id', $id)->first();
        abort_if($inv === null, 404);
        $inv->lines = json_decode($inv->lines, true) ?: [];
        $inv->seller = json_decode($inv->seller, true) ?: [];
        $inv->buyer = json_decode($inv->buyer, true) ?: [];

        return view('billing.invoice', ['inv' => $inv]);
    }

    public function profile(Request $request, TenantContext $tenant)
    {
        $this->authorizeView($request);

        return view('billing.profile', ['p' => DB::table('billing_profiles')->first(), 'canManage' => $request->user()->can('billing.manage')]);
    }

    public function saveProfile(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('billing.manage'), 403);
        $d = $request->validate(['legal_name' => ['required', 'string', 'max:200'], 'address' => ['nullable', 'string', 'max:500'], 'tax_id' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:254']]);
        DB::statement('insert into billing_profiles (tenant_id, legal_name, address, tax_id, email) values (?, ?, ?, ?, ?)
            on conflict (tenant_id) do update set legal_name = excluded.legal_name, address = excluded.address, tax_id = excluded.tax_id, email = excluded.email, updated_at = now()',
            [$tenant->idOrFail(), trim($d['legal_name']), $d['address'] ?? null, $d['tax_id'] ?? null, $d['email'] ?? null]);

        return back()->with('status', 'Billing information was saved. It will be printed on new invoices.');
    }

    public function cancel(Request $request, TenantContext $tenant, PgBillingRepo $repo)
    {
        return $this->act($request, fn () => $repo->cancelSubscription($tenant->idOrFail(), $request->boolean('immediate')) === 'cancelled'
            ? 'The subscription was cancelled.' : 'The subscription will be cancelled at the end of the current term. All benefits stay active until then.');
    }

    public function resume(Request $request, TenantContext $tenant, PgBillingRepo $repo)
    {
        return $this->act($request, function () use ($tenant, $repo) {
            $repo->resumeSubscription($tenant->idOrFail());

            return 'The cancellation was withdrawn.';
        });
    }

    public function downgrade(Request $request, TenantContext $tenant, PgBillingRepo $repo)
    {
        $d = $request->validate(['plan' => ['required', 'string', 'size:26'], 'interval' => ['required', Rule::in(['month', 'year'])]]);

        return $this->act($request, function () use ($tenant, $repo, $d) {
            $repo->scheduleDowngrade($tenant->idOrFail(), $d['plan'], $d['interval']);

            return 'The downgrade was scheduled; it takes effect when the current term ends. No money will be charged or refunded now.';
        });
    }

    public function clearDowngrade(Request $request, TenantContext $tenant, PgBillingRepo $repo)
    {
        return $this->act($request, function () use ($tenant, $repo) {
            $repo->clearDowngrade($tenant->idOrFail());

            return 'The scheduled downgrade was cancelled.';
        });
    }

    /** The pretend-gateway page: shows signed Pay / Fail buttons. Only exists while BILLING_DEMO=true. */
    public function demo(Request $request, string $order, GatewayRegistry $gateways, TenantContext $tenant)
    {
        abort_unless($request->user()->can('billing.manage'), 403);
        $demo = $gateways->find('demo');
        abort_if($demo === null, 404);
        $o = DB::selectOne("select id, item_name, currency, total_minor, status from orders where id = ? and gateway = 'demo'", [$order]);
        abort_if($o === null || $o->status !== 'pending', 404);
        $sign = fn (string $r) => $demo->sign(trim($o->id), (int) $o->total_minor, $r);

        return view('billing.demo', ['o' => $o, 'ref' => 'demo_'.trim($o->id), 'paySig' => $sign('paid'), 'failSig' => $sign('fail')]);
    }

    // ------------------------------------------------------------------------------------------------------------------

    private function act(Request $request, \Closure $do)
    {
        abort_unless($request->user()->can('billing.manage'), 403);
        try {
            return back()->with('status', $do());
        } catch (BillingException $e) {
            return back()->withErrors(['billing' => $e->userMessage()]);
        }
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->can('billing.view') || $request->user()->can('billing.manage'), 403);
    }

    /** @return list<string> */
    private function currencies(GatewayRegistry $gateways): array
    {
        $c = [];
        foreach ($gateways->all() as $g) {
            $c = array_merge($c, $g->currencies());
        }

        return array_values(array_unique($c ?: ['BDT']));
    }

    private function currency(Request $request, array $allowed): string
    {
        $c = strtoupper((string) $request->query('currency', 'BDT'));

        return in_array($c, $allowed, true) ? $c : $allowed[0];
    }

    /** @return array{kind: string, ref: string, interval: ?string, currency: string, coupon: ?string} */
    private function validatedPurchase(Request $request): array
    {
        $d = $request->validate([
            'kind' => ['required', Rule::in(['subscription', 'credits'])],
            'ref' => ['required', 'string', 'size:26', 'regex:/\A[0-9a-z]{26}\z/'],
            'interval' => ['nullable', Rule::in(['month', 'year'])],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'coupon' => ['nullable', 'string', 'max:32'],
        ]);

        return ['kind' => $d['kind'], 'ref' => $d['ref'], 'interval' => $d['kind'] === 'subscription' ? ($d['interval'] ?? 'month') : null, 'currency' => $d['currency'], 'coupon' => trim((string) ($d['coupon'] ?? '')) ?: null];
    }
}
