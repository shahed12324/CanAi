<?php

declare(strict_types=1);

namespace Jarvis\Connector;

/** The few database calls the bundled store needs. LaravelDb is used in a shop; PdoDb lets the tests run real SQL. */
interface Db
{
    /** @param list<mixed> $bindings @return list<array<string, mixed>> */
    public function select(string $sql, array $bindings = []): array;

    /** @param list<mixed> $bindings @return int rows affected */
    public function execute(string $sql, array $bindings = []): int;

    public function lastId(): string;

    /** @template T @param callable(): T $fn @return T */
    public function transaction(callable $fn): mixed;
}
