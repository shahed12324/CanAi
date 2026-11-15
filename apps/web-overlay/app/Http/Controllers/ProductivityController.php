<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Productivity\DailyPlan;
use App\Productivity\ProductivityText;
use App\Productivity\TaskInput;
use App\Productivity\TaskRepo;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Http\Request;

/** Personal tasks, reminders, notes and the daily plan. Everything belongs to the signed-in person; nobody else (not even an owner) sees it. */
final class ProductivityController extends Controller
{
    public function __construct(private readonly TaskRepo $repo, private readonly TenantContext $tenant) {}

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    private static function tz(Request $r): string
    {
        return (string) ($r->user()->timezone ?: 'Asia/Dhaka');
    }

    private static function back(string $result, string $ok)
    {
        $err = ProductivityText::error($result);

        return $err === null ? back()->with('status', $ok) : back()->withErrors(['task' => $err])->withInput();
    }

    public function tasks(Request $request)
    {
        $tz = self::tz($request);
        $tasks = $this->repo->tasks((string) $request->user()->id);
        $open = array_values(array_filter($tasks, fn ($t) => $t['status'] === 'open'));
        $today = (new DateTimeImmutable('now', TaskInput::zone($tz)))->format('Y-m-d');

        return view('productivity.tasks', ['open' => $open, 'done' => array_values(array_filter($tasks, fn ($t) => $t['status'] === 'done')), 'plan' => DailyPlan::build($open, $today, $tz),
            'today' => $today, 'tz' => $tz]);
    }

    public function store(Request $request)
    {
        $in = TaskInput::parse($request->only(['title', 'details', 'priority', 'due_on', 'remind_at']), self::tz($request));
        if ($in['error'] !== null) {
            return self::back($in['error'], '');
        }

        return self::back($this->repo->add($this->tenant->idOrFail(), (string) $request->user()->id, CreateWorkspace::newId(), $in['title'], $in['details'], $in['priority'], $in['due'], $in['remind']), 'The task was added.');
    }

    public function update(Request $request, string $id)
    {
        $in = TaskInput::parse($request->only(['title', 'details', 'priority', 'due_on', 'remind_at']), self::tz($request));
        if ($in['error'] !== null) {
            return self::back($in['error'], '');
        }

        return self::back($this->repo->edit($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $in['title'], $in['details'], $in['priority'], $in['due'], $in['remind']), 'Updated.');
    }

    public function done(Request $request, string $id)
    {
        $request->validate(['done' => ['required', 'in:0,1']]);

        return self::back($this->repo->setDone($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $request->input('done') === '1'), 'Done.');
    }

    public function destroy(Request $request, string $id)
    {
        return self::back($this->repo->delete($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id)), 'Deleted.');
    }

    public function clearDone(Request $request)
    {
        $n = $this->repo->clearDone($this->tenant->idOrFail(), (string) $request->user()->id);

        return back()->with('status', "{$n} finished tasks were deleted.");
    }

    public function notes(Request $request)
    {
        return view('productivity.notes', ['notes' => $this->repo->notes((string) $request->user()->id)]);
    }

    public function noteStore(Request $request)
    {
        $d = $request->validate(['title' => ['nullable', 'string', 'max:200'], 'body' => ['nullable', 'string', 'max:20000'], 'pinned' => ['nullable', 'boolean']]);

        return self::back($this->repo->saveNote($this->tenant->idOrFail(), (string) $request->user()->id, CreateWorkspace::newId(), (string) ($d['title'] ?? ''), (string) ($d['body'] ?? ''), $request->boolean('pinned'), true), 'Note saved.');
    }

    public function noteUpdate(Request $request, string $id)
    {
        $d = $request->validate(['title' => ['nullable', 'string', 'max:200'], 'body' => ['nullable', 'string', 'max:20000'], 'pinned' => ['nullable', 'in:0,1']]);

        return self::back($this->repo->saveNote($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), (string) ($d['title'] ?? ''), (string) ($d['body'] ?? ''),
            isset($d['pinned']) ? $d['pinned'] === '1' : null, false), 'Note saved.');
    }

    public function noteDestroy(Request $request, string $id)
    {
        return self::back($this->repo->deleteNote($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id)), 'Deleted.');
    }
}
