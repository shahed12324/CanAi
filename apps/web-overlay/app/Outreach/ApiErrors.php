<?php

declare(strict_types=1);

namespace App\Outreach;

/** Maps a Gmail / Graph HTTP answer to what the sender should do. Account-level problems are not permanent: the mail waits. */
final class ApiErrors
{
    /** @param array<string, mixed> $json */
    public static function failure(int $status, array $json): TransportFailure
    {
        if ($status === 400) {
            return new TransportFailure('api_rejected', true);              // this recipient / this mail is refused for good
        }
        if ($status === 401) {
            return new TransportFailure('oauth_revoked', false);
        }
        if ($status === 429) {
            return new TransportFailure('quota', false);
        }
        if ($status === 403) {
            $reason = (string) ($json['error']['errors'][0]['reason'] ?? $json['error']['status'] ?? '');

            return new TransportFailure(preg_match('/ratelimit|quota|dailylimit/i', $reason) === 1 ? 'quota' : 'oauth_scope', false);
        }

        return new TransportFailure('api_unavailable', false);
    }
}
