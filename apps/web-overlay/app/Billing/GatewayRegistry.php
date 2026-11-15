<?php

declare(strict_types=1);

namespace App\Billing;

/** The gateways that are switched on (credentials present). Order = order shown to the customer. */
final class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $byCode = [];

    /** @param list<PaymentGateway> $gateways */
    public function __construct(array $gateways)
    {
        foreach ($gateways as $g) {
            $this->byCode[$g->code()] = $g;
        }
    }

    public function find(string $code): ?PaymentGateway
    {
        return $this->byCode[$code] ?? null;
    }

    public function get(string $code): PaymentGateway
    {
        return $this->byCode[$code] ?? throw new BillingException('invalid_gateway');
    }

    /** @return list<PaymentGateway> */
    public function forCurrency(string $currency): array
    {
        return array_values(array_filter($this->byCode, fn (PaymentGateway $g) => in_array($currency, $g->currencies(), true)));
    }

    /** @return list<PaymentGateway> */
    public function all(): array
    {
        return array_values($this->byCode);
    }
}
