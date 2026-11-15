<?php

use App\Messaging\GatewayHandler;
use App\Messaging\InboundMessage;
use Tests\Support\FakeApprovals;
use Tests\Support\FakeBridge;
use Tests\Support\FakeChannelStore;
use Tests\Support\FakeLimiter;
use Tests\Support\FakeSender;
use Tests\Support\FakeVoiceInbound;

function vgw(): array
{
    $s = new FakeChannelStore;
    $snd = new FakeSender;
    $snd->fileBytes = str_repeat('x', 500);
    $b = new FakeBridge;
    $v = new FakeVoiceInbound;
    $s->links['880'] = ['link_id' => 'LNK1', 'tenant_id' => 'TEN1', 'user_id' => 'USR1', 'chat_id' => '880', 'conversation_id' => null];

    return [new GatewayHandler($s, $snd, $b, new FakeLimiter, new FakeApprovals, voice: $v), $s, $snd, $b, $v];
}

function vmsg(int $id = 1): InboundMessage
{
    return new InboundMessage($id, InboundMessage::VOICE, '880', '880', null, false, true, fileId: 'M1', mime: 'audio/ogg');
}

it('sends the transcript to the AI chat', function () {
    [$h, $s, $snd, $b, $v] = vgw();
    $h->handle('01ACCOUNT00000000000000000', vmsg());
    expect($v->calls)->toHaveCount(1)->and($b->calls)->toHaveCount(1)->and($b->calls[0]['text'])->toBe('what is the weather');
    expect($snd->sent[0]['text'])->toContain('what is the weather');
});

it('runs a spoken command without asking the AI', function () {
    [$h, $s, $snd, $b, $v] = vgw();
    $v->result = ['status' => 'ok', 'transcript' => 'run report', 'reply' => 'Report started.'];
    $h->handle('01ACCOUNT00000000000000000', vmsg(2));
    expect($b->calls)->toHaveCount(0)->and(end($snd->sent)['text'])->toBe('Report started.')->and($s->events[2]['outcome'])->toBe('processed');
});

it('explains voice failures', function () {
    [$h, $s, $snd, $b, $v] = vgw();
    $v->result = ['status' => 'no_credits', 'transcript' => null, 'reply' => null];
    $h->handle('01ACCOUNT00000000000000000', vmsg(3));
    expect($b->calls)->toHaveCount(0)->and($snd->sent[0]['text'])->toContain('credits')->and($s->events[3]['code'])->toBe('voice_no_credits');
});

it('handles a failed download', function () {
    [$h, $s, $snd, $b, $v] = vgw();
    $snd->fileBytes = null;
    $h->handle('01ACCOUNT00000000000000000', vmsg(4));
    expect($v->calls)->toHaveCount(0)->and($s->events[4]['code'])->toBe('voice_download_failed');
});

it('keeps the old reply when voice is not wired', function () {
    $s = new FakeChannelStore;
    $snd = new FakeSender;
    $s->links['880'] = ['link_id' => 'LNK1', 'tenant_id' => 'TEN1', 'user_id' => 'USR1', 'chat_id' => '880', 'conversation_id' => null];
    (new GatewayHandler($s, $snd, new FakeBridge, new FakeLimiter, new FakeApprovals))->handle('01ACCOUNT00000000000000000', vmsg(5));
    expect($snd->sent[0]['text'])->toContain('not enabled');
});
