<?php

declare(strict_types=1);

namespace Jarvis\Connector;

use Illuminate\Support\Facades\DB;

final class LaravelDb implements Db
{
    public function __construct(private readonly ?string $connection = null) {}

    private function c(): \Illuminate\Database\Connection
    {
        return DB::connection($this->connection);
    }

    public function select(string $sql, array $bindings = []): array
    {
        return array_map(fn ($r) => (array) $r, $this->c()->select($sql, $bindings));
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->c()->affectingStatement($sql, $bindings);
    }

    public function lastId(): string
    {
        return (string) $this->c()->getPdo()->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        return $this->c()->transaction($fn);
    }
}
