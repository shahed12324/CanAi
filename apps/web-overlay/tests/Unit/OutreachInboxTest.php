<?php

use App\Outreach\GmailInbox;
use App\Outreach\GraphInbox;
use App\Outreach\InboundClassifier;
use App\Outreach\InboxFailure;
use App\Outreach\InboxReader;
use App\Outreach\OutreachInbox;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeOutreachRepo;

const OWN = ['me@agency.test'];

function msg(array $o = []): array
{
    return $o + ['id' => 'm1', 'from' => 'boss@alo.test', 'subject' => 'Re: আপনার সাইট', 'thread_id' => null, 'in_reply_to' => null, 'content_type' => 'text/plain', 'auto_submitted' => null, 'precedence' => null, 'x_auto_response_suppress' => null, 'x_failed_recipients' => null, 'text' => 'ঠিক আছে, কথা বলি।', 'received_at' => '2026-11-03T05:00:00+00:00'];
}

it('tells replies, bounces and robots apart', function () {
    expect(InboundClassifier::kind(msg(), OWN))->toBe('reply');
    expect(InboundClassifier::kind(msg(['from' => 'ME@agency.test']), OWN))->toBe('other');                         // our own copy
    expect(InboundClassifier::kind(msg(['from' => 'mailer-daemon@googlemail.com', 'subject' => 'Delivery Status Notification (Failure)']), OWN))->toBe('bounce');
    expect(InboundClassifier::kind(msg(['from' => 'postmaster@alo.test', 'subject' => 'x']), OWN))->toBe('bounce');
    expect(InboundClassifier::kind(msg(['from' => 'noreply@outlook.com', 'subject' => 'Undeliverable: আপনার সাইট']), OWN))->toBe('bounce');
    expect(InboundClassifier::kind(msg(['content_type' => 'multipart/report; report-type=delivery-status; boundary=x', 'from' => 'a@b.test']), OWN))->toBe('bounce');
    foreach ([['auto_submitted' => 'auto-replied'], ['precedence' => 'bulk'], ['x_auto_response_suppress' => 'All'], ['subject' => 'Automatic reply: আপনার সাইট'], ['subject' => 'Out of Office until Monday']] as $o) {
        expect(InboundClassifier::kind(msg($o), OWN))->toBe('auto');
    }
    expect(InboundClassifier::kind(msg(['auto_submitted' => 'no']), OWN))->toBe('reply');                       // "no" means a human
    expect(InboundClassifier::kind(msg(['from' => '']), OWN))->toBe('other');
});

it('finds the address that could not be reached, never ours', function () {
    expect(InboundClassifier::failedRecipient(['x_failed_recipients' => 'Gone@Alo.test', 'text' => ''], OWN))->toBe('gone@alo.test');
    expect(InboundClassifier::failedRecipient(['text' => "Final-Recipient: rfc822; boss@alo.test\nAction: failed"], OWN))->toBe('boss@alo.test');
    expect(InboundClassifier::failedRecipient(['text' => "Your message wasn't delivered to boss@alo.test because the address couldn't be found."], OWN))->toBe('boss@alo.test');
    expect(InboundClassifier::failedRecipient(['text' => 'Message from me@agency.test could not reach <who@alo.test>.'], OWN))->toBe('who@alo.test');
    expect(InboundClassifier::failedRecipient(['text' => 'something bad happened'], OWN))->toBeNull();
    expect(InboundClassifier::failedRecipient(['text' => 'delivery to me@agency.test failed'], OWN))->toBeNull();
});

class FakeReader implements InboxReader
{
    public array $messages = [];

    public ?string $throw = null;

    public array $calls = [];

    public function fetch(array $conn, string $sinceIso, callable $isSeen): array
    {
        $this->calls[] = [$conn, $sinceIso];
        if ($this->throw) {
            throw new InboxFailure($this->throw);
        }

        return array_values(array_filter($this->messages, fn ($m) => ! $isSeen($m['id'])));
    }
}

function inboxWorld(): array
{
    $repo = new FakeOutreachRepo;
    $repo->accounts['acc'] = ['id' => 'acc', 'provider' => 'gmail', 'from_email' => 'me@agency.test', 'from_name' => null, 'host' => null, 'port' => null, 'security' => 'tls', 'username' => null, 'secret_ct' => null, 'refresh_ct' => 'CT', 'key_version' => 1, 'daily_limit' => 20, 'warmup_started_on' => '2026-10-01', 'status' => 'active'];
    $repo->campaigns['camp'] = ['id' => 'camp', 'account_id' => 'acc', 'subject_tpl' => 's', 'body_tpl' => 'b', 'followup_after_days' => 3, 'followup_body_tpl' => 'f', 'status' => 'active'];
    foreach (['boss@alo.test' => 'A', 'other@beta.test' => 'B'] as $email => $_) {
        $id = $repo->enqueue('T', 'camp', null, null, $email, 1, 's', 'b');
        $repo->approve($id, 'U');
        $repo->claim('acc', 10);
        $repo->markSent($id, 'rfc-'.$email, 'p', 'thread-'.$email);
    }
    $reader = new FakeReader;
    $inbox = new OutreachInbox($repo, ['gmail' => $reader], fn ($a) => 'AT', fn () => 2_000_000);

    return [$inbox, $repo, $reader, array_keys($repo->messages)];
}

