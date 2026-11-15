<?php

use App\Outreach\Compose;
use App\Outreach\DnsAuth;
use App\Outreach\DnsResolver;
use App\Outreach\HostGuard;
use App\Outreach\OutreachService;
use App\Outreach\Warmup;
use Tests\Support\FakeCrmStore;
use Tests\Support\FakeLeadStore;
use Tests\Support\FakeMailTransport;
use Tests\Support\FakeOutreachRepo;

const OT = 'TENANT1000000000000000000A';
const OU = 'USER10000000000000000000AA';

function world(int $limit = 20, string $started = '2026-10-01'): array
{
    $repo = new FakeOutreachRepo;
    $repo->accounts['acc'] = ['id' => 'acc', 'provider' => 'smtp', 'refresh_ct' => null, 'from_email' => 'me@agency.test', 'from_name' => 'Agency', 'host' => 'smtp.agency.test', 'port' => 465, 'security' => 'tls', 'username' => 'me', 'secret_ct' => 'CT', 'key_version' => 1, 'daily_limit' => $limit, 'warmup_started_on' => $started, 'status' => 'active'];
    $repo->campaigns['camp'] = ['id' => 'camp', 'account_id' => 'acc', 'subject_tpl' => 'আপনার সাইট {{domain}}', 'body_tpl' => "প্রিয় {{client_name}},\n{{gaps_list}}", 'followup_after_days' => 3, 'followup_body_tpl' => 'আবার লিখছি, {{client_name}}', 'status' => 'active'];
    for ($i = 1; $i <= 8; $i++) {
        $repo->leads["lead$i"] = ['lead_id' => "lead$i", 'contact_id' => "ct$i", 'email' => "p$i@c$i.test", 'email_status' => 'mx_ok', 'name' => "কোম্পানি $i", 'domain' => "c$i.test", 'analysis' => ['gaps' => [['code' => 'no_fb_pixel']]]];
    }
    $tr = new FakeMailTransport;
    $crm = new FakeCrmStore(new FakeLeadStore);
    $svc = new OutreachService($repo, $crm, $tr, fn ($ct, $v) => 'secret-pw', fn ($a) => 'ACCESS-'.$a['provider'], fn ($tok) => "https://app.test/u/$tok", fn () => '2026-11-02', fn () => null);

    return [$svc, $repo, $tr, $crm];
}

function approveAll(OutreachService $svc, FakeOutreachRepo $repo): void
{
    $svc->approve(array_keys($repo->messages), OU);
}

it('warms a new address up slowly and never past its own limit', function () {
    expect(Warmup::limitFor(20, '2026-11-02', '2026-11-02'))->toBe(5)
        ->and(Warmup::limitFor(20, '2026-11-01', '2026-11-02'))->toBe(8)
        ->and(Warmup::limitFor(20, '2026-10-01', '2026-11-02'))->toBe(20)
        ->and(Warmup::limitFor(3, '2026-11-02', '2026-11-02'))->toBe(3)
        ->and(Warmup::limitFor(20, '2026-11-09', '2026-11-02'))->toBe(5);       // a start date in the future is not a bonus
});

it('refuses SMTP hosts that point into a private network', function () {
    $dns = fn (string $h) => ['rebind.example.org' => ['10.0.0.5'], 'smtp.good.org' => ['93.184.216.34'], 'mixed.org' => ['93.184.216.34', '192.168.1.9']][$h] ?? [];
    foreach (['smtp.good.org', '93.184.216.34'] as $ok) {
        expect(HostGuard::allowed($ok, $dns))->toBeTrue();
    }
    foreach (['localhost', '127.0.0.1', '10.1.2.3', '192.168.0.1', '169.254.169.254', '::1', '[::1]', 'fd00::1', '::ffff:127.0.0.1', 'db.internal', 'printer.local', 'intranet', 'rebind.example.org', 'mixed.org', 'nonexistent.org', '', 'a b.org'] as $bad) {
        expect(HostGuard::allowed($bad, $dns))->toBeFalse();
    }
});

