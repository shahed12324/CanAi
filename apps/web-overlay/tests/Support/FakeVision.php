<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BillScan\VisionPicker;

final class FakeVision implements VisionPicker
{
    public function __construct(private readonly ?array $model = ['id' => 'm1', 'provider_id' => 'p1', 'model_key' => 'gpt-vision', 'name' => 'Vision', 'credit_multiplier' => 1.0]) {}

    public function pick(string $tenantId): ?array
    {
        return $this->model;
    }
}