it('marks a real reply, ignores robots and strangers, and handles each mail once', function () {
    [$inbox, $repo, $reader, $ids] = inboxWorld();
    $reader->messages = [
        msg(['id' => 'r1', 'from' => 'boss@alo.test']),
        msg(['id' => 'a1', 'from' => 'other@beta.test', 'subject' => 'Automatic reply: hi', 'auto_submitted' => 'auto-replied']),
        msg(['id' => 's1', 'from' => 'stranger@nowhere.test']),
    ];
    $r = $inbox->poll('T', 'acc');
    expect($r)->toBe(['replied' => 1, 'bounced' => 0, 'auto' => 1, 'other' => 1, 'stopped' => null]);
    expect($repo->messages[$ids[0]]['status'])->toBe('replied')->and($repo->messages[$ids[1]]['status'])->toBe('sent');
    expect($repo->checked['acc'])->toBe(2_000_000);
    expect($repo->inbound['acc|s1']['kind'])->toBe('other')->and($repo->inbound['acc|r1']['kind'])->toBe('reply')->and($repo->inbound['acc|a1']['kind'])->toBe('auto');
    $again = $inbox->poll('T', 'acc');                                                                          // the same mails come back
    expect($again['replied'])->toBe(0)->and($again['auto'])->toBe(0)->and($again['other'])->toBe(0);
    expect($repo->messages[$ids[0]]['status'])->toBe('replied');
});

it('finds a reply that comes from a colleague by its thread', function () {
    [$inbox, $repo, $reader, $ids] = inboxWorld();
    $reader->messages = [msg(['id' => 'r2', 'from' => 'cto@alo.test', 'thread_id' => 'thread-boss@alo.test'])];
    expect($inbox->poll('T', 'acc')['replied'])->toBe(1)->and($repo->messages[$ids[0]]['status'])->toBe('replied');
});

it('turns a failure notice into a bounce of the right mail', function () {
    [$inbox, $repo, $reader, $ids] = inboxWorld();
    $reader->messages = [msg(['id' => 'b1', 'from' => 'mailer-daemon@googlemail.com', 'subject' => 'Delivery Status Notification (Failure)', 'text' => "Your message wasn't delivered to other@beta.test because the address couldn't be found."])];
    expect($inbox->poll('T', 'acc')['bounced'])->toBe(1);
    expect($repo->messages[$ids[1]]['status'])->toBe('bounced')->and($repo->messages[$ids[0]]['status'])->toBe('sent')->and($repo->suppressed['other@beta.test'])->toBe('bounced');
});

it('does not guess when a notice names nobody we wrote to', function () {
    [$inbox, $repo, $reader, $ids] = inboxWorld();
    $reader->messages = [msg(['id' => 'b2', 'from' => 'mailer-daemon@x.test', 'subject' => 'Undeliverable', 'text' => 'no address here']), msg(['id' => 'b3', 'from' => 'mailer-daemon@x.test', 'subject' => 'Undeliverable', 'text' => 'delivery to unknown@else.test failed. recipient unknown@else.test'])];
    $r = $inbox->poll('T', 'acc');
    expect($r['bounced'])->toBe(0)->and($r['other'])->toBe(2);
    foreach ($ids as $id) {
        expect($repo->messages[$id]['status'])->toBe('sent');
    }
});

it('does nothing when no mail is waiting, and stops cleanly on a read failure', function () {
    [$inbox, $repo, $reader, $ids] = inboxWorld();
    $reader->throw = 'inbox_scope';
    expect($inbox->poll('T', 'acc')['stopped'])->toBe('inbox_scope')->and($repo->errors['acc'])->toBe('inbox_scope')->and(array_key_exists('acc', $repo->checked))->toBeFalse();
    $reader->throw = 'inbox_unavailable';
    $repo->errors = [];
    expect($inbox->poll('T', 'acc')['stopped'])->toBe('inbox_unavailable')->and(array_key_exists('acc', $repo->errors))->toBeFalse();
    foreach ($ids as $id) {
        $repo->messages[$id]['status'] = 'replied';
    }
    $reader->throw = null;
    $calls = count($reader->calls);
    expect($inbox->poll('T', 'acc')['stopped'])->toBe('nothing_pending')->and(count($reader->calls))->toBe($calls);        // no API call at all
    $repo->accounts['acc']['provider'] = 'smtp';
    expect($inbox->poll('T', 'acc')['stopped'])->toBe('no_inbox');
});

