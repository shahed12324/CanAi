<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAudit;
use App\Billing\Amount;
use App\Billing\AdminBilling;
use App\Billing\BillingException;
use App\Billing\GatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin: revenue and subscription analytics, review queue, coupons, refunds. */
final class BillingController extends AdminController
{
    private const STATUSES = ['pending', 'paid', 'failed', 'cancelled', 'expired', 'review', 'partially_refunded', 'refunded'];

    public function index(Request $request, GatewayRegistry $gateways)
    {
        $this->allow('billing.manage');
        $b = new AdminBilling($this->db());
        $status = (string) $request->query('status', '');
        $status = in_array($status, self::STATUSES, true) ? $status : '';
        $churn = $b->churn();

        return view('admin.billing.index', [
            'revenue' => $b->revenueByMonth(12), 'mrr' => $b->mrr(), 'byPlan' => $b->subscribersByPlan(), 'churn' => $churn,
            'gatewayStats' => $b->gatewayStats(), 'orders' => $b->orders($status), 'status' => $status, 'statuses' => self::STATUSES,
            'review' => $b->reviewQueue(), 'refunds' => $b->recentRefunds(), 'coupons' => $b->coupons(),
            'configured' => array_map(fn ($g) => $g->code(), $gateways->all()),
            'settings' => $b->settings(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->allow('billing.manage');
        $d = $request->validate([
            'vat_percent' => ['required', 'numeric', 'min:0', 'max:100'], 'grace_days' => ['required', 'integer', 'min:0', 'max:60'], 'order_ttl_minutes' => ['required', 'integer', 'min:10', 'max:1440'],
            'seller_name' => ['nullable', 'string', 'max:200'], 'seller_address' => ['nullable', 'string', 'max:500'], 'seller_bin' => ['nullable', 'string', 'max:40'], 'seller_email' => ['nullable', 'email', 'max:254'],
        ]);
        $b = new AdminBilling($this->db());
        $me = (string) $request->user()->id;
        $before = $b->settings();
        $this->db()->transaction(function () use ($b, $d, $me): void {
            $b->saveSetting('billing.vat_bp', (int) round(((float) $d['vat_percent']) * 100), $me);
            $b->saveSetting('billing.grace_days', (int) $d['grace_days'], $me);
            $b->saveSetting('billing.order_ttl_minutes', (int) $d['order_ttl_minutes'], $me);
            $b->saveSetting('billing.seller', ['name' => trim((string) ($d['seller_name'] ?? '')), 'address' => trim((string) ($d['seller_address'] ?? '')), 'bin' => trim((string) ($d['seller_bin'] ?? '')), 'email' => trim((string) ($d['seller_email'] ?? ''))], $me);
        });
        AdminAudit::record('admin.billing.settings', 'settings', 'billing', $before, $b->settings());

        return back()->with('status', 'Billing settings were saved. They apply to new orders and invoices.');
    }

    public function storeCoupon(Request $request)
    {
        $this->allow('billing.manage');
        $d = $request->validate([
            'code' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{3,32}\z/'],
            'description' => ['nullable', 'string', 'max:200'],
            'kind' => ['required', Rule::in(['percent', 'fixed'])],
            'percent' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'fixed' => ['nullable', 'string', 'regex:/\A\d{1,9}(\.\d{1,2})?\z/'],
            'currency' => ['nullable', 'regex:/\A[A-Z]{3}\z/'],
            'applies_to' => ['required', Rule::in(['any', 'subscription', 'credits'])],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'per_tenant_limit' => ['required', 'integer', 'min:1', 'max:100'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
        ]);
        $percent = $d['kind'] === 'percent' ? (int) round(((float) ($d['percent'] ?? 0)) * 100) : null;
        $fixed = $d['kind'] === 'fixed' ? Amount::fromDecimal($d['fixed'] ?? null) : null;
        if (($d['kind'] === 'percent' && ! $percent) || ($d['kind'] === 'fixed' && (! $fixed || empty($d['currency'])))) {
            return back()->withInput()->withErrors(['code' => 'Give the coupon amount (and currency if fixed).']);
        }
        $coupon = [
            'code' => $d['code'], 'description' => $d['description'] ?? null, 'kind' => $d['kind'], 'percent_bp' => $percent, 'fixed_minor' => $fixed,
            'currency' => $d['kind'] === 'fixed' ? $d['currency'] : null, 'applies_to' => $d['applies_to'], 'max_redemptions' => $d['max_redemptions'] ?? null,
            'per_tenant_limit' => (int) $d['per_tenant_limit'], 'valid_from' => $d['valid_from'] ?? null, 'valid_until' => $d['valid_until'] ?? null,
        ];
        try {
            $id = (new AdminBilling($this->db()))->createCoupon($coupon);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return back()->withInput()->withErrors(['code' => 'A coupon with this code already exists.']);
        }
        AdminAudit::record('admin.coupon.created', 'coupon', $id, null, $coupon);

        return back()->with('status', 'The coupon was created.');
    }

    public function couponStatus(Request $request, string $id)
    {
        $this->allow('billing.manage');
        $status = $request->validate(['status' => ['required', Rule::in(['active', 'disabled'])]])['status'];
        (new AdminBilling($this->db()))->setCouponStatus($id, $status);
        AdminAudit::record('admin.coupon.status', 'coupon', $id, null, ['status' => $status]);

        return back()->with('status', 'The coupon’s status changed.');
    }

    /** Records a refund the admin has made (or is making) in the gateway dashboard: credit note, order status, optional credit claw-back / cancellation. */
    public function refund(Request $request, string $id)
    {
        $this->allow('billing.manage');
        $d = $request->validate([
            'amount' => ['required', 'string', 'regex:/\A\d{1,9}(\.\d{1,2})?\z/'], 'reason' => ['required', 'string', 'max:500'],
            'gateway_ref' => ['nullable', 'string', 'max:128'], 'claw_back' => ['nullable', 'boolean'], 'cancel_subscription' => ['nullable', 'boolean'],
        ]);
        try {
            $rid = (new AdminBilling($this->db()))->refund($id, (int) Amount::fromDecimal($d['amount']), $d['reason'], $d['gateway_ref'] ?? null,
                $request->boolean('claw_back'), $request->boolean('cancel_subscription'), (string) $request->user()->id);
        } catch (BillingException $e) {
            return back()->withErrors(['refund' => $e->userMessage().' ('.$e->errorCode.')']);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return back()->withErrors(['refund' => 'The refund could not be recorded (if the credit balance is low, "take back credits" leave it out and try).']);
        }
        AdminAudit::record('admin.refund.recorded', 'refund', $rid, null, ['order' => $id, 'amount' => $d['amount'], 'reason' => $d['reason']]);

        return back()->with('status', 'The refund was recorded and a credit note was issued. Remember: the actual money must be refunded from the gateway dashboard.');
    }

    public function closeReview(Request $request, string $id)
    {
        $this->allow('billing.manage');
        try {
            (new AdminBilling($this->db()))->closeReview($id, (string) $request->user()->id);
        } catch (BillingException $e) {
            return back()->withErrors(['refund' => $e->userMessage()]);
        }
        AdminAudit::record('admin.order.review_closed', 'order', $id, null, null);

        return back()->with('status', 'The order was closed.');
    }
}
