<?php

declare(strict_types=1);

namespace App\Inbox\Agent\Orders;

interface OrderDraftRepo
{
    public function get(string $conversationId): ?OrderDraft;

    public function save(string $tenantId, string $conversationId, OrderDraft $d): void;

    public function delete(string $conversationId): void;
}
