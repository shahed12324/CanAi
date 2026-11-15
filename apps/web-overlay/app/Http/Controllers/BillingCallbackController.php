<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\GatewayFailure;
use App\Billing\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Where gateways send the customer back and where they post IPNs / webhooks. No session: nothing here trusts the request itself. */
final class BillingCallbackController extends Controller
{
    private const SHOWN = ['applied', 'duplicate', 'failed', 'cancelled', 'pending', 'unverified', 'review', 'amount_mismatch', 'currency_mismatch', 'double_payment', 'error'];

    public function customerReturn(Request $request, string $gateway, PaymentService $payments)
    {
        try {
            $r = $payments->handleCallback($gateway, $this->input($request), $this->headers($request), $request->getContent());
            $outcome = in_array($r->outcome, self::SHOWN, true) ? $r->outcome : 'unverified';
        } catch (GatewayFailure $e) {
            report($e);
            $outcome = 'error';                                    // the gateway could not be asked; its IPN will settle it later
        }

        return redirect()->route('billing.index', ['result' => $outcome], 303);
    }

    public function ipn(Request $request, string $gateway, PaymentService $payments): Response
    {
        try {
            $r = $payments->handleCallback($gateway, $this->input($request), $this->headers($request), $request->getContent());
        } catch (GatewayFailure $e) {
            report($e);

            return response('retry', str_contains($e->getMessage(), 'signature') ? 400 : 503);   // 503: the gateway will deliver it again
        }

        return response($r->acknowledge() ? 'OK' : 'unknown', $r->acknowledge() ? 200 : 404);
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $json = str_contains((string) $request->header('Content-Type'), 'json') ? ($request->json()->all() ?: []) : [];

        return array_merge($json, $request->query(), $request->post());
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $out = [];
        foreach ($request->headers->all() as $name => $values) {
            $out[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return $out;
    }
}