it('reads Gmail metadata only, and skips mails it has seen', function () {
    $http = new FakeHttpJson;
    $http->queue = [
        ['status' => 200, 'json' => ['messages' => [['id' => 'g1'], ['id' => 'g2'], ['id' => 'seen']], 'nextPageToken' => 'P2']],
        ['status' => 200, 'json' => ['messages' => [['id' => 'g3']]]],
        ['status' => 200, 'json' => ['threadId' => 'T1', 'internalDate' => '1793595600000', 'snippet' => 'Your message wasn&#39;t delivered to x@y.test', 'payload' => ['headers' => [['name' => 'From', 'value' => 'Mail Delivery Subsystem <mailer-daemon@googlemail.com>'], ['name' => 'Subject', 'value' => 'Delivery Status Notification (Failure)'], ['name' => 'In-Reply-To', 'value' => '<a@b>']]]]],
        ['status' => 404, 'json' => []],
        ['status' => 200, 'json' => ['threadId' => 'T3', 'internalDate' => '1793595700000', 'snippet' => 'ok', 'payload' => ['headers' => [['name' => 'From', 'value' => 'boss@alo.test'], ['name' => 'Subject', 'value' => 'Re: x']]]]],
    ];
    $out = (new GmailInbox($http))->fetch(['access_token' => 'AT'], '2026-11-02T00:00:00+00:00', fn ($id) => $id === 'seen');
    expect(count($out))->toBe(2)->and($out[0]['from'])->toBe('mailer-daemon@googlemail.com')->and($out[0]['text'])->toBe("Your message wasn't delivered to x@y.test")->and($out[0]['thread_id'])->toBe('T1')->and($out[0]['in_reply_to'])->toBe('<a@b>')->and($out[1]['from'])->toBe('boss@alo.test');
    expect($http->calls[0]['url'])->toContain('q=in%3Ainbox%20after%3A')->and($http->calls[2]['url'])->toContain('format=metadata')->and($http->calls[2]['headers']['Authorization'])->toBe('Bearer AT');
    expect(str_contains($http->calls[2]['url'], 'format=full'))->toBeFalse();
    expect($out[0]['received_at'])->toBe('2026-11-02T05:00:00+00:00');
});

it('maps Gmail and Graph read errors', function () {
    foreach ([[401, 'oauth_revoked'], [403, 'inbox_scope'], [500, 'inbox_unavailable']] as [$status, $code]) {
        foreach ([new GmailInbox($h1 = new FakeHttpJson), new GraphInbox($h2 = new FakeHttpJson)] as $reader) {
            $h1->queue = $h2->queue = [['status' => $status, 'json' => []]];
            $got = null;
            try {
                $reader->fetch(['access_token' => 'x'], '2026-11-02T00:00:00Z', fn () => false);
            } catch (InboxFailure $e) {
                $got = $e->getMessage();
            }
            expect($got)->toBe($code);
        }
    }
});

it('reads the Graph inbox in one call and never sends the token to another host', function () {
    $http = new FakeHttpJson;
    $http->queue = [
        ['status' => 200, 'json' => ['value' => [
            ['id' => 'x1', 'subject' => 'RE: आपका', 'from' => ['emailAddress' => ['address' => 'Boss@Alo.test']], 'conversationId' => 'C1', 'receivedDateTime' => '2026-11-03T05:00:00Z', 'bodyPreview' => 'hello', 'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => '<id@agency.test>'], ['name' => 'Auto-Submitted', 'value' => 'auto-replied']]],
            ['id' => 'old', 'subject' => 's', 'from' => ['emailAddress' => ['address' => 'z@z.test']]],
        ], '@odata.nextLink' => 'https://evil.test/steal?x=1']],
    ];
    $out = (new GraphInbox($http))->fetch(['access_token' => 'AT'], '2026-11-02T00:00:00Z', fn ($id) => $id === 'old');
    expect(count($out))->toBe(1)->and($out[0]['from'])->toBe('boss@alo.test')->and($out[0]['in_reply_to'])->toBe('<id@agency.test>')->and($out[0]['auto_submitted'])->toBe('auto-replied')->and($out[0]['thread_id'])->toBe('C1');
    expect(count($http->calls))->toBe(1)->and($http->calls[0]['url'])->toStartWith('https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages?')->and($http->calls[0]['url'])->toContain('receivedDateTime%20ge%202026-11-02T00%3A00%3A00Z');
});
