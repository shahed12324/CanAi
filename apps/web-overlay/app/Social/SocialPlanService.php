<?php

declare(strict_types=1);

namespace App\Social;

use App\Actions\Tenancy\CreateWorkspace;
use App\Ai\ProviderCallException;
use App\Assist\AssistEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Chat\TokenEstimator;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * The content-plan agent.  One bounded request: brief + period -> proposed posts.  It only DRAFTS — nothing is stored as a post until the person picks proposals (see addDrafts).
 * Every request is logged (audit + daily cap).  Money invariant: the reservation ends in exactly one settle (the provider answered, even if unreadable) or one release (no answer).
 */
final class SocialPlanService
{
    private const MAX_OUTPUT = 3500;

    public function __construct(
        private readonly ModelPicker $models,
        private readonly AssistEngine $engine,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
        private readonly SocialRepo $repo,
        private readonly SocialService $social,
    ) {}

    /**
     * @param  list<string>  $platforms
     * @return array{status: string, posts: ?list<array{title: string, platform: string, caption: string, scheduled: string, media: string, idea: string}>}
     *                                                                                                                                          status: ok|empty|brief_long|bad_days|bad_count|bad_platform|bad_tone|person_cap|workspace_cap|no_model|no_credits|provider|unreadable|failed
     */
    public function plan(string $tenantId, string $userId, string $brief, int $days, int $count, array $platforms, string $tone, string $requestId, ?\DateTimeImmutable $now = null): array
    {
        $fail = fn (string $s) => ['status' => $s, 'posts' => null];
        $brief = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $brief));
        $platforms = array_values(array_unique($platforms));
        if ($brief === '') {
            return $fail('empty');
        }
        if (mb_strlen($brief) > SocialPlanPrompt::MAX_BRIEF) {
            return $fail('brief_long');
        }
        if ($days < 1 || $days > 14) {
            return $fail('bad_days');
        }
        if ($count < 1 || $count > SocialPlanPrompt::MAX_POSTS) {
            return $fail('bad_count');
        }
        if ($platforms === [] || count($platforms) > 6 || array_diff($platforms, array_keys(SocialText::PLATFORMS)) !== []) {
            return $fail('bad_platform');
        }
        if (! isset(SocialText::TONES[$tone])) {
            return $fail('bad_tone');
        }
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return $fail('no_model');
        }
        $planId = CreateWorkspace::newId();
        try {
            $started = $this->repo->startPlan($tenantId, $userId, $planId, $brief, $days, $platforms);
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }
        if ($started !== 'ok') {
            return $fail(in_array($started, ['person_cap', 'workspace_cap'], true) ? $started : 'failed');
        }
        $status = $this->run($tenantId, $userId, $brief, $days, $count, $platforms, $tone, $requestId, $model, $now ?? new \DateTimeImmutable('now', $this->social->zone()), $planId);
        try {
            $this->repo->finishPlan($tenantId, $userId, $planId, $status['status'] === 'ok', count($status['posts'] ?? []));
        } catch (\Throwable $e) {
            self::report($e);
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $model
     * @param  list<string>  $platforms
     * @return array{status: string, posts: ?list<array{title: string, platform: string, caption: string, scheduled: string, media: string, idea: string}>}
     */
    private function run(string $tenantId, string $userId, string $brief, int $days, int $count, array $platforms, string $tone, string $requestId, array $model, \DateTimeImmutable $now, string $planId): array
    {
        $fail = fn (string $s) => ['status' => $s, 'posts' => null];
        $start = $now->setTimezone($this->social->zone())->format('Y-m-d');
        $messages = SocialPlanPrompt::messages($brief, $days, $count, $platforms, SocialText::TONES[$tone], $start, $this->best(), $this->planned($now, $days));
        $mult = $model['credit_multiplier'] ?? null;
        $maxOut = min(self::MAX_OUTPUT, max(1200, (int) ($model['max_output'] ?? self::MAX_OUTPUT)));
        $est = TokenEstimator::estimate($messages[0]['content'].$messages[1]['content']);
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount($est, $maxOut, $mult), 'splan:'.$requestId, $requestId)['reservation_id'];
        } catch (CreditException $e) {
            return $fail($e->errorCode === 'insufficient_credits' ? 'no_credits' : 'failed');
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }
        $answer = null;
        $status = 'failed';
        try {
            $answer = $this->engine->complete($model['provider_id'], $model['model_key'], $messages, $tenantId, $userId, $maxOut);
        } catch (ProviderCallException) {
            $status = 'provider';
        } catch (\Throwable $e) {
            self::report($e);
        }
        if ($answer === null) {
            try {
                $this->credits->release($tenantId, $reservation, 'splan-release:'.$requestId);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return $fail($status);
        }
        $in = max(0, (int) ($answer['input_tokens'] ?? $est));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        try {
            $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'splan-settle:'.$requestId, [
                'source' => 'assist', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ]);
        } catch (\Throwable $e) {
            self::report($e);     // the hold is returned by credit_release_stale()
        }
        $posts = SocialPlan::parse((string) $answer['text'], $platforms, $days, $start, $count);

        return $posts === null ? $fail('unreadable') : ['status' => 'ok', 'posts' => $posts];
    }

    /** @return list<array{title: string, caption: string, score: int}> */
    private function best(): array
    {
        try {
            $rows = array_filter($this->repo->posted(30), fn ($r) => $r['reactions'] !== null);
        } catch (\Throwable $e) {
            self::report($e);

            return [];
        }
        $rows = array_map(fn ($r) => ['title' => $r['title'], 'caption' => (string) ($r['caption'] ?? ''), 'score' => (int) $r['reactions'] + (int) $r['comments'] + (int) $r['shares']], array_values($rows));
        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_values(array_filter(array_slice($rows, 0, 3), fn ($r) => $r['score'] > 0));
    }

    /** @return list<string> */
    private function planned(\DateTimeImmutable $now, int $days): array
    {
        try {
            $from = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
            $to = $now->modify("+{$days} days")->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');

            return array_values(array_map(fn ($p) => $p['title'], $this->repo->between($from, $to, false)));
        } catch (\Throwable $e) {
            self::report($e);

            return [];
        }
    }

    /**
     * Turns the proposals the person picked into ordinary drafts (author = the person).  Media is attached later by the person; the idea is kept as a note.
     *
     * @param  list<array{title?: mixed, platform?: mixed, caption?: mixed, scheduled?: mixed, idea?: mixed}>  $items
     * @return array{added: int, failed: int, ids: list<string>}
     */
    public function addDrafts(string $tenantId, string $userId, array $items): array
    {
        $added = 0;
        $failed = 0;
        $ids = [];
        foreach (array_slice($items, 0, SocialPlanPrompt::MAX_POSTS) as $it) {
            $r = $this->social->save($tenantId, $userId, null, ['title' => $it['title'] ?? '', 'platform' => $it['platform'] ?? '', 'caption' => $it['caption'] ?? '', 'media_kind' => 'none', 'scheduled' => $it['scheduled'] ?? '']);
            if ($r['status'] !== 'ok') {
                $failed++;

                continue;
            }
            $added++;
            $ids[] = (string) $r['id'];
            $idea = trim((string) ($it['idea'] ?? ''));
            if ($idea !== '') {
                try {
                    $this->repo->setNote($tenantId, $userId, (string) $r['id'], mb_substr($idea, 0, 500));
                } catch (\Throwable $e) {
                    self::report($e);
                }
            }
        }

        return ['added' => $added, 'failed' => $failed, 'ids' => $ids];
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
