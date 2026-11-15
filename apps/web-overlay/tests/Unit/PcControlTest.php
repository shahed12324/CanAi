<?php

use App\Pc\MonitorAdvisor;
use App\Pc\PcService;
use Tests\Support\FakePcRepo;

function pcs(?FakePcRepo $r = null): array
{
    $r ??= new FakePcRepo;

    return [new PcService($r), $r];
}

it('starts pairing with a random one-time code and stores only its hash', function () {
    [$s, $r] = pcs();
    $a = $s->startPairing('T', 'U', 'Laptop', 'windows');
    $b = $s->startPairing('T', 'U', 'Laptop', 'windows');
    expect($a['ok'])->toBeTrue();
    expect(preg_match('/\A[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{10}\z/', $a['code']))->toBe(1);
    expect($a['code'])->not->toBe($b['code']);
    expect($r->created[0]['codeHash'])->toBe(hash('sha256', $a['code']));
    expect(json_encode($r->created))->not->toContain($a['code']);
});

it('explains every pairing refusal', function () {
    foreach (['limit' => 'At most 5', 'forbidden' => 'not a member', 'bad_input' => '1–60'] as $res => $needle) {
        [$s, $r] = pcs();
        $r->createResult = $res;
        $x = $s->startPairing('T', 'U', 'n', 'windows');
        expect($x['ok'])->toBeFalse();
        expect($x['error'])->toContain($needle);
        expect(isset($x['code']))->toBeFalse();
    }
});

it('redeems a code once, hands the token out once and authenticates with it', function () {
    [$s] = pcs();
    $code = $s->startPairing('T', 'U', 'Laptop', 'linux')['code'];
    $d = $s->redeem(substr($code, 0, 5).'-'.strtolower(substr($code, 5)));      // tolerant of dash/lowercase
    expect($d)->not->toBeNull();
    expect(preg_match('/\A[0-9a-f]{64}\z/', $d['token']))->toBe(1);
    expect($s->redeem($code))->toBeNull();                                       // one use
    $who = $s->authenticate($d['token']);
    expect($who['device_id'])->toBe($d['device_id']);
    expect($s->authenticate(str_repeat('a', 64)))->toBeNull();
});

it('rejects malformed codes and tokens before touching the database', function () {
    [$s, $r] = pcs();
    $r->codes[hash('sha256', 'AAAAAAAAAA')] = ['device_id' => 'D', 'tenant_id' => 'T'];
    foreach (['', 'short', 'AAAAAAAAAAA', 'AAAAAAAAA0', 'AAAAAAAAAI', "AAAAAAAAAA\n; drop"] as $bad) {
        expect($s->redeem($bad))->toBeNull();
    }
    expect(count($r->codes))->toBe(1);
    foreach (['', 'zz', str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 65)] as $bad) {
        expect($s->authenticate($bad))->toBeNull();
    }
});

it('turns action results into friendly messages', function () {
    [$s, $r] = pcs();
    $r->requestResult = ['status' => 'pending', 'reason' => null, 'id' => 'X'];
    $x = $s->request('T', 'U', 'D', 'read_file', ['path' => '/a']);
    expect($x['ok'])->toBeTrue();
    expect($x['message'])->toContain('Awaiting your approval');
    $r->requestResult = ['status' => 'approved', 'reason' => null, 'id' => 'Y'];
    expect($s->request('T', 'U', 'D', 'read_file', [])['message'])->toContain('Approved');
    foreach (['emergency_stop', 'no_policy', 'denied_by_policy', 'outside_scope', 'bad_params', 'not_found'] as $why) {
        $r->requestResult = ['status' => $why === 'not_found' ? 'rejected_input' : 'blocked', 'reason' => $why];
        $x = $s->request('T', 'U', 'D', 'read_file', []);
        expect($x['ok'])->toBeFalse();
        expect($x['message'])->toBe(PcService::REASONS[$why]);
    }
    $r->requestResult = ['status' => 'blocked', 'reason' => 'weird'];
    expect($s->request('T', 'U', 'D', 'k', [])['message'])->toBe('The job cannot be done.');
    expect(count(array_unique(array_values(PcService::REASONS))))->toBe(count(PcService::REASONS));
});

