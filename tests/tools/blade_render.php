<?php
// Renders workflow/index and workflow/show with Laravel's real BladeCompiler (see blade_compile.php).  Run from apps/web-overlay:  php ../../tests/tools/blade_render.php   -> /tmp/render/*.html
// Renders the workflow pages with Laravel's real BladeCompiler and stub helpers (route/asset/csrf/old/session/$errors), using the app's own services on in-memory repos.
// Needs BLADE_SRC=<folder with the illuminate clones> (see blade_compile.php).  Output: /tmp/render/*.html
require __DIR__.'/blade_compile.php';   // sets up autoload + $bc (it also lints argv files; none given)
spl_autoload_register(function ($c) { foreach (['App\\' => 'app/', 'Tests\\Support\\' => 'tests/Support/'] as $p => $d) { if (str_starts_with($c, $p)) { $f = $d.str_replace('\\', '/', substr($c, strlen($p))).'.php'; if (is_file($f)) { require $f; } } } });
eval('namespace Symfony\\Component\\Uid; class Ulid { public function __construct(public ?string $v = null) { $this->v ??= strtoupper(substr(bin2hex(random_bytes(16)), 0, 26)); } public function __toString(): string { return $this->v; } }');
function route($n, $p = []) { $q = is_array($p) ? $p : ['id' => $p]; $id = $q['id'] ?? ''; unset($q['id']); return '/r/'.$n.($id !== '' ? '/'.$id : '').($q ? '?'.http_build_query($q) : ''); }
function asset($p) { return '/assets/'.$p; }
function csrf_field() { return '<input type="hidden" name="_token" value="TOKEN">'; }
function method_field($m) { return '<input type="hidden" name="_method" value="'.$m.'">'; }
function old($k = null, $d = null) { return $d; }
function session($k = null) { return $GLOBALS['__session'][$k] ?? null; }
class FakeErrors { function any() { return false; } function has($k) { return false; } function first($k = null) { return ''; } function get($k) { return []; } }
class FakeEnv { private array $stack = []; function addLoop($d) { $this->stack[] = (object) ['index' => 0, 'iteration' => 1, 'first' => true, 'last' => false, 'count' => is_countable($d) ? count($d) : 0, 'remaining' => 0, 'depth' => 1, 'parent' => null]; } function incrementLoopIndices() { $l = end($this->stack); $l->index++; $l->iteration++; $l->first = false; } function popLoop() { array_pop($this->stack); } function getLastLoop() { return end($this->stack); } function startSection($n) {} function stopSection() {} function yieldContent($n) { return ''; } }
function render_view(string $file, array $data): string {
    global $bc;
    $src = preg_replace(['/<x-layouts\.app[^>]*>/', '/<\/x-layouts\.app>/'], ['<main>', '</main>'], file_get_contents($file));
    $php = $bc->compileString($src);
    $errors = new FakeErrors; $__env = new FakeEnv;
    extract($data, EXTR_SKIP);
    ob_start(); eval('?>'.$php); return (string) ob_get_clean();
}
$GLOBALS['__session'] = ['status' => 'Saved.'];
use App\Workflow\{Templates, WorkflowCatalog, WorkflowService, WorkflowDefinition, ToolRegistry, Risk};
use Tests\Support\{FakeWorkflowRepo, FakeTool};
$U = 'USER000000000000000000000A'; $T = 'TENANT0000000000000000000A';
$repo = new FakeWorkflowRepo;
$tools = new ToolRegistry([new FakeTool('notes.add', Risk::WRITE_OWN, 'x', null, ['title' => 'Title']), new FakeTool('web.send', Risk::EXTERNAL, 'x', null, ['url' => 'URL']), new FakeTool('knowledge.search', Risk::READ, 'x', null, ['query' => 'Query']), new FakeTool('pc.list_dir', Risk::WRITE_OWN, 'x', null, ['path' => 'Path', 'device?' => 'Device'])]);
$svc = new WorkflowService($repo, $tools);
$a = $svc->fromTemplate($T, $U, 'morning_plan')['id'];
$b = $svc->fromTemplate($T, $U, 'email_reply')['id'];
$c = $svc->fromTemplate($T, $U, 'desktop_folder_summary')['id'];
$svc->pause($T, $U, $b); $svc->disable($T, $U, $c);
$blank = $svc->createBlank($T, $U, 'My <script>alert(1)</script> flow', 'desc & "quotes"', 'knowledge')['id'];
$repo->runs['R1'] = ['id' => 'R1', 'workflow_id' => $b, 'user_id' => $U, 'status' => 'failed', 'trigger' => 'manual', 'workflow_name' => 'Email reply draft', 'spent' => 0.5, 'max_credits' => 1.0, 'error' => 'AI down', 'created_at' => '2026-11-14 10:00', 'finished_at' => null, 'definition' => [], 'vars' => [], 'current_node' => null];
$repo->runs['R2'] = ['id' => 'R2', 'workflow_id' => $a, 'user_id' => $U, 'status' => 'done', 'trigger' => 'test', 'workflow_name' => 'Morning plan', 'spent' => 0.0, 'max_credits' => 2.0, 'error' => null, 'created_at' => '2026-11-14 10:05', 'finished_at' => null, 'definition' => [], 'vars' => [], 'current_node' => null];
$cards = $svc->cards($U);
$tpl = Templates::all();
$groups = Templates::GROUPS;
$index = render_view('resources/views/workflow/index.blade.php', [
    'cards' => $cards, 'total' => count($cards), 'runs' => $repo->runs($U, 15), 'pending' => [['run_id' => 'R9', 'workflow_name' => 'WF', 'message' => 'OK?']], 'openRun' => 'R1', 'steps' => [['seq' => 1, 'node_id' => 'a', 'node_type' => 'ai', 'status' => 'skipped', 'output' => 'Test mode: x <b>', 'credits' => 0.0]],
    'templates' => $tpl, 'templateCounts' => WorkflowCatalog::templateCounts($tpl), 'groups' => $groups, 'statuses' => WorkflowCatalog::STATUSES, 'f' => ['q' => '', 'group' => '', 'status' => '', 'tq' => '', 'tgroup' => ''],
    'categories' => WorkflowService::EDITABLE, 'inputs' => array_column(array_map(fn ($x) => ['id' => $x['id'], 'in' => WorkflowDefinition::inputs($x['definition'])], $cards), 'in', 'id'),
]);
file_put_contents('/tmp/render/index.html', $index);
$specs = [];
foreach ($tools->all() as $k => $t) { $specs[$k] = ['label' => $t->label(), 'risk' => $t->risk(), 'args' => array_map(fn ($x, $l) => ['name' => rtrim((string) $x, '?'), 'optional' => str_ends_with((string) $x, '?'), 'label' => $l], array_keys($t->args()), array_values($t->args())), 'desktop' => str_starts_with($k, 'pc.')];
}
foreach (['a' => $a, 'c' => $c, 'blank' => $blank] as $name => $id) {
    $w = $repo->workflow($U, $id);
    $html = render_view('resources/views/workflow/show.blade.php', ['w' => $w, 'status' => WorkflowCatalog::status($w, null), 'group' => WorkflowCatalog::groupOfWorkflow($w), 'groups' => $groups, 'categories' => WorkflowService::EDITABLE, 'allCategories' => WorkflowService::CATEGORIES,
        'inputs' => WorkflowDefinition::inputs($w['definition']), 'runs' => $repo->runsFor($U, $id), 'openRun' => 'R2', 'steps' => [['seq' => 1, 'node_id' => 'plan', 'node_type' => 'tool', 'status' => 'skipped', 'output' => 'Test mode — NOT run', 'credits' => 0.0]], 'models' => [['id' => 'm1', 'name' => 'Model One', 'provider' => 'Prov']], 'toolSpecs' => $specs,
        'desktopTools' => WorkflowService::desktopTools($w['definition']), 'devices' => [['id' => 'd', 'name' => 'My PC', 'platform' => 'windows']], 'userMode' => 'agent', 'templateName' => $w['template_key'] !== null ? (Templates::get($w['template_key'])['name'] ?? null) : null]);
    file_put_contents("/tmp/render/show_$name.html", $html);
}
echo "rendered\n";
