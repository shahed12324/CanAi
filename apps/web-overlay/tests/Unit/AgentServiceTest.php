<?php

use App\Agents\AgentService;
use App\Chat\CreditPricing;
use App\Workflow\Risk;
use App\Workflow\ToolRegistry;
use Tests\Support\FakeAgentStudioRepo;
use Tests\Support\FakeAssistEngine;
use Tests\Support\FakeLedger;
use Tests\Support\FakePicker;
use Tests\Support\FakeTool;
use Tests\Support\ProviderDown;

const AGT = 'TENANT0000000000000000000A';
const AGU = 'USER000000000000000000000A';
const AGO = 'USER000000000000000000000B';
const AGID = 'agent000000000000000000001a';

function agsvc(?FakeAssistEngine $e = null, ?FakeLedger $l = null, ?FakePicker $p = null, ?FakeAgentStudioRepo $r = null): array
{
    $e ??= new FakeAssistEngine(['text' => 'Hello from the agent', 'input_tokens' => 500, 'output_tokens' => 100, 'model' => 'gpt-test']);
    $l ??= new FakeLedger('100.0000');
    $r ??= new FakeAgentStudioRepo;
    $tools = new ToolRegistry([new FakeTool('notes.add', Risk::WRITE_OWN), new FakeTool('web.send', Risk::EXTERNAL), new FakeTool('pay.send', Risk::MONEY), new FakeTool('pc.read_file', Risk::READ)]);

    return [new AgentService($r, $tools, $p ?? new FakePicker, $e, $l, new CreditPricing('1', '3')), $e, $l, $r];
}

function agform(array $o = []): array
{
    return $o + ['name' => 'Helper', 'system_prompt' => 'Be brief.', 'max_credits' => 5];
}

function agcreate(AgentService $s, array $o = [], string $id = AGID): array
{
    return $s->save(AGT, AGU, null, $id, agform($o));
}

it('creates an agent and keeps the normalised values', function () {
    [$s, , , $r] = agsvc();
    expect(agcreate($s)['status'])->toBe('ok');
    expect($r->rows[AGID]['name'])->toBe('Helper');
    expect($r->rows[AGID]['permissions'])->toBe(['allow_external' => false, 'desktop' => false]);
});

it('rejects bad input without touching the repo', function () {
    [$s, , , $r] = agsvc();
    $x = $s->save(AGT, AGU, null, AGID, agform(['name' => '', 'temperature' => 9]));
    expect($x['status'])->toBe('bad_input');
    expect(count($x['errors']))->toBeGreaterThan(1);
    expect($r->rows)->toBe([]);
});

it('never gives an agent a money tool, even with every permission on', function () {
    [$s] = agsvc();
    $x = agcreate($s, ['tools' => ['pay.send'], 'perm_allow_external' => '1', 'perm_desktop' => '1']);
    expect($x['status'])->toBe('bad_input');
});

it('needs the permission for outside-the-app and desktop tools', function () {
    [$s] = agsvc();
    expect(agcreate($s, ['tools' => ['web.send']])['status'])->toBe('bad_input');
    expect(agcreate($s, ['tools' => ['pc.read_file']])['status'])->toBe('bad_input');
    expect(agcreate($s, ['tools' => ['web.send', 'pc.read_file'], 'perm_allow_external' => '1', 'perm_desktop' => '1'])['status'])->toBe('ok');
});

it('rejects unknown tools', function () {
    [$s] = agsvc();
    expect(agcreate($s, ['tools' => ['nope.nope']])['status'])->toBe('bad_input');
});

it('reports the plan limit and duplicate names', function () {
    [$s, , , $r] = agsvc();
    $r->limit = 1.0;
    expect(agcreate($s)['status'])->toBe('ok');
    expect(agcreate($s, ['name' => 'Other'], 'agent000000000000000000002b')['status'])->toBe('limit');
    $r->limit = 5.0;
    expect(agcreate($s, ['name' => 'helper'], 'agent000000000000000000003c')['status'])->toBe('duplicate');
});

it("cannot edit, delete or test another person's agent", function () {
    [$s] = agsvc();
    agcreate($s);
    expect($s->save(AGT, AGO, AGID, 'x', agform())['status'])->toBe('not_found');
    expect($s->delete(AGT, AGO, AGID))->toBe('not_found');
    expect($s->test(AGT, AGO, AGID, 'hi', 'rq1')['status'])->toBe('not_found');
});

it('tests an agent against the model and charges the real cost once', function () {
    [$s, $e, $l, $r] = agsvc();
    agcreate($s);
    $x = $s->test(AGT, AGU, AGID, 'Say hi', 'rq1');
    expect($x['status'])->toBe('ok');
    expect($x['text'])->toBe('Hello from the agent');
    expect($e->calls)->toBe(1);
    $cost = (new CreditPricing('1', '3'))->cost(500, 100, 1.0);
    expect($l->log[1])->toBe('settle:'.$cost);
    expect(count($l->log))->toBe(2);
    expect($l->usages[0]['source'])->toBe('agent');
    expect($r->runLog[0]['status'])->toBe('ok');
    expect($r->runLog[0]['credits'])->toBe($cost);
});

