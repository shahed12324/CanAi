<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Video\VideoRepo;

final class FakeVideoRepo implements VideoRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $jobs = [];

    public string $createResult = 'ok';

    public bool $failDone = false;

    public function list(string $userId): array
    {
        return array_values(array_filter($this->jobs, fn ($j) => $j['user_id'] === $userId));
    }

    public function find(string $userId, string $id): ?array
    {
        $j = $this->jobs[$id] ?? null;

        return $j !== null && $j['user_id'] === $userId ? $j : null;
    }

    public function get(string $id): ?array
    {
        return $this->jobs[$id] ?? null;
    }

    public function activeCount(string $userId): int
    {
        return count(array_filter($this->jobs, fn ($j) => $j['user_id'] === $userId && in_array($j['status'], ['queued', 'rendering'], true)));
    }

    public function create(string $tenantId, string $userId, string $id, string $title, string $size, array $scenes, ?string $narration, ?string $voice, array $brand, ?string $logoB64, ?string $audioPath, string $reservationId, string $amount, string $seconds): string
    {
        if ($this->createResult !== 'ok') {
            return $this->createResult;
        }
        $this->jobs[$id] = ['id' => $id, 'user_id' => $userId, 'title' => $title, 'size_key' => $size, 'scenes' => $scenes, 'narration' => $narration, 'voice' => $voice, 'brand' => $brand, 'logo_b64' => $logoB64,
            'audio_path' => $audioPath, 'reservation_id' => $reservationId, 'amount' => $amount, 'charged' => null, 'seconds' => $seconds, 'status' => 'queued', 'file_path' => null, 'bytes' => null, 'error' => null,
            'created_at' => '2026-11-04 10:00:00', 'finished_at' => null];

        return 'ok';
    }

    public function cancel(string $tenantId, string $userId, string $id): string
    {
        if (($this->jobs[$id]['status'] ?? '') !== 'queued' || $this->jobs[$id]['user_id'] !== $userId) {
            return 'not_found';
        }
        $this->jobs[$id]['status'] = 'cancelled';

        return 'ok';
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        if (! isset($this->jobs[$id]) || $this->jobs[$id]['user_id'] !== $userId || in_array($this->jobs[$id]['status'], ['queued', 'rendering'], true)) {
            return 'not_found';
        }
        unset($this->jobs[$id]);

        return 'ok';
    }

    public function claim(int $limit): array
    {
        $out = [];
        foreach ($this->jobs as $id => $j) {
            if ($j['status'] === 'queued' && count($out) < $limit) {
                $this->jobs[$id]['status'] = 'rendering';
                $out[] = ['id' => $id, 'tenant_id' => 'T'];
            }
        }

        return $out;
    }

    public function done(string $tenantId, string $id, string $filePath, int $bytes, string $charged): string
    {
        if ($this->failDone) {
            throw new \RuntimeException('db down');
        }
        $this->jobs[$id] = ['status' => 'done', 'file_path' => $filePath, 'bytes' => $bytes, 'charged' => $charged] + $this->jobs[$id];

        return 'ok';
    }

    public function fail(string $tenantId, string $id, string $error): string
    {
        $this->jobs[$id]['status'] = 'failed';
        $this->jobs[$id]['error'] = $error;

        return 'ok';
    }
}
