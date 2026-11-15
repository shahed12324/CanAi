<?php

use App\Developer\Mcp\McpBackend;
use App\Developer\Mcp\McpServer;
use App\Workflow\Risk;
use App\Workflow\ToolRegistry;
use App\Workflow\WorkflowService;
use Tests\Support\FakeTool;
use Tests\Support\FakeWorkflowRepo;

const MW = '01hzzzzzzzzzzzzzzzzzzzzzzz';   // 26 chars

final class FakeMcp implements McpBackend
{
    public array $runs = [];

    public array $looked = [];

    public function workflows(): array { return [['id' => MW, 'name' => 'W', 'enabled' => true, 'inputs' => ['email']]]; }

    public function run(string $id, array $inputs, bool $dryRun): array { $this->runs[] = [$id, $inputs, $dryRun]; return ['status' => $id === MW ? 'ok' : 'not_found', 'run_id' => $dryRun ? null : 'R'.str_repeat('0', 25)]; }

    public function runStatus(string $id): ?array { $this->looked[] = $id; return $id === 'r'.str_repeat('0', 25) ? ['status' => 'done', 'spent' => '1', 'max_credits' => '5'] : null; }

    public function credits(): array { return ['balance' => '10', 'reserved' => '0', 'available' => '10']; }
}

function mcp(array $scopes = ['workflows:read', 'workflows:run', 'credits:read', 'me']): array
{
    $b = new FakeMcp;

    return [new McpServer($b, $scopes), $b];
}

function rpc(string $method, array $params = [], int|string|null $id = 1): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
}

function call(McpServer $s, string $tool, array $args = []): array
{
    return $s->handle(rpc('tools/call', ['name' => $tool, 'arguments' => $args]));
}

it('answers initialize with the protocol version and tools capability', function () {
    [$s] = mcp();
    $r = $s->handle(rpc('initialize'));
    expect($r['id'])->toBe(1)->and($r['result']['protocolVersion'])->toBe('2025-06-18')->and(isset($r['result']['capabilities']['tools']))->toBeTrue()->and($r['result']['serverInfo']['name'])->toBe('jarvis-one');
});

