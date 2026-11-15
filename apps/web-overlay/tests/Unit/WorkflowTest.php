<?php

use App\Chat\CreditPricing;
use App\Workflow\CreditedAiStep;
use App\Workflow\Risk;
use App\Workflow\Templates;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;
use App\Workflow\ToolRegistry;
use App\Workflow\Tools\CalendarUpcomingTool;
use App\Workflow\Tools\ChatPostTool;
use App\Workflow\Tools\KnowledgeSearchTool;
use App\Workflow\Tools\NotesAddTool;
use App\Workflow\Tools\NotifyMeTool;
use App\Workflow\Tools\PlanTodayTool;
use App\Workflow\Tools\TasksAddTool;
use App\Workflow\Tools\TasksListTool;
use App\Workflow\Vars;
use App\Workflow\WorkflowDefinition;
use App\Workflow\WorkflowRunner;
use App\Workflow\WorkflowService;
use Tests\Support\FakeAiStep;
use Tests\Support\FakeIcsFetcher;
use Tests\Support\FakeLinkRepo;
use Tests\Support\FakePoster;
use Tests\Support\FakeSecrets;
use Tests\Support\FakeAssistEngine;
use Tests\Support\FakeKnowledgeContext;
use Tests\Support\FakeLedger;
use Tests\Support\FakeMobileRepo;
use Tests\Support\FakePicker;
use Tests\Support\FakeTaskRepo;
use Tests\Support\FakeTool;
use Tests\Support\FakeWorkflowRepo;
use Tests\Support\ProviderDown;

const WT = 'TENANT0000000000000000000A';
const WU = 'USER000000000000000000000A';
const WU2 = 'USER000000000000000000000B';

function wreg(?FakeTool ...$extra): ToolRegistry
{
    return new ToolRegistry(array_merge([new FakeTool('fake.read'), new FakeTool('fake.write', Risk::WRITE_OWN), new FakeTool('fake.ext', Risk::EXTERNAL), new FakeTool('fake.pay', Risk::MONEY)], $extra));
}

function wreal(): ToolRegistry
{
    $t = new FakeTaskRepo;

    return new ToolRegistry([new TasksAddTool($t), new TasksListTool($t), new PlanTodayTool($t), new NotesAddTool($t), new KnowledgeSearchTool(new FakeKnowledgeContext), new NotifyMeTool(new FakeMobileRepo),
        new CalendarUpcomingTool(new App\Calendar\CalendarService($l = new FakeLinkRepo, $sec = new FakeSecrets, new FakeIcsFetcher, fn ($k, $t, $m) => $m())), new ChatPostTool(new App\Links\ChatHooks($l, $sec, new FakePoster)),
        new App\Workflow\Tools\MarketingSnapshotTool(new Tests\Support\FakeReportRepo, new Tests\Support\FakeMarketingAccess), new App\Workflow\Tools\SeoLatestTool(new Tests\Support\FakeSeoRepo, new Tests\Support\FakeMarketingAccess),
        new App\Workflow\Tools\PcReadFileTool($pc = new App\Pc\PcService($pr = new Tests\Support\FakePcRepo), $pr), new App\Workflow\Tools\PcResultTool($pr), new App\Workflow\Tools\PcListDirTool($pc, $pr), new App\Workflow\Tools\WebhookSendTool(new Tests\Support\FakeWebhookRepo)]);
}

function wdef(array $nodes, string $start = 'a', array $extra = []): array
{
    return ['start' => $start, 'nodes' => $nodes] + $extra;
}

function wout(): array
{
    return ['type' => 'output', 'text' => '{{x}}'];
}

// ---------- variables
it('fills {{name}} with text, empty for unknown, json for arrays, and cuts long values', function () {
    expect(Vars::render('a {{x}} b {{ y }} c {{z}}', ['x' => 'এক', 'y' => 5]))->toBe('a এক b 5 c ');
    expect(Vars::render('{{x}}', ['x' => ['a' => 'ক']]))->toBe('{"a":"ক"}');
    expect(mb_strlen(Vars::render('{{x}}', ['x' => str_repeat('ক', 9000)])))->toBe(8000);
    expect(Vars::render('{{__output}} {{X}} {{1a}}', ['__output' => 'no']))->toBe('{{__output}} {{X}} {{1a}}');
});

it('only allows plain lowercase variable names', function () {
    expect(Vars::validName('plan_1'))->toBeTrue();
    expect(Vars::validName('Plan'))->toBeFalse();
    expect(Vars::validName('__approved:x'))->toBeFalse();
    expect(Vars::validName(''))->toBeFalse();
    expect(Vars::validName(str_repeat('a', 41)))->toBeFalse();
});

// ---------- definition checks
it('accepts every ready-made template', function () {
    foreach (Templates::all() as $k => $t) {
        expect([$k, WorkflowDefinition::validate($t['definition'], wreal())])->toBe([$k, []]);
        expect(isset(WorkflowService::CATEGORIES[$t['category']]))->toBeTrue();
        expect($t['max_credits'] > 0 && $t['max_credits'] <= 1000)->toBeTrue();
    }
});

