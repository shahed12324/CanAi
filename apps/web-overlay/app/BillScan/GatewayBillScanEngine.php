<?php

declare(strict_types=1);

namespace App\BillScan;

use App\Ai\ProviderGateway;

final class GatewayBillScanEngine implements BillScanEngine
{
    public function __construct(private readonly ProviderGateway $gateway) {}

    public function scan(string $providerId, string $modelKey, string $mime, string $bytes, array $accounts, string $tenantId, string $userId): array
    {
        return $this->gateway->scanBill($providerId, $modelKey, $mime, $bytes, $accounts, $tenantId, $userId);
    }
}
