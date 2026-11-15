<?php

declare(strict_types=1);

namespace App\Social;

use App\Actions\Tenancy\CreateWorkspace;

/** Connects and disconnects Facebook Pages.  The Page token is checked with Facebook first, then sealed; it is never shown again. */
final class SocialAccounts
{
    public function __construct(private readonly SocialRepo $repo, private readonly TokenSealer $secrets, private readonly FacebookGraph $graph) {}

    /** @return string ok|bad_page|bad_token|mismatch|token|rejected|limit|failed */
    public function connect(string $tenantId, string $pageId, string $token, ?string &$message = null): string
    {
        $pageId = trim($pageId);
        $token = trim($token);
        if (preg_match('/\A[0-9]{5,30}\z/', $pageId) !== 1) {
            return 'bad_page';
        }
        if (preg_match('/\A[A-Za-z0-9_\-.]{20,600}\z/', $token) !== 1) {
            return 'bad_token';
        }
        $p = $this->graph->page($pageId, $token);
        if (! $p['ok']) {
            $message = $p['message'];

            return $p['token'] ? 'token' : 'rejected';
        }
        if ($p['id'] !== $pageId) {
            return 'mismatch';
        }
        try {
            $sealed = $this->secrets->seal($token);
            $r = $this->repo->saveAccount($tenantId, CreateWorkspace::newId(), $pageId, mb_substr($p['name'], 0, 120), $sealed['cipher'], $sealed['version']);
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return 'failed';
        }

        return $r === 'limit' ? 'limit' : 'ok';
    }

    public function disconnect(string $tenantId, string $accountId): string
    {
        try {
            return $this->repo->deleteAccount($tenantId, $accountId);
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return 'failed';
        }
    }
}
