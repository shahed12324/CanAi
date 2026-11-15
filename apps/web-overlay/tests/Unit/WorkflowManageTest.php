<?php

use App\Workflow\Risk;
use App\Workflow\Templates;
use App\Workflow\ToolRegistry;
use App\Workflow\WorkflowCatalog;
use App\Workflow\WorkflowDefinition;
use App\Workflow\WorkflowRunner;
use App\Workflow\WorkflowService;
use Tests\Support\FakeAiStep;
use Tests\Support\FakeMobileRepo;
use Tests\Support\FakeTool;
use Tests\Support\FakeWorkflowRepo;

const MT = 'TENANT0000000000000000000A';
const MU = 'USER000000000000000000000A';
const MV = 'USER000000000000000000000B';
const MMODEL = 'model0000000000000000000a1';

function mg_tools(): ToolRegistry
{
    return new ToolRegistry([new FakeTool('fake.read'), new FakeTool('fake.write', Risk::WRITE_OWN), new FakeTool('fake.ext', Risk::EXTERNAL), new FakeTool('fake.pay', Risk::MONEY), new FakeTool('pc.read_file', Risk::WRITE_OWN, 'x', null, ['path' => 'P'])]);
}

function mg_def(): array
{
    return ['start' => 'a', 'nodes' => ['a' => ['type' => 'ai', 'prompt' => 'Say hi {{x}}', 'save_as' => 'x', 'next' => 'b'], 'b' => ['type' => 'output', 'text' => '{{x}}']]];
}

function mg_svc(?FakeWorkflowRepo $r = null): array
{
    $r ??= new FakeWorkflowRepo;

    return [new WorkflowService($r, mg_tools()), $r];
}

function mg_add(FakeWorkflowRepo $r, string $id = 'W1', array $o = []): void
{
    $r->wfs[$id] = $o + ['id' => $id, 'user' => MU, 'name' => 'Morning plan', 'description' => 'd', 'category' => 'personal', 'definition' => mg_def(), 'version' => 1, 'enabled' => true, 'schedule_time' => '08:00', 'max_credits' => 2.0,
        'paused' => false, 'model_id' => null, 'template_key' => null];
}

// ---------- templates
it('keeps all 13 original templates, with their keys and a valid definition', function () {
    $all = Templates::all();
    expect(count(Templates::ORIGINAL))->toBe(13);
    foreach (Templates::ORIGINAL as $k) {
        expect(isset($all[$k]))->toBeTrue();
    }
    expect(count($all))->toBe(15);
});

it('puts every template into one of the six groups, and every group has at least one', function () {
    $counts = WorkflowCatalog::templateCounts(Templates::all());
    expect(array_keys($counts))->toBe(['productivity', 'marketing', 'communication', 'knowledge', 'desktop', 'development']);
    expect(array_sum($counts))->toBe(15);
    foreach ($counts as $n) {
        expect($n > 0)->toBeTrue();
    }
    foreach (array_keys(Templates::all()) as $k) {
        expect(isset(Templates::GROUPS[Templates::group($k)]))->toBeTrue();
    }
});

it('searches and filters templates', function () {
    $t = Templates::all();
    expect(array_keys(WorkflowCatalog::filterTemplates($t, '', 'marketing')))->toBe(['marketing_weekly', 'marketing_monthly_ads', 'marketing_next_action', 'seo_fix_plan', 'marketing_content_ideas']);
    expect(array_keys(WorkflowCatalog::filterTemplates($t, 'EMAIL', 'all')))->toBe(['email_reply']);
    expect(WorkflowCatalog::filterTemplates($t, 'zzzz', ''))->toBe([]);
});

