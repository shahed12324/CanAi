<?php

declare(strict_types=1);

namespace App\Automation;

use App\Actions\Tenancy\CreateWorkspace;
use App\Messaging\ChannelSender;
use App\Messaging\ChatBridge;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs scheduled AI jobs. A job is claimed (its next time is moved forward) BEFORE it runs, so a crash or a slow AI can never make it run twice:
 * at most once per scheduled time. Each run is a normal chat turn for the owner (same memory, same credits, same limits as typing it in the chat),
 * and the answer goes to the app inbox and/or the owner's linked Telegram/WhatsApp chat.
 */
final class JobRunner
{
    public const MAX_FAILS = 5;

    public function __construct(private readonly ChatBridge $chat, private readonly TenantContext $tenant, private readonly ChannelSender $sender) {}

    /** Claims due jobs and moves their next time forward. @return list<object> */
    public function claim(int $limit = 10, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $db = DB::connection('admin');

        return $db->transaction(function () use ($db, $limit, $now): array {
            $rows = $db->select("select * from scheduled_jobs where status = 'active' and next_run_at <= ? order by next_run_at limit ? for update skip locked", [$now->format('c'), $limit]);
            foreach ($rows as $r) {
                $next = self::nextFor($r, $now);
                $db->table('scheduled_jobs')->where('id', $r->id)->update([
                    'next_run_at' => $next?->format('c'), 'status' => $next === null ? 'done' : 'active', 'last_run_at' => $now->format('c'),
                    'run_count' => $r->run_count + 1, 'updated_at' => $now->format('c'),
                ]);
            }

            return $rows;
        });
    }

    public static function nextFor(object $job, DateTimeImmutable $from): ?DateTimeImmutable
    {
        return NextRun::after(json_decode((string) $job->schedule, true, 8, JSON_THROW_ON_ERROR), (string) $job->tz, $from);
    }

    /** @param object $job a claimed row @return array{status: string, delivered: string} */
    public function execute(object $job): array
    {
        return $this->tenant->run($job->tenant_id, function () use ($job): array {
            $t = (string) $job->tenant_id;
            $u = (string) $job->user_id;
            $when = (new DateTimeImmutable('now', new DateTimeZone((string) $job->tz)))->format('D d M Y, H:i');
            $prompt = "[Scheduled task “{$job->title}” — {$when}]\n".$job->prompt;
            try {
                $out = $this->chat->ask($t, $u, $job->continuity ? $job->conversation_id : null, $prompt, []);
            } catch (\Throwable $e) {
                if (function_exists('report')) {
                    report($e);
                }
                $out = ['conversation_id' => null, 'text' => '', 'error' => 'The task could not run (internal error).', 'credits' => null];
            }
            $ok = $out['error'] === null && trim($out['text']) !== '';
            $text = $ok ? trim($out['text']) : '⚠ '.($out['error'] ?? 'The AI gave no answer.');
            $delivered = $this->deliver($job, $t, $u, $text, $ok);

            DB::table('scheduled_job_runs')->insert([
                'id' => CreateWorkspace::newId(), 'job_id' => $job->id, 'tenant_id' => $t, 'status' => $ok ? 'ok' : 'error',
                'error' => $ok ? null : Str::limit((string) $out['error'], 290, ''), 'output' => $ok ? Str::limit($text, 7990, '') : null,
                'credits' => $out['credits'], 'delivered' => $delivered,
            ]);
            $streak = $ok ? 0 : (int) $job->fail_streak + 1;
            $upd = ['last_status' => $ok ? 'ok' : 'error', 'fail_streak' => $streak, 'updated_at' => now()];
            if ($job->continuity && $ok && $out['conversation_id'] !== null && $job->conversation_id === null) {
                $upd['conversation_id'] = $out['conversation_id'];
            }
            if ($streak >= self::MAX_FAILS) {
                $upd['status'] = 'paused';   // five failures in a row (usually no credits): stop spending the owner's attention
            }
            DB::table('scheduled_jobs')->where('id', $job->id)->where('tenant_id', $t)->update($upd);

            return ['status' => $ok ? 'ok' : 'error', 'delivered' => $delivered];
        });
    }

    private function deliver(object $job, string $t, string $u, string $text, bool $ok): string
    {
        $done = [];
        $want = (string) $job->deliver;
        if (in_array($want, ['inbox', 'both'], true) || ! $ok) {
            DB::table('inbox_notifications')->insert([
                'id' => CreateWorkspace::newId(), 'tenant_id' => $t, 'user_id' => $u, 'kind' => 'scheduled_job', 'title' => Str::limit('⏰ '.$job->title, 155, ''),
                'body' => Str::limit($text, 495, '…'), 'ref_kind' => 'scheduled_job', 'ref_id' => $job->id,
            ]);
            $done[] = 'inbox';
        }
        if (in_array($want, ['channel', 'both'], true) && $ok) {
            $links = DB::table('channel_links')->where('tenant_id', $t)->where('user_id', $u)->whereNull('revoked_at')->get(['account_id', 'external_chat_id']);
            foreach ($links as $l) {
                try {
                    $this->sender->sendText((string) $l->account_id, (string) $l->external_chat_id, Str::limit("⏰ {$job->title}\n\n".$text, 3800, '…'));
                    $done[] = 'channel';
                } catch (\Throwable) {
                    // one broken chat must not stop the others; the inbox copy below still tells the owner
                }
            }
            if (! in_array('channel', $done, true) && ! in_array('inbox', $done, true)) {
                DB::table('inbox_notifications')->insert([
                    'id' => CreateWorkspace::newId(), 'tenant_id' => $t, 'user_id' => $u, 'kind' => 'scheduled_job', 'title' => Str::limit('⏰ '.$job->title, 155, ''),
                    'body' => Str::limit($text, 495, '…'), 'ref_kind' => 'scheduled_job', 'ref_id' => $job->id,
                ]);
                $done[] = 'inbox';
            }
        }

        return implode(',', array_unique($done));
    }
}
