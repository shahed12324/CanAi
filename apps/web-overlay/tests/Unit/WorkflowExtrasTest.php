<?php

use App\Pc\PcService;
use App\Workflow\Risk;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;
use App\Workflow\ToolRegistry;
use App\Workflow\Tools\PcListDirTool;
use App\Workflow\Tools\PcReadFileTool;
use App\Workflow\Tools\PcResultTool;
use App\Workflow\Tools\PcWriteFileTool;
use App\Workflow\Tools\WebhookSendTool;
use App\Workflow\WorkflowDefinition;
use Tests\Support\FakeAiStep;
use Tests\Support\FakePcRepo;
use Tests\Support\FakeTool;
use Tests\Support\FakeWebhookRepo;

require_once __DIR__.'/WorkflowTest.php';

const WX_DEV = 'DEV00000000000000000000001';

function wxctx(): ToolContext
{
    return new ToolContext(WT, WU);
}

function wxpc(array $devices = [['id' => WX_DEV, 'name' => 'অফিস-পিসি', 'platform' => 'windows', 'last_seen_at' => null, 'user_id' => WU]]): array
{
    $r = new FakePcRepo;
    $r->deviceRows = $devices;

    return [$r, new PcService($r)];
}

function wxfails(callable $f): string
{
    try {
        $f();
    } catch (ToolException $e) {
        return $e->getMessage();
    }

    return '';
}

// ---------- webhook.send
it('webhook.send is an external tool and reports every answer in plain Bengali', function () {
    $h = new FakeWebhookRepo;
    $t = new WebhookSendTool($h);
    expect($t->key())->toBe('webhook.send');
    expect($t->risk())->toBe(Risk::EXTERNAL);
    expect($t->run(wxctx(), ['to' => ' alerts ', 'text' => 'হ্যালো']))->toContain('alerts');
    foreach (['not_found', 'limit', 'bad_input'] as $code) {
        $h->messageResult = $code;
        expect(wxfails(fn () => $t->run(wxctx(), ['to' => 'a', 'text' => 'x'])))->toBe(WebhookSendTool::MESSAGES[$code]);
    }
    $h->messageResult = 'weird';
    expect(wxfails(fn () => $t->run(wxctx(), ['to' => 'a', 'text' => 'x'])))->toBe('The message could not be sent.');
});

it('webhook.send needs an approval step before it', function () {
    $reg = new ToolRegistry([new WebhookSendTool(new FakeWebhookRepo)]);
    $send = ['type' => 'tool', 'tool' => 'webhook.send', 'args' => ['to' => 'a', 'text' => 'x'], 'next' => 'o'];
    expect(WorkflowDefinition::validate(wdef(['a' => $send, 'o' => wout()]), $reg) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wdef(['p' => ['type' => 'approval', 'message' => 'পাঠাব?', 'next' => 'a'], 'a' => $send, 'o' => wout()], 'p'), $reg))->toBe([]);
});

// ---------- pc tools
it('pc tools only file a request and pass exactly the checked parameters', function () {
    [$repo, $pc] = wxpc();
    $repo2 = new class($repo) extends FakePcRepo {
        public array $got = [];

        public function __construct(FakePcRepo $o)
        {
            $this->deviceRows = $o->deviceRows;
        }

        public function actionRequest(string $tenantId, string $userId, string $deviceId, string $kind, array $params): array
        {
            $this->got[] = compact('deviceId', 'kind', 'params');

            return $this->requestResult;
        }
    };
    $pc = new PcService($repo2);
    expect((new PcReadFileTool($pc, $repo2))->run(wxctx(), ['path' => 'C:\\a.txt']))->toContain('id=ACT00000000000000000000001');
    (new PcListDirTool($pc, $repo2))->run(wxctx(), ['path' => 'C:\\docs']);
    (new PcWriteFileTool($pc, $repo2))->run(wxctx(), ['path' => 'C:\\o.txt', 'content' => 'Text']);
    expect($repo2->got[0])->toBe(['deviceId' => WX_DEV, 'kind' => 'read_file', 'params' => ['path' => 'C:\\a.txt']]);
    expect($repo2->got[1]['kind'])->toBe('list_dir');
    expect($repo2->got[2])->toBe(['deviceId' => WX_DEV, 'kind' => 'write_file', 'params' => ['path' => 'C:\\o.txt', 'content' => 'Text']]);
});

