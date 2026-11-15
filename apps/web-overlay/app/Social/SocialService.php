<?php

declare(strict_types=1);

namespace App\Social;

use App\Actions\Tenancy\CreateWorkspace;

/** Validates what the person typed and maps the database answers to short codes.  The database decides who may do what to which post. */
final class SocialService
{
    public function __construct(private readonly SocialRepo $repo, private readonly ?\DateTimeZone $tz = null) {}

    public function zone(): \DateTimeZone
    {
        return $this->tz ?? new \DateTimeZone(function_exists('config') ? (string) config('app.timezone', 'Asia/Dhaka') : 'Asia/Dhaka');
    }

    /** "2026-12-05T18:30" typed in the workspace's local time -> a UTC instant "2026-12-05 12:30:00+00"; blank -> null; unreadable -> false. */
    public function toUtc(?string $local): string|false|null
    {
        $local = trim((string) $local);
        if ($local === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, $this->zone());
        $e = \DateTimeImmutable::getLastErrors();
        if ($d === false || ($e !== false && ($e['warning_count'] > 0 || $e['error_count'] > 0)) || (int) $d->format('Y') < 2000 || (int) $d->format('Y') > 2100) {
            return false;
        }

        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }

    /** A stored instant shown back in the form's local format. */
    public function toLocal(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($utc))->setTimezone($this->zone())->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param  array{title?: mixed, platform?: mixed, caption?: mixed, media_kind?: mixed, media_id?: mixed, media_size?: mixed, scheduled?: mixed}  $in
     * @return array{status: string, id: ?string} status: ok|bad_title|bad_platform|bad_caption|bad_time|bad_media|bad_input|not_found|locked|limit|failed
     */
    public function save(string $tenantId, string $userId, ?string $id, array $in): array
    {
        $title = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', (string) ($in['title'] ?? '')));
        $caption = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', str_replace("\r\n", "\n", (string) ($in['caption'] ?? ''))));
        $platform = (string) ($in['platform'] ?? '');
        $kind = (string) ($in['media_kind'] ?? 'none');
        $mediaId = $kind === 'none' ? null : trim((string) ($in['media_id'] ?? ''));
        $size = $kind === 'design' ? (string) ($in['media_size'] ?? '') : null;
        $fail = fn (string $s) => ['status' => $s, 'id' => null];
        if ($title === '' || mb_strlen($title) > 120) {
            return $fail('bad_title');
        }
        if (! isset(SocialText::PLATFORMS[$platform])) {
            return $fail('bad_platform');
        }
        if ($caption === '' || mb_strlen($caption) > 3000) {
            return $fail('bad_caption');
        }
        if (! in_array($kind, ['none', 'design', 'video'], true) || ($kind !== 'none' && preg_match('/\A[0-9a-z]{26}\z/', (string) $mediaId) !== 1)
            || ($kind === 'design' && ! in_array($size, ['post', 'portrait', 'story', 'cover', 'ad'], true))) {
            return $fail('bad_media');
        }
        $at = $this->toUtc(isset($in['scheduled']) ? (string) $in['scheduled'] : null);
        if ($at === false) {
            return $fail('bad_time');
        }
        $new = $id === null;
        $pid = $id ?? CreateWorkspace::newId();
        try {
            $r = $this->repo->save($new, $tenantId, $userId, $pid, $title, $platform, $caption, $kind, $mediaId, $size, $at);
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }

        return ['status' => $r, 'id' => $r === 'ok' ? $pid : null];
    }

    public function submit(string $tenantId, string $userId, string $id): string
    {
        return $this->run(fn () => $this->repo->submit($tenantId, $userId, $id));
    }

    public function decide(string $tenantId, string $approverId, string $id, bool $approve, ?string $note): string
    {
        $note = $approve ? null : mb_substr(trim((string) $note), 0, 300);

        return $this->run(fn () => $this->repo->decide($tenantId, $approverId, $id, $approve, $note === '' ? null : $note));
    }

    public function reopen(string $tenantId, string $userId, string $id): string
    {
        return $this->run(fn () => $this->repo->reopen($tenantId, $userId, $id));
    }

    public function markPosted(string $tenantId, string $userId, string $id, ?string $url): string
    {
        $url = trim((string) $url);
        if ($url !== '' && (mb_strlen($url) > 300 || preg_match('#\Ahttps://[^\s<>"\']+\z#i', $url) !== 1)) {
            return 'bad_url';
        }

        return $this->run(fn () => $this->repo->markPosted($tenantId, $userId, $id, $url === '' ? null : $url));
    }

    /** Attach (or with null remove) the Page that will publish this post. */
    public function attach(string $tenantId, string $userId, string $id, ?string $accountId): string
    {
        $accountId = $accountId === null || trim($accountId) === '' ? null : trim($accountId);
        if ($accountId !== null && preg_match('/\A[0-9a-z]{26}\z/', $accountId) !== 1) {
            return 'bad_account';
        }

        return $this->run(fn () => $this->repo->setAccount($tenantId, $userId, $id, $accountId));
    }

    public function retry(string $tenantId, string $userId, string $id): string
    {
        return $this->run(fn () => $this->repo->retry($tenantId, $userId, $id));
    }

    public function cancel(string $tenantId, string $userId, string $id): string
    {
        return $this->run(fn () => $this->repo->cancel($tenantId, $userId, $id));
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return $this->run(fn () => $this->repo->delete($tenantId, $userId, $id));
    }

    /** @param  \Closure(): string  $f */
    private function run(\Closure $f): string
    {
        try {
            return $f();
        } catch (\Throwable $e) {
            self::report($e);

            return 'failed';
        }
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
