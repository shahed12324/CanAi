<?php

use App\Outreach\Compose;
use App\Outreach\ImapConnection;
use App\Outreach\ImapInbox;
use App\Outreach\InboxFailure;
use App\Outreach\Mime;
use App\Outreach\OutreachInbox;
use App\Outreach\OutreachService;
use App\Outreach\TransportFailure;
use App\Outreach\WhatsAppLink;
use Tests\Support\FakeCrmStore;
use Tests\Support\FakeLeadStore;
use Tests\Support\FakeMailTransport;
use Tests\Support\FakeOutreachRepo;

// ------------------------------------------------------------------ WhatsApp link
it('turns local and international numbers into one wa.me number', function () {
    foreach (['01712345678', '+880 1712-345678', '8801712345678', '00 880 1712 345678', ' (+88) 01712345678 '] as $p) {
        expect(WhatsAppLink::number($p))->toBe('8801712345678');
    }
    expect(WhatsAppLink::number('+1 415 555 2671'))->toBe('14155552671');
    expect(WhatsAppLink::number('1415555267'))->toBe('1415555267')->and(WhatsAppLink::number('141555526'))->toBeNull();      // 10 digits is the shortest
    expect(WhatsAppLink::number('123456789012345'))->toBe('123456789012345')->and(WhatsAppLink::number('1234567890123456'))->toBeNull();   // 15 the longest
    foreach ([null, '', 'abc', '12345', '0171234567', '017123456789', '01212345678', '8801212345678', '+880 171234567', '0000000000'] as $bad) {
        expect(WhatsAppLink::number($bad))->toBeNull();
    }
});

it('builds the link with an encoded, shortened greeting', function () {
    expect(WhatsAppLink::url('01712345678'))->toBe('https://wa.me/8801712345678');
    $u = WhatsAppLink::url('01712345678', "হ্যালো  \n আলো");
    expect($u)->toBe('https://wa.me/8801712345678?text='.rawurlencode('হ্যালো আলো'));
    expect(strlen((string) parse_url((string) WhatsAppLink::url('01712345678', str_repeat('ক', 500)), PHP_URL_QUERY)))->toBe(strlen('text=') + strlen(rawurlencode(str_repeat('ক', 200))));
    expect(WhatsAppLink::url('nope', 'x'))->toBeNull();
    expect(WhatsAppLink::url(null))->toBeNull();
});

it('puts the WhatsApp line before the footer, and the footer stays', function () {
    $with = Compose::finalBody('হ্যালো', 'https://app.test/u/x', 'Agency', 'Dhaka', 'https://wa.me/8801712345678');
    expect(str_contains($with, 'https://wa.me/8801712345678'))->toBeTrue();
    expect(strpos($with, 'wa.me') < strpos($with, 'https://app.test/u/x'))->toBeTrue();
    $without = Compose::finalBody('হ্যালো', 'https://app.test/u/x', 'Agency', 'Dhaka');
    expect(str_contains($without, 'wa.me'))->toBeFalse()->and(str_contains($without, 'WhatsApp'))->toBeFalse()->and(str_contains($without, 'https://app.test/u/x'))->toBeTrue();
    expect($without)->toBe("হ্যালো\n".Compose::footer('https://app.test/u/x', 'Agency', 'Dhaka'));
});

// ------------------------------------------------------------------ MIME with attachments
function oxmail(array $o = []): array
{
    return $o + ['from_email' => 'a@b.co', 'from_name' => 'আমি', 'to' => 'x@y.co', 'subject' => 'hi', 'body' => "সালাম\nbody", 'headers' => []];
}

it('writes a multipart mail with the text first and the file base64-encoded', function () {
    $raw = Mime::message(oxmail(['attachments' => [['name' => 'প্রস্তাব.pdf', 'mime' => 'application/pdf', 'bytes' => "%PDF-1.4\n\x00\xff"]]]), 'id@a', 'Mon, 1 Jan 2026 00:00:00 +0000', 'BND');
    expect($raw)->toContain('Content-Type: multipart/mixed; boundary="BND"');
    expect(substr_count($raw, '--BND'))->toBe(3);
    expect(str_ends_with($raw, "--BND--\r\n"))->toBeTrue();
    expect(strpos($raw, 'text/plain') < strpos($raw, 'application/pdf'))->toBeTrue();
    expect($raw)->toContain(chunk_split(base64_encode("%PDF-1.4\n\x00\xff"), 76, "\r\n"));
    expect($raw)->toContain("filename*=UTF-8''".rawurlencode('প্রস্তাব.pdf'));
    expect(preg_match('/Content-Disposition: attachment/', $raw))->toBe(1);
    expect(str_contains($raw, "\n\n"))->toBeFalse();                                              // CRLF only
});

