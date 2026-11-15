<?php

use App\Pc\PcService;
use Tests\Support\FakePcRepo;

function pcw(): array
{
    $r = new FakePcRepo;

    return [new PcService($r), $r];
}

it('parses the policy form: one item per line, trimmed, deduplicated, hosts lower-cased', function () {
    $p = PcService::parsePolicy([
        'roots' => "C:\\Users\\me\\Docs\r\n  /home/me/work \n\n/home/me/work\n", 'apps' => "Notepad\nnotepad\nCalc", 'commands' => "git\n\n npm ", 'hosts' => "Example.COM\nexample.com\nB.org",
        'modes' => ['read_file' => 'allow', 'write_file' => 'deny', 'list_dir' => '', 'run_command' => 'ask', 'hack' => 'allow', 'power' => 'maybe'],
    ]);
    expect($p['roots'])->toBe(['C:\\Users\\me\\Docs', '/home/me/work']);
    expect($p['apps'])->toBe(['Notepad', 'notepad', 'Calc']);
    expect($p['commands'])->toBe(['git', 'npm']);
    expect($p['hosts'])->toBe(['example.com', 'b.org']);
    expect($p['modes'])->toBe(['read_file' => 'allow', 'write_file' => 'deny']);
});

it('tolerates missing or wrongly typed form fields', function () {
    expect(PcService::parsePolicy([]))->toBe(['roots' => [], 'apps' => [], 'commands' => [], 'hosts' => [], 'modes' => []]);
    $p = PcService::parsePolicy(['roots' => ['x'], 'apps' => 5, 'modes' => 'allow']);
    expect($p['roots'])->toBe([]);
    expect($p['apps'])->toBe([]);
    expect($p['modes'])->toBe([]);
});

it('caps list length at 100 items', function () {
    $many = implode("\n", array_map(fn ($i) => "/r$i", range(1, 150)));
    expect(count(PcService::parsePolicy(['roots' => $many])['roots']))->toBe(100);
});

it('turns one-line targets into action params', function () {
    foreach (['read_file', 'list_dir', 'write_file', 'delete_file'] as $k) {
        expect(PcService::paramsFor($k, '  /home/me/a.txt '))->toBe(['path' => '/home/me/a.txt']);
    }
    expect(PcService::paramsFor('open_app', 'Notepad'))->toBe(['app' => 'Notepad']);
    expect(PcService::paramsFor('browser_navigate', 'https://example.com/x'))->toBe(['url' => 'https://example.com/x']);
    expect(PcService::paramsFor('run_command', 'git status  -s'))->toBe(['command' => 'git', 'args' => ['status', '-s']]);
    expect(PcService::paramsFor('run_command', 'git'))->toBe(['command' => 'git']);
    expect(PcService::paramsFor('run_command', '   '))->toBe(['command' => '']);
    expect(PcService::paramsFor('input_control', 'click'))->toBe(['action' => 'click']);
    expect(PcService::paramsFor('power', ' restart '))->toBe(['action' => 'restart']);
});

it('parses the new file kinds and mirrors their risk', function () {
    expect(PcService::paramsFor('copy_file', ' /a/x.txt -> /a/y.txt '))->toBe(['src' => '/a/x.txt', 'dst' => '/a/y.txt']);
    expect(PcService::paramsFor('screenshot', '/a/s.png'))->toBe(['path' => '/a/s.png'])->and(PcService::paramsFor('browser_extract', 'https://example.com'))->toBe(['url' => 'https://example.com'])
        ->and(PcService::paramsFor('window_info', ''))->toBe([])
        ->and(PcService::paramsFor('wait_window', 'notepad | Untitled | 15'))->toBe(['process' => 'notepad', 'title_contains' => 'Untitled', 'timeout' => 15])
        ->and(PcService::paramsFor('wait_window', 'notepad'))->toBe(['process' => 'notepad']);
    expect(PcService::paramsFor('move_file', '/a/x'))->toBe(['src' => '/a/x', 'dst' => '']);
    expect(PcService::paramsFor('trash_file', ' /a/x '))->toBe(['path' => '/a/x']);
    expect(PcService::KINDS['move_file'][1])->toBe('high')->and(PcService::KINDS['trash_file'][1])->toBe('high');
    expect(PcService::KINDS['copy_file'][1])->toBe('medium')->and(PcService::KINDS['create_xlsx'][1])->toBe('medium');
});

it('parses system settings values', function () {
    expect(PcService::paramsFor('system_setting', 'volume=30'))->toBe(['setting' => 'volume', 'value' => 30]);
    expect(PcService::paramsFor('system_setting', 'brightness=0.5'))->toBe(['setting' => 'brightness', 'value' => 0.5]);
    expect(PcService::paramsFor('system_setting', 'wifi=off'))->toBe(['setting' => 'wifi', 'value' => false]);
    expect(PcService::paramsFor('system_setting', 'bluetooth=ON'))->toBe(['setting' => 'bluetooth', 'value' => true]);
    expect(PcService::paramsFor('system_setting', 'volume'))->toBe(['setting' => 'volume', 'value' => '']);
});

