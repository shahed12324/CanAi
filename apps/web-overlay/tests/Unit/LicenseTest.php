<?php

use App\License\LicenseService;
use Tests\Support\FakeLicenseRepo;

function lsvc(): array
{
    $r = new FakeLicenseRepo;

    return [new LicenseService($r), $r];
}

it('lets people be invited when there is no licence or a free seat', function () {
    [$s, $r] = lsvc();
    expect($s->inviteBlockedReason('t'))->toBeNull();
    $r->left = 2;
    expect($s->inviteBlockedReason('t'))->toBeNull();
});

it('says the seats are full when none are left on a live licence', function () {
    [$s, $r] = lsvc();
    $r->left = 0;
    $r->row = ['seats' => 3, 'used' => 3, 'expires_at' => gmdate('c', time() + 86400), 'note' => ''];
    expect($s->inviteBlockedReason('t'))->toBe(LicenseService::MESSAGE_FULL);
});

it('says the licence expired when it has', function () {
    [$s, $r] = lsvc();
    $r->left = 0;
    $r->row = ['seats' => 30, 'used' => 3, 'expires_at' => gmdate('c', time() - 60), 'note' => ''];
    expect($s->inviteBlockedReason('t'))->toBe(LicenseService::MESSAGE_EXPIRED);
});

it('reports status: none without a licence, left seats, and never negative', function () {
    [$s, $r] = lsvc();
    expect($s->status('t'))->toBeNull();
    $r->row = ['seats' => 5, 'used' => 2, 'expires_at' => gmdate('c', time() + 86400), 'note' => 'n'];
    $st = $s->status('t');
    expect($st['left'])->toBe(3)->and($st['expired'])->toBeFalse()->and($st['note'])->toBe('n');
    $r->row['used'] = 9;
    expect($s->status('t')['left'])->toBe(0);
});

it('shows zero seats left once expired even if seats remain', function () {
    [$s, $r] = lsvc();
    $r->row = ['seats' => 50, 'used' => 2, 'expires_at' => gmdate('c', time() - 1), 'note' => ''];
    expect($s->status('t')['left'])->toBe(0)->and($s->status('t')['expired'])->toBeTrue();
});

it('treats a vanished licence row as full, not as a crash', function () {
    [$s, $r] = lsvc();
    $r->left = 0;
    $r->row = null;
    expect($s->inviteBlockedReason('t'))->toBe(LicenseService::MESSAGE_FULL);
});
