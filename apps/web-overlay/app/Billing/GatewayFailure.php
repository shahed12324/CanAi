<?php

declare(strict_types=1);

namespace App\Billing;

/** The gateway refused, was unreachable, or answered nonsense. The message is safe to log; it never holds a key. */
final class GatewayFailure extends \RuntimeException {}
