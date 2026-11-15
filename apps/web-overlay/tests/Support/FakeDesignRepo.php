<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Studio\DesignRepo;

final class FakeDesignRepo implements DesignRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, array<string, mixed>> */
    public array $revs = [];

    public ?string $saveResult = null;

    public ?string $reviewResult = null;

    public function list(string $userId): array
    {
        $out = [];
        foreach (array_reverse($this->rows) as $r) {
            if ($r['user'] === $userId) {
                $out[] = ['id' => $r['id'], 'title' => $r['title'], 'headline' => $r['headline'], 'sizes' => $r['sizes'], 'has_base' => $r['base_b64'] !== null, 'created_at' => 'now'];
            }
        }

        return $out;
    }

    public function count(string $userId): int
    {
        return count(array_filter($this->rows, fn ($r) => $r['user'] === $userId));
    }

    public function find(string $userId, string $id): ?array
    {
        $r = $this->rows[$id] ?? null;

        return $r !== null && $r['user'] === $userId ? $r : null;
    }

    public function save(string $tenantId, string $userId, string $id, string $title, string $brief, string $headline, string $subline, string $cta, string $theme, array $sizes, array $brand, ?string $logoB64, ?string $baseB64): string
    {
        if ($this->saveResult !== null) {
            return $this->saveResult;
        }
        $this->rows[$id] = ['id' => $id, 'user' => $userId, 'title' => $title, 'brief' => $brief, 'headline' => $headline, 'subline' => $subline, 'cta' => $cta, 'theme' => $theme, 'sizes' => $sizes,
            'brand' => $brand, 'logo_b64' => $logoB64, 'base_b64' => $baseB64, 'created_at' => 'now'];

        return 'ok';
    }

    /** @var array<string, list<array<string, mixed>>> */
    public array $versionsOf = [];

    public ?string $updateResult = null;

    public function update(string $tenantId, string $userId, string $id, string $title, string $headline, string $subline, string $cta, string $theme, array $sizes): string
    {
        if ($this->updateResult !== null) {
            return $this->updateResult;
        }
        $r = $this->rows[$id] ?? null;
        if ($r === null || $r['user'] !== $userId) {
            return 'not_found';
        }
        $a = [$r['title'], $r['headline'], $r['subline'], $r['cta'], $r['theme'], $r['sizes']];
        $b = [$title, $headline, $subline, $cta, $theme, $sizes];
        sort($a[5]);
        sort($b[5]);
        if ($a === $b) {
            return 'unchanged';
        }
        $this->snap($id);
        foreach (['title' => $title, 'headline' => $headline, 'subline' => $subline, 'cta' => $cta, 'theme' => $theme, 'sizes' => $sizes] as $k => $v) {
            $this->rows[$id][$k] = $v;
        }

        return 'ok';
    }

    private function snap(string $id): void
    {
        $r = $this->rows[$id];
        $n = count($this->versionsOf[$id] ?? []) + 1;
        $this->versionsOf[$id][] = ['n' => $n, 'title' => $r['title'], 'headline' => $r['headline'], 'subline' => $r['subline'], 'cta' => $r['cta'], 'theme' => $r['theme'], 'sizes' => $r['sizes'], 'created_at' => 'now'];
    }

    public function versions(string $userId, string $designId): array
    {
        if (($this->rows[$designId]['user'] ?? null) !== $userId) {
            return [];
        }

        return array_slice(array_reverse($this->versionsOf[$designId] ?? []), 0, 10);
    }

    public function restore(string $tenantId, string $userId, string $id, int $n): string
    {
        if (($this->rows[$id]['user'] ?? null) !== $userId) {
            return 'not_found';
        }
        foreach ($this->versionsOf[$id] ?? [] as $v) {
            if ($v['n'] === $n) {
                $this->snap($id);
                foreach (['title', 'headline', 'subline', 'cta', 'theme', 'sizes'] as $k) {
                    $this->rows[$id][$k] = $v[$k];
                }

                return 'ok';
            }
        }

        return 'not_found';
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        if (($this->rows[$id]['user'] ?? null) !== $userId) {
            return 'not_found';
        }
        unset($this->rows[$id]);

        return 'ok';
    }

    public function reviews(string $userId, string $designId): array
    {
        return array_values(array_filter($this->revs, fn ($r) => $r['user'] === $userId && $r['design'] === $designId));
    }

    public function createReview(string $tenantId, string $userId, string $id, string $designId, string $tokenHash, string $client, string $size, string $previewB64, int $days): string
    {
        if ($this->reviewResult !== null) {
            return $this->reviewResult;
        }
        foreach ($this->revs as $r) {
            if ($r['hash'] === $tokenHash) {
                return 'duplicate';
            }
        }
        $this->revs[$id] = ['id' => $id, 'user' => $userId, 'design' => $designId, 'hash' => $tokenHash, 'client_name' => $client, 'size_key' => $size, 'preview_b64' => $previewB64, 'status' => 'pending', 'comment' => null,
            'expires_at' => "+$days", 'decided_at' => null, 'created_at' => 'now'];

        return 'ok';
    }

    public function revokeReview(string $tenantId, string $userId, string $id): string
    {
        if (($this->revs[$id]['user'] ?? null) !== $userId || $this->revs[$id]['status'] !== 'pending') {
            return 'not_found';
        }
        $this->revs[$id]['status'] = 'revoked';

        return 'ok';
    }

    public function publicView(string $tokenHash): ?array
    {
        foreach ($this->revs as $r) {
            if ($r['hash'] === $tokenHash) {
                return ['title' => $this->rows[$r['design']]['title'] ?? '', 'brand_name' => '', 'client_name' => $r['client_name'], 'size' => $r['size_key'], 'preview_b64' => $r['preview_b64'],
                    'status' => $r['status'], 'comment' => $r['comment'], 'decided_at' => $r['decided_at'], 'expires_at' => $r['expires_at']];
            }
        }

        return null;
    }

    public function publicDecide(string $tokenHash, string $decision, string $comment): string
    {
        foreach ($this->revs as $id => $r) {
            if ($r['hash'] === $tokenHash) {
                if ($r['status'] !== 'pending') {
                    return $r['status'] === 'revoked' ? 'revoked' : 'decided';
                }
                $this->revs[$id]['status'] = $decision;
                $this->revs[$id]['comment'] = $comment === '' ? null : $comment;

                return 'ok';
            }
        }

        return 'not_found';
    }
}
