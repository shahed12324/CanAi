<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Crm\CrmMessages;
use App\Crm\CrmService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Writes on a single lead: stage, notes, tasks, tags, follow-up. Every action needs leads.manage; RLS keeps it inside the workspace. */
final class LeadCrmController extends Controller
{
    public function __construct(private readonly CrmService $crm, private readonly TenantContext $tenant) {}

    private function guard(Request $request, string $id): string
    {
        abort_unless($request->user()->can('leads.manage'), 403);
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return (string) $request->user()->getAuthIdentifier();
    }

    /** @param array{ok: bool, error?: string} $r */
    private function done(array $r, string $leadId, string $ok)
    {
        if (($r['error'] ?? '') === 'not_found') {
            abort(404);
        }

        return $r['ok']
            ? redirect()->route('leads.show', $leadId)->with('status', $ok)
            : redirect()->route('leads.show', $leadId)->withErrors(['crm' => CrmMessages::bn($r['error'] ?? '')])->withInput();
    }

    public function stage(Request $request, string $id)
    {
        $user = $this->guard($request, $id);
        $data = $request->validate(['stage' => ['required', 'string', 'max:20']]);
        $r = $this->crm->moveStage($id, $data['stage'], $user);
        if ($request->boolean('back_to_board')) {
            return ($r['ok'] ?? false) ? redirect()->route('leads.board') : redirect()->route('leads.board')->withErrors(['crm' => CrmMessages::bn($r['error'] ?? '')]);
        }

        return $this->done($r, $id, 'The stage was changed.');
    }

    public function note(Request $request, string $id)
    {
        $user = $this->guard($request, $id);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return $this->done($this->crm->addNote($id, $user, $data['body']), $id, 'The note was added.');
    }

    public function task(Request $request, string $id)
    {
        $user = $this->guard($request, $id);
        $data = $request->validate(['title' => ['required', 'string', 'max:500'], 'due' => ['nullable', 'string', 'max:20']]);

        return $this->done($this->crm->addTask($id, $user, $data['title'], $data['due'] ?? null, CrmMessages::zone($this->tenant->idOrFail())), $id, 'The task was added.');
    }

    public function taskDone(Request $request, string $taskId)
    {
        $user = $this->guard($request, $taskId);
        $r = $this->crm->completeTask($taskId, $user);
        abort_unless($r['ok'], 404);

        return back()->with('status', 'The task was marked finished.');
    }

    public function tags(Request $request, string $id)
    {
        $user = $this->guard($request, $id);
        $data = $request->validate(['tags' => ['nullable', 'string', 'max:600']]);
        $r = $this->crm->setTags($id, $user, (string) ($data['tags'] ?? ''));

        return $this->done($r, $id, ($r['truncated'] ?? false) ? 'At most 10 tags were kept.' : 'Tags saved.');
    }

    public function followUp(Request $request, string $id)
    {
        $user = $this->guard($request, $id);
        $data = $request->validate(['date' => ['nullable', 'string', 'max:20']]);

        return $this->done($this->crm->setFollowUp($id, $user, $data['date'] ?? null, CrmMessages::zone($this->tenant->idOrFail())), $id, 'Follow-up saved.');
    }
}
