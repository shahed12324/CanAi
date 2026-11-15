<?php

declare(strict_types=1);

namespace App\Orders;

interface OrderRepo
{
    /** @param list<array{variant_id: string, qty: int}> $items @return array{result: string, order_id: ?string, number: ?int, total_minor: ?int, risk_score: ?int, needs_review: ?bool} */
    public function create(string $tenantId, ?string $userId, string $source, ?string $conversationId, string $name, string $phone, string $address, string $zone, ?string $note, array $items, float $discountPct): array;

    /** @return 'ok'|'not_found'|'bad_transition'|'emergency'|'user_required' */
    public function transition(string $orderId, string $userId, string $to, ?string $note): string;

    /** @return list<array<string, mixed>> */
    public function orders(?string $status, int $limit = 100): array;

    /** @return array{order: array<string, mixed>, items: list<array<string, mixed>>, events: list<array<string, mixed>>}|null */
    public function order(string $id): ?array;

    /** On-sale variants (from the approved live copy) for the manual order form. @return list<array<string, mixed>> */
    public function sellable(): array;

    /** @return array{max_discount_pct: int, delivery_inside_minor: int, delivery_outside_minor: int, review_score: int, chat_orders: bool} */
    public function settings(): array;

    public function saveSettings(string $tenantId, int $maxDiscountPct, int $deliveryInside, int $deliveryOutside, int $reviewScore, bool $chatOrders): void;
}
