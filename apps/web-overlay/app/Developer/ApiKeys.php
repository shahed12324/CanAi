<?php

declare(strict_types=1);

namespace App\Developer;

use App\Actions\Tenancy\CreateWorkspace;

/**
 * Public API keys.  A key looks like `jk_live_` + 40 random characters, is shown once, and only its sha256 is stored.
 * It can do only what its scopes say, for the one person and workspace it was made for.
 */
final class ApiKeys
{
    public const SCOPES = [
        'me' => 'View own name and workspace',
        'credits:read' => 'View credit balance',
        'workflows:read' => 'View workflow list and runs',
        'workflows:run' => 'Run your own active workflows (this does not unlock approval/money steps)',
    ];

    public const PREFIX = 'jk_live_';

    public function __construct(private readonly ApiKeyRepo $repo) {}

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /** Pulls the key out of "Bearer jk_live_…"; anything else is no key. */
    public static function fromHeader(?string $header): ?string
    {
        if ($header === null || preg_match('/\ABearer (jk_live_[0-9a-f]{40})\z/', trim($header), $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * @param  list<string>  $scopes
     * @return array{status: string, key: ?string}  ok|bad_name|bad_scopes|bad_days|limit|failed
     */
    public function issue(string $tenantId, string $userId, string $name, array $scopes, ?int $days): array
    {
        $name = \App\Studio\StudioService::line($name, 200);
        $scopes = array_values(array_unique(array_filter($scopes, fn ($s) => is_string($s) && isset(self::SCOPES[$s]))));
        if ($name === '' || mb_strlen($name) > 80) {
            return ['status' => 'bad_name', 'key' => null];
        }
        if ($scopes === []) {
            return ['status' => 'bad_scopes', 'key' => null];
        }
        if ($days !== null && ($days < 1 || $days > 365)) {
            return ['status' => 'bad_days', 'key' => null];
        }
        $key = self::PREFIX.bin2hex(random_bytes(20));
        $r = $this->repo->create($tenantId, $userId, CreateWorkspace::newId(), $name, substr($key, 0, 12), self::hash($key), $scopes, $days);
        if ($r === 'ok') {
            return ['status' => 'ok', 'key' => $key];
        }

        return ['status' => $r === 'limit' ? 'limit' : 'failed', 'key' => null];
    }

    public function revoke(string $tenantId, string $userId, string $id): string
    {
        return $this->repo->revoke($tenantId, $userId, $id);
    }

    /** @return ?array{key_id: string, tenant_id: string, user_id: string, scopes: list<string>} */
    public function authenticate(?string $header): ?array
    {
        $key = self::fromHeader($header);

        return $key === null ? null : $this->repo->resolve(self::hash($key));
    }

    /** @param array{scopes: list<string>} $auth */
    public static function allows(array $auth, string $scope): bool
    {
        return in_array($scope, $auth['scopes'], true);
    }
}