it('pc tools have the right risk levels', function () {
    [$repo, $pc] = wxpc();
    expect((new PcReadFileTool($pc, $repo))->risk())->toBe(Risk::WRITE_OWN);
    expect((new PcListDirTool($pc, $repo))->risk())->toBe(Risk::WRITE_OWN);
    expect((new PcWriteFileTool($pc, $repo))->risk())->toBe(Risk::EXTERNAL);
    expect((new PcResultTool($repo))->risk())->toBe(Risk::READ);
    expect((new PcReadFileTool($pc, $repo))->key().(new PcListDirTool($pc, $repo))->key().(new PcWriteFileTool($pc, $repo))->key().(new PcResultTool($repo))->key())->toBe('pc.read_filepc.list_dirpc.write_filepc.result');
});

it('pc tools choose the computer by name or the only one, and say why not', function () {
    [$repo, $pc] = wxpc([]);
    $t = new PcReadFileTool($pc, $repo);
    expect(wxfails(fn () => $t->run(wxctx(), ['path' => 'a'])))->toContain('জোড়া None');
    $two = [['id' => WX_DEV, 'name' => 'A', 'platform' => 'x', 'last_seen_at' => null, 'user_id' => WU], ['id' => 'DEV00000000000000000000002', 'name' => 'B', 'platform' => 'x', 'last_seen_at' => null, 'user_id' => WU]];
    [$repo, $pc] = wxpc($two);
    $t = new PcReadFileTool($pc, $repo);
    expect(wxfails(fn () => $t->run(wxctx(), ['path' => 'a'])))->toContain('"device"');
    expect(wxfails(fn () => $t->run(wxctx(), ['path' => 'a', 'device' => 'Z'])))->toContain('"device"');
    expect($t->run(wxctx(), ['path' => 'a', 'device' => ' b ']))->toContain('id=');
});

it('pc tools turn a refused request into a clear failure', function () {
    [$repo, $pc] = wxpc();
    $repo->requestResult = ['status' => 'rejected_policy', 'reason' => 'emergency_stop', 'id' => null, 'risk' => 'low'];
    expect(wxfails(fn () => (new PcReadFileTool($pc, $repo))->run(wxctx(), ['path' => 'a'])))->not->toBe('');
    $repo->requestResult = ['status' => 'approved', 'reason' => null, 'id' => 'ACT00000000000000000000009', 'risk' => 'low'];
    expect((new PcReadFileTool($pc, $repo))->run(wxctx(), ['path' => 'a']))->toContain('approved');
});

it('pc tools check path and content before asking', function () {
    [$repo, $pc] = wxpc();
    $r = new PcReadFileTool($pc, $repo);
    $w = new PcWriteFileTool($pc, $repo);
    foreach (['', '   ', str_repeat('a', 521), "a\nb", "a\0b"] as $p) {
        expect(wxfails(fn () => $r->run(wxctx(), ['path' => $p])))->toBe('The file path is not valid.');
    }
    foreach (['', str_repeat('ক', 2501), "a\0b"] as $c) {
        expect(wxfails(fn () => $w->run(wxctx(), ['path' => 'a', 'content' => $c])))->toContain('Fileের Text');
    }
    expect($w->run(wxctx(), ['path' => 'a', 'content' => str_repeat('ক', 2500)]))->toContain('id=');
    expect($w->run(wxctx(), ['path' => str_repeat('a', 520), 'content' => 'x']))->toContain('id=');
});

it('pc.result finds the id inside text and reports status, reason and a cut result', function () {
    [$repo] = wxpc();
    $repo->actionRows = [['id' => 'ACT00000000000000000000001', 'status' => 'done', 'reason' => '', 'result' => str_repeat('ক', 3000)], ['id' => 'ACT00000000000000000000002', 'status' => 'rejected_policy', 'reason' => 'ask', 'result' => '']];
    $t = new PcResultTool($repo);
    $o = $t->run(wxctx(), ['id' => 'অনুরোধ জমা হয়েছে। id=ACT00000000000000000000001 অবস্থা=pending']);
    expect(mb_substr($o, 0, 12))->toBe('Status: done');
    expect(mb_strlen($o))->toBe(mb_strlen("অবস্থা: done\n") + 2000);
    expect($t->run(wxctx(), ['id' => 'x ACT00000000000000000000002']))->toBe('Status: rejected_policy (ask)');
    expect($t->run(wxctx(), ['id' => 'ACT00000000000000000000003']))->toContain('not_found');
    expect(wxfails(fn () => $t->run(wxctx(), ['id' => 'short'])))->toBe('The request id is not valid.');
});

