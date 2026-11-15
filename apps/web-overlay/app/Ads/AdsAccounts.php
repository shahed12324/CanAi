<?php

declare(strict_types=1);

namespace App\Ads;

use App\Actions\Tenancy\CreateWorkspace;
use App\Social\TokenSealer;

/** Connects and disconnects Meta ad accounts.  The token is checked with Meta first, then sealed; it is never shown again. */
final class AdsAccounts
{
    public function __construct(private readonly AdsRepo $repo, private readonly TokenSealer $secrets, private readonly MetaAdsApi $api) {}

    /** @return string ok|bad_account|bad_token|mismatch|token|rejected|limit|failed */
    public function connect(string $tenantId, string $accountId, string $token, ?string &$message = null): string
    {
        $accountId = (string) preg_replace('/\Aact_/i', '', trim($accountId));
        $token = trim($token);
        if (preg_match('/\A[0-9]{5,30}\z/', $accountId) !== 1) {
            return 'bad_account';
        }
        if (preg_match('/\A[A-Za-z0-9_\-.]{20,600}\z/', $token) !== 1) {
            return 'bad_token';
        }
        $a = $this->api->account($accountId, $token);
        if (! $a['ok']) {
            $message = $a['message'];

            return $a['token'] ? 'token' : 'rejected';
        }
        if ($a['id'] !== $accountId) {
            return 'mismatch';
        }
        try {
            $sealed = $this->secrets->seal($token);
            $r = $this->repo->saveAccount($tenantId, CreateWorkspace::newId(), $a['name'], $accountId, $a['currency'], $sealed['cipher'], $sealed['version']);
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
