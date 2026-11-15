<?php

use App\Admin\AssistantParser as P;

it('reads questions', function () {
    expect(P::parse('how many shops do we have')['name'])->toBe('stats');
    expect(P::parse('Revenue this month?')['name'])->toBe('revenue');
    expect(P::parse('show open tickets')['name'])->toBe('tickets');
    expect(P::parse('which trials are running')['name'])->toBe('trials');
    expect(P::parse('top shops by usage')['name'])->toBe('usage');
    expect(P::parse('')['name'])->toBe('help');
    expect(P::parse('blah blah')['name'])->toBe('unknown');
});

it('reads actions with their arguments', function () {
    $s = P::parse('suspend shop acme-store because unpaid invoice');
    expect($s['name'])->toBe('suspend_shop')->and($s['args']['shop'])->toBe('acme-store')->and($s['args']['reason'])->toBe('unpaid invoice');
    expect(P::parse('activate acme-store')['args']['shop'])->toBe('acme-store');
    $t = P::parse('start trial for acme-store 30 days on pro');
    expect($t['name'])->toBe('start_trial')->and($t['args'])->toBe(['shop' => 'acme-store', 'days' => 30, 'plan' => 'pro']);
    expect(P::parse('start trial acme')['args']['days'])->toBe(14);
    expect(P::parse('extend trial acme by 7 days')['args'])->toBe(['shop' => 'acme', 'days' => 7]);
    expect(P::parse('announce: Maintenance tonight at 2am')['args']['title'])->toBe('Maintenance tonight at 2am');
    expect(P::parse('find shop acme')['args']['term'])->toBe('acme');
});

it('marks only changes as actions and caps days', function () {
    expect(P::isAction('suspend_shop'))->toBeTrue()->and(P::isAction('stats'))->toBeFalse()->and(P::isAction('find_shop'))->toBeFalse();
    expect(P::parse('extend trial acme 9999 days')['args']['days'])->toBe(365);
});
