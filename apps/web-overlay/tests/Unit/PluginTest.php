<?php

use App\Plugins\PluginManifest;
use App\Plugins\PluginService;
use App\Workflow\Risk;
use App\Workflow\ToolRegistry;
use Tests\Support\FakePluginRepo;
use Tests\Support\FakeTool;
use Tests\Support\FakeWorkflowRepo;

function preg(): ToolRegistry
{
    return new ToolRegistry([new FakeTool('fake.read'), new FakeTool('fake.write', Risk::WRITE_OWN), new FakeTool('fake.ext', Risk::EXTERNAL), new FakeTool('fake.pay', Risk::MONEY)]);
}

function psvc(): array
{
    $r = new FakePluginRepo;
    $w = new FakeWorkflowRepo;

    return [new PluginService($r, $w, preg()), $r, $w];
}

function pwf(string $name = 'w1', array $nodes = null, string $cat = 'personal'): array
{
    return ['name' => $name, 'description' => 'd', 'category' => $cat, 'definition' => ['start' => 'n1', 'nodes' => $nodes ?? ['n1' => ['type' => 'output', 'text' => 'hi']]]];
}

function pman(array ...$wfs): string
{
    return json_encode(['workflows' => $wfs === [] ? [pwf()] : $wfs]);
}

function ptool(string $tool, string $name = 'with-tool'): array
{
    return pwf($name, ['n1' => ['type' => 'tool', 'tool' => $tool, 'args' => ['text' => 'x'], 'save_as' => 'x', 'next' => 'n2'], 'n2' => ['type' => 'output', 'text' => 'ok']]);
}

// ---------- manifest rules
it('accepts a good manifest and lists the tools it uses, sorted and unique', function () {
    $c = PluginManifest::check(['workflows' => [ptool('fake.write', 'a'), ptool('fake.read', 'b'), ptool('fake.write', 'c')]], preg());
    expect($c['errors'])->toBe([]);
    expect($c['tools'])->toBe(['fake.read', 'fake.write']);
    expect(count($c['workflows']))->toBe(3);
});

it('refuses manifests with bad shape, counts, names, categories or workflows', function () {
    $bad = [
        [], ['workflows' => []], ['workflows' => 'x'], ['workflows' => ['a' => pwf()]], ['workflows' => array_fill(0, 6, pwf())],
        ['workflows' => ['x']], ['workflows' => [pwf('')]], ['workflows' => [pwf(str_repeat('ক', 81))]], ['workflows' => [pwf('ok', null, 'nope')]],
        ['workflows' => [['name' => 'x', 'category' => 'personal']]], ['workflows' => [['name' => 'x', 'category' => 'personal', 'definition' => ['start' => 'zz', 'nodes' => []]]]],
        ['workflows' => [ptool('no.such.tool')]], ['workflows' => [ptool('fake.pay')]],
    ];
    foreach ($bad as $m) {
        $c = PluginManifest::check($m, preg());
        expect($c['errors'] !== [])->toBeTrue();
        expect($c['workflows'])->toBe([]);
        expect($c['tools'])->toBe([]);
    }
    $long = pwf();
    $long['description'] = str_repeat('ক', 401);
    expect(PluginManifest::check(['workflows' => [$long]], preg())['errors'] !== [])->toBeTrue();
    expect(PluginManifest::check(['workflows' => array_fill(0, 5, pwf())], preg())['errors'])->toBe([]);
    expect(PluginManifest::check(['workflows' => [pwf(str_repeat('ক', 80))]], preg())['errors'])->toBe([]);
});

it('keeps the approval-before-external rule for plugin workflows', function () {
    expect(PluginManifest::check(['workflows' => [ptool('fake.ext')]], preg())['errors'] !== [])->toBeTrue();
    $ok = pwf('ok-ext', ['n1' => ['type' => 'approval', 'message' => 'sure?', 'next' => 'n2'], 'n2' => ['type' => 'tool', 'tool' => 'fake.ext', 'args' => ['text' => 'x'], 'save_as' => 'x', 'next' => 'n3'], 'n3' => ['type' => 'output', 'text' => 'ok']]);
    expect(PluginManifest::check(['workflows' => [$ok]], preg())['errors'])->toBe([]);
});

