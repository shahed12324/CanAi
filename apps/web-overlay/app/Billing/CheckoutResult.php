<?php

declare(strict_types=1);

namespace App\Billing;

final class CheckoutResult
{
    public function __construct(public readonly string $orderId, public readonly ?string $redirectUrl, public readonly bool $settled) {}
}
