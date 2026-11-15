<?php

declare(strict_types=1);

namespace App\GoogleAds;

use App\Actions\Tenancy\CreateWorkspace;
use App\Social\TokenSealer;

/** Connects a Google Ads customer after Google's consent screen: the refresh token is sealed and never shown; the customer is checked with Google before anything is saved. */
final class GoogleAdsAccounts
{
    public function __construct(private readonly GoogleAdsRepo $repo, private readonly TokenSealer $secrets, private readonly GoogleAdsApi $api) {}

    /** Digits only; dashes and spaces are allowed in the input. */
    public static function cleanId(string $v): ?string
    {
        $d = (string) preg_replace('/[\s-]/', '', trim($v));

        return preg_match('/\A[0-9]{10}\z/', $d) === 1 ? $d : null;
    }

    /** @return string ok|failed|no_refresh|scope|token|rejected|inactive|limit */
    public function connect(string $tenantId, string $customerId, ?string $loginId, string $code, string $verifier, string $redirectUri, ?string &$message = null): string
    {
        if ($code === '' || mb_strlen($code) > 2048 || $verifier === '' || preg_match('/\A[0-9]{10}\z/', $customerId) !== 1 || ($loginId !== null && preg_match('/\A[0-9]{10}\z/', $loginId) !== 1)) {
            return 'failed';
        }
        $x = $this->api->exchange($code, $verifier, $redirectUri);
        if ($x['status'] !== 'ok') {
            return $x['status'];
        }
        $c = $this->api->customer($customerId, $loginId, $x['access']);
        if (! $c['ok']) {
            $message = $c['message'];

            return $c['token'] ? 'token' : 'rejected';
        }
        if (! $c['active']) {
            return 'inactive';
        }
        try {
            $sealed = $this->secrets->seal($x['refresh']);
            $r = $this->repo->saveAccount($tenantId, CreateWorkspace::newId(), $c['name'], $customerId, $loginId, $c['currency'], $sealed['cipher'], $sealed['version']);
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
