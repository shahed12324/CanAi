<?php

declare(strict_types=1);

namespace App\Billing;

final class Initiation
{
    public function __construct(public readonly string $redirectUrl, public readonly string $ref) {}
}