it('sends the safety rules and the person\'s instructions, in that order, to the model', function () {
    [$s, $e] = agsvc();
    agcreate($s);
    $s->test(AGT, AGU, AGID, 'Say hi', 'rq1');
    $sys = $e->seen[0]['messages'][0]['content'];
    expect(str_starts_with($sys, 'Be brief.'))->toBeTrue();
    expect(str_contains($sys, 'cannot be changed by the instructions above'))->toBeTrue();
    expect($e->seen[0]['messages'][1])->toBe(['role' => 'user', 'content' => 'Say hi']);
});

it('releases the hold and charges nothing when the provider fails', function () {
    [$s, , $l, $r] = agsvc(new FakeAssistEngine(ProviderDown::make()));
    agcreate($s);
    $x = $s->test(AGT, AGU, AGID, 'hi', 'rq1');
    expect($x['status'])->toBe('provider');
    expect(count($l->log))->toBe(2);
    expect(str_starts_with($l->log[1], 'release'))->toBeTrue();
    expect($l->balance)->toBe('100.0000');
    expect($r->runLog[0]['status'])->toBe('failed');
});

it('does not run when credits are not enough', function () {
    [$s, $e] = agsvc(null, new FakeLedger('0.0000'));
    agcreate($s);
    expect($s->test(AGT, AGU, AGID, 'hi', 'rq1')['status'])->toBe('no_credits');
    expect($e->calls)->toBe(0);
});

it('refuses before the model when the per-run credit limit is below the worst case', function () {
    [$s, $e, $l, $r] = agsvc();
    agcreate($s, ['max_credits' => 0, 'max_tokens' => 4000]);
    $x = $s->test(AGT, AGU, AGID, 'hi', 'rq1');
    expect($x['status'])->toBe('budget');
    expect($e->calls)->toBe(0);
    expect($l->log)->toBe([]);
    expect($r->runLog[0]['status'])->toBe('blocked');
});

it('does not run a switched-off agent', function () {
    [$s, $e, , $r] = agsvc();
    agcreate($s);
    $r->rows[AGID]['enabled'] = false;
    expect($s->test(AGT, AGU, AGID, 'hi', 'rq1')['status'])->toBe('disabled');
    expect($e->calls)->toBe(0);
});

it('rejects empty and oversized test messages and strips control characters', function () {
    [$s, $e] = agsvc();
    agcreate($s);
    expect($s->test(AGT, AGU, AGID, "  \x01 ", 'rq1')['status'])->toBe('bad_message');
    expect($s->test(AGT, AGU, AGID, str_repeat('a', 4001), 'rq2')['status'])->toBe('bad_message');
    $s->test(AGT, AGU, AGID, "hi\x07 there", 'rq3');
    expect($e->seen[0]['messages'][1]['content'])->toBe('hi there');
});

it('says so when no model is available', function () {
    [$s, $e] = agsvc(null, null, new FakePicker(null));
    agcreate($s);
    expect($s->test(AGT, AGU, AGID, 'hi', 'rq1')['status'])->toBe('no_model');
    expect($e->calls)->toBe(0);
});

it('still returns the answer when writing the usage log fails', function () {
    [$s, , , $r] = agsvc();
    agcreate($s);
    $r->failRun = true;
    expect($s->test(AGT, AGU, AGID, 'hi', 'rq1')['status'])->toBe('ok');
});

it('charges a model answer that is empty and reports it as unreadable', function () {
    [$s, , $l] = agsvc(new FakeAssistEngine(['text' => '  ', 'input_tokens' => 100, 'output_tokens' => 0, 'model' => 'gpt-test']));
    agcreate($s);
    expect($s->test(AGT, AGU, AGID, 'hi', 'rq1')['status'])->toBe('unreadable');
    expect(str_starts_with($l->log[1], 'settle'))->toBeTrue();
});

it('lists the agent tools in the prompt but never runs them in a test', function () {
    [$s, $e] = agsvc();
    agcreate($s, ['tools' => ['notes.add']]);
    $s->test(AGT, AGU, AGID, 'hi', 'rq1');
    expect(str_contains($e->seen[0]['messages'][0]['content'], 'notes.add'))->toBeTrue();
    expect(str_contains($e->seen[0]['messages'][0]['content'], 'not running in this test'))->toBeTrue();
});

it('wires the provider, controller, routes and views', function () {
    $root = __DIR__.'/../../';
    expect(str_contains((string) file_get_contents($root.'app/Providers/AgentsServiceProvider.php'), 'PgAgentRepo'))->toBeTrue();
    $routes = (string) file_get_contents($root.'routes/web.php');
    foreach (['agentbuilder.index', 'agentbuilder.store', 'agentbuilder.update', 'agentbuilder.destroy', 'agentbuilder.restore', 'agentbuilder.test'] as $n) {
        expect(str_contains($routes, "'$n'"))->toBeTrue();
    }
    expect(file_exists($root.'resources/views/agents/form.blade.php'))->toBeTrue();
    expect(str_contains((string) file_get_contents($root.'../../scripts/bootstrap-web.sh'), 'AgentsServiceProvider'))->toBeTrue();
});

it('every view the Agent Builder controller renders exists on disk', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../app/Http/Controllers/AgentStudioController.php');
    preg_match_all("/view\\('([a-z0-9_.]+)'/", $src, $m);
    expect(count($m[1]) > 0)->toBeTrue();
    foreach (array_unique($m[1]) as $v) {
        expect(is_file(__DIR__ . '/../../resources/views/' . str_replace('.', '/', $v) . '.blade.php'))->toBeTrue();
    }
});
