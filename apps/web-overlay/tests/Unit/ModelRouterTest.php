<?php

use App\Ai\HealthPolicy;
use App\Ai\ModelRouter as R;

function cand(string $id, int $q, ?float $cost, ?float $lat, array $caps = ['text'], int $pp = 100): array
{
    return ['id' => $id, 'provider_id' => 'p', 'provider_priority' => $pp, 'quality_rank' => $q, 'cost' => $cost, 'latency_ms' => $lat, 'capabilities' => $caps];
}

function pool(): array
{
    return [cand('cheap', 40, 1.0, 900), cand('fast', 50, 5.0, 100), cand('best', 95, 30.0, 1500, ['text', 'vision']), cand('mid', 70, 8.0, 600)];
}

it('picks per mode', function () {
    expect(R::choose(pool(), ['routing_mode' => 'cheapest'], null)['id'])->toBe('cheap')
        ->and(R::choose(pool(), ['routing_mode' => 'fastest'], null)['id'])->toBe('fast')
        ->and(R::choose(pool(), ['routing_mode' => 'best_quality'], null)['id'])->toBe('best');
});

it('balanced blends quality (0.6) and cost efficiency (0.4)', function () {
    expect(R::choose(pool(), ['routing_mode' => 'balanced'], null)['id'])->toBe('cheap')
        ->and(R::choose([...pool(), cand('star', 90, 0.5, 50)], ['routing_mode' => 'balanced'], null)['id'])->toBe('star');
});

it('filters by required capability and returns null when nothing fits', function () {
    expect(R::choose(pool(), ['routing_mode' => 'cheapest'], null, ['text', 'vision'])['id'])->toBe('best')
        ->and(R::choose(pool(), [], null, ['image_gen']))->toBeNull()
        ->and(R::choose([], [], null))->toBeNull();
});

it('honours a manual default but degrades gracefully', function () {
    expect(R::choose(pool(), ['routing_mode' => 'manual', 'default_model_id' => 'fast'], null)['id'])->toBe('fast')
        ->and(R::choose(pool(), ['routing_mode' => 'manual', 'default_model_id' => 'fast'], null, ['vision'])['id'])->toBe('best')
        ->and(R::choose(pool(), ['routing_mode' => 'manual', 'default_model_id' => 'ghost'], null))->not->toBeNull();
});

it('applies the admin rule only when the tenant left the mode on balanced', function () {
    $rule = ['mode' => 'cheapest', 'preferred_model_ids' => ['ghost', 'best']];
    expect(R::choose(pool(), ['routing_mode' => 'balanced'], $rule)['id'])->toBe('best')
        ->and(R::choose(pool(), ['routing_mode' => 'fastest'], $rule)['id'])->toBe('fast')
        ->and(R::choose(pool(), ['routing_mode' => 'balanced'], ['mode' => 'cheapest', 'preferred_model_ids' => ['ghost']])['id'])->toBe('cheap')
        ->and(R::choose(pool(), ['routing_mode' => 'balanced'], ['mode' => 'manual', 'preferred_model_ids' => []]))->not->toBeNull();
});

it('treats unknown cost and latency as worst, and breaks ties deterministically', function () {
    expect(R::choose([cand('known', 50, 2.0, null), cand('unknown', 50, null, null)], ['routing_mode' => 'cheapest'], null)['id'])->toBe('known')
        ->and(R::choose([cand('a', 50, 1, null), cand('b', 50, 1, 800)], ['routing_mode' => 'fastest'], null)['id'])->toBe('b')
        ->and(R::choose([cand('z', 50, 1, 1, ['text'], 200), cand('y', 50, 1, 1, ['text'], 10)], ['routing_mode' => 'best_quality'], null)['id'])->toBe('y')
        ->and(R::choose([cand('b', 50, 1, 1), cand('a', 50, 1, 1)], ['routing_mode' => 'best_quality'], null)['id'])->toBe('a');
});

it('builds fallback chains that stop at loops, disabled links and unknown providers', function () {
    $p = ['a' => ['fallback' => 'b', 'status' => 'active'], 'b' => ['fallback' => 'c', 'status' => 'degraded'], 'c' => ['fallback' => null, 'status' => 'active']];
    expect(R::fallbackChain('a', $p))->toBe(['a', 'b', 'c']);
    $p['c']['fallback'] = 'a';
    expect(R::fallbackChain('a', $p))->toBe(['a', 'b', 'c']);
    $p['b']['status'] = 'disabled';
    expect(R::fallbackChain('a', $p))->toBe(['a'])->and(R::fallbackChain('x', $p))->toBe([]);
});

it('health policy degrades after 3 failures, recovers on success, never enables a disabled provider', function () {
    expect(HealthPolicy::next('degraded', [true, false, false]))->toBe('active')
        ->and(HealthPolicy::next('active', [false, false, true]))->toBe('active')
        ->and(HealthPolicy::next('active', [false, false, false]))->toBe('degraded')
        ->and(HealthPolicy::next('disabled', [true]))->toBe('disabled')
        ->and(HealthPolicy::next('disabled', [false, false, false]))->toBe('disabled')
        ->and(HealthPolicy::next('active', []))->toBe('active');
});