it('remembers the template a workflow came from, and renaming never changes it', function () {
    [$s, $r] = mg_svc();
    $x = $s->fromTemplate(MT, MU, 'morning_plan');
    expect($x['status'])->toBe('ok');
    expect($r->wfs[$x['id']]['template_key'])->toBe('morning_plan');
    expect($s->fromTemplate(MT, MU, 'nope')['status'])->toBe('no_template');
    $before = $r->wfs[$x['id']];
    expect($s->rename(MT, MU, $x['id'], '  My   day  ', 'mine'))->toBe('ok');
    $after = $r->wfs[$x['id']];
    expect($after['name'])->toBe('My day');
    foreach (['id', 'template_key', 'definition', 'version', 'schedule_time', 'enabled', 'max_credits', 'category'] as $k) {
        expect($after[$k])->toBe($before[$k]);
    }
});

// ---------- rename
it('validates names and descriptions and is owner-only', function () {
    [$s, $r] = mg_svc();
    mg_add($r);
    expect($s->rename(MT, MU, 'W1', '   ', ''))->toBe('bad_name');
    expect($s->rename(MT, MU, 'W1', str_repeat('a', 121), ''))->toBe('bad_name');
    expect($s->rename(MT, MU, 'W1', 'ok', str_repeat('d', 501)))->toBe('bad_desc');
    expect($s->rename(MT, MV, 'W1', 'stolen', ''))->toBe('not_found');
    expect($r->wfs['W1']['name'])->toBe('Morning plan');
    expect($s->rename(MT, MU, 'W1', "tab\x07bed\tname", ''))->toBe('ok');
    expect($r->wfs['W1']['name'])->toBe('tabbed name');
});

// ---------- settings
it('validates category, time, credit limit and the model against the plan', function () {
    [$s, $r] = mg_svc();
    mg_add($r);
    $ok = fn (string $c, string $t, string $b, string $m) => $s->updateSettings(MT, MU, 'W1', $c, $t, $b, $m, [MMODEL]);
    expect($ok('gaming', '', '5', ''))->toBe('bad_input');
    expect($ok('desktop', '25:00', '5', ''))->toBe('bad_time');
    expect($ok('desktop', '9:00', '5', ''))->toBe('bad_time');
    expect($ok('desktop', '', '1001', ''))->toBe('bad_budget');
    expect($ok('desktop', '', 'abc', ''))->toBe('bad_budget');
    expect($ok('desktop', '', '5', 'other00000000000000000000x'))->toBe('bad_model');
    expect($ok('desktop', '', '5', 'short'))->toBe('bad_model');
    expect($r->wfs['W1']['category'])->toBe('personal');
    expect($ok('desktop', '10:30', '7', MMODEL))->toBe('ok');
    expect([$r->wfs['W1']['category'], $r->wfs['W1']['schedule_time'], $r->wfs['W1']['max_credits'], $r->wfs['W1']['model_id']])->toBe(['desktop', '10:30', 7.0, MMODEL]);
    expect($ok('desktop', '', '', ''))->toBe('ok');
    expect([$r->wfs['W1']['schedule_time'], $r->wfs['W1']['max_credits'], $r->wfs['W1']['model_id']])->toBe([null, 5.0, null]);
});

// ---------- steps (builder save)
it('saves valid steps, refuses invalid ones and never lets money or an unapproved external tool in', function () {
    [$s, $r] = mg_svc();
    mg_add($r);
    expect($s->saveSteps(MT, MU, 'W1', 'not json')['status'])->toBe('bad_json');
    expect($s->saveSteps(MT, MU, 'W1', ['start' => 'a', 'nodes' => []])['status'])->toBe('invalid');
    $pay = ['start' => 'a', 'nodes' => ['a' => ['type' => 'tool', 'tool' => 'fake.pay', 'args' => ['text' => 'x'], 'next' => 'b'], 'b' => ['type' => 'output', 'text' => 'x']]];
    expect($s->saveSteps(MT, MU, 'W1', $pay)['status'])->toBe('invalid');
    $ext = ['start' => 'a', 'nodes' => ['a' => ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'x'], 'next' => 'b'], 'b' => ['type' => 'output', 'text' => 'x']]];
    expect($s->saveSteps(MT, MU, 'W1', $ext)['status'])->toBe('invalid');
    $loop = ['start' => 'a', 'nodes' => ['a' => ['type' => 'ai', 'prompt' => 'x', 'save_as' => 'x', 'next' => 'a']]];
    expect($s->saveSteps(MT, MU, 'W1', $loop)['status'])->toBe('invalid');
    expect($r->wfs['W1']['definition'])->toBe(mg_def());                 // nothing was stored by the refused saves
    $new = mg_def();
    $new['nodes']['a']['prompt'] = 'Say bye {{x}}';
    expect($s->saveSteps(MT, MU, 'W1', $new))->toBe(['status' => 'ok', 'errors' => []]);
    expect([$r->wfs['W1']['version'], $r->wfs['W1']['definition']['nodes']['a']['prompt'], $r->wfs['W1']['name']])->toBe([2, 'Say bye {{x}}', 'Morning plan']);
    expect($s->saveSteps(MT, MV, 'W1', $new)['status'])->toBe('not_found');
});