// ---------- parallel: validation
function wxpar(array $over = [], array $extraNodes = []): array
{
    $nodes = [
        'p' => $over + ['type' => 'parallel', 'branches' => ['a1', 'b1'], 'join' => 'j'],
        'a1' => ['type' => 'ai', 'prompt' => 'এক', 'save_as' => 'ra', 'next' => 'j'],
        'b1' => ['type' => 'tool', 'tool' => 'fake.read', 'args' => ['text' => 'খ'], 'save_as' => 'rb', 'next' => 'j'],
        'j' => ['type' => 'ai', 'prompt' => '{{ra}} {{rb}}', 'save_as' => 'x', 'next' => 'o'],
        'o' => wout(),
    ];

    return wdef($extraNodes + $nodes, 'p');
}

it('accepts a parallel step with independent branches', function () {
    expect(WorkflowDefinition::validate(wxpar(), wreg()))->toBe([]);
    $d = wxpar(['branches' => ['a1', 'b1', 'c1', 'd1']], [
        'c1' => ['type' => 'ai', 'prompt' => 'গ', 'save_as' => 'rc', 'next' => 'c2'],
        'c2' => ['type' => 'ai', 'prompt' => 'ঘ', 'save_as' => 'rc2', 'next' => 'j'],
        'd1' => ['type' => 'tool', 'tool' => 'fake.write', 'args' => ['text' => 'ঙ'], 'next' => 'j'],
    ]);
    expect(WorkflowDefinition::validate($d, wreg()))->toBe([]);
});