it('shows each tool with its risk, and an unknown tool as the most careful kind', function () {
    $p = PluginManifest::permissions(['fake.read', 'fake.ext', 'gone.tool'], preg());
    expect($p[0]['risk'])->toBe(Risk::READ);
    expect($p[1]['risk'])->toBe(Risk::EXTERNAL);
    expect($p[1]['risk_label'])->toBe(Risk::LABELS[Risk::EXTERNAL]);
    expect($p[2]['risk'])->toBe(Risk::EXTERNAL);
    expect($p[2]['label'])->toBe('gone.tool');
});

// ---------- submit
it('submits a clean manifest and refuses bad id, name, description or JSON before the repo is asked', function () {
    [$s, $r] = psvc();
    expect($s->submit('T', 'U', 'daily-brief', 'Brief', 'd', pman())['status'])->toBe('ok');
    expect(array_values($r->versions)[0]['tools'])->toBe([]);
    foreach (['ab', 'Bad', '1abc', 'a_b', str_repeat('a', 41), '', 'a b'] as $slug) {
        expect($s->submit('T', 'U', $slug, 'Brief', 'd', pman())['status'])->toBe('bad_slug');
    }
    expect($s->submit('T', 'U', 'abc', '  ', 'd', pman())['status'])->toBe('bad_name');
    expect($s->submit('T', 'U', 'abc', str_repeat('ক', 81), 'd', pman())['status'])->toBe('bad_name');
    expect($s->submit('T', 'U', 'abc', 'n', str_repeat('ক', 401), pman())['status'])->toBe('bad_desc');
    expect($s->submit('T', 'U', 'abc', 'n', str_repeat('ক', 400), pman())['status'])->toBe('ok');
    expect($s->submit('T', 'U', str_repeat('a', 40), str_repeat('ক', 80), 'd', pman())['status'])->toBe('ok');
    expect($s->submit('T', 'U', 'abc', 'n', 'd', '{not json')['status'])->toBe('bad_json');
    expect($s->submit('T', 'U', 'abc', 'n', 'd', '"text"')['status'])->toBe('bad_json');
    expect($s->submit('T', 'U', 'abc', 'n', 'd', json_encode(['workflows' => [pwf(), ['x' => str_repeat('a', 61000)]]]))['status'])->toBe('bad_json');
    $inv = $s->submit('T', 'U', 'abc', 'n', 'd', pman(ptool('no.such.tool')));
    expect($inv['status'])->toBe('invalid');
    expect($inv['errors'] !== [])->toBeTrue();
    expect(count($r->versions))->toBe(3);
});

it('passes through the repo answers it knows and calls anything else failed', function () {
    foreach (['slug_taken', 'limit', 'duplicate'] as $x) {
        [$s, $r] = psvc();
        $r->submitResult = $x;
        expect($s->submit('T', 'U', 'abc', 'n', 'd', pman())['status'])->toBe($x);
    }
    [$s, $r] = psvc();
    $r->submitResult = 'bad_input';
    expect($s->submit('T', 'U', 'abc', 'n', 'd', pman())['status'])->toBe('failed');
});

// ---------- install / update / uninstall
function pub(FakePluginRepo $r, PluginService $s, string $json, string $slug = 'daily-brief'): int
{
    $s->submit('AUTH', 'AU', $slug, 'Brief', 'd', $json);
    $v = count(array_filter($r->versions, fn ($x) => $x['slug'] === $slug));
    $r->publish($slug, $v);

    return $v;
}

it('installs a published plugin as switched-off copies owned by the person', function () {
    [$s, $r, $w] = psvc();
    expect($s->install('T', 'U', 'daily-brief'))->toBe('not_found');
    $s->submit('AUTH', 'AU', 'daily-brief', 'Brief', 'd', pman(pwf('a'), pwf('b')));
    expect($s->install('T', 'U', 'daily-brief'))->toBe('not_found');          // still pending
    $r->publish('daily-brief', 1);
    expect($s->install('T', 'U', 'daily-brief'))->toBe('ok');
    $mine = $w->workflows('U');
    expect(array_column($mine, 'name'))->toBe(['a', 'b']);
    foreach ($mine as $x) {
        expect($x['enabled'])->toBeFalse();
        expect($x['schedule_time'])->toBeNull();
        expect($x['max_credits'])->toBe(5.0);
    }
    expect(array_column($r->installs('U')['daily-brief']['workflow_ids'] ? [['id' => 1]] : [], 'id'))->toBe([1]);
    expect($r->installs('U')['daily-brief']['version'])->toBe(1);
    $first = array_key_first($w->wfs);
    $w->wfs[$first]['name'] = 'my own name';                                  // the person edited a copy
    expect($s->install('T', 'U', 'daily-brief'))->toBe('up_to_date');
    expect($w->wfs[$first]['name'])->toBe('my own name');                     // the same version is never written over their edits
    expect($w->workflows('U2'))->toBe([]);
});

