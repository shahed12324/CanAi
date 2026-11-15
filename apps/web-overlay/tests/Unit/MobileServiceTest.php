<?php

use App\Mobile\MobileService;
use App\Mobile\PinPolicy;
use Tests\Support\FakeMobileRepo;

function mobile(?FakeMobileRepo $repo = null, ?int $now = null): array
{
    $repo ??= new FakeMobileRepo;

    return [new MobileService($repo, $now === null ? null : fn () => $now), $repo];
}

function devWithPin(string $pin = '482915', ?string $lockedUntil = null): array
{
    return ['id' => 'dev', 'has_pin' => true, 'pin_hash' => password_hash($pin, PASSWORD_BCRYPT, ['cost' => 4]), 'locked_until' => $lockedUntil, 'push_token' => null];
}

it('accepts a good PIN and rejects easy or malformed ones', function () {
    expect(PinPolicy::problem('482915'))->toBeNull();
    expect(PinPolicy::problem('907314'))->toBeNull();
    foreach (['12345', '1234567', 'abcdef', '12 345', '', '١٢٣٤٥٦', '111111', '000000', '123456', '654321', '234567', '890123', '321098', '121212', '112233'] as $bad) {
        expect(PinPolicy::problem($bad) !== null)->toBeTrue();
    }
});

it('hashes the PIN before it reaches the database and never stores it', function () {
    [$svc, $repo] = mobile();
    expect($svc->setPin(['id' => 'dev'], 'u1', '482915'))->toBe(['ok' => true]);
    $hash = $repo->calls[0][1]['hash'];
    expect(str_contains($hash, '482915'))->toBeFalse();
    expect(password_verify('482915', $hash))->toBeTrue();
});

it('does not call the database for a weak PIN', function () {
    [$svc, $repo] = mobile();
    $r = $svc->setPin(['id' => 'dev'], 'u1', '123456');
    expect($r['ok'])->toBeFalse();
    expect($r['error'])->toBe('weak_pin');
    expect($repo->count('setPin'))->toBe(0);
});

it('steps up with the right PIN for five minutes', function () {
    [$svc, $repo] = mobile();
    $r = $svc->stepUp(devWithPin(), 'u1', '482915');
    expect($r)->toBe(['result' => 'ok', 'expires_in' => 300]);
    expect($repo->calls[0][1]['ok'])->toBeTrue();
});

it('tells the database when the PIN is wrong', function () {
    [$svc, $repo] = mobile();
    $repo->pinResult = 'bad';
    expect($svc->stepUp(devWithPin(), 'u1', '482916'))->toBe(['result' => 'bad']);
    expect($repo->calls[0][1]['ok'])->toBeFalse();
    $svc->stepUp(devWithPin(), 'u1', 'abcdef');                        // junk is never "ok", even if it could match
    expect($repo->calls[1][1]['ok'])->toBeFalse();
});

it('does not even check a locked device, so a lock cannot be probed', function () {
    [$svc, $repo] = mobile(null, 1_000);
    expect($svc->stepUp(devWithPin('482915', gmdate('c', 2_000)), 'u1', '482915'))->toBe(['result' => 'locked']);
    expect($repo->count('pinAttempt'))->toBe(0);
    [$svc, $repo] = mobile(null, 3_000);                              // lock already over: the normal check runs
    expect($svc->stepUp(devWithPin('482915', gmdate('c', 2_000)), 'u1', '482915')['result'])->toBe('ok');
});

it('reports a device that has no PIN yet', function () {
    [$svc, $repo] = mobile();
    expect($svc->stepUp(['id' => 'dev', 'has_pin' => false, 'pin_hash' => null, 'locked_until' => null, 'push_token' => null], 'u1', '482915'))->toBe(['result' => 'no_pin']);
    expect($repo->count('pinAttempt'))->toBe(0);
});

it('registers a phone and keeps only well-formed push addresses', function () {
    [$svc, $repo] = mobile();
    $svc->register('t', 'u1', 5, 'Pixel', 'android', 'ExponentPushToken[abcdefghijkl]', '1.0.0');
    expect($repo->calls[0][1]['pushToken'])->toBe('ExponentPushToken[abcdefghijkl]');
    $svc->register('t', 'u1', 5, 'Pixel', 'android', 'https://evil.test/x', '1.0.0');
    expect($repo->calls[1][1]['pushToken'])->toBeNull();
    expect(fn () => $svc->register('t', 'u1', 5, 'PC', 'windows', null, null))->toThrow(InvalidArgumentException::class);
});

it('tells every phone in the workspace when it is stopped or resumed, and nobody when nothing changed', function () {
    [$svc, $repo] = mobile();
    expect($svc->emergency('t', ['id' => 'dev'], 'u1', true, ' fire '))->toBe('stopped');
    expect($repo->calls[0][1]['reason'])->toBe('fire');
    expect(array_column($repo->notified, 'user'))->toBe(['u1', 'u2']);
    expect($repo->notified[0]['kind'])->toBe('emergency');
    $repo->notified = [];
    foreach (['unchanged', 'step_up_required'] as $r) {
        $repo->emergencyResult = $r;
        expect($svc->emergency('t', ['id' => 'dev'], 'u1', false, ''))->toBe($r);
    }
    expect($repo->notified)->toBe([]);
});

it('passes approval decisions straight to the database rule', function () {
    [$svc, $repo] = mobile();
    expect($svc->decide(['id' => 'dev'], 'u1', 'outreach_message', 'r1', 'approve'))->toBe('approved');
    expect($repo->calls[0][1])->toBe(['kind' => 'outreach_message', 'ref' => 'r1', 'decision' => 'approve']);
});