it('rejects bad branch counts and a missing or wrong join', function () {
    expect(WorkflowDefinition::validate(wxpar(['branches' => ['a1']]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wxpar(['branches' => []]), wreg()) !== [])->toBeTrue();
    $more = [];
    foreach (['c', 'd', 'e'] as $x) {
        $more[$x.'1'] = ['type' => 'ai', 'prompt' => 'x', 'save_as' => 'v'.$x, 'next' => 'j'];
    }
    expect(WorkflowDefinition::validate(wxpar(['branches' => ['a1', 'b1', 'c1', 'd1']], array_slice($more, 0, 2, true)), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate(wxpar(['branches' => ['a1', 'b1', 'c1', 'd1', 'e1']], $more), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wxpar(['branches' => ['a1' => 'a1', 'b1' => 'b1']]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wxpar(['join' => 'nope']), wreg()) !== [])->toBeTrue();
    $d = wxpar();
    unset($d['nodes']['p']['join']);
    expect(WorkflowDefinition::validate($d, wreg()) !== [])->toBeTrue();
});

it('rejects a branch that is too long, never ends at the join, or shares a step', function () {
    $chain = [];
    for ($i = 1; $i <= 6; $i++) {
        $chain['c'.$i] = ['type' => 'ai', 'prompt' => 'x', 'save_as' => 'v'.$i, 'next' => $i === 6 ? 'j' : 'c'.($i + 1)];
    }
    expect(WorkflowDefinition::validate(wxpar(['branches' => ['a1', 'c1']], $chain), wreg()) !== [])->toBeTrue();
    $five = array_slice($chain, 0, 5, true);
    $five['c5']['next'] = 'j';
    expect(WorkflowDefinition::validate(wxpar(['branches' => ['a1', 'c1']], $five), wreg()))->toBe([]);
    $d = wxpar();
    $d['nodes']['b1']['next'] = 'o';                                   // runs past the join
    expect(WorkflowDefinition::validate($d, wreg()) !== [])->toBeTrue();
    $d = wxpar();
    unset($d['nodes']['b1']['next']);
    expect(WorkflowDefinition::validate($d, wreg()) !== [])->toBeTrue();
    $d = wxpar(['branches' => ['a1', 'a1']]);                          // both branches start at the same step
    unset($d['nodes']['a1']['save_as']);
    $d['nodes']['j']['prompt'] = 'x';
    expect(WorkflowDefinition::validate($d, wreg()) !== [])->toBeTrue();
    $d = wxpar(['branches' => ['b1', 'b1']]);                          // a tool step without save_as, reached twice
    unset($d['nodes']['b1']['save_as']);
    $d['nodes']['j']['prompt'] = 'x';
    expect(WorkflowDefinition::validate($d, wreg()) !== [])->toBeTrue();
    $d = wxpar(['branches' => ['b1', 'x2']], ['x2' => ['type' => 'tool', 'tool' => 'fake.read', 'args' => ['text' => 'y'], 'next' => 'b1']]);
    unset($d['nodes']['b1']['save_as']);
    $d['nodes']['j']['prompt'] = 'x';
    expect(WorkflowDefinition::validate($d, wreg()) !== [])->toBeTrue();
});

it('rejects forbidden step kinds inside a branch', function () {
    foreach ([
        ['type' => 'approval', 'message' => 'm', 'next' => 'j'],
        ['type' => 'delay', 'minutes' => 5, 'next' => 'j'],
        ['type' => 'condition', 'var' => 'v', 'op' => 'not_empty', 'then' => 'j', 'else' => 'j'],
        ['type' => 'output', 'text' => 'x'],
        ['type' => 'tool', 'tool' => 'fake.ext', 'args' => [], 'next' => 'j'],
        ['type' => 'tool', 'tool' => 'fake.pay', 'args' => [], 'next' => 'j'],
        ['type' => 'tool', 'tool' => 'unknown.tool', 'args' => [], 'next' => 'j'],
        ['type' => 'tool', 'tool' => 'fake.read', 'args' => [], 'each' => 'items', 'next' => 'j'],
    ] as $bad) {
        $d = wxpar();
        $d['nodes']['b1'] = $bad;
        expect(WorkflowDefinition::validate($d, wreg()) !== [])->toBeTrue();
    }
});

it('rejects an external tool in a branch even when an approval came first', function () {
    $d = wxpar();
    $d['start'] = 'ap';
    $d['nodes']['ap'] = ['type' => 'approval', 'message' => 'ok?', 'next' => 'p'];
    expect(WorkflowDefinition::validate($d, wreg()))->toBe([]);
    $d['nodes']['b1'] = ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'x'], 'save_as' => 'rb', 'next' => 'j'];
    expect(WorkflowDefinition::validate($d, wreg()) !== [])->toBeTrue();
});

it('rejects a nested parallel step, a join inside a branch and shared variable names', function () {
    $d = wxpar();
    $d['nodes']['b1'] = ['type' => 'parallel', 'branches' => ['a1', 'a1'], 'join' => 'j'];
    expect(WorkflowDefinition::validate($d, wreg()) !== [])->toBeTrue();
    $d2 = wxpar();
    $d2['nodes']['b1']['save_as'] = 'ra';                              // both branches save "ra"
    expect(WorkflowDefinition::validate($d2, wreg()) !== [])->toBeTrue();
    $d3 = wxpar();
    $d3['nodes']['b1']['args'] = ['text' => 'দেখুন {{ra}}'];           // reads what the sibling saves
    expect(WorkflowDefinition::validate($d3, wreg()) !== [])->toBeTrue();
    $d4 = wxpar();
    $d4['nodes']['b1']['args'] = ['text' => 'দেখুন {{earlier}}'];     // an input / earlier variable is fine
    expect(WorkflowDefinition::validate($d4, wreg()))->toBe([]);
    $d5 = wxpar();
    $d5['nodes']['a1']['prompt'] = 'শাখা {{ rb }}';                    // spaces inside braces still count
    expect(WorkflowDefinition::validate($d5, wreg()) !== [])->toBeTrue();
});

it('lets the join step read what the branches saved', function () {
    $d = wxpar();
    $d['nodes']['j']['prompt'] = '{{ra}} {{rb}}';
    expect(WorkflowDefinition::validate($d, wreg()))->toBe([]);
});

it('still needs an approval before an external tool that comes after the join', function () {
    $d = wxpar();
    $d['nodes']['j'] = ['type' => 'tool', 'tool' => 'fake.ext', 'args' => [], 'next' => 'o'];
    expect(WorkflowDefinition::validate($d, wreg()) !== [])->toBeTrue();
    $d['nodes']['j'] = ['type' => 'approval', 'message' => 'ঠিক?', 'next' => 'e'];
    $d['nodes']['e'] = ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'x'], 'next' => 'o'];
    expect(WorkflowDefinition::validate($d, wreg()))->toBe([]);
});

