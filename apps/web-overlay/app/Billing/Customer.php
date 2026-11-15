<?php

declare(strict_types=1);

namespace App\Billing;

final class Customer
{
    public function __construct(public readonly string $name, public readonly string $email) {}
}
