<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Memory\MemoryGuard;
use App\Memory\MemoryRepo;
use App\Memory\MemorySuggestService;
use App\Memory\MemoryText;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** The Memory Center: what the assistant may remember. Everything here belongs to the signed-in person, except the business-wide notes that managers keep. */
final class MemoryController extends Controller
{
    public function __construct(private readonly MemoryRepo $repo, private readonly MemorySuggestService $suggest, private readonly TenantContext $tenant) {}

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    private static function manager(Request $r): bool
    {
        return $r->user()->can('memory.manage');
    }

    private static function back(string $result, string $ok)
    {
        $err = MemoryText::error($result);

        return $err === null ? back()->with('status', $ok) : back()->withErrors(['memory' => $err])->withInput();
    }

    public function index(Request $request)
    {
        $uid = (string) $request->user()->id;

        return view('memory.index', ['memories' => $this->repo->list($uid), 'pending' => $this->repo->pendingSuggestions($uid), 'chats' => $this->repo->recentChats($uid, 20),
            'useInChat' => $this->repo->useInChat($uid), 'canBusiness' => self::manager($request), 'categories' => MemoryGuard::CATEGORIES]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['content' => ['required', 'string', 'max:1000'], 'category' => ['required', 'in:preference,fact,business,person,other'], 'scope' => ['nullable', 'in:user,business'], 'pinned' => ['nullable', 'boolean']]);
        $scope = ($d['scope'] ?? 'user') === 'business' ? 'business' : 'user';
        abort_if($scope === 'business' && ! self::manager($request), 403);

        return self::back($this->repo->add($this->tenant->idOrFail(), (string) $request->user()->id, CreateWorkspace::newId(), $scope, $d['category'], $d['content'], $request->boolean('pinned'), 'user', null), 'Remembered.');
    }

    public function update(Request $request, string $id)
    {
        $d = $request->validate(['content' => ['nullable', 'string', 'max:1000'], 'category' => ['nullable', 'in:preference,fact,business,person,other'], 'pinned' => ['nullable', 'in:0,1'], 'active' => ['nullable', 'in:0,1']]);

        return self::back($this->repo->update($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), self::manager($request), $d['content'] ?? null, $d['category'] ?? null,
            isset($d['pinned']) ? $d['pinned'] === '1' : null, isset($d['active']) ? $d['active'] === '1' : null), 'Updated.');
    }

    public function destroy(Request $request, string $id)
    {
        return self::back($this->repo->delete($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), self::manager($request)), 'Deleted.');
    }

    public function clear(Request $request)
    {
        $request->validate(['confirm' => ['required', 'in:yes']]);
        $n = $this->repo->clear($this->tenant->idOrFail(), (string) $request->user()->id);

        return back()->with('status', "Your {$n} personal memories were deleted.");
    }

    public function setting(Request $request)
    {
        $request->validate(['use' => ['required', 'in:0,1']]);

        return self::back($this->repo->saveSetting($this->tenant->idOrFail(), (string) $request->user()->id, $request->input('use') === '1'), 'Saved.');
    }

    public function suggest(Request $request)
    {
        $d = $request->validate(['conversation_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/']]);
        $ids = array_map(fn () => CreateWorkspace::newId(), range(1, 5));
        $r = $this->suggest->suggest($this->tenant->idOrFail(), (string) $request->user()->id, $d['conversation_id'], $ids, CreateWorkspace::newId());

        return $r['status'] === 'ok'
            ? back()->with('status', "{$r['count']} suggestions were found — review below and approve or discard.")
            : back()->withErrors(['memory' => MemoryText::SUGGEST_FAIL[$r['status']] ?? 'Could not be done.']);
    }

    public function accept(Request $request, string $id)
    {
        return self::back($this->repo->acceptSuggestion($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), CreateWorkspace::newId()), 'Remembered.');
    }

    public function dismiss(Request $request, string $id)
    {
        return self::back($this->repo->dismissSuggestion($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id)), 'Discarded.');
    }
}