it('has a message for every decision outcome', function () {
    $seen = [];
    foreach (['ok', 'not_pending', 'expired', 'emergency_stop', 'mobile_required', 'step_up_required', 'not_found', 'anything'] as $r) {
        $seen[] = PcService::decisionMessage($r);
    }
    expect(count(array_unique($seen)))->toBe(7);       // not_found and anything share the generic one
    expect(PcService::decisionMessage('mobile_required'))->toContain('mobile app');
    expect(PcService::decisionMessage('emergency_stop'))->toContain('emergency stop');
});

it('raises monitor alerts at the thresholds and not below', function () {
    $codes = fn (array $s) => array_column(MonitorAdvisor::alerts($s), 'code');
    expect($codes(['cpu' => 89.9, 'mem' => 89.9, 'disk' => 89.9, 'gpu' => 89.9, 'temp_c' => 84.9, 'battery' => 15.1]))->toBe([]);
    expect($codes(['cpu' => 90]))->toBe(['cpu_high']);
    expect($codes(['mem' => 90]))->toBe(['mem_high']);
    expect($codes(['disk' => 90]))->toBe(['disk_full']);
    expect($codes(['gpu' => 90]))->toBe(['gpu_high']);
    expect($codes(['temp_c' => 85]))->toBe(['temp_high']);
    expect($codes(['battery' => 15]))->toBe(['battery_low']);
    expect($codes(['battery' => 5, 'charging' => true]))->toBe([]);
    expect($codes(['battery' => 5, 'charging' => false]))->toBe(['battery_low']);
    expect($codes(['cpu' => null, 'mem' => null, 'disk' => null, 'gpu' => null, 'temp_c' => null, 'battery' => null]))->toBe([]);
    expect($codes([]))->toBe([]);
});

it('labels alert severity and shows clean numbers', function () {
    $a = MonitorAdvisor::alerts(['temp_c' => 91.5, 'cpu' => 95, 'gpu' => 99]);
    $by = array_column($a, 'level', 'code');
    expect($by)->toBe(['cpu_high' => 'warn', 'gpu_high' => 'info', 'temp_high' => 'critical']);
    expect($a[0]['message'])->toContain('95%');
    expect(array_column($a, 'message', 'code')['temp_high'])->toContain('91.5°C');
});

it('never uses look-alike characters in pairing codes', function () {
    [$s] = pcs();
    for ($i = 0; $i < 300; $i++) {
        expect(preg_match('/\A[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{10}\z/', $s->startPairing('T', 'U', 'n', 'windows')['code']))->toBe(1);
    }
});

it('does not even query the database for a malformed bearer token', function () {
    [$s, $r] = pcs();
    expect($s->authenticate('not-a-token'))->toBeNull();
    expect($r->lookups)->toBe(0);
    $s->authenticate(str_repeat('a', 64));
    expect($r->lookups)->toBe(1);
});

it('maps every phone decision outcome to one status, code and message', function () {
    $want = ['ok' => 200, 'step_up_required' => 403, 'not_pending' => 409, 'expired' => 410, 'emergency_stop' => 423, 'bad_input' => 422, 'not_found' => 404, 'weird' => 404];
    $msgs = [];
    foreach ($want as $r => $status) {
        $o = PcService::mobileOutcome($r);
        expect($o['status'])->toBe($status);
        expect($o['code'] === ($r === 'weird' ? 'not_found' : $r))->toBeTrue();
        expect($o['message'] !== '')->toBeTrue();
        $msgs[$o['code']] = $o['message'];
    }
    expect(count($msgs))->toBe(7);
    expect(count(array_unique($msgs)))->toBe(7);
});

