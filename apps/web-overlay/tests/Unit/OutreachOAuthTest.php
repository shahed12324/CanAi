<?php

use App\Outreach\AccessTokens;
use App\Outreach\GmailTransport;
use App\Outreach\GoogleOAuth;
use App\Outreach\GraphTransport;
use App\Outreach\MicrosoftOAuth;
use App\Outreach\Mime;
use App\Outreach\OAuthFailure;
use App\Outreach\OAuthRevoked;
use App\Outreach\OutreachService;
use App\Outreach\Pkce;
use App\Outreach\RoutingMailTransport;
use App\Outreach\TransportFailure;
use Tests\Support\FakeCrmStore;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeLeadStore;
use Tests\Support\FakeMailTransport;
use Tests\Support\FakeOutreachRepo;

function mail1(array $over = []): array
{
    return $over + ['from_email' => 'me@agency.test', 'from_name' => 'এজেন্সি', 'to' => 'boss@alo.test', 'subject' => 'আপনার সাইট', 'body' => "প্রিয় আলো,\nধন্যবাদ", 'headers' => ['List-Unsubscribe' => '<https://app.test/u/abc>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']];
}

function decodeMime(string $raw): array
{
    [$head, $body] = explode("\r\n\r\n", $raw, 2);
    $h = [];
    foreach (explode("\r\n", preg_replace("/\r\n[ \t]+/", ' ', $head)) as $line) {
        [$k, $v] = explode(': ', $line, 2);
        $h[$k] = $v;
    }

    return [$h, base64_decode($body, true)];
}

function decodeWords(string $v): string
{
    $v = preg_replace('/\?=\s+(?==\?UTF-8)/', '?=', $v);               // blanks between two encoded words are not part of the text (RFC 2047)

    return preg_replace_callback('/=\?UTF-8\?B\?([^?]+)\?=/', fn ($m) => base64_decode($m[1]), $v);
}

it('writes a mail whose headers can not be hijacked and whose Bengali survives', function () {
    $raw = Mime::message(mail1(['subject' => "Hi\r\nBcc: evil@x.test", 'from_name' => "Eve\r\nBcc: z@x.test <a>", 'headers' => ['List-Unsubscribe' => "<https://a.test/u>\r\nX-Evil: 1", 'Bad Header' => 'x', 'Subject' => 'override']]), 'id1@agency.test', 'Mon, 02 Nov 2026 10:00:00 +0000');
    [$h, $body] = decodeMime($raw);
    expect($h)->toHaveKey('From')->and($h['Subject'])->toBe('Hi Bcc: evil@x.test')->and($h['Message-ID'])->toBe('<id1@agency.test>');
    expect(array_key_exists('Bcc', $h))->toBeFalse()->and(array_key_exists('X-Evil', $h))->toBeFalse()->and(array_key_exists('Bad Header', $h))->toBeFalse();
    expect($h['List-Unsubscribe'])->toBe('<https://a.test/u> X-Evil: 1')->and(decodeWords($h['From']))->toContain('Eve Bcc: z@x.test a');
    expect($raw)->toStartWith('Date: ')->and(substr_count($raw, "\r\nBcc:"))->toBe(0);

    [$h2, $body2] = decodeMime(Mime::message(mail1(), 'id2@agency.test', 'Mon, 02 Nov 2026 10:00:00 +0000'));
    expect(decodeWords($h2['Subject']))->toBe('আপনার সাইট')->and(decodeWords($h2['From']))->toBe('এজেন্সি <me@agency.test>')->and($body2)->toBe("প্রিয় আলো,\r\nধন্যবাদ");
    expect($h2['Content-Type'])->toBe('text/plain; charset=UTF-8');
});

it('never cuts a Bengali letter in half when it splits a long subject', function () {
    $subject = str_repeat('বাংলাদেশের ', 12);
    $enc = Mime::encodeHeader($subject);
    expect(decodeWords(str_replace("\r\n ", '', $enc)))->toBe(trim($subject));
    foreach (explode("\r\n ", $enc) as $line) {
        expect(strlen($line))->toBeLessThan(76);
    }
});

