<?php

declare(strict_types=1);

namespace App\GoogleAds;

use App\Social\GraphResult;
use App\Social\TokenSealer;

/**
 * Creates one claimed (approved) Google Ads draft: budget → campaign (PAUSED) → ad group → responsive search ad → keywords → targeting.
 * Each id is saved the moment Google returns it, and a step that is already done is skipped — so a retry only does what is missing.
 * Before any call: the plan must be valid and the token openable (otherwise nothing is created).  A rejection stops the attempt with Google's reason; an unknown outcome stops it as "unknown" and is never retried by itself.
 */
final class GadsLauncher
{
    private const STEPS = ['budget' => 'Budget', 'campaign' => 'Campaign', 'adgroup' => 'Ad group', 'ad' => 'Ad', 'keywords' => 'Keywords', 'targeting' => 'Location/language/negatives'];

    public function __construct(private readonly GadsLaunchRepo $repo, private readonly GoogleAdsRepo $accounts, private readonly TokenSealer $secrets, private readonly GoogleAdsApi $auth, private readonly GoogleAdsLaunchApi $api) {}

    /**
     * @param  array<string, mixed>  $c  one claimed row (see PgGadsLaunchRepo::claim)
     * @return string created|failed
     */
    public function launch(array $c, ?int $now = null): string
    {
        $plan = GadsLaunchPlan::build($c, $now ?? time());
        if ($plan['error'] !== null) {
            return $this->fail($c, $plan['error'].' Nothing was created.', false);
        }
        $refresh = $this->secrets->open((string) $c['cipher'], (int) $c['version']);
        if ($refresh === null) {
            $this->safe(fn () => $this->accounts->fail((string) $c['tenant_id'], (string) $c['account_id'], 'The token could not be opened — connect the account again.', true));

            return $this->fail($c, 'The token could not be opened — connect the Google Ads account again. Nothing was created.', false);
        }
        $t = $this->auth->refresh($refresh);
        if ($t['status'] === 'revoked') {
            $this->safe(fn () => $this->accounts->fail((string) $c['tenant_id'], (string) $c['account_id'], 'Google Permission was withdrawn — connect the account again.', true));

            return $this->fail($c, 'Google Permission was withdrawn — connect the Google Ads account again.'.$this->kept($c), false);
        }
        if ($t['status'] !== 'ok') {
            return $this->fail($c, 'Google-could not be reached (token could not be fetched) — nothing changed; shortly "retry" give.'.$this->kept($c), false);
        }
        $access = $t['access'];
        $cu = (string) $c['customer_id'];
        $lg = $c['login_id'] ?? null;
        $ids = ['budget' => $c['remote_budget'] ?? null, 'campaign' => $c['remote_campaign'] ?? null, 'adgroup' => $c['remote_adgroup'] ?? null, 'ad' => $c['remote_ad'] ?? null,
            'keywords' => ($c['keywords_done'] ?? false) ? 'done' : null, 'targeting' => ($c['targeting_done'] ?? false) ? 'done' : null];

        foreach (self::STEPS as $step => $label) {
            if ($ids[$step] !== null) {
                continue;
            }
            $res = match ($step) {
                'budget' => $this->api->budget($cu, $lg, $access, $plan['budget']),
                'campaign' => $this->api->campaign($cu, $lg, $access, (string) $ids['budget'], $plan['campaign']),
                'adgroup' => $this->api->adGroup($cu, $lg, $access, (string) $ids['campaign'], $plan['adgroup']),
                'ad' => $this->api->ad($cu, $lg, $access, (string) $ids['adgroup'], $plan['ad']),
                'keywords' => $this->api->keywords($cu, $lg, $access, (string) $ids['adgroup'], $plan['keywords']),
                'targeting' => $this->api->targeting($cu, $lg, $access, (string) $ids['campaign'], $plan['targeting']),
            };
            if ($res->status !== 'ok') {
                return $this->stopped($c, $label, $res, $ids);
            }
            $ids[$step] = (string) $res->id;
            if ($this->safe(fn () => $this->repo->step((string) $c['tenant_id'], (string) $c['id'], $step, (string) $res->id)) !== 'ok') {
                return $this->fail($c, "{$label} Google-was created in but could not be saved here — check in Google Ads, then \"retry\" give.", true);
            }
        }
        $done = $this->safe(fn () => $this->repo->done((string) $c['tenant_id'], (string) $c['id']));

        return $done === 'ok' ? 'created' : $this->fail($c, 'All objects were created but the status could not be saved — check in Google Ads; "retry" giving it will not create anything new.', true);
    }

    /** @param  array<string, mixed>  $c */
    private function kept(array $c): string
    {
        $made = array_filter(['budget' => $c['remote_budget'] ?? null, 'campaign' => $c['remote_campaign'] ?? null, 'adgroup' => $c['remote_adgroup'] ?? null, 'ad' => $c['remote_ad'] ?? null, 'keywords' => ($c['keywords_done'] ?? false) ? 'x' : null, 'targeting' => ($c['targeting_done'] ?? false) ? 'x' : null], fn ($v) => $v !== null);

        return $made === [] ? ' Nothing was created.' : ' Created so far (PAUSED): '.implode(', ', array_map(fn ($k) => self::STEPS[$k], array_keys($made))).' — These will not be created again on retry.';
    }

    /** @param  array<string, ?string>  $ids */
    private function stopped(array $c, string $label, GraphResult $res, array $ids): string
    {
        $made = array_keys(array_filter($ids, fn ($v) => $v !== null));
        $kept = $made === [] ? 'Nothing was created.' : 'Created so far (PAUSED): '.implode(', ', array_map(fn ($k) => self::STEPS[$k], $made)).' — These will not be created again on retry.';
        if ($res->status === 'token') {
            $this->safe(fn () => $this->accounts->fail((string) $c['tenant_id'], (string) $c['account_id'], 'Google did not accept the permission — connect the account again.', true));

            return $this->fail($c, "{$label}: Google did not accept the permission — connect the Google Ads account again. {$kept}", false);
        }
        if ($res->status === 'rejected') {
            return $this->fail($c, "{$label} Not created — {$res->message} {$kept}", false);
        }

        return $this->fail($c, "{$label}: {$res->message} Google Ads-see in; {$kept} then \"retry\" give.", true);
    }

    /** @param  array<string, mixed>  $c */
    private function fail(array $c, string $why, bool $unknown): string
    {
        $this->safe(fn () => $this->repo->fail((string) $c['tenant_id'], (string) $c['id'], $why, $unknown));

        return 'failed';
    }

    /** @template T @param  \Closure(): T  $f @return T|null */
    private function safe(\Closure $f): mixed
    {
        try {
            return $f();
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return null;
        }
    }
}