it('reads SPF, DKIM and DMARC and says what is wrong', function () {
    $mk = fn (array $recs) => new class($recs) implements DnsResolver
    {
        public function __construct(private array $r) {}

        public function txt(string $name): array
        {
            return $this->r[$name] ?? [];
        }
    };
    $good = (new DnsAuth($mk(['a.test' => ['v=spf1 include:_spf.google.com ~all'], 'google._domainkey.a.test' => ['v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3'], '_dmarc.a.test' => ['v=DMARC1; p=reject; rua=mailto:x@a.test']])))->check('A.test', 'google');
    expect($good['spf']['status'])->toBe('ok')->and($good['dkim']['status'])->toBe('ok')->and($good['dmarc']['status'])->toBe('ok');

    $none = (new DnsAuth($mk([])))->check('b.test', 'google');
    expect($none['spf']['status'])->toBe('missing')->and($none['dkim']['status'])->toBe('missing')->and($none['dmarc']['status'])->toBe('missing');

    $weak = (new DnsAuth($mk(['c.test' => ['v=spf1 +all', 'v=spf1 -all'], '_dmarc.c.test' => ['v=DMARC1; p=none; rua=mailto:x@c.test'], 'k._domainkey.c.test' => ['v=DKIM1; p=']])))->check('c.test', 'k');
    expect($weak['spf']['status'])->toBe('warn')->and($weak['dmarc']['status'])->toBe('warn')->and($weak['dkim']['status'])->toBe('warn');

    $lax = (new DnsAuth($mk(['d.test' => ['v=spf1 include:x.test ?all']])))->check('d.test');
    expect($lax['spf']['status'])->toBe('warn')->and($lax['dkim']['status'])->toBe('warn');              // no selector given: asks for one
    expect((new DnsAuth($mk([])))->check('e.test', 'bad selector!')['dkim']['status'])->toBe('warn');
});

