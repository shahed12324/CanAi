<?php

declare(strict_types=1);

namespace Jarvis\Connector;

final class PdoDb implements Db
{
    public function __construct(private readonly \PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    public function select(string $sql, array $bindings = []): array
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($bindings);

        return $s->fetchAll();
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($bindings);

        return $s->rowCount();
    }

    public function lastId(): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn();
            $this->pdo->commit();

            return $r;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
