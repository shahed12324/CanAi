<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Orders\OrderRepo;

final class FakeOrderRepo implements OrderRepo
{
    public array $settings = ['max_discount_pct' => 0, 'delivery_inside_minor' => 6000, 'delivery_outside_minor' => 12000, 'review_score' => 50, 'chat_orders' => true];

    public array $created = [];

    public string $result = 'ok';

    public ?array $orderData = null;

    public function create(string $tenantId, ?string $userId, string $source, ?string $conversationId, string $name, string $phone, string $address, string $zone, ?string $note, array $items, float $discountPct): array
    {
        $this->created[] = compact('tenantId', 'userId', 'source', 'conversationId', 'name', 'phone', 'address', 'zone', 'items', 'discountPct');

        return $this->result === 'ok' ? ['result' => 'ok', 'order_id' => str_repeat('o', 26), 'number' => 7, 'total_minor' => 131000, 'risk_score' => 0, 'needs_review' => false]
            : ['result' => $this->result, 'order_id' => null, 'number' => null, 'total_minor' => null, 'risk_score' => null, 'needs_review' => null];
    }

    public function transition(string $orderId, string $userId, string $to, ?string $note): string
    {
        return 'ok';
    }

    public function orders(?string $status, int $limit = 100): array
    {
        return [];
    }

    public function order(string $id): ?array
    {
        return $this->orderData;
    }

    public function sellable(): array
    {
        return [];
    }

    public function settings(): array
    {
        return $this->settings;
    }

    public function saveSettings(string $tenantId, int $maxDiscountPct, int $deliveryInside, int $deliveryOutside, int $reviewScore, bool $chatOrders): void {}
}