it('keeps a subject on one line and never expands placeholders twice', function () {
    $m = Compose::fill('Hi {{client_name}}', 'Dear {{client_name}}', ['client_name' => "Evil\r\nBcc: x@y.test {{domain}}", 'domain' => 'SECRET']);
    expect($m['subject'])->toBe('Hi Evil Bcc: x@y.test {{domain}}')->and($m['body'])->toContain('{{domain}}');
    $b = Compose::finalBody('Hello', 'https://app.test/u/abc', 'Agency', 'Dhaka');
    expect($b)->toContain('https://app.test/u/abc')->and($b)->toContain('Agency, Dhaka');
    expect(Compose::headers('https://app.test/u/abc'))->toMatchArray(['List-Unsubscribe' => '<https://app.test/u/abc>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
});

it('queues drafts only for reachable, unsuppressed, new people', function () {
    [$svc, $repo] = world();
    $repo->leads['lead2']['email'] = null;
    $repo->suppressed['p3@c3.test'] = 'manual';
    $r = $svc->queue(OT, 'camp', ['lead1', 'lead2', 'lead3', 'lead4', 'lead4', 'nope']);
    expect($r)->toBe(['queued' => 2, 'no_email' => 2, 'suppressed' => 1, 'duplicate' => 0, 'no_proposal' => 0]);
    expect($svc->queue(OT, 'camp', ['lead1']))->toBe(['queued' => 0, 'no_email' => 0, 'suppressed' => 0, 'duplicate' => 1, 'no_proposal' => 0]);
    $first = array_values($repo->messages)[0];
    expect($first['status'])->toBe('pending_approval')->and($first['subject'])->toBe('আপনার সাইট c1.test')->and($first['body'])->toContain('কোম্পানি 1');
});

it('sends nothing that nobody approved', function () {
    [$svc, $repo, $tr] = world();
    $svc->queue(OT, 'camp', ['lead1', 'lead2', 'lead3']);
    $out = $svc->sendFor('acc');
    expect($tr->sent)->toHaveLength(0)->and($out['sent'])->toBe(0);
    $svc->reject([array_key_first($repo->messages)]);
    $ids = array_keys($repo->messages);
    $svc->approve([$ids[1]], OU);
    $svc->sendFor('acc');
    expect($tr->sent)->toHaveLength(1)->and($tr->sent[0]['mail']['to'])->toBe('p2@c2.test');
    expect($repo->messages[$ids[0]]['status'])->toBe('rejected')->and($repo->messages[$ids[2]]['status'])->toBe('pending_approval');
    $svc->sendFor('acc');
    expect($tr->sent)->toHaveLength(1);                                            // a second run does not resend
});

it('adds the unsubscribe link and header to every mail and decrypts the password only to send', function () {
    [$svc, $repo, $tr] = world();
    $svc->queue(OT, 'camp', ['lead1']);
    approveAll($svc, $repo);
    $svc->sendFor('acc');
    $s = $tr->sent[0];
    expect($s['smtp']['password'])->toBe('secret-pw')->and($s['smtp']['host'])->toBe('smtp.agency.test')
        ->and($s['mail']['body'])->toContain('https://app.test/u/'.md5(array_key_first($repo->messages)))
        ->and($s['mail']['headers']['List-Unsubscribe'])->toContain('https://app.test/u/')
        ->and($s['mail']['body'])->toContain('Dhaka');
    expect($repo->messages[array_key_first($repo->messages)]['rfc'])->toBe('id1@agency.test');
});

it('never mails a suppressed address, even one approved before it unsubscribed', function () {
    [$svc, $repo, $tr] = world();
    $svc->queue(OT, 'camp', ['lead1', 'lead2']);
    approveAll($svc, $repo);
    $repo->suppressed['p1@c1.test'] = 'unsubscribed';
    $out = $svc->sendFor('acc');
    expect($tr->sent)->toHaveLength(1)->and($tr->sent[0]['mail']['to'])->toBe('p2@c2.test')->and($out['sent'])->toBe(1);
    expect($repo->messages[array_key_first($repo->messages)]['status'])->toBe('cancelled');
    expect($svc->approve([array_key_first($repo->messages)], OU)['approved'])->toBe(0);
});

it('stops at the daily limit, including the warm-up allowance, and continues tomorrow', function () {
    [$svc, $repo, $tr] = world(20, '2026-11-02');                                   // day one: 5
    $svc->queue(OT, 'camp', ['lead1', 'lead2', 'lead3', 'lead4', 'lead5', 'lead6', 'lead7', 'lead8']);
    approveAll($svc, $repo);
    expect($svc->sendFor('acc')['sent'])->toBe(5)->and($tr->sent)->toHaveLength(5);
    $again = $svc->sendFor('acc');
    expect($again['sent'])->toBe(0)->and($again['stopped'])->toBe('daily_limit')->and($tr->sent)->toHaveLength(5);
    $repo->now = '2026-11-03';                                                      // next day: 8 allowed, 5 already used yesterday
    $svc2 = new OutreachService($repo, new FakeCrmStore(new FakeLeadStore), $tr, fn () => 'pw', fn ($a) => 'x', fn ($t) => "https://app.test/u/$t", fn () => '2026-11-03', fn () => null);
    expect($svc2->sendFor('acc')['sent'])->toBe(3)->and($tr->sent)->toHaveLength(8);
});

it('treats a bad login as an account problem: stops, keeps the mails, gives the attempt back', function () {
    [$svc, $repo, $tr] = world();
    $svc->queue(OT, 'camp', ['lead1', 'lead2', 'lead3']);
    approveAll($svc, $repo);
    $tr->failAll = 'smtp_auth';
    $r = $svc->sendFor('acc');
    expect($r['sent'])->toBe(0)->and($r['failed'])->toBe(0)->and($r['stopped'])->toBe('smtp_auth')->and($r['deferred'])->toBe(3);
    foreach ($repo->messages as $m) {
        expect($m['status'])->toBe('approved')->and($m['attempts'])->toBe(0);
    }
    $tr->failAll = null;
    expect($svc->sendFor('acc')['sent'])->toBe(3);                                  // fixed password: they go out
});

it('fails only the recipient that the server refused', function () {
    [$svc, $repo, $tr] = world();
    $svc->queue(OT, 'camp', ['lead1', 'lead2']);
    approveAll($svc, $repo);
    $tr->fail['p1@c1.test'] = 'smtp_rejected';
    $r = $svc->sendFor('acc');
    expect($r['sent'])->toBe(1)->and($r['failed'])->toBe(1)->and($r['stopped'])->toBeNull();
    $ids = array_keys($repo->messages);
    expect($repo->messages[$ids[0]]['status'])->toBe('failed')->and($repo->messages[$ids[1]]['status'])->toBe('sent');
});

it('gives up after three tries on a flaky server', function () {
    [$svc, $repo, $tr] = world();
    $svc->queue(OT, 'camp', ['lead1']);
    approveAll($svc, $repo);
    $id = array_key_first($repo->messages);
    $repo->messages[$id]['attempts'] = 2;
    $tr->fail['p1@c1.test'] = 'smtp_other';
    $svc->sendFor('acc');
    expect($repo->messages[$id]['status'])->toBe('failed');
});

it('does nothing for a paused account', function () {
    [$svc, $repo, $tr] = world();
    $svc->queue(OT, 'camp', ['lead1']);
    approveAll($svc, $repo);
    $repo->accounts['acc']['status'] = 'paused';
    expect($svc->sendFor('acc')['stopped'])->toBe('account_inactive')->and($tr->sent)->toHaveLength(0);
});

it('writes one history line per mail into the lead timeline', function () {
    [$svc, $repo, , $crm] = world();
    $svc->queue(OT, 'camp', ['lead1']);
    approveAll($svc, $repo);
    $svc->sendFor('acc');
    expect($crm->activities)->toHaveLength(1)->and($crm->activities[0]['kind'])->toBe('email');
});

it('queues each follow-up once, still behind approval, and a reply cancels it', function () {
    [$svc, $repo, $tr] = world();
    $svc->queue(OT, 'camp', ['lead1', 'lead2']);
    approveAll($svc, $repo);
    $svc->sendFor('acc');
    expect($svc->queueFollowups(OT))->toBe(0);                                     // too early
    foreach ($repo->messages as $k => $_) {
        $repo->messages[$k]['sent_age_days'] = 4;
    }
    expect($svc->queueFollowups(OT))->toBe(2)->and($svc->queueFollowups(OT))->toBe(0);
    $follow = array_values(array_filter($repo->messages, fn ($m) => $m['step'] === 2));
    expect($follow)->toHaveLength(2)->and($follow[0]['status'])->toBe('pending_approval')->and($follow[0]['subject'])->toBe('Re: আপনার সাইট c1.test');
    $before = count($tr->sent);
    $svc->sendFor('acc');
    expect($tr->sent)->toHaveLength($before);                                      // not approved yet: not sent
    $first = array_key_first($repo->messages);
    expect($svc->markReplied($first))->toBeTrue()->and($svc->markReplied($first))->toBeFalse();
    $f1 = array_values(array_filter($repo->messages, fn ($m) => $m['step'] === 2 && $m['to_email'] === 'p1@c1.test'))[0];
    expect($f1['status'])->toBe('cancelled');
});

it('a bounce suppresses the address and stops later queueing', function () {
    [$svc, $repo] = world();
    $svc->queue(OT, 'camp', ['lead1']);
    approveAll($svc, $repo);
    $svc->sendFor('acc');
    expect($svc->markBounced(array_key_first($repo->messages)))->toBeTrue();
    expect($svc->queue(OT, 'camp', ['lead1'])['suppressed'])->toBe(1);
});
