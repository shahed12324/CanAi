<?php

declare(strict_types=1);

namespace App\Ads;

use App\Social\GraphResult;
use App\Social\TokenSealer;

/**
 * Creates one claimed (approved) draft on Meta as PAUSED objects: campaign → ad set → creative → ad.
 * Each id is saved the moment Meta returns it, and a step that already has an id is skipped — so a retry only does what is missing.
 * Before any call: the plan must be valid and the token openable (otherwise nothing is created).  A rejection stops the attempt with Meta's reason; an unknown outcome stops it as "unknown" and is never retried by itself.
 */
final class AdsLauncher
{
    private const STEPS = ['campaign' => 'Campaign', 'adset' => 'Ad set', 'creative' => 'Creative', 'ad' => 'Ad'];

    public function __construct(private readonly AdsLaunchRepo $repo, private readonly AdsRepo $accounts, private readonly TokenSealer $secrets, private readonly MetaAdsLaunchApi $api) {}

    /**
     * @param  array<string, mixed>  $c  one claimed row (see PgAdsLaunchRepo::claim)
     * @return string created|failed
     */
    public function launch(array $c, ?int $now = null): string
    {
        $plan = AdsLaunchPlan::build($c, $now ?? time());
        if ($plan['error'] !== null) {
            return $this->fail($c, $plan['error'].' Nothing was created.', false);
        }
        $token = $this->secrets->open((string) $c['cipher'], (int) $c['version']);
        if ($token === null) {
            $this->safe(fn () => $this->accounts->fail((string) $c['tenant_id'], (string) $c['account_id'], 'The token could not be opened — connect the account again.', true));

            return $this->fail($c, 'The token could not be opened — connect the ad account again. Nothing was created.', false);
        }
        $ext = (string) $c['account_ext'];
        $ids = ['campaign' => $c['remote_campaign'] ?? null, 'adset' => $c['remote_adset'] ?? null, 'creative' => $c['remote_creative'] ?? null, 'ad' => $c['remote_ad'] ?? null];

        foreach (self::STEPS as $step => $label) {
            if ($ids[$step] !== null) {
                continue;
            }
            $params = $plan[$step];
            if ($step === 'adset') {
                $params['campaign_id'] = (string) $ids['campaign'];
            }
            if ($step === 'ad') {
                $params['adset_id'] = (string) $ids['adset'];
                $params['creative'] = json_encode(['creative_id' => (string) $ids['creative']], JSON_THROW_ON_ERROR);
            }
            $res = match ($step) {
                'campaign' => $this->api->campaign($ext, $token, $params),
                'adset' => $this->api->adSet($ext, $token, $params),
                'creative' => $this->api->creative($ext, $token, $params),
                'ad' => $this->api->ad($ext, $token, $params),
            };
            if ($res->status !== 'ok') {
                return $this->stopped($c, $step, $label, $res, $ids);
            }
            $ids[$step] = (string) $res->id;
            if ($this->safe(fn () => $this->repo->step((string) $c['tenant_id'], (string) $c['id'], $step, (string) $res->id)) !== 'ok') {
                return $this->fail($c, "{$label} Meta-was created in (ID {$res->id}) but could not be saved here — check in Ads Manager, then \"retry\" give.", true);
            }
        }
        $done = $this->safe(fn () => $this->repo->done((string) $c['tenant_id'], (string) $c['id']));

        return $done === 'ok' ? 'created' : $this->fail($c, 'All objects were created but the status could not be saved — check in Ads Manager; "retry" giving it will not create anything new.', true);
    }

    /** @param  array<string, ?string>  $ids */
    private function stopped(array $c, string $step, string $label, GraphResult $res, array $ids): string
    {
        $made = array_keys(array_filter($ids, fn ($v) => $v !== null));
        $kept = $made === [] ? 'Nothing was created.' : 'Created so far (PAUSED): '.implode(', ', array_map(fn ($k) => self::STEPS[$k], $made)).' — These will not be created again on retry.';
        if ($res->status === 'token') {
            $this->safe(fn () => $this->accounts->fail((string) $c['tenant_id'], (string) $c['account_id'], 'Meta The token was not accepted — connect the account again (with ads_management permission).', true));

            return $this->fail($c, "{$label}: Meta The token was not accepted — connect the ad account again (ads_management permission is needed). {$kept}", false);
        }
        if ($res->status === 'rejected') {
            return $this->fail($c, "{$label} Not created — {$res->message} {$kept}", false);
        }

        return $this->fail($c, "{$label}: {$res->message} Ads Manager-see in; {$kept} then \"retry\" give.", true);
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
