<?php

use App\Mobile\ApprovalAlert;
use App\Mobile\PushDispatcher;
use App\Pc\PcService;
use Tests\Support\FakeMobileAdminRepo;
use Tests\Support\FakePcRepo;
use Tests\Support\FakePushSender;

function aa(): array
{
    $repo = new FakeMobileAdminRepo;
    $push = new FakePushSender;
    $repo->queue = [['id' => 'n1', 'tenant' => 'T', 'token' => 'ExponentPushToken[aaaaaaaaaaaaaa]', 'title' => 'x', 'body' => 'b', 'kind' => 'pc_approval', 'ref_kind' => 'pc', 'ref_id' => null]];

    return [new ApprovalAlert($repo, new PushDispatcher($repo, $push, fn () => true)), $repo, $push];
}

it('writes one generic notification and sends it at once', function () {
    [$a, $repo, $push] = aa();
    expect($a->computerWaiting('T', 'U', true))->toBeTrue();
    expect($repo->inserted[0]['kind'])->toBe('pc_approval');
    expect(count($push->sent))->toBe(1);
    expect($repo->pushed[0][0])->toBe('n1');
});

it('never puts a goal, path or command in the message', function () {
    [$a, $repo] = aa();
    $a->computerWaiting('T', 'U', false);
    $text = $repo->inserted[0]['title'].' '.$repo->inserted[0]['body'];
    expect(preg_match('~[/\\\\]|\.\w{2,4}\b.*\.|delete|C:|password~i', $text))->toBe(0);
});

it('does not flood: a second alert waits until the first is read', function () {
    [$a, $repo] = aa();
    expect($a->computerWaiting('T', 'U', true))->toBeTrue();
    expect($a->computerWaiting('T', 'U', true))->toBeFalse();
    expect(count($repo->inserted))->toBe(1);
});

it('never throws, even when the notification store is broken', function () {
    $repo = new FakeMobileAdminRepo;
    $repo->broken = true;
    $a = new ApprovalAlert($repo, new PushDispatcher($repo, new FakePushSender, fn () => true));
    expect($a->computerWaiting('T', 'U', true))->toBeFalse();
});

it('alerts for a pending web request but not for a request made on the phone, and not for refused ones', function () {
    [$a, $repo] = aa();
    $pc = new FakePcRepo;
    $svc = new PcService($pc, $a);
    $svc->request('T', 'U', 'D', 'read_file', ['path' => '/a']);
    expect(count($repo->inserted))->toBe(1);
    [$a2, $repo2] = aa();
    (new PcService($pc, $a2))->mobileRequest('T', 'U', '01234567890123456789012345', 'screenshot', 'x');
    expect(count($repo2->inserted))->toBe(0);
    [$a3, $repo3] = aa();
    $pc->requestResult = ['status' => 'rejected_policy', 'reason' => 'kind_off', 'id' => null, 'risk' => 'low'];
    (new PcService($pc, $a3))->request('T', 'U', 'D', 'read_file', ['path' => '/a']);
    expect(count($repo3->inserted))->toBe(0);
});

it('is wired into the planner, the service and both providers', function () {
    $root = __DIR__.'/../../app/';
    expect(str_contains(file_get_contents($root.'Pc/PcPlanner.php'), 'alert?->computerWaiting'))->toBeTrue();
    expect(str_contains(file_get_contents($root.'Providers/PcServiceProvider.php'), 'ApprovalAlert::class'))->toBeTrue();
    expect(str_contains(file_get_contents($root.'Providers/MobileServiceProvider.php'), 'ApprovalAlert::class'))->toBeTrue();
});