it('cleans the builder layout: only real nodes, whole numbers in range, nothing else survives', function () {
    $d = mg_def() + ['layout' => ['a' => ['x' => '40.7', 'y' => -5], 'b' => ['x' => 99999, 'y' => 10], 'ghost' => ['x' => 1, 'y' => 1], 'a2' => 'bad'], 'evil' => ['x' => 1]];
    $t = WorkflowService::tidy($d);
    expect(array_keys($t))->toBe(['start', 'nodes', 'layout']);
    expect($t['layout'])->toBe(['a' => ['x' => 40, 'y' => 0], 'b' => ['x' => 5000, 'y' => 10]]);
    expect(isset(WorkflowService::tidy(mg_def())['layout']))->toBeFalse();
});

// ---------- state
it('pauses, resumes, switches off and on without touching anything else', function () {
    [$s, $r] = mg_svc();
    mg_add($r);
    expect($s->pause(MT, MU, 'W1'))->toBe('ok');
    expect([$r->wfs['W1']['paused'], $r->wfs['W1']['enabled']])->toBe([true, true]);
    expect($s->disable(MT, MU, 'W1'))->toBe('ok');
    expect([$r->wfs['W1']['paused'], $r->wfs['W1']['enabled']])->toBe([true, false]);
    expect($s->resume(MT, MU, 'W1'))->toBe('ok');
    expect($s->enable(MT, MU, 'W1'))->toBe('ok');
    expect([$r->wfs['W1']['paused'], $r->wfs['W1']['enabled'], $r->wfs['W1']['schedule_time'], $r->wfs['W1']['name']])->toBe([false, true, '08:00', 'Morning plan']);
    expect($s->pause(MT, MV, 'W1'))->toBe('not_found');
    expect($s->disable(MT, MU, 'nope'))->toBe('not_found');
});

// ---------- duplicate / blank
it('duplicates into a switched-off, unscheduled copy and truncates long names', function () {
    [$s, $r] = mg_svc();
    mg_add($r, 'W1', ['name' => str_repeat('n', 120), 'template_key' => 'email_reply']);
    $x = $s->duplicate(MT, MU, 'W1');
    expect($x['status'])->toBe('ok');
    $c = $r->wfs[$x['id']];
    expect(mb_strlen($c['name']))->toBe(120);
    expect(str_ends_with($c['name'], ' (copy)'))->toBeTrue();
    expect([$c['enabled'], $c['paused'], $c['schedule_time'], $c['version'], $c['template_key'], $c['definition']])->toBe([false, false, null, 1, 'email_reply', mg_def()]);
    expect($x['id'] !== 'W1')->toBeTrue();
    expect($s->duplicate(MT, MV, 'W1')['status'])->toBe('not_found');
    $r->maxWorkflows = 2;
    expect($s->duplicate(MT, MU, 'W1')['status'])->toBe('limit');
});