it('the fake phone repo records who decided what', function () {
    $r = new Tests\Support\FakePcRepo;
    $r->mobileResult = 'step_up_required';
    expect($r->mobileDecide('DEV', 'USR', 'ACT', 'approve'))->toBe('step_up_required');
    expect($r->mobilePending('DEV', 'USR'))->toBe([]);
    expect($r->mobileCalls)->toBe([['deviceId' => 'DEV', 'userId' => 'USR', 'actionId' => 'ACT', 'decision' => 'approve'], ['deviceId' => 'DEV', 'userId' => 'USR']]);
});

it('phone hand-off: only look-and-open kinds, checked input, and the device policy still decides', function () {
    $repo = new Tests\Support\FakePcRepo;
    $s = new PcService($repo);
    $dev = 'DEV00000000000000000000001';
    $dev = strtolower($dev);
    expect(PcService::MOBILE_KINDS)->toBe(['read_file', 'list_dir', 'open_app', 'browser_navigate']);
    $bad = [['write_file', 'C:\\a'], ['delete_file', '/a'], ['power', 'x'], ['read_file', ''], ['read_file', '   '], ['read_file', str_repeat('a', 521)], ['read_file', "a\nb"], ['read_file', "a\0b"]];
    foreach ($bad as [$k, $t]) {
        $r = $s->mobileRequest('T', 'U', $dev, $k, $t);
        expect([$r['ok'], $r['code']])->toBe([false, 'bad_input']);
    }
    expect($s->mobileRequest('T', 'U', 'short', 'read_file', '/a')['code'])->toBe('bad_input');
    expect($s->mobileRequest('T', 'U', strtoupper($dev), 'read_file', '/a')['code'])->toBe('bad_input');
    $ok = $s->mobileRequest('T', 'U', $dev, 'browser_navigate', ' https://example.com ');
    expect([$ok['ok'], $ok['code'], $ok['status'], $ok['id']])->toBe([true, 'ok', 'pending', 'ACT00000000000000000000001']);
    $repo->requestResult = ['status' => 'blocked', 'reason' => 'outside_scope', 'id' => null, 'risk' => 'low'];
    $no = $s->mobileRequest('T', 'U', $dev, 'read_file', '/etc/passwd');
    expect([$no['ok'], $no['code'], $no['id']])->toBe([false, 'refused', null]);
    expect($no['message'] !== '')->toBeTrue();
});

it('phone hand-off passes the right params to the database', function () {
    $repo = new class extends Tests\Support\FakePcRepo {
        public array $got = [];

        public function actionRequest(string $t, string $u, string $d, string $k, array $p): array
        {
            $this->got[] = [$k, $p];

            return $this->requestResult;
        }
    };
    $s = new PcService($repo);
    $s->mobileRequest('T', 'U', str_repeat('a', 26), 'open_app', ' Notepad ');
    $s->mobileRequest('T', 'U', str_repeat('a', 26), 'read_file', '/home/me/a.txt');
    expect($repo->got)->toBe([['open_app', ['app' => 'Notepad']], ['read_file', ['path' => '/home/me/a.txt']]]);
});

it('rotates a device token: the new one works, the old one is dead, bad tokens never reach the database', function () {
    [$s, $r] = pcs();
    $d = $s->redeem($s->startPairing('T', 'U', 'Laptop', 'linux')['code']);
    $new = str_repeat('ab', 32);
    expect($s->rotate($d['token'], $new))->toBeTrue();
    expect($s->authenticate($d['token']))->toBeNull();
    expect($s->authenticate($new)['device_id'])->toBe($d['device_id']);
    expect($s->rotate($d['token'], str_repeat('cd', 32)))->toBeFalse();            // the old token cannot rotate again
    $before = $r->lookups;
    foreach ([[$new, $new], [$new, 'short'], ['short', $new], [$new, str_repeat('G', 64)]] as [$o, $n]) {
        expect($s->rotate($o, $n))->toBeFalse();
    }
    expect($s->authenticate($new)['device_id'])->toBe($d['device_id']);              // nothing was disturbed
});