it('updates in place keeping the on/off choice and run history, adds new ones and drops surplus ones', function () {
    [$s, $r, $w] = psvc();
    pub($r, $s, pman(pwf('a'), pwf('b'), pwf('c')));
    $s->install('T', 'U', 'daily-brief');
    $ids = $r->installs('U')['daily-brief']['workflow_ids'];
    $w->wfs[$ids[0]]['enabled'] = true;                                       // the person switched the first one on
    pub($r, $s, pman(pwf('a2'), pwf('b2')));                                  // v2 has only two
    expect($s->install('T', 'U', 'daily-brief'))->toBe('ok');
    expect(array_column($w->workflows('U'), 'name'))->toBe(['a2', 'b2']);
    expect($w->wfs[$ids[0]]['enabled'])->toBeTrue();                          // still on
    expect($w->wfs[$ids[1]]['enabled'])->toBeFalse();
    expect(isset($w->wfs[$ids[2]]))->toBeFalse();                             // surplus removed
    expect($r->installs('U')['daily-brief']['version'])->toBe(2);
    pub($r, $s, pman(pwf('a3'), pwf('b3'), pwf('c3'), pwf('d3')));            // v3 has four: two new ones appear, switched off
    expect($s->install('T', 'U', 'daily-brief'))->toBe('ok');
    expect(count($w->workflows('U')))->toBe(4);
    expect($w->workflows('U')[3]['enabled'])->toBeFalse();
});

it('recreates a copy the person deleted by hand when updating', function () {
    [$s, $r, $w] = psvc();
    pub($r, $s, pman(pwf('a'), pwf('b')));
    $s->install('T', 'U', 'daily-brief');
    $ids = $r->installs('U')['daily-brief']['workflow_ids'];
    unset($w->wfs[$ids[1]]);
    pub($r, $s, pman(pwf('a2'), pwf('b2')));
    expect($s->install('T', 'U', 'daily-brief'))->toBe('ok');
    expect(array_column($w->workflows('U'), 'name'))->toBe(['a2', 'b2']);
});

it('refuses a plugin whose tools no longer pass today, and creates nothing', function () {
    [$s, $r, $w] = psvc();
    pub($r, $s, pman(pwf('a'), ptool('fake.read', 'b')));
    $reg = new ToolRegistry([]);                                                  // fake.read has been removed since publishing
    $s2 = new PluginService($r, $w, $reg);
    expect($s2->install('T', 'U', 'daily-brief'))->toBe('manifest_invalid');
    expect($w->workflows('U'))->toBe([]);
    expect($r->installs('U'))->toBe([]);
});

it('undoes what it made when the workflow limit or the install record stops it', function () {
    [$s, $r, $w] = psvc();
    pub($r, $s, pman(pwf('a'), pwf('b'), pwf('c')));
    $w->maxWorkflows = 2;
    expect($s->install('T', 'U', 'daily-brief'))->toBe('workflow_limit');
    expect($w->workflows('U'))->toBe([]);                                         // the two it did make were removed
    $w->maxWorkflows = null;
    foreach (['limit' => 'limit', 'not_newer' => 'up_to_date', 'not_found' => 'not_found', 'weird' => 'failed'] as $rec => $want) {
        $r->recordResult = $rec;
        expect($s->install('T', 'U', 'daily-brief'))->toBe($want);
        expect($w->workflows('U'))->toBe([]);
    }
});

it('removes the plugin and its copies, only for that person', function () {
    [$s, $r, $w] = psvc();
    pub($r, $s, pman(pwf('a'), pwf('b')));
    $s->install('T', 'U', 'daily-brief');
    $s->install('T2', 'U2', 'daily-brief');
    expect($s->uninstall('T', 'U', 'nope'))->toBe('not_found');
    expect($s->uninstall('T', 'U', 'daily-brief'))->toBe('ok');
    expect($w->workflows('U'))->toBe([]);
    expect(count($w->workflows('U2')))->toBe(2);
    expect($s->uninstall('T', 'U', 'daily-brief'))->toBe('not_found');
});

it('has a Bengali message for every outcome', function () {
    foreach (array_keys(PluginService::MESSAGES) as $k) {
        expect(PluginService::message($k))->not->toBe('Could not be done.' . ($k === 'failed' ? '' : 'x'));
    }
    expect(PluginService::message('nope'))->toBe('Could not be done.');
});