it('refuses an address that is not one', function () {
    foreach (['a b@c.test', 'a@b', 'a@b.test>', '"x"@y.test', "a@b.test\r\nBcc: z@y.test"] as $bad) {
        $threw = false;
        try {
            Mime::address($bad);
        } catch (InvalidArgumentException) {
            $threw = true;
        }
        expect($threw)->toBeTrue();
    }
});

it('builds a PKCE pair per RFC 7636', function () {
    expect(Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
    $v = Pkce::verifier();
    expect(strlen($v))->toBe(64)->and(preg_match('/\A[A-Za-z0-9_-]{64}\z/', $v))->toBe(1)->and(Pkce::verifier() === $v)->toBeFalse();
});

it('builds Google and Microsoft consent URLs with PKCE, state and the minimum scopes', function () {
    $g = (new GoogleOAuth(new FakeHttpJson, 'cid', 'sec'))->authUrl('https://app.test/cb', 'STATE1', 'CHAL');
    parse_str(parse_url($g, PHP_URL_QUERY), $q);
    expect($g)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')->and($q['state'])->toBe('STATE1')->and($q['code_challenge'])->toBe('CHAL')->and($q['code_challenge_method'])->toBe('S256')
        ->and($q['access_type'])->toBe('offline')->and($q['redirect_uri'])->toBe('https://app.test/cb');
    expect($q['scope'])->toContain('gmail.send')->and($q['scope'])->toContain('gmail.readonly');
    expect(str_contains($q['scope'], 'mail.google.com'))->toBeFalse();                       // no full-mailbox scope

    $m = (new MicrosoftOAuth(new FakeHttpJson, 'cid', 'sec', 'contoso.onmicrosoft.com'))->authUrl('https://app.test/cb', 'S2', 'C2');
    parse_str(parse_url($m, PHP_URL_QUERY), $q2);
    expect($m)->toStartWith('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize?')->and($q2['scope'])->toContain('Mail.Send')->and($q2['scope'])->toContain('offline_access')->and($q2['code_challenge_method'])->toBe('S256');
    $threw = false;
    try {
        new MicrosoftOAuth(new FakeHttpJson, 'a', 'b', '../evil.com/x');
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});

it('exchanges a Google code, verifies the e-mail and insists on the needed permission', function () {
    $http = new FakeHttpJson;
    $http->queue = [
        ['status' => 200, 'json' => ['access_token' => 'AT', 'refresh_token' => 'RT', 'scope' => 'openid email https://www.googleapis.com/auth/gmail.send']],
        ['status' => 200, 'json' => ['email' => 'Me@Gmail.test', 'email_verified' => true]],
    ];
    $r = (new GoogleOAuth($http, 'cid', 'sec'))->exchange('CODE', 'VER', 'https://app.test/cb');
    expect($r)->toBe(['refresh_token' => 'RT', 'access_token' => 'AT', 'email' => 'me@gmail.test']);
    expect($http->calls[0]['form']['code_verifier'])->toBe('VER')->and($http->calls[0]['form']['grant_type'])->toBe('authorization_code')->and($http->calls[1]['headers']['Authorization'])->toBe('Bearer AT');

    foreach ([
        [['status' => 200, 'json' => ['access_token' => 'AT', 'scope' => 'gmail.send']], 'google_no_refresh_token'],
        [['status' => 200, 'json' => ['access_token' => 'AT', 'refresh_token' => 'RT', 'scope' => 'openid email']], 'google_scope_missing'],
        [['status' => 400, 'json' => ['error' => 'invalid_grant']], 'google_exchange_failed'],
    ] as [$answer, $code]) {
        $h = new FakeHttpJson;
        $h->queue = [$answer];
        $got = null;
        try {
            (new GoogleOAuth($h, 'c', 's'))->exchange('c', 'v', 'u');
        } catch (OAuthFailure $e) {
            $got = $e->getMessage();
        }
        expect($got)->toBe($code);
    }
    $h = new FakeHttpJson;
    $h->queue = [['status' => 200, 'json' => ['access_token' => 'AT', 'refresh_token' => 'RT', 'scope' => 'gmail.send']], ['status' => 200, 'json' => ['email' => 'x@y.test', 'email_verified' => false]]];
    $got = null;
    try {
        (new GoogleOAuth($h, 'c', 's'))->exchange('c', 'v', 'u');
    } catch (OAuthFailure $e) {
        $got = $e->getMessage();
    }
    expect($got)->toBe('google_email_unverified');
});

it('exchanges a Microsoft code and refreshes with a rotated token', function () {
    $http = new FakeHttpJson;
    $http->queue = [
        ['status' => 200, 'json' => ['access_token' => 'AT', 'refresh_token' => 'RT', 'scope' => 'Mail.Send Mail.Read User.Read']],
        ['status' => 200, 'json' => ['mail' => null, 'userPrincipalName' => 'Me@Contoso.test']],
        ['status' => 200, 'json' => ['access_token' => 'AT2', 'refresh_token' => 'RT2']],
    ];
    $ms = new MicrosoftOAuth($http, 'cid', 'sec');
    expect($ms->exchange('CODE', 'VER', 'https://app.test/cb')['email'])->toBe('me@contoso.test');
    expect($ms->refresh('RT'))->toBe(['access_token' => 'AT2', 'refresh_token' => 'RT2']);
    expect($http->calls[2]['url'])->toBe('https://login.microsoftonline.com/common/oauth2/v2.0/token');
});

it('tells a revoked grant from a passing outage', function () {
    $http = new FakeHttpJson;
    $http->queue = [['status' => 400, 'json' => ['error' => 'invalid_grant']], ['status' => 503, 'json' => []], ['status' => 400, 'json' => ['error' => 'invalid_grant']]];
    $g = new GoogleOAuth($http, 'c', 's');
    $caught = [];
    foreach (range(1, 2) as $_) {
        try {
            $g->refresh('RT');
        } catch (OAuthRevoked) {
            $caught[] = 'revoked';
        } catch (OAuthFailure) {
            $caught[] = 'failure';
        }
    }
    expect($caught)->toBe(['revoked', 'failure']);
    $threw = false;
    try {
        (new MicrosoftOAuth($http, 'c', 's'))->refresh('RT');
    } catch (OAuthRevoked) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});

it('sends through Gmail as base64url RFC 5322 with the bearer token and keeps the provider ids', function () {
    $http = new FakeHttpJson;
    $http->queue = [['status' => 200, 'json' => ['id' => '18f', 'threadId' => 't9', 'labelIds' => ['SENT']]]];
    $r = (new GmailTransport($http))->send(['type' => 'gmail', 'access_token' => 'AT'], mail1());
    expect($r['provider_id'])->toBe('18f')->and($r['thread_id'])->toBe('t9')->and(str_ends_with($r['message_id'], '@agency.test'))->toBeTrue();
    $c = $http->calls[0];
    expect($c['url'])->toBe('https://gmail.googleapis.com/gmail/v1/users/me/messages/send')->and($c['headers']['Authorization'])->toBe('Bearer AT');
    $raw = base64_decode(strtr(json_decode($c['body'], true)['raw'], '-_', '+/'), true);
    [$h] = decodeMime($raw);
    expect($h['To'])->toBe('boss@alo.test')->and($h['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')->and($h['Message-ID'])->toBe('<'.$r['message_id'].'>');
});

it('sends through Graph as base64 MIME text/plain and accepts only 202', function () {
    $http = new FakeHttpJson;
    $http->queue = [['status' => 202, 'json' => []], ['status' => 200, 'json' => []]];
    $g = new GraphTransport($http);
    $r = $g->send(['type' => 'graph', 'access_token' => 'AT'], mail1());
    expect($r['provider_id'])->toBeNull();
    $c = $http->calls[0];
    expect($c['url'])->toBe('https://graph.microsoft.com/v1.0/me/sendMail')->and($c['headers']['Content-Type'])->toBe('text/plain');
    [$h] = decodeMime(base64_decode($c['body'], true));
    expect($h['List-Unsubscribe'])->toBe('<https://app.test/u/abc>');
    $code = null;
    try {
        $g->send(['type' => 'graph', 'access_token' => 'AT'], mail1());
    } catch (TransportFailure $e) {
        $code = $e->code_;
    }
    expect($code)->toBe('api_unavailable');                           // 200 is not what sendMail answers: do not call it sent
});

it('maps API answers to the right reaction', function () {
    foreach ([[400, [], 'api_rejected', true], [401, [], 'oauth_revoked', false], [429, [], 'quota', false], [403, ['error' => ['errors' => [['reason' => 'userRateLimitExceeded']]]], 'quota', false], [403, ['error' => ['status' => 'PERMISSION_DENIED']], 'oauth_scope', false], [500, [], 'api_unavailable', false]] as [$status, $json, $code, $perm]) {
        $http = new FakeHttpJson;
        $http->queue = [['status' => $status, 'json' => $json]];
        $got = null;
        try {
            (new GmailTransport($http))->send(['access_token' => 'x'], mail1());
        } catch (TransportFailure $e) {
            $got = [$e->code_, $e->permanent];
        }
        expect($got)->toBe([$code, $perm]);
    }
    $http = new FakeHttpJson;
    $got = null;
    try {
        (new GmailTransport($http))->send(['access_token' => 'x'], mail1(['to' => "a@b.test\r\nBcc: c@d.test"]));
    } catch (TransportFailure $e) {
        $got = $e->code_;
    }
    expect($got)->toBe('api_rejected')->and(count($http->calls))->toBe(0);               // a bad address never reaches the network
});

it('routes by account type', function () {
    $smtp = new FakeMailTransport;
    $gm = new FakeHttpJson;
    $gm->queue = [['status' => 200, 'json' => ['id' => 'g1', 'threadId' => 't1']]];
    $routing = new RoutingMailTransport($smtp, new GmailTransport($gm), new GraphTransport(new FakeHttpJson));
    expect($routing->send(['type' => 'smtp', 'host' => 'h'], mail1())['provider_id'])->toBeNull();
    expect($routing->send(['type' => 'gmail', 'access_token' => 'a'], mail1())['provider_id'])->toBe('g1');
});

function tokens(OAuthProviderFake $fake, FakeOutreachRepo $repo): AccessTokens
{
    return new AccessTokens(['gmail' => $fake], $repo, fn ($c, $v) => 'plain-'.$c, fn ($p) => ['cipher' => 'enc-'.$p, 'version' => 2]);
}

class OAuthProviderFake implements App\Outreach\OAuthProvider
{
    public $result = ['access_token' => 'AT', 'refresh_token' => null];

    public $throw = null;

    public $ok = true;

    public function configured(): bool
    {
        return $this->ok;
    }

    public function authUrl(string $r, string $s, string $c): string
    {
        return '';
    }

    public function exchange(string $c, string $v, string $r): array
    {
        return [];
    }

    public function refresh(string $t): array
    {
        if ($this->throw) {
            throw $this->throw;
        }

        return $this->result;
    }
}

it('hands out an access token, stores a rotated refresh token and clears old errors', function () {
    $repo = new FakeOutreachRepo;
    $fake = new OAuthProviderFake;
    $acct = ['id' => 'acc', 'provider' => 'gmail', 'refresh_ct' => 'CT', 'key_version' => 1];
    expect(tokens($fake, $repo)->forAccount($acct))->toBe('AT')->and($repo->refreshSaved)->toBe([])->and($repo->errors['acc'])->toBeNull();
    $fake->result = ['access_token' => 'AT3', 'refresh_token' => 'NEW'];
    tokens($fake, $repo)->forAccount($acct);
    expect($repo->refreshSaved['acc'])->toBe(['enc-NEW', 2]);
});

it('flags a revoked account for the owner but not a passing outage', function () {
    $repo = new FakeOutreachRepo;
    $fake = new OAuthProviderFake;
    $acct = ['id' => 'acc', 'provider' => 'gmail', 'refresh_ct' => 'CT', 'key_version' => 1];
    $fake->throw = new OAuthRevoked('x');
    $code = null;
    try {
        tokens($fake, $repo)->forAccount($acct);
    } catch (TransportFailure $e) {
        $code = $e->code_;
    }
    expect($code)->toBe('oauth_revoked')->and($repo->errors['acc'])->toBe('oauth_revoked');
    $repo->errors = [];
    $fake->throw = new OAuthFailure('x');
    try {
        tokens($fake, $repo)->forAccount($acct);
    } catch (TransportFailure $e) {
        $code = $e->code_;
    }
    expect($code)->toBe('oauth_unavailable')->and(array_key_exists('acc', $repo->errors))->toBeFalse();
    $fake->ok = false;
    try {
        tokens($fake, $repo)->forAccount($acct);
    } catch (TransportFailure $e) {
        $code = $e->code_;
    }
    expect($code)->toBe('oauth_not_configured');
});

function gmailWorld(): array
{
    $repo = new FakeOutreachRepo;
    $repo->accounts['acc'] = ['id' => 'acc', 'provider' => 'gmail', 'from_email' => 'me@gmail.test', 'from_name' => 'Me', 'host' => null, 'port' => null, 'security' => 'tls', 'username' => null, 'secret_ct' => null, 'refresh_ct' => 'CT', 'key_version' => 1, 'daily_limit' => 20, 'warmup_started_on' => '2026-10-01', 'status' => 'active'];
    $repo->campaigns['camp'] = ['id' => 'camp', 'account_id' => 'acc', 'subject_tpl' => 'Hi {{domain}}', 'body_tpl' => 'Hello {{client_name}}', 'followup_after_days' => null, 'followup_body_tpl' => null, 'status' => 'active'];
    $repo->leads['l1'] = ['lead_id' => 'l1', 'contact_id' => 'c1', 'email' => 'p1@c1.test', 'email_status' => 'mx_ok', 'name' => 'A', 'domain' => 'c1.test', 'analysis' => []];
    $repo->leads['l2'] = ['lead_id' => 'l2', 'contact_id' => 'c2', 'email' => 'p2@c2.test', 'email_status' => 'mx_ok', 'name' => 'B', 'domain' => 'c2.test', 'analysis' => []];
    $tr = new FakeMailTransport;
    $fakeTok = new OAuthProviderFake;
    $tok = new AccessTokens(['gmail' => $fakeTok], $repo, fn ($c, $v) => 'rt', fn ($p) => ['cipher' => $p, 'version' => 1]);
    $svc = new OutreachService($repo, new FakeCrmStore(new FakeLeadStore), $tr, fn () => 'x', fn ($a) => $tok->forAccount($a), fn ($t) => "https://app.test/u/$t", fn () => '2026-11-02', fn () => null);

    return [$svc, $repo, $tr, $fakeTok];
}

it('sends from a Gmail account with an access token and records the provider ids', function () {
    [$svc, $repo, $tr] = gmailWorld();
    $svc->queue('T', 'camp', ['l1']);
    $svc->approve(array_keys($repo->messages), 'U');
    expect($svc->sendFor('acc')['sent'])->toBe(1);
    expect($tr->sent[0]['smtp'])->toBe(['type' => 'gmail', 'access_token' => 'AT']);
    $m = array_values($repo->messages)[0];
    expect($m['provider_id'])->toBe('prov1')->and($m['thread_id'])->toBe('thr1');
});

it('keeps approved mails waiting when the Gmail grant is revoked, and says why', function () {
    [$svc, $repo, $tr, $fake] = gmailWorld();
    $svc->queue('T', 'camp', ['l1', 'l2']);
    $svc->approve(array_keys($repo->messages), 'U');
    $fake->throw = new OAuthRevoked('x');
    $r = $svc->sendFor('acc');
    expect($r['sent'])->toBe(0)->and($r['stopped'])->toBe('oauth_revoked')->and($tr->sent)->toHaveLength(0)->and($repo->errors['acc'])->toBe('oauth_revoked');
    foreach ($repo->messages as $m) {
        expect($m['status'])->toBe('approved')->and($m['attempts'])->toBe(0);
    }
    $fake->throw = null;                                              // the owner reconnects
    expect($svc->sendFor('acc')['sent'])->toBe(2)->and($repo->errors['acc'])->toBeNull();
});

it('stops the batch on a quota answer and keeps the rest for later', function () {
    [$svc, $repo, $tr] = gmailWorld();
    $svc->queue('T', 'camp', ['l1', 'l2']);
    $svc->approve(array_keys($repo->messages), 'U');
    $tr->failAll = 'quota';
    $r = $svc->sendFor('acc');
    expect($r['sent'])->toBe(0)->and($r['stopped'])->toBe('quota')->and($r['deferred'])->toBe(2);
    foreach ($repo->messages as $m) {
        expect($m['status'])->toBe('approved')->and($m['attempts'])->toBe(0);
    }
});
