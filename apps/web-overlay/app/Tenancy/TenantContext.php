<?php

declare(strict_types=1);

namespace App\Tenancy;

use Closure;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Holds the active tenant and mirrors it into PostgreSQL (`app.tenant_id`) so Row-Level
 * Security enforces isolation even if an Eloquent scope is forgotten.
 *
 * The value is set per CONNECTION SESSION, so always call set() outside a DB transaction
 * (a rollback would undo it) and always clear() when the unit of work ends. Do not use
 * with transaction-mode connection poolers.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    public function __construct(private readonly ConnectionInterface $db) {}

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function idOrFail(): string
    {
        return $this->tenantId ?? throw new RuntimeException('No tenant context is active.');
    }

    public function set(string $tenantId): void
    {
        if (! self::isValidId($tenantId)) {
            throw new InvalidArgumentException('Invalid tenant id.');
        }
        $this->db->select("select set_config('app.tenant_id', ?, false)", [$tenantId]);
        $this->tenantId = $tenantId;
    }

    public function clear(): void
    {
        $this->db->select("select set_config('app.tenant_id', '', false)");
        $this->tenantId = null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $tenantId, Closure $callback): mixed
    {
        $previous = $this->tenantId;
        $this->set($tenantId);
        try {
            return $callback();
        } finally {
            $previous === null ? $this->clear() : $this->set($previous);
        }
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/\A[0-9A-Za-z]{26}\z/', $id) === 1;
    }
}