it('validates the agent metrics body', function () {
    expect(PcService::metricsFrom([]))->toBeNull();
    expect(PcService::metricsFrom(['cpu' => 1, 'mem' => 2]))->toBeNull();
    expect(PcService::metricsFrom(['cpu' => 'x', 'mem' => 2, 'disk' => 3]))->toBeNull();
    expect(PcService::metricsFrom(['cpu' => [1], 'mem' => 2, 'disk' => 3]))->toBeNull();
    expect(PcService::metricsFrom(['cpu' => true, 'mem' => 2, 'disk' => 3]))->toBeNull();
    $m = PcService::metricsFrom(['cpu' => '12.5', 'mem' => 20, 'disk' => 30.5, 'gpu' => 'abc', 'battery' => 80, 'temp_c' => 61.2, 'charging' => 'yes']);
    expect($m)->toBe(['cpu' => 12.5, 'mem' => 20.0, 'disk' => 30.5, 'gpu' => null, 'battery' => 80.0, 'temp_c' => 61.2, 'charging' => null]);
    expect(PcService::metricsFrom(['cpu' => 1, 'mem' => 2, 'disk' => 3, 'charging' => false])['charging'])->toBeFalse();
});

it('saves a policy through the repo and explains refusals', function () {
    [$s, $r] = pcw();
    expect($s->savePolicy('T', 'U', 'D', [])['ok'])->toBeTrue();
    $bad = new class extends FakePcRepo
    {
        public string $res = 'bad_input';

        public function policySave(string $tenantId, string $userId, string $deviceId, array $p): string
        {
            return $this->res;
        }
    };
    $svc = new PcService($bad);
    $x = $svc->savePolicy('T', 'U', 'D', []);
    expect($x['ok'])->toBeFalse();
    expect($x['message'])->toContain('There is an error in the rules');
    $bad->res = 'not_found';
    expect($svc->savePolicy('T', 'U', 'D', [])['message'])->toBe(PcService::REASONS['not_found']);
});

it('lists every action kind with a label and the same risk classes as the database', function () {
    expect(array_keys(PcService::KINDS))->toBe(['read_file', 'list_dir', 'write_file', 'open_app', 'browser_navigate', 'system_setting', 'delete_file', 'run_command', 'input_control', 'power', 'copy_file', 'make_dir', 'create_xlsx', 'create_docx', 'move_file', 'trash_file', 'screenshot', 'browser_extract', 'window_info', 'wait_window', 'screen_read', 'screen_find', 'ui_click', 'screen_describe', 'browser_run', 'make_report']);
    $risk = array_map(fn ($v) => $v[1], PcService::KINDS);
    expect(array_keys($risk, 'critical'))->toBe(['power']);
    expect(array_keys($risk, 'high'))->toBe(['delete_file', 'run_command', 'input_control', 'move_file', 'trash_file', 'screenshot', 'screen_read', 'screen_find', 'ui_click', 'screen_describe', 'browser_run']);
    expect(in_array('make_report', array_keys($risk, 'medium'), true))->toBeTrue();
    // the latest SQL definition of pc_kind_risk (phase 36a) is the authority
    $sql = (string) file_get_contents(__DIR__.'/../../database/sql/phase36a.up.sql');
    foreach ($risk as $k => $r) {
        expect(str_contains($sql, "WHEN '$k' THEN '$r'"))->toBeTrue();
    }
    foreach (PcService::KINDS as $k => [$label]) {
        expect($label)->not->toBe('');
    }
});

it('agent routes: only pairing is open, everything else needs the device token, and nothing is unthrottled', function () {
    $src = (string) file_get_contents(__DIR__.'/../../routes/api_pc.php');
    expect($src)->toContain("Route::post('pair', [AgentController::class, 'pair'])->middleware('throttle:10,1')");
    $guard = strpos($src, "Route::middleware(PcAgentAuth::class)");
    expect($guard)->not->toBeFalse();
    foreach (["'actions/next'", "'actions/{id}/report'", "'emergency'", "'metrics'"] as $route) {
        expect(strpos($src, $route))->toBeGreaterThan($guard);
    }
    expect($src)->toContain("Route::middleware('throttle:120,1')");
    $provider = (string) file_get_contents(__DIR__.'/../../app/Providers/PcServiceProvider.php');
    expect($provider)->toContain("prefix('api/pc/v1')");
    expect($provider)->toContain("Route::middleware('api')");
});

it('web routes for computer control sit behind the workspace middleware and are throttled', function () {
    $src = (string) file_get_contents(__DIR__.'/../../routes/web.php');
    $a = strpos($src, "prefix('app/computer')");
    expect($a)->toBeGreaterThan(0);
    $block = substr($src, $a, 1500);
    expect(substr($src, $a - 60, 60))->toContain('ResolveTenant::class');
    foreach (['pc.pair', 'pc.revoke', 'pc.policy', 'pc.request', 'pc.decide'] as $n) {
        expect(preg_match("/throttle:\\d+,1'\\)->name\\('".preg_quote($n, '/')."'\\)/", $block))->toBe(1);
    }
});

it('the agent middleware refuses without saying why', function () {
    $src = (string) file_get_contents(__DIR__.'/../../app/Http/Middleware/PcAgentAuth.php');
    expect($src)->toContain("str_starts_with(\$h, 'Bearer ')");
    expect($src)->toContain("['error' => 'unauthorized'], 401");
    expect($src)->toContain('$this->context->clear()');
});

it('turns plan refusal reasons into sentences without echoing arbitrary text', function () {
    expect(PcService::planReason('outside_scope:s2'))->toContain('step s2');
    expect(PcService::planReason('bad_params:?'))->not->toContain('?');
    expect(PcService::planReason('unknown_kind:s1'))->toContain('does not exist');
    expect(PcService::planReason('bad_params:<script>'))->not->toContain('script');
    expect(PcService::planReason('whatever'))->toBe('The plan is not valid.');
});

it('explains plan decisions, high-risk steps point to the phone', function () {
    expect(PcService::planDecisionMessage('mobile_required'))->toContain('mobile app');
    expect(PcService::planDecisionMessage('step_up_required'))->toContain('PIN');
    expect(PcService::planDecisionMessage('nope'))->toBe(PcService::REASONS['not_found']);
});