it('keeps a plain mail plain when there is no attachment', function () {
    foreach ([oxmail(), oxmail(['attachments' => []])] as $m) {
        $raw = Mime::message($m, 'id@a', 'Mon, 1 Jan 2026 00:00:00 +0000');
        expect($raw)->toContain('Content-Type: text/plain; charset=UTF-8')->and(str_contains($raw, 'multipart'))->toBeFalse();
    }
});

it('cleans the file name and the type, and refuses too much', function () {
    $raw = Mime::message(oxmail(['attachments' => [['name' => "a\"b/c\\d\r\nX-Evil: 1.pdf", 'mime' => "text/html\r\nX-Evil: 2", 'bytes' => 'x']]]), 'id@a', 'd', 'B');
    expect($raw)->not->toContain("\r\nX-Evil")->and($raw)->toContain('application/octet-stream');
    expect($raw)->toContain("filename*=UTF-8''a_b_c_d%20X-Evil%3A%201.pdf");
    $big = ['name' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => str_repeat('a', Mime::MAX_ATTACHMENT_BYTES + 1)];
    expect(fn () => Mime::message(oxmail(['attachments' => [$big]]), 'i@a', 'd'))->toThrow(InvalidArgumentException::class);
    $ok = ['name' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => str_repeat('a', 1000)];
    expect(fn () => Mime::message(oxmail(['attachments' => [$ok, $ok, $ok, $ok]]), 'i@a', 'd'))->toThrow(InvalidArgumentException::class);
    expect(strlen(Mime::message(oxmail(['attachments' => [$ok, $ok, $ok]]), 'i@a', 'd')) > 3000)->toBeTrue();
    $edge = ['name' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => str_repeat('a', Mime::MAX_ATTACHMENT_BYTES)];
    expect(strlen(Mime::message(oxmail(['attachments' => [$edge]]), 'i@a', 'd')) > Mime::MAX_ATTACHMENT_BYTES)->toBeTrue();
});

it('names the file sensibly when the name is empty or very long', function () {
    $raw = Mime::message(oxmail(['attachments' => [['name' => " \r\n ", 'mime' => 'application/pdf', 'bytes' => 'x']]]), 'i@a', 'd', 'B');
    expect($raw)->toContain("filename*=UTF-8''attachment\r\n");
    $raw = Mime::message(oxmail(['attachments' => [['name' => str_repeat('a', 300).'.pdf', 'mime' => 'application/pdf', 'bytes' => 'x']]]), 'i@a', 'd', 'B');
    expect($raw)->toContain("filename*=UTF-8''".str_repeat('a', 120)."\r\n");
});

// ------------------------------------------------------------------ service: WhatsApp + proposal attachment
function oxworld(array $camp = [], ?Closure $attach = null): array
{
    $repo = new FakeOutreachRepo;
    $repo->accounts['acc'] = ['id' => 'acc', 'provider' => 'smtp', 'refresh_ct' => null, 'from_email' => 'me@agency.test', 'from_name' => 'Agency', 'host' => 'smtp.agency.test', 'port' => 465, 'security' => 'tls', 'username' => 'me', 'secret_ct' => 'CT', 'key_version' => 1, 'daily_limit' => 20, 'warmup_started_on' => '2026-10-01', 'status' => 'active'];
    $repo->campaigns['camp'] = $camp + ['id' => 'camp', 'account_id' => 'acc', 'subject_tpl' => 's', 'body_tpl' => 'b', 'followup_after_days' => 3, 'followup_body_tpl' => 'again', 'status' => 'active', 'whatsapp_button' => false, 'attach_proposal' => false];
    foreach ([1, 2] as $i) {
        $repo->leads["lead$i"] = ['lead_id' => "lead$i", 'contact_id' => "ct$i", 'email' => "p$i@c$i.test", 'email_status' => 'mx_ok', 'name' => "K$i", 'domain' => "c$i.test", 'analysis' => ['gaps' => []]];
    }
    $tr = new FakeMailTransport;
    $svc = new OutreachService($repo, new FakeCrmStore(new FakeLeadStore), $tr, fn () => 'pw', fn ($a) => 'x', fn ($t) => "https://app.test/u/$t", fn () => '2026-11-02', fn () => null, $attach);

    return [$svc, $repo, $tr];
}

