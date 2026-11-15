<?php

declare(strict_types=1);

namespace App\Billing;

final class Urls
{
    public function __construct(public readonly string $return, public readonly string $ipn, public readonly string $cancel) {}
}
