<?php

declare(strict_types=1);

namespace App\Video;

use Illuminate\Support\Facades\DB;

final class PgVideoRepo implements VideoRepo
{
    private const COLS = 'id, user_id, title, size_key, scenes::text as scenes, narration, voice, brand::text as brand, audio_path, reservation_id, amount, charged, seconds, status, file_path, bytes, error, created_at, finished_at';

    private static function row(object $r, ?string $logo = null): array
    {
        return ['id' => trim((string) $r->id), 'user_id' => trim((string) $r->user_id), 'title' => (string) $r->title, 'size_key' => (string) $r->size_key, 'scenes' => (array) json_decode((string) $r->scenes, true),
            'narration' => $r->narration === null ? null : (string) $r->narration, 'voice' => $r->voice === null ? null : (string) $r->voice, 'brand' => (array) json_decode((string) $r->brand, true), 'logo_b64' => $logo,
            'audio_path' => $r->audio_path === null ? null : (string) $r->audio_path, 'reservation_id' => $r->reservation_id === null ? null : trim((string) $r->reservation_id), 'amount' => (string) $r->amount,
            'charged' => $r->charged === null ? null : (string) $r->charged, 'seconds' => (string) $r->seconds, 'status' => (string) $r->status, 'file_path' => $r->file_path === null ? null : (string) $r->file_path,
            'bytes' => $r->bytes === null ? null : (int) $r->bytes, 'error' => $r->error === null ? null : (string) $r->error, 'created_at' => (string) $r->created_at, 'finished_at' => $r->finished_at === null ? null : (string) $r->finished_at];
    }

    public function list(string $userId): array
    {
        return array_map(fn ($r) => self::row($r), DB::select('select '.self::COLS.' from video_jobs where user_id = ? order by created_at desc, id desc', [$userId]));
    }

    public function find(string $userId, string $id): ?array
    {
        $r = DB::selectOne('select '.self::COLS.' from video_jobs where user_id = ? and id = ?', [$userId, $id]);

        return $r === null ? null : self::row($r);
    }

    public function get(string $id): ?array
    {
        $r = DB::selectOne('select '.self::COLS.', logo_b64 from video_jobs where id = ?', [$id]);

        return $r === null ? null : self::row($r, $r->logo_b64 === null ? null : (string) $r->logo_b64);
    }

    public function activeCount(string $userId): int
    {
        return (int) DB::scalar("select count(*) from video_jobs where user_id = ? and status in ('queued', 'rendering')", [$userId]);
    }

    public function create(string $tenantId, string $userId, string $id, string $title, string $size, array $scenes, ?string $narration, ?string $voice, array $brand, ?string $logoB64, ?string $audioPath, string $reservationId, string $amount, string $seconds): string
    {
        $j = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return (string) DB::selectOne('select video_job_create(?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?::jsonb, ?, ?, ?, ?, ?) as r',
            [$id, $tenantId, $userId, $title, $size, $j($scenes), $narration, $voice, $j($brand), $logoB64, $audioPath, $reservationId, $amount, $seconds])->r;
    }

    public function cancel(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select video_job_cancel(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select video_job_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function claim(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->job_id), 'tenant_id' => trim((string) $r->tenant_id)],
            DB::connection('admin')->select('select job_id, tenant_id, user_id from video_job_claim(?)', [max(1, min(10, $limit))]));
    }

    public function done(string $tenantId, string $id, string $filePath, int $bytes, string $charged): string
    {
        return (string) DB::connection('admin')->selectOne('select video_job_done(?, ?, ?, ?, ?) as r', [$id, $tenantId, $filePath, $bytes, $charged])->r;
    }

    public function fail(string $tenantId, string $id, string $error): string
    {
        return (string) DB::connection('admin')->selectOne('select video_job_fail(?, ?, ?) as r', [$id, $tenantId, $error])->r;
    }
}