it('creates a workflow from scratch that is valid, and validates the form', function () {
    [$s, $r] = mg_svc();
    $x = $s->createBlank(MT, MU, '  My  flow ', 'desc', 'knowledge');
    expect($x['status'])->toBe('ok');
    expect($r->wfs[$x['id']]['name'])->toBe('My flow');
    expect(WorkflowDefinition::validate($r->wfs[$x['id']]['definition'], mg_tools()))->toBe([]);
    expect($r->wfs[$x['id']]['template_key'])->toBeNull();
    expect($s->createBlank(MT, MU, '', '', 'knowledge')['status'])->toBe('bad_name');
    expect($s->createBlank(MT, MU, 'x', str_repeat('d', 501), 'knowledge')['status'])->toBe('bad_desc');
    expect($s->createBlank(MT, MU, 'x', '', 'personal')['status'])->toBe('bad_input');     // old categories are not offered for new workflows
});

// ---------- catalog: status, groups, search, filters
it('works out the status dot', function () {
    $w = ['enabled' => true, 'paused' => false, 'schedule_time' => null];
    expect(WorkflowCatalog::status($w, null))->toBe('active');
    expect(WorkflowCatalog::status($w, 'done'))->toBe('active');
    expect(WorkflowCatalog::status(['schedule_time' => '08:00'] + $w, 'done'))->toBe('scheduled');
    expect(WorkflowCatalog::status($w, 'failed'))->toBe('attention');
    expect(WorkflowCatalog::status($w, 'waiting_approval'))->toBe('attention');
    expect(WorkflowCatalog::status($w, 'running'))->toBe('running');
    expect(WorkflowCatalog::status($w, 'queued'))->toBe('running');
    expect(WorkflowCatalog::status(['paused' => true] + $w, 'done'))->toBe('paused');
    expect(WorkflowCatalog::status(['enabled' => false, 'paused' => true] + $w, 'failed'))->toBe('disabled');
});

it('maps old categories onto the six groups and puts desktop workflows under Desktop AI', function () {
    foreach (['personal' => 'productivity', 'sales' => 'communication', 'developer' => 'development', 'finance' => 'productivity', 'ecommerce' => 'marketing', 'knowledge' => 'knowledge'] as $c => $g) {
        expect(WorkflowCatalog::groupOfWorkflow(['category' => $c, 'definition' => mg_def()]))->toBe($g);
    }
    $desk = ['start' => 'a', 'nodes' => ['a' => ['type' => 'tool', 'tool' => 'pc.list_dir', 'args' => [], 'next' => 'b'], 'b' => ['type' => 'output', 'text' => '']]];
    expect(WorkflowCatalog::groupOfWorkflow(['category' => 'personal', 'definition' => $desk]))->toBe('desktop');
    expect(WorkflowService::desktopTools($desk))->toBe(['pc.list_dir']);
    expect(WorkflowService::desktopTools(mg_def()))->toBe([]);
});

it('lists cards with status, group and last real run, and filters by search, group and status', function () {
    [$s, $r] = mg_svc();
    mg_add($r, 'W1', ['name' => 'Morning plan']);
    mg_add($r, 'W2', ['name' => 'Email helper', 'category' => 'communication', 'schedule_time' => null]);
    mg_add($r, 'W3', ['name' => 'Old report', 'enabled' => false]);
    mg_add($r, 'W4', ['name' => 'Paused one', 'paused' => true]);
    $r->runs['R1'] = ['id' => 'R1', 'workflow_id' => 'W2', 'user_id' => MU, 'status' => 'failed', 'trigger' => 'manual'];
    $r->runs['R2'] = ['id' => 'R2', 'workflow_id' => 'W1', 'user_id' => MU, 'status' => 'failed', 'trigger' => 'test'];     // a failed TEST does not mark W1 as needing attention
    $names = fn (array $c) => array_column($c, 'name');
    $all = $s->cards(MU);
    expect(count($all))->toBe(4);
    $by = array_column($all, 'status', 'id');
    expect($by)->toBe(['W1' => 'scheduled', 'W2' => 'attention', 'W3' => 'disabled', 'W4' => 'paused']);
    expect($names($s->cards(MU, 'EMAIL')))->toBe(['Email helper']);
    expect($names($s->cards(MU, '', 'communication')))->toBe(['Email helper']);
    expect($names($s->cards(MU, '', '', 'disabled')))->toBe(['Old report']);
    expect($names($s->cards(MU, '', '', 'paused')))->toBe(['Paused one']);
    expect($names($s->cards(MU, '', '', 'attention')))->toBe(['Email helper']);
    expect($names($s->cards(MU, '', '', 'scheduled')))->toBe(['Morning plan', 'Paused one']);
    expect($names($s->cards(MU, '', '', 'active')))->toBe(['Morning plan', 'Email helper']);
    expect($s->cards(MV))->toBe([]);                                                        // other people's workflows are never listed
});

