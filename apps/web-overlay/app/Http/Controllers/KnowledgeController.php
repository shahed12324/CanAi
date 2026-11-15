<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Knowledge\KnowledgeRepo;
use App\Knowledge\KnowledgeService;
use App\Knowledge\KnowledgeText;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** The company's Knowledge Base. Everyone in the workspace can look and search; managers add, rename, switch off and delete. */
final class KnowledgeController extends Controller
{
    public function __construct(private readonly KnowledgeRepo $repo, private readonly KnowledgeService $service, private readonly TenantContext $tenant) {}

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    private static function manage(Request $r): void
    {
        abort_unless($r->user()->can('knowledge.manage'), 403);
    }

    private static function back(string $result, string $ok, ?string $reason = null)
    {
        $err = KnowledgeText::error($result, $reason);

        return $err === null ? back()->with('status', $ok) : back()->withErrors(['knowledge' => $err]);
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        return view('knowledge.index', ['docs' => $this->repo->documents(), 'q' => mb_substr($q, 0, 300), 'hits' => $q === '' ? [] : $this->service->search($q, 8), 'canManage' => $request->user()->can('knowledge.manage')]);
    }

    public function store(Request $request)
    {
        self::manage($request);
        $d = $request->validate(['file' => ['required', 'file', 'max:5000'], 'title' => ['nullable', 'string', 'max:200'], 'ocr' => ['nullable', 'boolean']]);
        $f = $request->file('file');
        $name = (string) preg_replace('/[^\p{L}\p{N}._ \-]/u', '_', $f->getClientOriginalName());
        $r = $this->service->add($this->tenant->idOrFail(), (string) $request->user()->id, CreateWorkspace::newId(), (string) $f->getRealPath(), $name === '' ? 'file' : $name, 'application/octet-stream', (int) $f->getSize(), $d['title'] ?? null, $request->boolean('ocr'));
        $paid = (float) $r['credits'] > 0 ? ' To read the image '.rtrim(rtrim($r['credits'], '0'), '.').' credits were used.' : '';

        return self::back($r['status'], "Added — {$r['chunks']} was split into parts and made searchable.".$paid, $r['vision']);
    }

    public function update(Request $request, string $id)
    {
        self::manage($request);
        $d = $request->validate(['title' => ['nullable', 'string', 'max:300'], 'active' => ['nullable', 'in:0,1']]);

        return self::back($this->repo->update($this->tenant->idOrFail(), self::id($id), $d['title'] ?? null, isset($d['active']) ? $d['active'] === '1' : null), 'Updated.');
    }

    public function destroy(Request $request, string $id)
    {
        self::manage($request);

        return self::back($this->repo->delete($this->tenant->idOrFail(), self::id($id)), 'Deleted.');
    }
}
