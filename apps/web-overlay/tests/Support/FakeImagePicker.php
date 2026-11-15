<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Studio\ImagePicker;

final class FakeImagePicker implements ImagePicker
{
    public function __construct(public ?array $model = ['id' => 'I1', 'provider_id' => 'p1', 'model_key' => 'gpt-image-1', 'name' => 'Img', 'credit_multiplier' => 1.0]) {}

    public function pick(string $tenantId): ?array
    {
        return $this->model;
    }
}