// ---------- test mode
function mg_trun(array $def, array $vars = [], bool $sim = false, ?FakeAiStep $ai = null, ?ToolRegistry $reg = null, ?string $model = null): array
{
    $repo = new FakeWorkflowRepo;
    mg_add($repo, 'W', ['definition' => $def, 'model_id' => $model]);
    $svc = new WorkflowService($repo, $reg ?? mg_tools());
    $res = $svc->startTest(MT, MU, 'W', $vars, $sim);
    expect($res['status'])->toBe('ok');
    $repo->claim($res['run_id']);
    $ai ??= new FakeAiStep;
    $mobile = new FakeMobileRepo;

    return [new WorkflowRunner($repo, $reg ?? mg_tools(), $ai, $mobile), $repo, $ai, $mobile, $res['run_id']];
}

it('queues a test run flagged as a test, with the AI mode, and checks the inputs', function () {
    $repo = new FakeWorkflowRepo;
    mg_add($repo, 'W', ['definition' => ['start' => 'a', 'inputs' => [['key' => 'q', 'label' => 'Q']], 'nodes' => ['a' => ['type' => 'output', 'text' => '{{q}}']]], 'enabled' => false]);
    $s = new WorkflowService($repo, mg_tools());
    expect($s->startTest(MT, MU, 'W', [], false)['status'])->toBe('missing_input');
    expect($s->startTest(MT, MU, 'W', ['q' => str_repeat('a', 5001)], false)['status'])->toBe('too_long');
    $x = $s->startTest(MT, MU, 'W', ['q' => 'hi'], true);                                    // works even though the workflow is switched off
    expect($x['status'])->toBe('ok');
    expect($repo->runs[$x['run_id']]['trigger'])->toBe('test');
    expect($repo->runs[$x['run_id']]['vars'])->toBe(['q' => 'hi', '__test_ai' => 'sim']);
    expect($s->startTest(MT, MU, 'W', ['q' => 'hi'], true)['status'])->toBe('busy');
    expect($s->startTest(MT, MV, 'W', ['q' => 'hi'], true)['status'])->toBe('not_found');
});

it('in a test: look-only tools run, writers / senders / the computer are skipped and logged, nothing is changed', function () {
    $read = new FakeTool('fake.read', Risk::READ, 'READ-RESULT');
    $write = new FakeTool('fake.write', Risk::WRITE_OWN);
    $ext = new FakeTool('fake.ext', Risk::EXTERNAL);
    $pc = new FakeTool('pc.read_file', Risk::WRITE_OWN, 'x', null, ['path' => 'P']);
    $reg = new ToolRegistry([$read, $write, $ext, $pc]);
    $def = ['start' => 'r', 'nodes' => [
        'r' => ['type' => 'tool', 'tool' => 'fake.read', 'args' => ['text' => 'q'], 'save_as' => 'got', 'next' => 'w'],
        'w' => ['type' => 'tool', 'tool' => 'fake.write', 'args' => ['text' => 'secret {{got}}'], 'save_as' => 'wrote', 'next' => 'ap'],
        'ap' => ['type' => 'approval', 'message' => 'Send it? {{got}}', 'next' => 'e'],
        'e' => ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'out'], 'next' => 'p'],
        'p' => ['type' => 'tool', 'tool' => 'pc.read_file', 'args' => ['path' => '/etc/passwd'], 'save_as' => 'file', 'next' => 'out'],
        'out' => ['type' => 'output', 'text' => '{{got}} | {{wrote}} | {{file}}']]];
    [$runner, $repo, , $mobile, $rid] = mg_trun($def, [], true, null, $reg);
    $runner->execute(MT, $rid);
    expect($repo->runs[$rid]['status'])->toBe('done');
    expect($read->calls)->toBe([['text' => 'q']]);
    expect([$write->calls, $ext->calls, $pc->calls])->toBe([[], [], []]);
    $st = array_column($repo->steps, 'status', 'node_id');
    expect($st)->toBe(['r' => 'ok', 'w' => 'skipped', 'ap' => 'skipped', 'e' => 'skipped', 'p' => 'skipped', 'out' => 'ok']);
    $out = array_column($repo->steps, 'output', 'node_id');
    expect(str_contains($out['w'], 'NOT run'))->toBeTrue();
    expect(str_contains($out['w'], 'secret READ-RESULT'))->toBeTrue();
    expect(str_contains($out['p'], 'Nothing was sent to your computer'))->toBeTrue();
    expect(str_contains($out['ap'], 'you would be asked to approve: Send it? READ-RESULT'))->toBeTrue();
    expect($repo->runs[$rid]['vars']['__output'])->toBe('READ-RESULT | [test: result of fake.write] | [test: result of pc.read_file]');
    expect($mobile->notified)->toBe([]);
});