it('answers ping, and notifications get no answer', function () {
    [$s] = mcp();
    expect($s->handle(rpc('ping'))['result'] instanceof \stdClass)->toBeTrue();
    expect($s->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']))->toBeNull();
});

it('lists only the tools the key has scopes for', function () {
    [$s] = mcp();
    $names = array_column($s->handle(rpc('tools/list'))['result']['tools'], 'name');
    expect($names)->toBe(['list_workflows', 'run_workflow', 'get_run', 'get_credits']);
    [$r] = mcp(['workflows:read']);
    expect(array_column($r->handle(rpc('tools/list'))['result']['tools'], 'name'))->toBe(['list_workflows', 'get_run']);
    [$n] = mcp(['me']);
    expect($n->handle(rpc('tools/list'))['result']['tools'])->toBe([]);
});

it('refuses to run a tool the key has no scope for, even if called by name', function () {
    [$s, $b] = mcp(['workflows:read']);
    $r = call($s, 'run_workflow', ['workflow_id' => MW]);
    expect($r['result']['isError'])->toBeTrue()->and($b->runs)->toBe([]);
    [$c] = mcp(['workflows:read']);
    expect(call($c, 'get_credits')['result']['isError'])->toBeTrue();
});

it('runs a workflow and returns the run id', function () {
    [$s, $b] = mcp();
    $r = call($s, 'run_workflow', ['workflow_id' => MW, 'inputs' => ['email' => 'a@b.c', 'n' => 5]]);
    $d = json_decode($r['result']['content'][0]['text'], true);
    expect($r['result']['isError'])->toBeFalse()->and($d['status'])->toBe('queued')->and($b->runs[0])->toBe([MW, ['email' => 'a@b.c', 'n' => '5'], false]);
});

it('passes dry_run through and starts nothing', function () {
    [$s, $b] = mcp();
    $r = call($s, 'run_workflow', ['workflow_id' => MW, 'dry_run' => true]);
    expect(json_decode($r['result']['content'][0]['text'], true))->toBe(['dry_run' => true, 'ok' => true])->and($b->runs[0][2])->toBeTrue();
});

it('rejects a malformed workflow id, inputs or dry_run without touching the backend', function () {
    [$s, $b] = mcp();
    foreach ([['workflow_id' => 'x'], ['workflow_id' => MW, 'inputs' => 'e'], ['workflow_id' => MW, 'inputs' => ['k' => ['a']]], ['workflow_id' => MW, 'dry_run' => 'yes'], ['workflow_id' => MW, 'inputs' => [0 => 'v']], []] as $a) {
        expect(call($s, 'run_workflow', $a)['result']['isError'])->toBeTrue();
    }
    expect($b->runs)->toBe([]);
});

it('says what went wrong in plain words and never leaks other workflows', function () {
    [$s] = mcp();
    $r = call($s, 'run_workflow', ['workflow_id' => '01hyyyyyyyyyyyyyyyyyyyyyyy']);
    expect($r['result']['isError'])->toBeTrue()->and($r['result']['content'][0]['text'])->toBe('No such workflow.');
});

it('reads a run, validating the id', function () {
    [$s, $b] = mcp();
    $ok = call($s, 'get_run', ['run_id' => 'r'.str_repeat('0', 25)]);
    expect(json_decode($ok['result']['content'][0]['text'], true)['status'])->toBe('done');
    expect(call($s, 'get_run', ['run_id' => 'r'.str_repeat('1', 25)])['result']['content'][0]['text'])->toBe('No such run.');
    expect(call($s, 'get_run', ['run_id' => '../x'])['result']['isError'])->toBeTrue()->and(in_array('../x', $b->looked, true))->toBeFalse();
    expect(call($s, 'get_run', [])['result']['isError'])->toBeTrue();
});

it('lists workflows and credits', function () {
    [$s] = mcp();
    expect(json_decode(call($s, 'list_workflows')['result']['content'][0]['text'], true)[0]['inputs'])->toBe(['email']);
    expect(json_decode(call($s, 'get_credits')['result']['content'][0]['text'], true)['available'])->toBe('10');
});

it('gives JSON-RPC errors for bad messages, unknown methods and unknown tools', function () {
    [$s] = mcp();
    expect($s->handle('x')['error']['code'])->toBe(-32600);
    expect($s->handle([rpc('ping')])['error']['code'])->toBe(-32600);                  // batches are not supported
    expect($s->handle(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'])['error']['code'])->toBe(-32600);
    expect($s->handle(['jsonrpc' => '2.0', 'id' => [1], 'method' => 'ping'])['error']['code'])->toBe(-32600);
    expect($s->handle(rpc('nope'))['error']['code'])->toBe(-32601);
    expect($s->handle(rpc('tools/call', ['name' => 'rm_rf']))['error']['code'])->toBe(-32602);
    expect($s->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => 'x'])['error']['code'])->toBe(-32602);
    expect($s->handle(rpc('ping', [], 'abc'))['id'])->toBe('abc');
});

it('has no tool that approves, deletes, or changes keys or money', function () {
    [$s] = mcp();
    $names = array_column($s->handle(rpc('tools/list'))['result']['tools'], 'name');
    expect(array_diff($names, ['list_workflows', 'run_workflow', 'get_run', 'get_credits']))->toBe([]);
});

// ---------- dry run in the real service
it('dry run checks inputs and enabled state but never creates a run', function () {
    $repo = new FakeWorkflowRepo;
    $svc = new WorkflowService($repo, new ToolRegistry([new FakeTool('fake.read')]));
    $def = ['start' => 'n1', 'inputs' => [['key' => 'who', 'label' => 'Who']], 'nodes' => ['n1' => ['type' => 'output', 'text' => 'hi {{who}}']]];
    $repo->save('t', 'u', MW, 'w', '', 'personal', $def, true, null, 5.0, true);
    expect($svc->start('t', 'u', MW, [], true)['status'])->toBe('missing_input');
    expect($svc->start('t', 'u', MW, ['who' => str_repeat('a', 5001)], true)['status'])->toBe('too_long');
    expect($svc->start('t', 'other', MW, ['who' => 'x'], true)['status'])->toBe('not_found');
    $r = $svc->start('t', 'u', MW, ['who' => 'x'], true);
    expect($r)->toBe(['status' => 'ok', 'run_id' => null])->and($repo->runs)->toBe([]);
    $repo->save('t', 'u', MW, 'w', '', 'personal', $def, false, null, 5.0, false);
    expect($svc->start('t', 'u', MW, ['who' => 'x'], true)['status'])->toBe('disabled');
    $repo->save('t', 'u', MW, 'w', '', 'personal', $def, true, null, 5.0, false);
    expect($svc->start('t', 'u', MW, ['who' => 'x'])['status'])->toBe('ok')->and(count($repo->runs))->toBe(1);
});