function oxrun(OutreachService $svc, FakeOutreachRepo $repo, array $leads = ['lead1']): array
{
    $q = $svc->queue('T', 'camp', $leads);
    $svc->approve(array_keys($repo->messages), 'U');

    return [$q, $svc->sendFor('acc')];
}

it('adds the WhatsApp link only when the campaign asks and the brand has a real phone', function () {
    foreach ([[true, '01712345678', true], [false, '01712345678', false], [true, null, false], [true, 'garbage', false]] as [$flag, $phone, $expect]) {
        [$svc, $repo, $tr] = oxworld(['whatsapp_button' => $flag]);
        $repo->sender['phone'] = $phone;
        [, $r] = oxrun($svc, $repo);
        expect($r['sent'])->toBe(1);                                                    // a missing phone never blocks the mail
        $body = $tr->sent[0]['mail']['body'];
        expect(str_contains($body, 'https://wa.me/8801712345678?text='))->toBe($expect);
        expect(str_contains($body, 'https://app.test/u/'))->toBeTrue();                 // unsubscribe link always there
    }
});

it('pins the lead\'s newest proposal when queueing and skips leads that have none', function () {
    [$svc, $repo] = oxworld(['attach_proposal' => true]);
    $repo->proposals['lead1'] = ['id' => 'prop1', 'stamp' => '2026-11-01 10:00:00+00'];
    $q = $svc->queue('T', 'camp', ['lead1', 'lead2']);
    expect($q)->toBe(['queued' => 1, 'no_email' => 0, 'suppressed' => 0, 'duplicate' => 0, 'no_proposal' => 1]);
    $m = array_values($repo->messages)[0];
    expect([$m['lead_id'], $m['proposal_id'], $m['proposal_stamp']])->toBe(['lead1', 'prop1', '2026-11-01 10:00:00+00']);
    [$svc2, $repo2] = oxworld();                                                         // flag off: no proposal pinned, nothing skipped
    $repo2->proposals['lead1'] = ['id' => 'prop1', 'stamp' => 's'];
    expect($svc2->queue('T', 'camp', ['lead1', 'lead2'])['queued'])->toBe(2);
    expect(array_values($repo2->messages)[0]['proposal_id'])->toBeNull();
});

it('sends the PDF of the proposal that was approved', function () {
    $calls = [];
    [$svc, $repo, $tr] = oxworld(['attach_proposal' => true], function (array $m) use (&$calls) {
        $calls[] = $m;

        return ['name' => 'proposal.pdf', 'mime' => 'application/pdf', 'bytes' => '%PDF-x'];
    });
    $repo->proposals['lead1'] = ['id' => 'prop1', 'stamp' => 'S1'];
    $repo->proposalNow['prop1'] = 'S1';
    [, $r] = oxrun($svc, $repo);
    expect($r['sent'])->toBe(1)->and($tr->sent[0]['mail']['attachments'])->toBe([['name' => 'proposal.pdf', 'mime' => 'application/pdf', 'bytes' => '%PDF-x']]);
    expect($calls)->toHaveCount(1)->and($calls[0]['proposal_id'])->toBe('prop1')->and($calls[0]['approved_by'])->toBe('U');
});