it('in a test the wait step does not park the run and the AI can be simulated or real', function () {
    $def = ['start' => 'd', 'nodes' => ['d' => ['type' => 'delay', 'minutes' => 60, 'next' => 'a'], 'a' => ['type' => 'ai', 'prompt' => 'hi', 'save_as' => 'x', 'next' => 'o'], 'o' => ['type' => 'output', 'text' => '{{x}}']]];
    [$runner, $repo, $ai, , $rid] = mg_trun($def, [], true);
    $runner->execute(MT, $rid);
    expect($repo->parked)->toBe([]);
    expect($ai->calls)->toBe([]);
    expect($repo->runs[$rid]['status'])->toBe('done');
    expect(array_column($repo->steps, 'status', 'node_id'))->toBe(['d' => 'skipped', 'a' => 'skipped', 'o' => 'ok']);
    expect($repo->runs[$rid]['vars']['__output'])->toBe('[test: AI answer]');

    [$runner, $repo, $ai, , $rid] = mg_trun($def, [], false, new FakeAiStep('ok', 'REAL', '0.5000'), null, MMODEL);
    $runner->execute(MT, $rid);
    expect(count($ai->calls))->toBe(1);
    expect($ai->calls[0]['model'])->toBe(MMODEL);                                           // the workflow's preferred model is passed on
    expect($repo->runs[$rid]['vars']['__output'])->toBe('REAL');
    expect($repo->runs[$rid]['spent'])->toBe(0.5);
});

it('a failing test run does not send a failure notification, a failing real run still does', function () {
    $def = ['start' => 'a', 'nodes' => ['a' => ['type' => 'ai', 'prompt' => 'hi', 'save_as' => 'x', 'next' => 'o'], 'o' => ['type' => 'output', 'text' => '{{x}}']]];
    [$runner, $repo, , $mobile, $rid] = mg_trun($def, [], false, new FakeAiStep('provider'));
    $runner->execute(MT, $rid);
    expect($repo->runs[$rid]['status'])->toBe('failed');
    expect(count($mobile->notified))->toBe(0);

    $r2 = new FakeWorkflowRepo;
    mg_add($r2, 'W', ['definition' => $def]);
    expect($r2->createRun(MT, MU, 'R9', 'W', []))->toBe('ok');
    $r2->claim('R9');
    $m2 = new FakeMobileRepo;
    (new WorkflowRunner($r2, mg_tools(), new FakeAiStep('provider'), $m2))->execute(MT, 'R9');
    expect($r2->runs['R9']['status'])->toBe('failed');
    expect(count($m2->notified))->toBe(1);
});

