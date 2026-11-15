<?php

declare(strict_types=1);

namespace App\Social;

use App\Actions\Tenancy\CreateWorkspace;

/** Connects a YouTube channel after Google's consent screen: the refresh token is sealed and never shown; only the channel id and name are kept in the clear. */
final class YoutubeAccounts
{
    public function __construct(private readonly SocialRepo $repo, private readonly TokenSealer $secrets, private readonly YoutubeApi $api) {}

    /** @return string ok|failed|no_refresh|scope|no_channel|limit */
    public function connect(string $tenantId, string $code, string $verifier, string $redirectUri): string
    {
        if ($code === '' || mb_strlen($code) > 2048 || $verifier === '') {
            return 'failed';
        }
        $x = $this->api->exchange($code, $verifier, $redirectUri);
        if ($x['status'] !== 'ok') {
            return $x['status'];
        }
        $ch = $this->api->channel($x['access']);
        if (! $ch['ok']) {
            return 'no_channel';
        }
        try {
            $sealed = $this->secrets->seal($x['refresh']);
            $r = $this->repo->saveChannel($tenantId, CreateWorkspace::newId(), $ch['id'], $ch['name'], $sealed['cipher'], $sealed['version']);
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return 'failed';
        }

        return $r === 'limit' ? 'limit' : 'ok';
    }
}
