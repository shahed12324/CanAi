<?php

use App\Mobile\ExpoPush;
use App\Mobile\PushDispatcher;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeMobileAdminRepo;
use Tests\Support\FakePushSender;

function msgs(int $n): array
{
    return array_map(fn ($i) => ['to' => "ExponentPushToken[tok{$i}aaaaaaaa]", 'title' => "t{$i}", 'body' => 'b', 'data' => ['id' => "n{$i}"]], range(1, $n));
}

it('sends the expected payload to Expo and maps each ticket in order', function () {
    $http = new FakeHttpJson;
    $http->queue[] = ['status' => 200, 'json' => ['data' => [['status' => 'ok'], ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']], ['status' => 'error', 'details' => ['error' => 'MessageRateExceeded']]]]];
    $r = (new ExpoPush($http))->send(msgs(3));
    expect($r)->toBe(['ok', 'invalid_token', 'error:MessageRateExceeded']);
    expect($http->calls[0]['url'])->toBe('https://exp.host/--/api/v2/push/send');
    $body = json_decode($http->calls[0]['body'], true);
    expect($body)->toHaveCount(3);
    expect($body[0]['to'])->toBe('ExponentPushToken[tok1aaaaaaaa]');
    expect($body[0]['priority'])->toBe('high');
    expect(isset($http->calls[0]['headers']['Authorization']))->toBeFalse();
});

it('adds the access token only when one is configured', function () {
    $http = new FakeHttpJson;
    $http->queue[] = ['status' => 200, 'json' => ['data' => [['status' => 'ok']]]];
    (new ExpoPush($http, 'secret-token'))->send(msgs(1));
    expect($http->calls[0]['headers']['Authorization'])->toBe('Bearer secret-token');
});

it('sends at most 100 messages per request', function () {
    $http = new FakeHttpJson;
    $http->queue[] = ['status' => 200, 'json' => ['data' => array_fill(0, 100, ['status' => 'ok'])]];
    $http->queue[] = ['status' => 200, 'json' => ['data' => array_fill(0, 20, ['status' => 'ok'])]];
    $r = (new ExpoPush($http))->send(msgs(120));
    expect($http->calls)->toHaveCount(2);
    expect($r)->toHaveCount(120);
});

it('reports a failed request per message instead of throwing', function () {
    $http = new FakeHttpJson;
    $http->queue[] = ['status' => 503, 'json' => []];
    expect((new ExpoPush($http))->send(msgs(2)))->toBe(['error:http_503', 'error:http_503']);
});

function dispatcher(FakeMobileAdminRepo $repo, FakePushSender $push, bool $can = true): PushDispatcher
{
    return new PushDispatcher($repo, $push, fn () => $can);
}

it('makes one approvals note per person and not another while it is unread', function () {
    $repo = new FakeMobileAdminRepo;
    $repo->tenants = [['tenant' => 't1', 'pending' => 4]];
    $repo->users = ['t1' => ['u1', 'u2']];
    $d = dispatcher($repo, new FakePushSender);
    expect($d->digestApprovals())->toBe(2);
    expect($repo->inserted[0]['title'])->toContain('4');
    expect($d->digestApprovals())->toBe(0);
});

it('does not notify people who may not approve', function () {
    $repo = new FakeMobileAdminRepo;
    $repo->tenants = [['tenant' => 't1', 'pending' => 1]];
    $repo->users = ['t1' => ['u1']];
    expect(dispatcher($repo, new FakePushSender, false)->digestApprovals())->toBe(0);
});

function queued(int $n): array
{
    return array_map(fn ($i) => ['id' => "n{$i}", 'tenant' => 't1', 'token' => "ExponentPushToken[tok{$i}aaaaaaaa]", 'title' => "t{$i}", 'body' => 'b', 'kind' => 'x', 'ref_kind' => null, 'ref_id' => null], range(1, $n));
}

it('marks delivered notifications, drops dead tokens and records other errors', function () {
    $repo = new FakeMobileAdminRepo;
    $repo->queue = queued(3);
    $push = new FakePushSender;
    $push->answers = ['ok', 'invalid_token', 'error:MessageRateExceeded'];
    expect(dispatcher($repo, $push)->flush())->toBe(['sent' => 1, 'failed' => 2]);
    expect($repo->pushed)->toBe([['n1', null], ['n2', 'invalid_token'], ['n3', 'error:MessageRateExceeded']]);
    expect($repo->dropped)->toBe(['ExponentPushToken[tok2aaaaaaaa]']);
});

it('never puts message bodies or ids of other people in the push data', function () {
    $repo = new FakeMobileAdminRepo;
    $repo->queue = queued(1);
    $push = new FakePushSender;
    dispatcher($repo, $push)->flush();
    expect(array_keys($push->sent[0]['data']))->toBe(['id', 'kind', 'ref_kind', 'tenant']);
});

it('does nothing when there is nothing to push', function () {
    $push = new FakePushSender;
    expect(dispatcher(new FakeMobileAdminRepo, $push)->flush())->toBe(['sent' => 0, 'failed' => 0]);
    expect($push->sent)->toBe([]);
});

it('keeps a notification delivered when one of its phones succeeded and another did not', function () {
    $repo = new FakeMobileAdminRepo;
    $q = queued(1);
    $repo->queue = [$q[0], $q[0]];
    $push = new FakePushSender;
    $push->answers = ['ok', 'error:Boom'];
    dispatcher($repo, $push)->flush();
    expect($repo->pushed)->toBe([['n1', null]]);
});

it('retires phones whose login is gone through the repository', function () {
    $repo = new FakeMobileAdminRepo;
    $repo->retired = 3;
    expect(dispatcher($repo, new FakePushSender)->retireDeadDevices())->toBe(3);
});
