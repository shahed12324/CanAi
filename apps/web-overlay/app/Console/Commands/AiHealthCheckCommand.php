<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\HealthPolicy;
use App\Ai\ProviderCallException;
use App\Ai\ProviderGateway;
use App\Tenancy\TenantRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Pings every enabled provider, records the result, and flips active <-> degraded. Never enables a disabled one. */
final class AiHealthCheckCommand extends Command
{
    protected $signature = 'ai:health-check';

    protected $description = 'Check enabled AI providers and update their health status';

    public function handle(ProviderGateway $gateway): int
    {
        $admin = DB::connection('admin');
        foreach ($admin->table('ai_providers')->whereIn('status', ['active', 'degraded'])->get(['id', 'name', 'status']) as $p) {
            try {
                $r = $gateway->testConnection($p->id, TenantRoles::PLATFORM_TEAM);
                $ok = (bool) ($r['ok'] ?? false);
                $code = $ok ? null : substr((string) ($r['error_code'] ?? 'failed'), 0, 40);
                $latency = $ok ? (int) ($r['latency_ms'] ?? 0) : null;
            } catch (ProviderCallException|\RuntimeException $e) {
                $ok = false;
                $code = $e instanceof ProviderCallException ? substr($e->errorCode, 0, 40) : 'engine_unreachable';
                $latency = null;
            }
            $admin->table('provider_health_checks')->insert([
                'provider_id' => $p->id, 'status' => $ok ? 'ok' : 'failed', 'latency_ms' => $latency, 'error_code' => $code,
            ]);

            $recent = $admin->table('provider_health_checks')->where('provider_id', $p->id)
                ->orderByDesc('id')->limit(HealthPolicy::FAIL_THRESHOLD)->pluck('status')->map(fn ($s) => $s === 'ok')->all();
            $next = HealthPolicy::next($p->status, $recent);
            if ($next !== $p->status) {
                $admin->transaction(function () use ($admin, $p, $next): void {
                    $admin->table('ai_providers')->where('id', $p->id)->update(['status' => $next, 'updated_at' => now()]);
                    $admin->table('audit_logs')->insert([
                        'actor_type' => 'system', 'action' => 'ai.provider.health_status', 'subject_type' => 'provider',
                        'subject_id' => $p->id, 'before' => json_encode(['status' => $p->status]),
                        'after' => json_encode(['status' => $next]), 'channel' => 'cli',
                    ]);
                });
                $this->warn("{$p->name}: {$p->status} -> {$next}");
            }
        }

        return self::SUCCESS;
    }
}