it('a normal (non-test) run still refuses an outside tool without approval and still waits for approval', function () {
    $def = ['start' => 'e', 'nodes' => ['e' => ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'x'], 'next' => 'o'], 'o' => ['type' => 'output', 'text' => 'x']]];
    $repo = new FakeWorkflowRepo;
    mg_add($repo, 'W', ['definition' => $def]);
    $repo->createRun(MT, MU, 'R1', 'W', []);
    $repo->claim('R1');
    (new WorkflowRunner($repo, mg_tools(), new FakeAiStep, new FakeMobileRepo))->execute(MT, 'R1');
    expect($repo->runs['R1']['status'])->toBe('failed');

    $def2 = ['start' => 'a', 'nodes' => ['a' => ['type' => 'approval', 'message' => 'ok?', 'next' => 'o'], 'o' => ['type' => 'output', 'text' => 'x']]];
    mg_add($repo, 'W2', ['definition' => $def2]);
    $repo->createRun(MT, MU, 'R2', 'W2', []);
    $repo->claim('R2');
    (new WorkflowRunner($repo, mg_tools(), new FakeAiStep, new FakeMobileRepo))->execute(MT, 'R2');
    expect($repo->runs['R2']['status'])->toBe('waiting_approval');
});

it('uses the workflow model for real runs too', function () {
    $def = ['start' => 'a', 'nodes' => ['a' => ['type' => 'ai', 'prompt' => 'hi', 'save_as' => 'x', 'next' => 'o'], 'o' => ['type' => 'output', 'text' => '{{x}}']]];
    $repo = new FakeWorkflowRepo;
    mg_add($repo, 'W', ['definition' => $def, 'model_id' => MMODEL]);
    $repo->createRun(MT, MU, 'R1', 'W', []);
    $repo->claim('R1');
    $ai = new FakeAiStep;
    (new WorkflowRunner($repo, mg_tools(), $ai, new FakeMobileRepo))->execute(MT, 'R1');
    expect($ai->calls[0]['model'])->toBe(MMODEL);
});

// ---------- wiring (routes, views, script)
it('has a route for every workflow route name the views and controller use, and no name is registered twice', function () {
    $root = __DIR__.'/../../';
    $routes = (string) file_get_contents($root.'routes/web.php');
    preg_match_all("/->name\\('([a-z.]+)'\\)/", $routes, $m);
    $names = $m[1];
    expect(count($names))->toBe(count(array_unique($names)));             // a repeated name would silently replace the older route
    $used = [];
    foreach (['resources/views/workflow/index.blade.php', 'resources/views/workflow/show.blade.php', 'resources/views/workflow/edit.blade.php', 'app/Http/Controllers/WorkflowController.php'] as $f) {
        preg_match_all("/route\\('([a-z.]+)'/", (string) file_get_contents($root.$f), $u);
        $used = array_merge($used, $u[1]);
    }
    foreach (array_unique($used) as $n) {
        expect([$n, in_array($n, $names, true)])->toBe([$n, true]);
    }
    foreach (['agents.show', 'agents.rename', 'agents.settings', 'agents.steps', 'agents.state', 'agents.duplicate', 'agents.test', 'agents.create', 'agents.run', 'agents.destroy'] as $n) {
        expect(in_array($n, $names, true))->toBeTrue();
    }
});

it('ships the builder script and the new database file with a migration', function () {
    $root = __DIR__.'/../../';
    expect(is_file($root.'public/js/workflow-builder.js'))->toBeTrue();
    expect(str_contains((string) file_get_contents($root.'resources/views/workflow/show.blade.php'), "asset('js/workflow-builder.js')"))->toBeTrue();
    expect(is_file($root.'database/sql/phase32c.up.sql') && is_file($root.'database/sql/phase32c.down.sql'))->toBeTrue();
    expect(str_contains((string) file_get_contents($root.'database/migrations/2027_01_02_100000_wf_manage.php'), 'phase32c.up.sql'))->toBeTrue();
});

it('keeps the old JSON editor route and the old run / template / decide routes', function () {
    $routes = (string) file_get_contents(__DIR__.'/../../routes/web.php');
    foreach (['agents.index', 'agents.template', 'agents.edit', 'agents.save', 'agents.toggle', 'agents.decide', 'agents.cancel'] as $n) {
        expect(str_contains($routes, "'$n'"))->toBeTrue();
    }
});