// ---------- parallel: running
it('runs every branch in order, merges the variables and goes on at the join', function () {
    $tool = new FakeTool('fake.read', Risk::READ, 'টুলফল');
    [$runner, $repo, $ai] = wrun(wxpar(), [], new FakeAiStep('ok', 'এআই'), wreg($tool));
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('done');
    expect(array_column($repo->steps, 'node_id'))->toBe(['a1', 'b1', 'j', 'o']);
    expect(array_column($repo->steps, 'seq'))->toBe([1, 2, 3, 4]);
    expect($ai->calls[1]['prompt'])->toBe('এআই টুলফল');
    expect(array_column($ai->calls, 'key'))->toBe(['R1-1', 'R1-3']);
    expect($tool->calls)->toBe([['text' => 'খ']]);
    expect($repo->runs['R1']['vars']['ra'])->toBe('এআই');
    expect($repo->runs['R1']['vars']['rb'])->toBe('টুলফল');
});

it('walks a branch of several steps before the next branch', function () {
    $d = wxpar(['branches' => ['a1', 'c1']], [
        'c1' => ['type' => 'ai', 'prompt' => 'গ', 'save_as' => 'rc', 'next' => 'c2'],
        'c2' => ['type' => 'ai', 'prompt' => 'ঘ {{rc}}', 'save_as' => 'rd', 'next' => 'j'],
    ]);
    $d['nodes']['j']['prompt'] = '{{ra}} {{rc}} {{rd}}';
    [$runner, $repo] = wrun($d);
    $runner->execute(WT, 'R1');
    expect(array_column($repo->steps, 'node_id'))->toBe(['a1', 'c1', 'c2', 'j', 'o']);
});

it('picks up after a restart without repeating a finished step', function () {
    [$runner, $repo, $ai] = wrun(wxpar(), [], new FakeAiStep('ok', 'এআই'));
    $tool = new FakeTool('fake.read', Risk::READ, 'টুলফল');
    // first pass dies right after the first branch step is saved
    $repo->beforeRecord = function ($r, $run, $node) {
        if ($node === 'b1') {
            throw new RuntimeException('crash');
        }
    };
    try {
        $runner->execute(WT, 'R1');
    } catch (RuntimeException) {
    }
    expect(array_column($repo->steps, 'node_id'))->toBe(['a1']);
    expect($repo->runs['R1']['vars']['__par:p'])->toBe('1|b1');
    $repo->beforeRecord = null;
    $repo->claim('R1');
    [$runner2] = [new App\Workflow\WorkflowRunner($repo, wreg($tool), $ai, new Tests\Support\FakeMobileRepo)];
    $runner2->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('done');
    expect(count(array_filter($ai->calls, fn ($c) => $c['prompt'] === 'এক')))->toBe(1);
    expect(array_column($repo->steps, 'node_id'))->toBe(['a1', 'b1', 'j', 'o']);
});

it('fails the whole run when a branch step fails, and stops there', function () {
    $tool = new FakeTool('fake.read', Risk::READ, '', new ToolException('টুল ভেঙেছে'));
    [$runner, $repo, $ai] = wrun(wxpar(), [], null, wreg($tool));
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');
    expect($repo->runs['R1']['error'] ?? '')->toContain('টুল ভেঙেছে');
    expect(count($ai->calls))->toBe(1);                                // join never ran
    $ai2 = new FakeAiStep('failed');
    [$runner, $repo] = wrun(wxpar(), [], $ai2);
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');
    expect(count($ai2->calls))->toBe(1);                               // second branch never started
});

it('shares one credit budget across branches', function () {
    [$runner, $repo, $ai] = wrun(wxpar(), [], new FakeAiStep('ok', 'এআই', '0.7000'), null, 2.0);
    $runner->execute(WT, 'R1');
    expect(array_column($ai->calls, 'budget'))->toBe([2.0, 1.3]);
    expect(round($repo->runs['R1']['spent'], 4))->toBe(1.4);
});

it('refuses a parallel step that was changed into something broken after saving', function () {
    $d = wxpar();
    $d['nodes']['p']['branches'] = ['a1'];
    [$runner, $repo] = wrun($d);
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');
    $d = wxpar();
    $d['nodes']['b1'] = ['type' => 'tool', 'tool' => 'fake.ext', 'args' => [], 'next' => 'j'];   // an external tool must never run in a branch
    $ext = new FakeTool('fake.ext', Risk::EXTERNAL);
    [$runner, $repo] = wrun($d, [], null, wreg($ext));
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');
    expect($ext->calls)->toBe([]);
});

it('never runs a looping tool step inside a branch', function () {
    $d = wxpar();
    $d['nodes']['b1']['each'] = 'items';
    $t = new FakeTool('fake.read');
    [$runner, $repo] = wrun($d, ['items' => 'a'], null, wreg($t));
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');
    expect($t->calls)->toBe([]);
});
