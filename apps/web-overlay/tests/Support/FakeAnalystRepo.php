<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Analyst\AnalystRepo;

final class FakeAnalystRepo implements AnalystRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $reports = [];

    public string $saveResult = 'ok';

    /** @param list<array<string, mixed>> $rows @param list<array<string, mixed>> $cash */
    public function __construct(public array $rows = [], public array $cash = [], public int $cashEnd = 0, public int $recv = 0, public int $pay = 0) {}

    public function monthly(string $from, string $to): array
    {
        return $this->rows;
    }

    public function cashMonthly(string $from, string $to): array
    {
        return $this->cash;
    }

    public function cashBalance(string $asof): int
    {
        return $this->cashEnd;
    }

    public function openItems(): array
    {
        return ['receivable' => $this->recv, 'payable' => $this->pay];
    }

    public function saveReport(string $tenantId, string $userId, string $id, string $month, array $facts, array $forecast, string $narrative, string $source, ?string $note, ?string $model, int $in, int $out, string $credits): string
    {
        if ($this->saveResult === 'ok') {
            $this->reports[$id] = compact('month', 'facts', 'forecast', 'narrative', 'source', 'note', 'model', 'in', 'out', 'credits');
        }

        return $this->saveResult;
    }

    public function report(string $id): ?array
    {
        return null;
    }

    public function recent(int $limit): array
    {
        return [];
    }
}
