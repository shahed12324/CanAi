<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Agents\AgentRepo;
use App\Agents\AgentService;
use App\Ai\ModelCatalog;
use App\Tenancy\TenantContext;
use App\Workflow\ToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Agent Builder: a person's own agents. Everything is scoped to the signed-in user inside the workspace; the database enforces it again (RLS + owner checks in the functions). */
final class AgentStudioController extends Controller
{
    public function __construct(private readonly AgentService $agents, private readonly AgentRepo $repo, private readonly TenantContext $tenant, private readonly ToolRegistry $tools, private readonly ModelCatalog $catalog) {}

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    private function user(Request $r): string
    {
        return (string) $r->user()->getAuthIdentifier();
    }

    private function formData(): array
    {
        $models = array_values(array_filter($this->catalog->candidates($this->tenant->idOrFail()), fn ($m) => in_array('text', $m['capabilities'], true)));
        $tools = [];
        foreach ($this->tools->all() as $k => $t) {
            $tools[$k] = ['label' => $t->label(), 'risk' => $t->risk()];
        }

        return ['models' => $models, 'toolList' => $tools];
    }

    public function index(Request $request)
    {
        $u = $this->user($request);

        return view('agents.index', ['agents' => $this->repo->agents($u), 'usage' => $this->repo->usage($u), 'limit' => $this->repo->limit($this->tenant->idOrFail())]);
    }

    public function create()
    {
        return view('agents.form', ['agent' => null, 'versions' => [], 'runs' => []] + $this->formData());
    }

    public function edit(Request $request, string $id)
    {
        $u = $this->user($request);
        $a = $this->repo->agent($u, self::id($id));
        abort_if($a === null, 404);

        return view('agents.form', ['agent' => $a, 'versions' => $this->repo->versions($u, $id), 'runs' => $this->repo->runs($u, $id, 10), 'systemPrompt' => $this->agents->systemPrompt($a)] + $this->formData());
    }

    public function store(Request $request)
    {
        return $this->persist($request, null);
    }

    public function update(Request $request, string $id)
    {
        return $this->persist($request, self::id($id));
    }

    private function persist(Request $request, ?string $id)
    {
        $in = $request->validate(['name' => ['nullable', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'], 'system_prompt' => ['nullable', 'string', 'max:20000'], 'model_id' => ['nullable', 'string', 'max:40'],
            'temperature' => ['nullable', 'numeric'], 'max_tokens' => ['nullable', 'numeric'], 'max_credits' => ['nullable', 'numeric'], 'tools' => ['nullable', 'array', 'max:60'], 'tools.*' => ['string', 'max:80'],
            'use_knowledge' => ['nullable'], 'use_memory' => ['nullable'], 'enabled' => ['nullable'], 'perm_allow_external' => ['nullable'], 'perm_desktop' => ['nullable']]);
        $in['use_knowledge'] = $request->boolean('use_knowledge');
        $in['use_memory'] = $request->boolean('use_memory');
        $in['enabled'] = $id === null ? true : $request->boolean('enabled');
        $new = strtolower((string) Str::ulid());
        $r = $this->agents->save($this->tenant->idOrFail(), $this->user($request), $id, $new, $in);
        if ($r['status'] === 'ok') {
            return redirect()->route('agentbuilder.edit', $id ?? $new)->with('status', AgentService::message('ok'));
        }
        $errs = $r['errors'] !== [] ? $r['errors'] : [AgentService::message($r['status'])];

        return back()->withErrors(['agent' => implode(' ', $errs)])->withInput();
    }

    public function destroy(Request $request, string $id)
    {
        $r = $this->agents->delete($this->tenant->idOrFail(), $this->user($request), self::id($id));

        return $r === 'ok' ? redirect()->route('agentbuilder.index')->with('status', 'The agent was deleted.') : back()->withErrors(['agent' => AgentService::message('not_found')]);
    }

    public function restore(Request $request, string $id)
    {
        $v = (int) $request->validate(['version' => ['required', 'integer', 'min:1', 'max:100000']])['version'];
        $r = $this->agents->restore($this->tenant->idOrFail(), $this->user($request), self::id($id), $v);

        return $r === 'ok' ? back()->with('status', 'Restored as a new version.') : back()->withErrors(['agent' => AgentService::message('not_found')]);
    }

    public function test(Request $request, string $id)
    {
        $in = $request->validate(['message' => ['required', 'string', 'max:6000']]);
        $r = $this->agents->test($this->tenant->idOrFail(), $this->user($request), self::id($id), $in['message'], strtolower((string) Str::ulid()));
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['agent' => AgentService::message($r['status'])])->withInput();
        }

        return back()->with('status', 'The agent answered.')->with('test', ['message' => $in['message'], 'text' => $r['text'], 'credits' => $r['credits'], 'model' => $r['model']]);
    }
}
