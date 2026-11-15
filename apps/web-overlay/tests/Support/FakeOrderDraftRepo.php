<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Inbox\Agent\Orders\OrderDraft;
use App\Inbox\Agent\Orders\OrderDraftRepo;

final class FakeOrderDraftRepo implements OrderDraftRepo
{
    /** @var array<string, OrderDraft> */
    public array $rows = [];

    public function get(string $conversationId): ?OrderDraft
    {
        return isset($this->rows[$conversationId]) ? unserialize(serialize($this->rows[$conversationId])) : null;
    }

    public function save(string $tenantId, string $conversationId, OrderDraft $d): void
    {
        $this->rows[$conversationId] = unserialize(serialize($d));
    }

    public function delete(string $conversationId): void
    {
        unset($this->rows[$conversationId]);
    }
}
