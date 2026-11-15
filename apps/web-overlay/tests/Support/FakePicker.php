<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Chat\ModelPicker;

final class FakePicker implements ModelPicker
{
    public function __construct(private readonly ?array $model = ['id' => 'm1', 'provider_id' => 'p1', 'model_key' => 'gpt-test', 'name' => 'Test Model', 'max_output' => 1000, 'context_window' => 16000, 'credit_multiplier' => 1.0]) {}

    public function pick(string $tenantId, ?string $preferredModelId): ?array
    {
        return $this->model;
    }
}