it('accepts a simple valid workflow and rejects missing pieces', function () {
    $ok = wdef(['a' => ['type' => 'tool', 'tool' => 'fake.read', 'args' => ['text' => 'hi'], 'next' => 'b'], 'b' => wout()]);
    expect(WorkflowDefinition::validate($ok, wreg()))->toBe([]);
    expect(WorkflowDefinition::validate([], wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(['start' => 'a', 'nodes' => []], wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(['start' => 'a', 'nodes' => [['type' => 'output', 'text' => 'x']]], wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wdef(['a' => wout()], 'zzz'), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(['nodes' => ['a' => wout()]], wreg()) !== [])->toBeTrue();
});

it('limits node count and checks node ids', function () {
    $many = [];
    for ($i = 0; $i < 31; $i++) {
        $many['n'.$i] = wout();
    }
    expect(WorkflowDefinition::validate(wdef($many, 'n0'), wreg()) !== [])->toBeTrue();
    $many = array_slice($many, 0, 30, true);
    expect(WorkflowDefinition::validate(wdef($many, 'n0'), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate(wdef(['A-b' => wout()], 'A-b'), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wdef([str_repeat('a', 41) => wout()], str_repeat('a', 41)), wreg()) !== [])->toBeTrue();
});

it('checks ai nodes', function () {
    $ai = fn (array $o) => wdef(['a' => $o + ['type' => 'ai', 'prompt' => 'p', 'save_as' => 'r', 'next' => 'b'], 'b' => wout()]);
    expect(WorkflowDefinition::validate($ai([]), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate($ai(['prompt' => '']), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($ai(['prompt' => str_repeat('ক', 4001)]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($ai(['prompt' => str_repeat('ক', 4000)]), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate($ai(['save_as' => 'Bad']), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($ai(['max_tokens' => 99]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($ai(['max_tokens' => 1501]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($ai(['max_tokens' => 100]), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate($ai(['max_tokens' => 1500]), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate($ai(['max_tokens' => '500']), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($ai(['next' => 'nope']), wreg()) !== [])->toBeTrue();
});

it('checks tool nodes: known tool, required args, no unknown args', function () {
    $t = fn (array $o) => wdef(['a' => $o + ['type' => 'tool', 'tool' => 'fake.read', 'args' => ['text' => 'x'], 'next' => 'b'], 'b' => wout()]);
    expect(WorkflowDefinition::validate($t([]), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate($t(['tool' => 'nope.tool']), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($t(['args' => []]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($t(['args' => ['text' => '  ']]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($t(['args' => ['text' => 'x', 'more' => 'y']]), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate($t(['args' => ['text' => 'x', 'evil' => 'y']]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($t(['args' => ['text' => ['a']]]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($t(['args' => ['text' => str_repeat('a', 2001)]]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($t(['args' => 'x']), wreg()) !== [])->toBeTrue();
});

it('forbids money tools and requires an approval before external tools on every path', function () {
    $pay = wdef(['a' => ['type' => 'tool', 'tool' => 'fake.pay', 'args' => ['text' => 'x'], 'next' => 'b'], 'b' => wout()]);
    expect(WorkflowDefinition::validate($pay, wreg()) !== [])->toBeTrue();
    $ext = fn (array $nodes) => wdef($nodes);
    $direct = $ext(['a' => ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'x'], 'next' => 'b'], 'b' => wout()]);
    expect(WorkflowDefinition::validate($direct, wreg()) !== [])->toBeTrue();
    $guarded = $ext(['a' => ['type' => 'approval', 'message' => 'ঠিক আছে?', 'next' => 'b'], 'b' => ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'x'], 'next' => 'c'], 'c' => wout()]);
    expect(WorkflowDefinition::validate($guarded, wreg()))->toBe([]);
    // one branch skips the approval -> rejected
    $branch = $ext(['a' => ['type' => 'condition', 'var' => 'x', 'op' => 'empty', 'then' => 'ap', 'else' => 'e'], 'ap' => ['type' => 'approval', 'message' => 'ok?', 'next' => 'e'],
        'e' => ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'x'], 'next' => 'z'], 'z' => wout()]);
    expect(WorkflowDefinition::validate($branch, wreg()) !== [])->toBeTrue();
});

it('checks condition and approval nodes', function () {
    $c = fn (array $o) => wdef(['a' => $o + ['type' => 'condition', 'var' => 'x', 'op' => 'contains', 'value' => 'ক', 'then' => 'b', 'else' => 'b'], 'b' => wout()]);
    expect(WorkflowDefinition::validate($c([]), wreg()))->toBe([]);
    foreach (WorkflowDefinition::OPS as $op) {
        expect(WorkflowDefinition::validate($c(['op' => $op]), wreg()))->toBe([]);
    }
    expect(WorkflowDefinition::validate($c(['op' => 'regex']), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($c(['var' => 'Bad']), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($c(['then' => 'zz']), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($c(['else' => null]), wreg()) !== [])->toBeTrue();
    $ap = fn (array $o) => wdef(['a' => $o + ['type' => 'approval', 'message' => 'ok?', 'next' => 'b'], 'b' => wout()]);
    expect(WorkflowDefinition::validate($ap([]), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate($ap(['message' => ' ']), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate($ap(['message' => str_repeat('ক', 1001)]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wdef(['a' => ['type' => 'weird']]), wreg()) !== [])->toBeTrue();
});

it('rejects loops, even self loops', function () {
    $loop = wdef(['a' => ['type' => 'ai', 'prompt' => 'p', 'save_as' => 'r', 'next' => 'b'], 'b' => ['type' => 'condition', 'var' => 'r', 'op' => 'empty', 'then' => 'a', 'else' => 'c'], 'c' => wout()]);
    expect(WorkflowDefinition::validate($loop, wreg()) !== [])->toBeTrue();
    $self = wdef(['a' => ['type' => 'approval', 'message' => 'm', 'next' => 'a']]);
    expect(WorkflowDefinition::validate($self, wreg()) !== [])->toBeTrue();
});

it('checks declared inputs', function () {
    $n = ['a' => wout()];
    expect(WorkflowDefinition::validate(wdef($n, 'a', ['inputs' => [['key' => 'email', 'label' => 'Email']]]), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate(wdef($n, 'a', ['inputs' => [['key' => 'Email', 'label' => 'Email']]]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wdef($n, 'a', ['inputs' => [['key' => 'e', 'label' => '']]]), wreg()) !== [])->toBeTrue();
    $six = array_map(fn ($i) => ['key' => 'k'.$i, 'label' => 'L'], range(1, 6));
    expect(WorkflowDefinition::validate(wdef($n, 'a', ['inputs' => $six]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wdef($n, 'a', ['inputs' => array_slice($six, 0, 5)]), wreg()))->toBe([]);
});

// ---------- conditions
it('tests conditions', function () {
    expect(WorkflowRunner::test('empty', '  ', ''))->toBeTrue();
    expect(WorkflowRunner::test('empty', 'x', ''))->toBeFalse();
    expect(WorkflowRunner::test('not_empty', 'x', ''))->toBeTrue();
    expect(WorkflowRunner::test('contains', 'Hello World', 'world'))->toBeTrue();
    expect(WorkflowRunner::test('contains', 'Hello', ''))->toBeFalse();
    expect(WorkflowRunner::test('equals', ' Yes ', 'yes'))->toBeTrue();
    expect(WorkflowRunner::test('equals', 'a', 'b'))->toBeFalse();
    expect(WorkflowRunner::test('gt', '10', '9'))->toBeTrue();
    expect(WorkflowRunner::test('gt', '9', '10'))->toBeFalse();
    expect(WorkflowRunner::test('gt', 'abc', '1'))->toBeFalse();
    expect(WorkflowRunner::test('lt', '1', '2'))->toBeTrue();
    expect(WorkflowRunner::test('lt', '2', '2'))->toBeFalse();
    expect(WorkflowRunner::test('weird', 'a', 'a'))->toBeFalse();
    expect(WorkflowRunner::test('not_empty', ['x'], ''))->toBeFalse();
});

// ---------- runner
function wrun(array $def, array $vars = [], ?FakeAiStep $ai = null, ?ToolRegistry $reg = null, float $max = 5.0): array
{
    $repo = new FakeWorkflowRepo;
    $repo->wfs['W'] = ['id' => 'W', 'user' => WU, 'name' => 'Workflow', 'description' => '', 'category' => 'personal', 'definition' => $def, 'version' => 1, 'enabled' => true, 'schedule_time' => null, 'max_credits' => $max];
    expect($repo->createRun(WT, WU, 'R1', 'W', $vars))->toBe('ok');
    $repo->claim('R1');
    $mobile = new FakeMobileRepo;
    $ai ??= new FakeAiStep;
    $runner = new WorkflowRunner($repo, $reg ?? wreg(), $ai, $mobile);

    return [$runner, $repo, $ai, $mobile];
}

it('runs tool -> ai -> output, passes variables on and logs every step', function () {
    $def = wdef([
        'a' => ['type' => 'tool', 'tool' => 'fake.read', 'args' => ['text' => 'প্রশ্ন {{q}}'], 'save_as' => 'found', 'next' => 'b'],
        'b' => ['type' => 'ai', 'prompt' => 'উত্তর দিন: {{found}}', 'save_as' => 'x', 'max_tokens' => 300, 'next' => 'c'],
        'c' => wout()]);
    $tool = new FakeTool('fake.read', Risk::READ, 'টুলের ফল');
    [$runner, $repo, $ai] = wrun($def, ['q' => 'দাম?'], null, wreg($tool));
    // the registry above has its own fake.read first; ours replaces it (same key)
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('done');
    expect(array_column($repo->steps, 'node_id'))->toBe(['a', 'b', 'c']);
    expect($tool->calls)->toBe([['text' => 'প্রশ্ন দাম?']]);
    expect($ai->calls[0]['prompt'])->toBe('উত্তর দিন: টুলের ফল');
    expect($ai->calls[0]['max'])->toBe(300);
    expect($repo->runs['R1']['vars']['__output'])->toBe('AI Replies');
    expect(round($repo->runs['R1']['spent'], 4))->toBe(0.5);
    expect($repo->runs['R1']['current_node'])->toBeNull();
});

it('gives each ai step its own key and the remaining budget', function () {
    $def = wdef(['a' => ['type' => 'ai', 'prompt' => 'one', 'save_as' => 'x', 'next' => 'b'], 'b' => ['type' => 'ai', 'prompt' => 'two', 'save_as' => 'y', 'next' => 'c'], 'c' => wout()]);
    [$runner, $repo, $ai] = wrun($def, [], null, null, 2.0);
    $runner->execute(WT, 'R1');
    expect(array_column($ai->calls, 'key'))->toBe(['R1-1', 'R1-2']);
    expect(array_column($ai->calls, 'budget'))->toBe([2.0, 1.5]);
    expect($ai->calls[0]['max'])->toBe(600);
});

it('follows a condition both ways', function () {
    $def = wdef(['a' => ['type' => 'condition', 'var' => 'v', 'op' => 'not_empty', 'then' => 'yes', 'else' => 'no'], 'yes' => ['type' => 'output', 'text' => 'Y'], 'no' => ['type' => 'output', 'text' => 'N']]);
    [$r, $repo] = wrun($def, ['v' => 'x']);
    $r->execute(WT, 'R1');
    expect($repo->runs['R1']['vars']['__output'])->toBe('Y');
    [$r, $repo] = wrun($def, []);
    $r->execute(WT, 'R1');
    expect($repo->runs['R1']['vars']['__output'])->toBe('N');
});

it('stops at an approval, runs nothing after it, and carries on once approved', function () {
    $def = wdef(['a' => ['type' => 'approval', 'message' => 'যোগ করব: {{t}}', 'next' => 'b'], 'b' => ['type' => 'tool', 'tool' => 'fake.write', 'args' => ['text' => '{{t}}'], 'next' => 'c'], 'c' => wout()]);
    $w = new FakeTool('fake.write', Risk::WRITE_OWN);
    [$runner, $repo, , $mobile] = wrun($def, ['t' => 'Job'], null, wreg($w));
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('waiting_approval');
    expect($w->calls)->toBe([]);
    expect($repo->steps[0]['status'])->toBe('waiting');
    expect($repo->steps[0]['output'])->toBe('যোগ করব: কাজ');
    expect($mobile->notified[0]['title'])->toContain('Approval');
    expect($repo->runs['R1']['current_node'])->toBe('a');
    // someone else cannot approve; owner can
    expect($repo->decide(WT, WU2, 'R1', true))->toBe('not_found');
    expect($repo->decide(WT, WU, 'R1', true))->toBe('ok');
    expect($repo->decide(WT, WU, 'R1', true))->toBe('not_waiting');
    $repo->claim('R1');
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('done');
    expect($w->calls)->toBe([['text' => 'Job']]);
    expect(array_column($repo->steps, 'status'))->toBe(['waiting', 'ok', 'ok', 'ok']);
});

it('rejecting an approval cancels the run and nothing after it runs', function () {
    $def = wdef(['a' => ['type' => 'approval', 'message' => 'm', 'next' => 'b'], 'b' => ['type' => 'tool', 'tool' => 'fake.write', 'args' => ['text' => 'x'], 'next' => 'c'], 'c' => wout()]);
    $w = new FakeTool('fake.write', Risk::WRITE_OWN);
    [$runner, $repo] = wrun($def, [], null, wreg($w));
    $runner->execute(WT, 'R1');
    expect($repo->decide(WT, WU, 'R1', false))->toBe('ok');
    expect($repo->runs['R1']['status'])->toBe('cancelled');
    $runner->execute(WT, 'R1');   // a cancelled run is not picked up again
    expect($w->calls)->toBe([]);
});

it('fails the run when a tool throws, with its message, and tells the owner', function () {
    $def = wdef(['a' => ['type' => 'tool', 'tool' => 'fake.read', 'args' => ['text' => 'x'], 'next' => 'b'], 'b' => wout()]);
    [$runner, $repo, , $mobile] = wrun($def, [], null, wreg(new FakeTool('fake.read', Risk::READ, '', new ToolException('The search subject is blank.'))));
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');
    expect($repo->runs['R1']['error'])->toBe('The search subject is blank.');
    expect($repo->steps[0]['status'])->toBe('failed');
    expect($mobile->notified[0]['title'])->toContain('Failed');
});

it('does not leak an unexpected tool error into the log', function () {
    $def = wdef(['a' => ['type' => 'tool', 'tool' => 'fake.read', 'args' => ['text' => 'x'], 'next' => 'b'], 'b' => wout()]);
    [$runner, $repo] = wrun($def, [], null, wreg(new FakeTool('fake.read', Risk::READ, '', new RuntimeException('SQLSTATE secret-host'))));
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');
    expect(str_contains((string) $repo->runs['R1']['error'], 'secret'))->toBeFalse();
});

it('refuses a money tool and an unapproved external tool even if the stored definition has them', function () {
    foreach (['fake.pay' => 'টুল চালানো যাবে না', 'fake.ext' => 'অনুমোদন লাগে'] as $key => $why) {
        $def = wdef(['a' => ['type' => 'tool', 'tool' => $key, 'args' => ['text' => 'x'], 'next' => 'b'], 'b' => wout()]);
        $t = new FakeTool($key, $key === 'fake.pay' ? Risk::MONEY : Risk::EXTERNAL);
        [$runner, $repo] = wrun($def, [], null, wreg($t));
        $runner->execute(WT, 'R1');
        expect($repo->runs['R1']['status'])->toBe('failed');
        expect(str_contains((string) $repo->runs['R1']['error'], $why))->toBeTrue();
        expect($t->calls)->toBe([]);
    }
});

it('runs an external tool after an approval', function () {
    $def = wdef(['a' => ['type' => 'approval', 'message' => 'm', 'next' => 'b'], 'b' => ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'x'], 'next' => 'c'], 'c' => wout()]);
    $t = new FakeTool('fake.ext', Risk::EXTERNAL);
    [$runner, $repo] = wrun($def, [], null, wreg($t));
    $runner->execute(WT, 'R1');
    $repo->decide(WT, WU, 'R1', true);
    $repo->claim('R1');
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('done');
    expect(count($t->calls))->toBe(1);
});

it('fails with a clear message for every ai failure and keeps the spend', function () {
    foreach (['budget', 'no_model', 'no_credits', 'provider', 'unreadable', 'failed'] as $s) {
        $def = wdef(['a' => ['type' => 'ai', 'prompt' => 'p', 'save_as' => 'x', 'next' => 'b'], 'b' => wout()]);
        [$runner, $repo] = wrun($def, [], new FakeAiStep($s));
        $runner->execute(WT, 'R1');
        expect([$s, $repo->runs['R1']['status'], $repo->runs['R1']['error']])->toBe([$s, 'failed', WorkflowRunner::MSG[$s]]);
    }
});

it('stops when the run was cancelled in the middle', function () {
    $def = wdef(['a' => ['type' => 'ai', 'prompt' => 'p', 'save_as' => 'x', 'next' => 'b'], 'b' => ['type' => 'ai', 'prompt' => 'q', 'save_as' => 'y', 'next' => 'c'], 'c' => wout()]);
    [$runner, $repo, $ai] = wrun($def);
    $repo->beforeRecord = function (FakeWorkflowRepo $r, string $run, string $node) {
        if ($node === 'b') {
            $r->cancel(WT, WU, $run);
        }
    };
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('cancelled');
    expect(count($ai->calls))->toBe(2);          // the second answer was paid for (it was already running) ...
    expect(array_column($repo->steps, 'node_id'))->toBe(['a']);   // ... but nothing was recorded or run after the cancel
});

it('fails a stored definition with a loop after the step cap instead of running forever', function () {
    $def = wdef(['a' => ['type' => 'condition', 'var' => 'x', 'op' => 'empty', 'then' => 'a', 'else' => 'a']]);
    [$runner, $repo] = wrun($def);
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');
    expect(count($repo->steps))->toBe(WorkflowRunner::MAX_STEPS + 1);
});

it('fails a run that points at a missing step, and ignores runs that are not running', function () {
    [$runner, $repo] = wrun(wdef(['a' => ['type' => 'approval', 'message' => 'm', 'next' => 'ghost'], 'ghost' => wout()]));
    $repo->runs['R1']['current_node'] = 'zzz';
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');
    [$runner, $repo, $ai] = wrun(wdef(['a' => ['type' => 'ai', 'prompt' => 'p', 'save_as' => 'x', 'next' => 'b'], 'b' => wout()]));
    $repo->runs['R1']['status'] = 'queued';
    $runner->execute(WT, 'R1');
    expect($ai->calls)->toBe([]);
    $runner->execute(WT, 'NOPE');
    expect(true)->toBeTrue();
});

// ---------- the credit-charging ai step
function wstep(?FakeAssistEngine $e = null, ?FakeLedger $l = null, ?FakePicker $p = null): array
{
    $e ??= new FakeAssistEngine(['text' => ' উত্তর ', 'input_tokens' => 1000, 'output_tokens' => 200, 'model' => 'm']);
    $l ??= new FakeLedger('100.0000');

    return [new CreditedAiStep($p ?? new FakePicker, $e, $l, new CreditPricing('1', '3')), $e, $l];
}

it('charges the real cost and ends the hold with exactly one settle', function () {
    [$s, $e, $l] = wstep();
    $r = $s->run(WT, WU, 'R1-1', 'Job', 500, 5.0);
    expect($r['status'])->toBe('ok');
    expect($r['text'])->toBe('Replies');
    expect((float) $r['credits'])->toBe((float) (new CreditPricing('1', '3'))->cost(1000, 200, 1.0));
    expect(count($l->settleKeys))->toBe(1);
    expect($l->releaseKeys)->toBe([]);
    expect($l->usages[0]['source'])->toBe('agent');
    expect($l->openReservations())->toBe(0);
    expect($e->seen[0]['max'])->toBe(500);
    expect($e->seen[0]['messages'][0]['content'])->toBe(CreditedAiStep::SYSTEM);
    expect($e->seen[0]['messages'][1]['content'])->toBe('Job');
});

it('does not start when the worst-case price is above the budget left', function () {
    [$s, $e, $l] = wstep();
    $r = $s->run(WT, WU, 'R1-1', 'Job', 500, 0.001);
    expect($r['status'])->toBe('budget');
    expect($e->calls)->toBe(0);
    expect($l->reserveKeys)->toBe([]);
});

it('releases the hold when there is no model answer and retries a provider error once', function () {
    [$s, $e, $l] = wstep(new FakeAssistEngine(ProviderDown::make()));
    $r = $s->run(WT, WU, 'R1-1', 'Job', 500, 5.0);
    expect($r['status'])->toBe('provider');
    expect($e->calls)->toBe(2);
    expect(count($l->releaseKeys))->toBe(2);
    expect($l->settleKeys)->toBe([]);
    expect(count(array_unique($l->reserveKeys)))->toBe(2);
    expect($l->openReservations())->toBe(0);
    [$s, $e, $l] = wstep(new FakeAssistEngine(new RuntimeException('x')));
    expect($s->run(WT, WU, 'R1-2', 'Job', 500, 5.0)['status'])->toBe('failed');
    expect($e->calls)->toBe(1);
    expect($l->openReservations())->toBe(0);
});

it('reports no model and no credits without calling the provider', function () {
    [$s, $e] = wstep(null, null, new FakePicker(null));
    expect($s->run(WT, WU, 'k', 'p', 500, 5.0)['status'])->toBe('no_model');
    [$s, $e] = wstep(null, new FakeLedger('0.0000'));
    expect($s->run(WT, WU, 'k', 'p', 500, 5.0)['status'])->toBe('no_credits');
    expect($e->calls)->toBe(0);
});

it('still charges for an empty answer and survives a settle failure', function () {
    [$s, , $l] = wstep(new FakeAssistEngine(['text' => '  ', 'input_tokens' => 500, 'output_tokens' => 0, 'model' => 'm']));
    $r = $s->run(WT, WU, 'k', 'p', 500, 5.0);
    expect($r['status'])->toBe('unreadable');
    expect((float) $r['credits'] > 0)->toBeTrue();
    expect(count($l->settleKeys))->toBe(1);
    [$s, , $l] = wstep();
    $l->failSettle = true;
    expect($s->run(WT, WU, 'k', 'p', 500, 5.0)['status'])->toBe('ok');
});

it('caps output by the model maximum', function () {
    [$s, $e] = wstep(null, null, new FakePicker(['id' => 'm', 'provider_id' => 'p', 'model_key' => 'k', 'name' => 'n', 'max_output' => 250, 'context_window' => 8000, 'credit_multiplier' => 1.0]));
    $s->run(WT, WU, 'k', 'p', 900, 5.0);
    expect($e->seen[0]['max'])->toBe(250);
});

// ---------- tools
it('adds a task, lists open tasks, builds today\'s plan and adds a note for the right person', function () {
    $t = new FakeTaskRepo;
    $c = new ToolContext(WT, WU, 'Asia/Dhaka');
    expect((new TasksAddTool($t))->run($c, ['title' => '  ক   খ ', 'due' => '2026-11-10']))->toContain('ক খ');
    expect($t->tasks[0]['user'])->toBe(WU);
    expect($t->tasks[0]['due_on'])->toBe('2026-11-10');
    expect(fn () => (new TasksAddTool($t))->run($c, ['title' => '']))->toThrow(ToolException::class);
    expect(fn () => (new TasksAddTool($t))->run($c, ['title' => 'x', 'due' => '2026-13-45']))->toThrow(ToolException::class);
    $t->addResult = 'limit';
    expect(fn () => (new TasksAddTool($t))->run($c, ['title' => 'y']))->toThrow(ToolException::class);
    $t->addResult = 'ok';
    (new TasksAddTool($t))->run(new ToolContext(WT, WU2, 'Asia/Dhaka'), ['title' => 'অন্যের']);
    expect((new TasksListTool($t))->run($c, []))->toContain('ক খ');
    expect(str_contains((new TasksListTool($t))->run($c, []), 'অন্যের'))->toBeFalse();
    expect((new TasksListTool(new FakeTaskRepo))->run($c, []))->toContain('None');
    expect((new PlanTodayTool(new FakeTaskRepo))->run($c, []))->toContain('জরুরি কিছু নেই');
    expect((new NotesAddTool($t))->run($c, ['title' => 'শি', 'body' => 'Text']))->toContain('শি');
    expect($t->notes[0]['user'])->toBe(WU);
});

it('searches the knowledge base and notifies only the owner', function () {
    $kb = new FakeKnowledgeContext;
    $c = new ToolContext(WT, WU);
    expect((new KnowledgeSearchTool($kb))->run($c, ['query' => 'Price']))->toBe('DOCS-BLOCK');
    expect($kb->questions)->toBe(['Price']);
    expect(fn () => (new KnowledgeSearchTool($kb))->run($c, ['query' => ' ']))->toThrow(ToolException::class);
    expect((new KnowledgeSearchTool(new FakeKnowledgeContext(['text' => null, 'sources' => []])))->run($c, ['query' => 'x']))->toContain('মিল পাওয়া যায়নি');
    $m = new FakeMobileRepo;
    expect((new NotifyMeTool($m))->run($c, ['title' => 'Title', 'body' => 'Message']))->toContain('Sent');
    expect($m->notified[0])->toBe(['user' => WU, 'kind' => 'workflow', 'title' => 'Title']);
    expect(fn () => (new NotifyMeTool($m))->run($c, ['title' => ' ']))->toThrow(ToolException::class);
});

it('only chat.post and webhook.send leave the app, and nothing moves money', function () {
    foreach (wreal()->all() as $k => $t) {
        expect(in_array($t->risk(), in_array($k, ['chat.post', 'webhook.send'], true) ? [Risk::EXTERNAL] : [Risk::READ, Risk::WRITE_OWN], true))->toBeTrue();
    }
});

// ---------- service
function wsvc(?FakeWorkflowRepo $r = null, ?ToolRegistry $reg = null): array
{
    $r ??= new FakeWorkflowRepo;

    return [new WorkflowService($r, $reg ?? wreal()), $r];
}

function wjson(array $d): string
{
    return json_encode($d, JSON_UNESCAPED_UNICODE);
}

it('copies a template into the person\'s own list', function () {
    [$s, $r] = wsvc();
    $x = $s->fromTemplate(WT, WU, 'morning_plan');
    expect($x['status'])->toBe('ok');
    expect($r->wfs[$x['id']]['user'])->toBe(WU);
    expect($r->wfs[$x['id']]['schedule_time'])->toBe('08:00');
    expect($s->fromTemplate(WT, WU, 'nope')['status'])->toBe('no_template');
    expect(count($r->workflows(WU2)))->toBe(0);
});

it('validates before saving and reports the reason', function () {
    [$s, $r] = wsvc();
    $good = wjson(Templates::get('email_reply')['definition']);
    $new = $s->save(WT, WU, null, 'Name', '', 'personal', $good, true, '', '');
    expect($new['status'])->toBe('ok');
    expect($r->wfs[$new['id']]['max_credits'])->toBe(5.0);
    expect($s->save(WT, WU, null, 'Name', '', 'personal', '{bad', true, '', '')['status'])->toBe('bad_json');
    expect($s->save(WT, WU, null, 'Name', '', 'personal', '"str"', true, '', '')['status'])->toBe('bad_json');
    expect($s->save(WT, WU, null, 'Name', '', 'personal', $good, true, '25:00', '')['status'])->toBe('bad_time');
    expect($s->save(WT, WU, null, 'Name', '', 'personal', $good, true, '8:00', '')['status'])->toBe('bad_time');
    expect($s->save(WT, WU, null, 'Name', '', 'personal', $good, true, '23:59', '')['status'])->toBe('ok');
    expect($s->save(WT, WU, null, 'Name', '', 'personal', $good, true, '', 'abc')['status'])->toBe('bad_budget');
    expect($s->save(WT, WU, null, 'Name', '', 'personal', $good, true, '', '1001')['status'])->toBe('bad_budget');
    expect($s->save(WT, WU, null, 'Name', '', 'personal', $good, true, '', '-1')['status'])->toBe('bad_budget');
    expect($s->save(WT, WU, null, 'Name', '', 'personal', $good, true, '', '1000')['status'])->toBe('ok');
    expect($s->save(WT, WU, null, 'Name', '', 'weird', $good, true, '', '')['status'])->toBe('bad_input');
    $bad = $s->save(WT, WU, null, 'Name', '', 'personal', wjson(wdef(['a' => ['type' => 'tool', 'tool' => 'nope', 'args' => [], 'next' => 'b'], 'b' => wout()])), true, '', '');
    expect($bad['status'])->toBe('invalid');
    expect($bad['errors'] !== [])->toBeTrue();
});

it('edits only the owner\'s workflow', function () {
    [$s, $r] = wsvc();
    $id = $s->fromTemplate(WT, WU, 'email_reply')['id'];
    $json = wjson($r->wfs[$id]['definition']);
    expect($s->save(WT, WU2, $id, 'x', '', 'personal', $json, true, '', '')['status'])->toBe('not_found');
    expect($s->save(WT, WU, $id, 'নতুন নাম', '', 'personal', $json, true, '', '')['status'])->toBe('ok');
    expect($r->wfs[$id]['name'])->toBe('নতুন নাম');
    expect($r->wfs[$id]['version'])->toBe(1);
    expect($s->setEnabled(WT, WU, $id, false))->toBe('ok');
    expect($r->wfs[$id]['enabled'])->toBeFalse();
    expect($s->setEnabled(WT, WU2, $id, true))->toBe('not_found');
    expect($s->delete(WT, WU2, $id))->toBe('not_found');
    expect($s->delete(WT, WU, $id))->toBe('ok');
});

it('starts a run only with the declared inputs, cleaned and limited', function () {
    [$s, $r] = wsvc();
    $id = $s->fromTemplate(WT, WU, 'email_reply')['id'];
    expect($s->start(WT, WU, $id, [])['status'])->toBe('missing_input');
    expect($s->start(WT, WU, $id, ['email' => '   '])['status'])->toBe('missing_input');
    expect($s->start(WT, WU, $id, ['email' => str_repeat('ক', 5001)])['status'])->toBe('too_long');
    expect($s->start(WT, WU2, $id, ['email' => 'x'])['status'])->toBe('not_found');
    $ok = $s->start(WT, WU, $id, ['email' => "  হ্যালো\x00 \n", 'extra' => 'ignored']);
    expect($ok['status'])->toBe('ok');
    expect($r->runs[$ok['run_id']]['vars'])->toBe(['email' => 'হ্যালো']);
    expect($s->start(WT, WU, $id, ['email' => 'again'])['status'])->toBe('busy');
    $s->setEnabled(WT, WU, $id, false);
    $r->runs[$ok['run_id']]['status'] = 'done';
    expect($s->start(WT, WU, $id, ['email' => 'x'])['status'])->toBe('disabled');
    expect(strlen(WorkflowService::message('busy')) > 0)->toBeTrue();
    expect(WorkflowService::message('zzz'))->toBe('Could not be done.');
});

it('lets only the owner decide or cancel a run', function () {
    [$s, $r] = wsvc();
    $id = $s->fromTemplate(WT, WU, 'idea_to_task')['id'];
    $run = $s->start(WT, WU, $id, ['idea' => 'x'])['run_id'];
    expect($s->cancel(WT, WU2, $run))->toBe('not_found');
    expect($s->decide(WT, WU, $run, true))->toBe('not_waiting');
    expect($s->cancel(WT, WU, $run))->toBe('ok');
    expect($s->cancel(WT, WU, $run))->toBe('finished');
});

it('runs the approval template end to end with the real tools', function () {
    $tasks = new FakeTaskRepo;
    $reg = new ToolRegistry([new TasksAddTool($tasks)]);
    $repo = new FakeWorkflowRepo;
    $svc = new WorkflowService($repo, $reg);
    $id = $svc->fromTemplate(WT, WU, 'idea_to_task')['id'];
    $run = $svc->start(WT, WU, $id, ['idea' => 'ক্যাটালগ হালনাগাদ'])['run_id'];
    $repo->claim($run);
    $runner = new WorkflowRunner($repo, $reg, new FakeAiStep('ok', 'ক্যাটালগ হালনাগাদ করা'), new FakeMobileRepo);
    $runner->execute(WT, $run);
    expect($repo->runs[$run]['status'])->toBe('waiting_approval');
    expect($tasks->tasks)->toBe([]);
    $svc->decide(WT, WU, $run, true);
    $repo->claim($run);
    $runner->execute(WT, $run);
    expect($repo->runs[$run]['status'])->toBe('done');
    expect($tasks->tasks[0]['title'])->toBe('ক্যাটালগ হালনাগাদ করা');
    expect($tasks->tasks[0]['user'])->toBe(WU);
});

// ---------- extra edges
it('keeps ai max_tokens inside the limits even if the stored definition is out of range', function () {
    foreach ([5000 => 1500, 10 => 100] as $given => $used) {
        $def = wdef(['a' => ['type' => 'ai', 'prompt' => 'p', 'save_as' => 'x', 'max_tokens' => $given, 'next' => 'b'], 'b' => wout()]);
        [$runner, , $ai] = wrun($def);
        $runner->execute(WT, 'R1');
        expect($ai->calls[0]['max'])->toBe($used);
    }
});

it('contains and gt need a real match', function () {
    expect(WorkflowRunner::test('contains', 'Hello', 'world'))->toBeFalse();
    expect(WorkflowRunner::test('gt', '5', '5'))->toBeFalse();
});

it('keeps long values and outputs short', function () {
    [$s, , $l] = wstep(new FakeAssistEngine(['text' => str_repeat('ক', 9000), 'input_tokens' => 10, 'output_tokens' => 10, 'model' => 'm']));
    expect(mb_strlen($s->run(WT, WU, 'k', 'p', 500, 5.0)['text']))->toBe(8000);
    expect(str_starts_with($l->settleKeys[0], 'agent-settle:k:a1'))->toBeTrue();
    [$s, , $l] = wstep(new FakeAssistEngine(ProviderDown::make()));
    $s->run(WT, WU, 'k', 'p', 500, 5.0);
    expect($l->releaseKeys)->toBe(['agent-release:k:a1', 'agent-release:k:a2']);
    [$s, , $l] = wstep(new FakeAssistEngine(['text' => 'x', 'input_tokens' => -50, 'output_tokens' => 0, 'model' => 'm']));
    expect((float) $s->run(WT, WU, 'k', 'p', 500, 5.0)['credits'])->toBe((float) (new CreditPricing('1', '3'))->cost(0, 0, 1.0));
});

it('checks output text length, ai save_as and input labels', function () {
    expect(WorkflowDefinition::validate(wdef(['a' => ['type' => 'output', 'text' => str_repeat('ক', 4001)]]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wdef(['a' => ['type' => 'output', 'text' => str_repeat('ক', 4000)]]), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate(wdef(['a' => ['type' => 'ai', 'prompt' => 'p', 'next' => 'b'], 'b' => wout()]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wdef(['a' => wout()], 'a', ['inputs' => [['key' => 'e', 'label' => str_repeat('ক', 61)]]]), wreg()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wdef(['a' => wout()], 'a', ['inputs' => [['key' => 'e', 'label' => str_repeat('ক', 60)]]]), wreg()))->toBe([]);
});

it('lists only open tasks, at most 40, and reports a failed note', function () {
    $t = new FakeTaskRepo;
    $c = new ToolContext(WT, WU);
    (new TasksAddTool($t))->run($c, ['title' => 'Open']);
    $t->tasks[] = ['id' => 'x', 'user' => WU, 'title' => 'শেষ-হওয়া', 'details' => '', 'priority' => 2, 'due_on' => null, 'remind_at' => null, 'reminded' => false, 'status' => 'done', 'done_at' => 'x'];
    $out = (new TasksListTool($t))->run($c, []);
    expect(str_contains($out, 'শেষ-হওয়া'))->toBeFalse();
    for ($i = 0; $i < 50; $i++) {
        (new TasksAddTool($t))->run($c, ['title' => 'Job'.$i]);
    }
    expect(count(explode("\n", (new TasksListTool($t))->run($c, []))))->toBe(40);
    $t->noteResult = 'limit';
    expect(fn () => (new NotesAddTool($t))->run($c, ['body' => 'x']))->toThrow(ToolException::class);
    $m = new FakeMobileRepo;
    (new NotifyMeTool($m))->run($c, ['title' => str_repeat('ক', 300)]);
    expect(mb_strlen($m->notified[0]['title']))->toBe(160);
});

it('a workflow that posts to a chat channel is only valid after an approval step', function () {
    $post = ['type' => 'tool', 'tool' => 'chat.post', 'args' => ['to' => 'টিম', 'text' => '{{x}}'], 'next' => 'o'];
    expect(WorkflowDefinition::validate(wdef(['a' => $post, 'o' => wout()]), wreal()) !== [])->toBeTrue();
    expect(WorkflowDefinition::validate(wdef(['g' => ['type' => 'approval', 'message' => 'পাঠাব?', 'next' => 'a'], 'a' => $post, 'o' => wout()], 'g'), wreal()))->toBe([]);
    expect(wreal()->get('chat.post')->risk())->toBe(Risk::EXTERNAL);
    expect(wreal()->get('calendar.upcoming')->risk())->toBe(Risk::READ);
});

// ---------- delay step and per-line repeat (phase 21b)
function wdelayDef(mixed $minutes = 30): array
{
    return wdef(['a' => ['type' => 'tool', 'tool' => 'fake.read', 'args' => ['text' => 'First'], 'save_as' => 'x', 'next' => 'd'], 'd' => ['type' => 'delay', 'minutes' => $minutes, 'next' => 'o'], 'o' => ['type' => 'output', 'text' => 'শেষ {{x}}']]);
}

it('accepts a delay step of 1 to 1440 minutes and nothing else', function () {
    expect(WorkflowDefinition::validate(wdelayDef(1), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate(wdelayDef(1440), wreg()))->toBe([]);
    foreach ([0, -5, 1441, '30', 30.5, null] as $bad) {
        expect([$bad, count(WorkflowDefinition::validate(wdelayDef($bad), wreg())) > 0])->toBe([$bad, true]);
    }
    $d = wdelayDef();
    unset($d['nodes']['d']['next']);
    expect(count(WorkflowDefinition::validate($d, wreg())) > 0)->toBeTrue();
});

it('allows at most three delay steps in one workflow', function () {
    $mk = fn (int $n) => wdef(array_merge(
        array_combine(array_map(fn ($i) => "d$i", range(1, $n)), array_map(fn ($i) => ['type' => 'delay', 'minutes' => 5, 'next' => $i === $n ? 'o' : 'd'.($i + 1)], range(1, $n))),
        ['o' => ['type' => 'output', 'text' => 'x']]), 'd1');
    expect(WorkflowDefinition::validate($mk(3), wreg()))->toBe([]);
    expect(count(WorkflowDefinition::validate($mk(4), wreg())) > 0)->toBeTrue();
});

it('a delay does not clear the approval an external tool needs, and does not hide one that is missing', function () {
    $nodes = fn (bool $approve) => wdef(['q' => $approve ? ['type' => 'approval', 'message' => 'ঠিক?', 'next' => 'd'] : ['type' => 'delay', 'minutes' => 5, 'next' => 'd'],
        'd' => ['type' => 'delay', 'minutes' => 5, 'next' => 'e'], 'e' => ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'hi'], 'next' => 'o'], 'o' => wout()], 'q');
    expect(WorkflowDefinition::validate($nodes(true), wreg()))->toBe([]);
    expect(count(WorkflowDefinition::validate($nodes(false), wreg())) > 0)->toBeTrue();
});

it('parks a run at a delay step and carries on from the next step when it is picked up again', function () {
    [$runner, $repo] = wrun(wdelayDef(45));
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('queued');
    expect($repo->runs['R1']['current_node'])->toBe('o');
    expect($repo->parked)->toBe(['R1' => 45]);
    expect(array_column($repo->steps, 'node_id'))->toBe(['a', 'd']);
    expect($repo->steps[1]['node_type'])->toBe('delay');
    $runner->execute(WT, 'R1');                                  // not claimed again yet: still queued, nothing happens
    expect(count($repo->steps))->toBe(2);
    $repo->claim('R1');
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('done');
    expect($repo->runs['R1']['vars']['__output'])->toBe('শেষ টুলের ফল');           // variables from before the wait are still there
    expect(array_column($repo->steps, 'node_id'))->toBe(['a', 'd', 'o']);
});

it('fails a stored definition whose delay time is not valid, instead of waiting forever', function () {
    foreach ([0, 1441, 'x'] as $bad) {
        [$runner, $repo] = wrun(wdelayDef($bad));
        $runner->execute(WT, 'R1');
        expect([$bad, $repo->runs['R1']['status']])->toBe([$bad, 'failed']);
        expect($repo->parked)->toBe([]);
    }
});

it('a run cancelled while it waits is not woken up', function () {
    [$runner, $repo] = wrun(wdelayDef(10));
    $runner->execute(WT, 'R1');
    $repo->runs['R1']['status'] = 'cancelled';
    $repo->claim('R1');                                          // (a worker must never claim it: here we only prove execute() refuses a non-running run)
    $repo->runs['R1']['status'] = 'cancelled';
    $runner->execute(WT, 'R1');
    expect(count($repo->steps))->toBe(2);
});

function weachDef(array $node = []): array
{
    return wdef(['a' => array_merge(['type' => 'tool', 'tool' => 'fake.write', 'args' => ['text' => '{{index}}: {{item}}'], 'each' => 'names', 'next' => 'o'], $node), 'o' => ['type' => 'output', 'text' => 'ok']]);
}

it('validates the per-line repeat: a variable name, 1 to 10 items, and never an outside-world tool', function () {
    expect(WorkflowDefinition::validate(weachDef(), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate(weachDef(['max_items' => 1]), wreg()))->toBe([]);
    expect(WorkflowDefinition::validate(weachDef(['max_items' => 10]), wreg()))->toBe([]);
    foreach ([['max_items' => 0], ['max_items' => 11], ['max_items' => '5'], ['each' => 'Bad Name'], ['each' => 5], ['each' => '__x']] as $bad) {
        expect([$bad, count(WorkflowDefinition::validate(weachDef($bad), wreg())) > 0])->toBe([$bad, true]);
    }
    $noEach = weachDef();
    unset($noEach['nodes']['a']['each']);
    $noEach['nodes']['a']['max_items'] = 3;
    expect(count(WorkflowDefinition::validate($noEach, wreg())) > 0)->toBeTrue();                  // max_items without each
    $ext = wdef(['q' => ['type' => 'approval', 'message' => 'ঠিক?', 'next' => 'a'], 'a' => ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'x'], 'each' => 'names', 'next' => 'o'], 'o' => wout()], 'q');
    expect(count(WorkflowDefinition::validate($ext, wreg())) > 0)->toBeTrue();                     // even after an approval
    $read = weachDef();
    $read['nodes']['a']['tool'] = 'fake.read';
    expect(WorkflowDefinition::validate($read, wreg()))->toBe([]);
});

it('runs the tool once per non-empty line with {{item}} and {{index}}, and joins the results', function () {
    $tool = new FakeTool('fake.write', Risk::WRITE_OWN, 'OK');
    $def = weachDef(['save_as' => 'res']);
    [$runner, $repo] = wrun($def, ['names' => "রহিম\n\n  করিম  \r\nসালমা\n"], null, wreg($tool));
    $runner->execute(WT, 'R1');
    expect($tool->calls)->toBe([['text' => '1: রহিম'], ['text' => '2: করিম'], ['text' => '3: সালমা']]);
    expect($repo->runs['R1']['vars']['res'])->toBe("OK\n OK\n OK");
    expect(array_key_exists('item', $repo->runs['R1']['vars']))->toBeFalse();       // the loop variables never leak into the run
    expect(count(array_filter($repo->steps, fn ($s) => $s['node_id'] === 'a')))->toBe(1);
    expect($repo->runs['R1']['status'])->toBe('done');
});

it('stops the per-line repeat at 10 lines, or at max_items, and survives an empty variable', function () {
    $tool = new FakeTool('fake.write', Risk::WRITE_OWN);
    [$runner] = wrun(weachDef(), ['names' => implode("\n", range(1, 25))], null, wreg($tool));
    $runner->execute(WT, 'R1');
    expect(count($tool->calls))->toBe(10);
    $t2 = new FakeTool('fake.write', Risk::WRITE_OWN);
    [$r2] = wrun(weachDef(['max_items' => 3]), ['names' => implode("\n", range(1, 25))], null, wreg($t2));
    $r2->execute(WT, 'R1');
    expect(count($t2->calls))->toBe(3);
    $t4 = new FakeTool('fake.write', Risk::WRITE_OWN);                                              // a stored definition is never trusted: 50 still means 10, 0 still means 1
    [$r4] = wrun(weachDef(['max_items' => 50]), ['names' => implode("\n", range(1, 25))], null, wreg($t4));
    $r4->execute(WT, 'R1');
    expect(count($t4->calls))->toBe(10);
    $t5 = new FakeTool('fake.write', Risk::WRITE_OWN);
    [$r5] = wrun(weachDef(['max_items' => 0]), ['names' => implode("\n", range(1, 25))], null, wreg($t5));
    $r5->execute(WT, 'R1');
    expect(count($t5->calls))->toBe(1);
    $t3 = new FakeTool('fake.write', Risk::WRITE_OWN);
    [$r3, $repo3] = wrun(weachDef(['save_as' => 'res']), ['names' => " \n "], null, wreg($t3));
    $r3->execute(WT, 'R1');
    expect($t3->calls)->toBe([]);
    expect($repo3->runs['R1']['vars']['res'])->toBe('(No items)');
    expect($repo3->runs['R1']['status'])->toBe('done');
});

it('stops the whole step at the first line that fails and says which line', function () {
    $tool = new FakeTool('fake.write', Risk::WRITE_OWN, 'OK', new ToolException('ঠিকানা ভুল'));
    [$runner, $repo, , $mobile] = wrun(weachDef(), ['names' => "এক\nদুই"], null, wreg($tool));
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');
    expect($repo->runs['R1']['error'])->toBe('লাইন 1: ঠিকানা ভুল');
    expect(count($tool->calls))->toBe(1);
});

it('refuses a per-line repeat of an outside-world tool even if the stored definition has one', function () {
    $tool = new FakeTool('fake.ext', Risk::EXTERNAL);
    $def = wdef(['q' => ['type' => 'approval', 'message' => 'ঠিক?', 'next' => 'a'], 'a' => ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => '{{item}}'], 'each' => 'names', 'next' => 'o'], 'o' => wout()], 'q');
    [$runner, $repo] = wrun($def, ['names' => "এক\nদুই", '__approved:q' => true], null, wreg($tool));
    $runner->execute(WT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');
    expect($tool->calls)->toBe([]);
});