it('refuses to send when the proposal changed or was deleted after approval', function () {
    foreach (['S2', null] as $now) {
        $called = 0;
        [$svc, $repo, $tr] = oxworld(['attach_proposal' => true], function () use (&$called) {
            $called++;

            return ['name' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => 'x'];
        });
        $repo->proposals['lead1'] = ['id' => 'prop1', 'stamp' => 'S1'];
        if ($now !== null) {
            $repo->proposalNow['prop1'] = $now;
        }
        [, $r] = oxrun($svc, $repo);
        expect($r['sent'])->toBe(0)->and($r['failed'])->toBe(1)->and($tr->sent)->toBe([])->and($called)->toBe(0);
        $m = array_values($repo->messages)[0];
        expect($m['status'])->toBe('failed')->and($m['error_code'])->toBe('proposal_changed');
    }
});

it('treats a pinned proposal without a version stamp as changed', function () {
    [$svc, $repo, $tr] = oxworld(['attach_proposal' => true], fn () => ['name' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => 'x']);
    $repo->messages['m'] = ['id' => 'm', 'tenant_id' => 'T', 'campaign_id' => 'camp', 'lead_id' => 'lead1', 'contact_id' => null, 'to_email' => 'z@z.test', 'step' => 1, 'subject' => 's', 'body' => 'b', 'status' => 'approved', 'proposal_id' => 'prop1', 'proposal_stamp' => null, 'approved_by' => 'U', 'attempts' => 0, 'unsub_token' => 'tok', 'error_code' => null, 'sent_day' => null, 'sent_age_days' => null];
    $repo->proposalNow['prop1'] = '';
    $r = $svc->sendFor('acc');
    expect($r['failed'])->toBe(1)->and($tr->sent)->toBe([])->and($repo->messages['m']['error_code'])->toBe('proposal_changed');
});

it('waits and retries when the PDF cannot be made, without blaming the account', function () {
    foreach ([fn () => throw new TransportFailure('proposal_unavailable', false), null] as $attach) {
        [$svc, $repo, $tr] = oxworld(['attach_proposal' => true], $attach);
        $repo->proposals['lead1'] = ['id' => 'prop1', 'stamp' => 'S1'];
        $repo->proposalNow['prop1'] = 'S1';
        [, $r] = oxrun($svc, $repo);
        expect($r['sent'])->toBe(0)->and($r['deferred'])->toBe(1)->and($r['stopped'])->toBeNull()->and($tr->sent)->toBe([]);
        $m = array_values($repo->messages)[0];
        expect($m['status'])->toBe('approved')->and($m['error_code'])->toBe('proposal_unavailable')->and($m['attempts'])->toBe(0);
        expect($repo->errors)->toBe([]);
    }
});

it('does not carry or look for a proposal in plain mails and follow-ups', function () {
    [$svc, $repo, $tr] = oxworld(['attach_proposal' => true], fn () => throw new RuntimeException('must not be called'));
    $repo->proposals['lead1'] = ['id' => 'prop1', 'stamp' => 'S1'];
    $repo->proposalNow['prop1'] = 'S1';
    $repo->messages['plain'] = ['id' => 'plain', 'tenant_id' => 'T', 'campaign_id' => 'camp', 'lead_id' => null, 'contact_id' => null, 'to_email' => 'z@z.test', 'step' => 1, 'subject' => 's', 'body' => 'b', 'status' => 'approved', 'proposal_id' => null, 'proposal_stamp' => null, 'approved_by' => 'U', 'attempts' => 0, 'unsub_token' => 'tok', 'error_code' => null, 'sent_day' => null, 'sent_age_days' => null];
    $r = $svc->sendFor('acc');
    expect($r['sent'])->toBe(1)->and(isset($tr->sent[0]['mail']['attachments']))->toBeFalse();
    $repo->messages['plain']['sent_age_days'] = 5;
    expect($svc->queueFollowups('T'))->toBe(1);
    $follow = array_values(array_filter($repo->messages, fn ($m) => $m['step'] === 2))[0];
    expect($follow['proposal_id'])->toBeNull();
});

// ------------------------------------------------------------------ IMAP reader
final class OxScriptedImap implements ImapConnection
{
    public string $buf = '';

    /** @var list<string> */
    public array $written = [];

    public array $events = [];

    public bool $closed = false;

    public ?string $openFails = null;

    /** @param array<string, string> $replies verb (first words, upper-case) => reply body; %T% is the tag */
    public function __construct(public array $replies, public string $greeting = "* OK IMAP ready\r\n") {}

