<?php

declare(strict_types=1);

namespace App\Admin;

use Illuminate\Support\Facades\DB;

/** Writes admin actions to the append-only audit_logs table, on the same admin connection/transaction. */
final class AdminAudit
{
    private const SENSITIVE = '/(password|secret|token|credential|api_?key)/i';

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $tenantId = null,
    ): void {
        $request = request();
        DB::connection('admin')->table('audit_logs')->insert([
            'tenant_id' => $tenantId,
            'actor_type' => 'user',
            'actor_id' => $request->user()?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before === null ? null : json_encode(self::redact($before), JSON_UNESCAPED_UNICODE),
            'after' => $after === null ? null : json_encode(self::redact($after), JSON_UNESCAPED_UNICODE),
            'ip' => $request->ip(),
            'channel' => 'admin',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key) === 1) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
