<?php

namespace Tests\Support;

use App\Brand\BrandRepo;

final class FakeBrandRepo implements BrandRepo
{
    public array $saves = [];

    public string $saveResult = 'ok';

    public ?array $row = null;

    public bool $throws = false;

    public function get(string $tenantId): ?array
    {
        if ($this->throws) {
            throw new \RuntimeException('db down');
        }

        return $this->row;
    }

    public function save(string $tenantId, string $userId, ?string $name, ?string $color, ?string $email, bool $hide): string
    {
        $this->saves[] = [$tenantId, $userId, $name, $color, $email, $hide];

        return $this->saveResult;
    }
}