    public function open(string $host, int $port, bool $implicitTls): void
    {
        $this->events[] = "open $host:$port ".($implicitTls ? 'tls' : 'plain');
        if ($this->openFails !== null) {
            throw new InboxFailure($this->openFails);
        }
        $this->buf = $this->greeting;
    }

    public function startTls(): void
    {
        $this->events[] = 'starttls';
    }

    public function line(): ?string
    {
        if ($this->buf === '') {
            return null;
        }
        $p = strpos($this->buf, "\r\n");
        $l = $p === false ? $this->buf : substr($this->buf, 0, $p);
        $this->buf = $p === false ? '' : substr($this->buf, $p + 2);

        return $l;
    }

    public function read(int $n): string
    {
        $o = substr($this->buf, 0, $n);
        $this->buf = (string) substr($this->buf, $n);

        return $o;
    }

    public function write(string $data): void
    {
        $this->written[] = $data;
        $this->events[] = 'write '.trim($data);
        if (preg_match('/\A(\S+) (\S+)( (\S+))?/', $data, $m) !== 1) {
            return;
        }
        $verb = strtoupper($m[2]).(strtoupper($m[2]) === 'UID' ? ' '.strtoupper($m[4] ?? '') : '');
        if (isset($this->replies[$verb])) {
            $this->buf .= str_replace('%T%', $m[1], $this->replies[$verb]);
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

function oxfetch(int $uid, string $headers, string $text = '', string $date = '03-Nov-2026 10:00:00 +0600'): string
{
    return "* $uid FETCH (UID $uid INTERNALDATE \"$date\" BODY[HEADER.FIELDS (FROM SUBJECT)] {".strlen($headers)."}\r\n".$headers." BODY[TEXT]<0> {".strlen($text)."}\r\n".$text.")\r\n";
}

function oximap(array $over = [], ?OxScriptedImap &$fake = null): ImapInbox
{
    $fake = new OxScriptedImap($over + [
        'LOGIN' => "%T% OK logged in\r\n",
        'EXAMINE' => "* 3 EXISTS\r\n* OK [UIDVALIDITY 77] ok\r\n%T% OK [READ-ONLY] done\r\n",
        'UID SEARCH' => "* SEARCH 5 6\r\n%T% OK done\r\n",
        'UID FETCH' => "%T% OK done\r\n",
    ]);

    return new ImapInbox(fn () => $fake);
}

$oxconn = ['type' => 'imap', 'host' => 'imap.agency.test', 'port' => 993, 'security' => 'tls', 'username' => 'me@agency.test', 'password' => 'p"w\\1'];

it('reads headers and text read-only and decodes them', function () use ($oxconn) {
    $hdr = "From: =?UTF-8?B?4KaV4KeN4Kay4Ka+4Kav4Ka84KaC?= <Boss@Alo.test>\r\nSubject: =?UTF-8?B?UmU6IOCmhuCmquCmqOCmvuCmsA==?=\r\n";
    $imap = oximap(['UID FETCH' => '%FETCH%'], $f);
    $f->replies['UID FETCH'] = oxfetch(5, "From: Boss <Boss@Alo.test>\r\nSubject: =?UTF-8?B?UmU6IOCmhuCmquCmqOCmvuCmsA==?=\r\nIn-Reply-To: <id1@agency.test>\r\nMessage-ID: <m5@alo.test>\r\nContent-Transfer-Encoding: base64\r\n\r\n", base64_encode('OK')).'%T% OK done'."\r\n";
    $out = $imap->fetch($oxconn, '2026-11-03T04:00:00+00:00', fn () => false);
    expect($out)->toHaveCount(2);
    $m = $out[0];
    expect($m['id'])->toBe('77-5')->and($m['from'])->toBe('boss@alo.test')->and($m['subject'])->toBe('Re: আপনার')->and($m['in_reply_to'])->toBe('<id1@agency.test>');
    expect($m['text'])->toBe('OK')->and($m['received_at'])->toBe('2026-11-03T04:00:00+00:00')->and($m['thread_id'])->toBeNull();
    $all = implode('', $f->written);
    expect($all)->toContain('LOGIN "me@agency.test" "p\\"w\\\\1"')->and($all)->toContain('EXAMINE INBOX')->and($all)->toContain('BODY.PEEK[HEADER.FIELDS')->and($all)->toContain('BODY.PEEK[TEXT]<0.2000>');
    foreach (['SELECT', 'STORE', 'EXPUNGE', 'DELETE', 'COPY', 'MOVE', 'APPEND', ' BODY[TEXT'] as $never) {
        expect(str_contains($all, $never))->toBeFalse();
    }
    expect($f->closed)->toBeTrue()->and($f->events[0])->toBe('open imap.agency.test:993 tls');
});

it('searches from a day before the given time, in English month names', function () use ($oxconn) {
    $imap = oximap([], $f);
    $imap->fetch($oxconn, '2026-11-03T10:00:00+00:00', fn () => true);
    expect(implode('', $f->written))->toContain('UID SEARCH SINCE 2-Nov-2026');
    $imap = oximap([], $f);
    $imap->fetch($oxconn, '2027-01-01T00:30:00+00:00', fn () => true);
    expect(implode('', $f->written))->toContain('SINCE 31-Dec-2026');
});

it('skips mails already handled and fetches only the newest sixty', function () use ($oxconn) {
    $imap = oximap(['UID SEARCH' => '* SEARCH '.implode(' ', range(1, 100))."\r\n%T% OK done\r\n"], $f);
    $imap->fetch($oxconn, '2026-11-03T00:00:00+00:00', fn (string $id) => $id === '77-100');
    $fetches = array_values(array_filter($f->written, fn ($w) => str_contains($w, 'UID FETCH')));
    expect($fetches)->toHaveCount(59);
    expect(str_contains(implode('', $fetches), 'UID FETCH 100 '))->toBeFalse()->and(str_contains(implode('', $fetches), 'UID FETCH 41 '))->toBeTrue()->and(str_contains(implode('', $fetches), 'UID FETCH 40 '))->toBeFalse();
});

it('skips a mail that vanished between search and fetch', function () use ($oxconn) {
    $imap = oximap([], $f);                                                  // FETCH answers only "OK"
    expect($imap->fetch($oxconn, '2026-11-03T00:00:00+00:00', fn () => false))->toBe([]);
});

it('reports a wrong login and never sends a password with line breaks', function () use ($oxconn) {
    $imap = oximap(['LOGIN' => "%T% NO [AUTHENTICATIONFAILED] bad\r\n"], $f);
    expect(fn () => $imap->fetch($oxconn, '2026-11-03T00:00:00+00:00', fn () => false))->toThrow(InboxFailure::class, 'imap_auth');
    expect($f->closed)->toBeTrue()->and(str_contains(implode('', $f->written), 'EXAMINE'))->toBeFalse();
    foreach (["x\r\nA9 DELETE", "x\ny", "x\0y", 'pässword', '', str_repeat('a', 501)] as $pw) {
        $imap = oximap([], $f);
        expect(fn () => $imap->fetch(['password' => $pw] + $oxconn, '2026-11-03T00:00:00+00:00', fn () => false))->toThrow(InboxFailure::class, 'imap_auth');
        expect($f->events)->toBe([]);                                       // not even connected
    }
    $imap = oximap([], $f);
    expect(fn () => $imap->fetch(['username' => "a\r\nb"] + $oxconn, 's', fn () => false))->toThrow(InboxFailure::class, 'imap_auth');
    expect(fn () => $imap->fetch(['host' => ''] + $oxconn, 's', fn () => false))->toThrow(InboxFailure::class, 'imap_auth');
});

it('upgrades to TLS before the password is sent when the server uses STARTTLS', function () use ($oxconn) {
    $imap = oximap(['STARTTLS' => "%T% OK go ahead\r\n"], $f);
    $imap->fetch(['security' => 'starttls', 'port' => 143] + $oxconn, '2026-11-03T00:00:00+00:00', fn () => true);
    $ev = $f->events;
    expect($ev[0])->toBe('open imap.agency.test:143 plain');
    $iTls = array_search('starttls', $ev, true);
    $iLogin = array_key_first(array_filter($ev, fn ($e) => str_contains($e, 'LOGIN')));
    expect($iTls)->not->toBeFalse()->and($iTls < $iLogin)->toBeTrue();
    $imap = oximap(['STARTTLS' => "%T% NO nope\r\n"], $f);
    expect(fn () => $imap->fetch(['security' => 'starttls'] + $oxconn, 's', fn () => true))->toThrow(InboxFailure::class, 'inbox_unavailable');
    expect(str_contains(implode('', $f->written), 'LOGIN'))->toBeFalse();
});

it('fails cleanly on a dead server, a bad greeting, a cut stream and a huge literal', function () use ($oxconn) {
    $imap = oximap([], $f);
    $f->openFails = 'inbox_unavailable';
    expect(fn () => $imap->fetch($oxconn, 's', fn () => false))->toThrow(InboxFailure::class, 'inbox_unavailable');
    expect($f->closed)->toBeTrue();
    $imap = oximap([], $f);
    $f->greeting = "* BYE go away\r\n";
    expect(fn () => $imap->fetch($oxconn, 's', fn () => false))->toThrow(InboxFailure::class, 'inbox_unavailable');
    $imap = oximap(['LOGIN' => ''], $f);                                  // server goes silent
    expect(fn () => $imap->fetch($oxconn, 's', fn () => false))->toThrow(InboxFailure::class, 'inbox_unavailable');
    $imap = oximap(['UID FETCH' => "* 5 FETCH (BODY[HEADER.FIELDS (FROM)] {1000001}\r\n".str_repeat('a', 1_000_001)." BODY[TEXT]<0> {0}\r\n)\r\n%T% OK done\r\n"], $f);
    expect(fn () => $imap->fetch($oxconn, '2026-11-03T00:00:00+00:00', fn () => false))->toThrow(InboxFailure::class, 'inbox_unavailable');
    $imap = oximap(['UID FETCH' => "* 5 FETCH (BODY[HEADER.FIELDS (FROM)] {1000000}\r\n".str_repeat('a', 1_000_000)." BODY[TEXT]<0> {0}\r\n)\r\n%T% OK done\r\n"], $f);
    expect($imap->fetch($oxconn, '2026-11-03T00:00:00+00:00', fn () => false))->toHaveCount(2);              // exactly the limit is fine
    $imap = oximap(['EXAMINE' => "%T% NO no such mailbox\r\n"], $f);
    expect(fn () => $imap->fetch($oxconn, 's', fn () => false))->toThrow(InboxFailure::class, 'inbox_unavailable');
});

it('unfolds headers and keeps the first of a repeated one', function () {
    $h = ImapInbox::headers("Subject: a\r\n  b\r\n\tc\r\nFrom: x@y.test\r\nSubject: second\r\n");
    expect($h['subject'])->toBe('a b c')->and($h['from'])->toBe('x@y.test');
});

// ------------------------------------------------------------------ OutreachInbox with an IMAP account
function oximapworld(array $acc = []): array
{
    $repo = new FakeOutreachRepo;
    $repo->accounts['acc'] = $acc + ['id' => 'acc', 'provider' => 'smtp', 'from_email' => 'me@agency.test', 'from_name' => null, 'host' => 'smtp.agency.test', 'port' => 465, 'security' => 'tls', 'username' => 'me@agency.test', 'secret_ct' => 'CT', 'refresh_ct' => null,
        'imap_host' => 'imap.agency.test', 'imap_port' => 993, 'imap_security' => 'tls', 'key_version' => 1, 'daily_limit' => 20, 'warmup_started_on' => '2026-10-01', 'status' => 'active'];
    $repo->campaigns['camp'] = ['id' => 'camp', 'account_id' => 'acc', 'subject_tpl' => 's', 'body_tpl' => 'b', 'followup_after_days' => null, 'followup_body_tpl' => null, 'status' => 'active'];
    $id = $repo->enqueue('T', 'camp', null, null, 'boss@alo.test', 1, 's', 'b');
    $repo->approve($id, 'U');
    $repo->claim('acc', 10);
    $repo->markSent($id, 'id1@agency.test', null, null);
    $reader = new class implements \App\Outreach\InboxReader
    {
        public array $calls = [];

        public ?string $throw = null;

        public array $messages = [];

        public function fetch(array $conn, string $sinceIso, callable $isSeen): array
        {
            $this->calls[] = $conn;
            if ($this->throw) {
                throw new InboxFailure($this->throw);
            }

            return $this->messages;
        }
    };
    $inbox = new OutreachInbox($repo, ['imap' => $reader], fn () => 'AT', fn () => 2_000_000, fn ($c, $v) => "PW($c,$v)");

    return [$inbox, $repo, $reader, $id];
}

it('polls an SMTP mailbox over IMAP with its decrypted login and finds the reply by In-Reply-To', function () {
    [$inbox, $repo, $reader, $id] = oximapworld();
    $reader->messages = [['id' => '77-5', 'from' => 'boss@alo.test', 'subject' => 'Re: x', 'thread_id' => null, 'in_reply_to' => '<id1@agency.test>', 'content_type' => 'text/plain', 'auto_submitted' => null, 'precedence' => null, 'x_auto_response_suppress' => null, 'x_failed_recipients' => null, 'text' => 'ok', 'received_at' => '2026-11-03T05:00:00+00:00']];
    $r = $inbox->poll('T', 'acc');
    expect($r['replied'])->toBe(1)->and($repo->messages[$id]['status'])->toBe('replied');
    expect($reader->calls[0])->toBe(['type' => 'imap', 'host' => 'imap.agency.test', 'port' => 993, 'security' => 'tls', 'username' => 'me@agency.test', 'password' => 'PW(CT,1)']);
});

it('does not poll an SMTP mailbox that has no IMAP settings, and flags a wrong IMAP login', function () {
    [$inbox, $repo, $reader] = oximapworld(['imap_host' => null, 'imap_port' => null, 'imap_security' => null]);
    expect($inbox->poll('T', 'acc')['stopped'])->toBe('no_inbox')->and($reader->calls)->toBe([]);
    [$inbox, $repo, $reader] = oximapworld();
    $reader->throw = 'imap_auth';
    expect($inbox->poll('T', 'acc')['stopped'])->toBe('imap_auth')->and($repo->errors['acc'])->toBe('imap_auth');
    [$inbox, $repo, $reader] = oximapworld();
    $reader->throw = 'inbox_unavailable';
    expect($inbox->poll('T', 'acc')['stopped'])->toBe('inbox_unavailable')->and($repo->errors)->toBe([]);
    $noDecrypt = new OutreachInbox($repo, ['imap' => $reader], fn () => 'AT', fn () => 1);                    // no password decryptor wired: never guess
    expect($noDecrypt->poll('T', 'acc')['stopped'])->toBe('no_inbox');
});

it('wires the new settings into the forms, controllers, scheduler and provider', function () {
    $root = __DIR__.'/../../';
    $acc = (string) file_get_contents($root.'app/Http/Controllers/EmailAccountController.php');
    expect($acc)->toContain('imap_host, imap_port, imap_security')->and($acc)->toContain("'required_with:imap_host'")->and(substr_count($acc, 'HostGuard::allowed'))->toBe(2);
    $camp = (string) file_get_contents($root.'app/Http/Controllers/OutreachController.php');
    expect($camp)->toContain('whatsapp_button, attach_proposal')->and($camp)->toContain("boolean('attach_proposal')");
    expect((string) file_get_contents($root.'resources/views/outreach/campaigns.blade.php'))->toContain('name="whatsapp_button"')->toContain('name="attach_proposal"');
    expect((string) file_get_contents($root.'resources/views/outreach/accounts.blade.php'))->toContain('name="imap_host"')->toContain('name="imap_security"');
    expect((string) file_get_contents($root.'app/Console/Commands/OutreachRunCommand.php'))->toContain("a.provider <> 'smtp' or a.imap_host is not null");
    $prov = (string) file_get_contents($root.'app/Providers/OutreachServiceProvider.php');
    expect($prov)->toContain("'imap' => new ImapInbox")->and($prov)->toContain("render(\$m['tenant_id'], \$m['approved_by'], \$m['proposal_id'], 'pdf'");
    $sql = (string) file_get_contents($root.'database/sql/phase28e.up.sql');
    expect($sql)->toContain('outreach_proposal_guard')->and($sql)->toContain('imap_security IN');
});
