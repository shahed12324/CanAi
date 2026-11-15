<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Inbox\Agent\AgentRepo;
use App\Inbox\InboxRepo;
use App\Inbox\InboxText;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Where the shop owner switches the AI support agent on per channel and writes the business facts it may use. */
final class InboxAgentController extends Controller
{
    public function __construct(private readonly AgentRepo $agent, private readonly InboxRepo $inbox, private readonly TenantContext $tenant) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('inbox.view'), 403);

        return view('inbox.agent', ['channels' => $this->inbox->channels(), 'settings' => $this->agent->settings(), 'canManage' => $request->user()->can('inbox.manage')]);
    }

    public function save(Request $request)
    {
        abort_unless($request->user()->can('inbox.manage'), 403);
        $d = $request->validate([
            'instructions' => ['nullable', 'string', 'max:1000'],
            'business_info' => ['nullable', 'string', 'max:3000'],
            'modes' => ['nullable', 'array', 'max:50'],
            'modes.*' => ['in:off,draft,auto'],
        ]);
        $this->agent->saveSettings($this->tenant->idOrFail(), trim($d['instructions'] ?? ''), trim($d['business_info'] ?? ''));
        $mine = array_column($this->inbox->channels(), null, 'id');      // only this workspace's channels are visible here
        foreach ($d['modes'] ?? [] as $channelId => $mode) {
            if (isset($mine[$channelId])) {
                $this->agent->setChannelMode((string) $channelId, $mode);
            }
        }

        return back()->with('status', 'AI The agent’s settings were saved.');
    }

    public function dismiss(Request $request, string $id)
    {
        abort_unless($request->user()->can('inbox.reply') && preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 403);
        abort_if($this->inbox->conversation($id) === null, 404);
        $this->agent->deleteDraft($id);

        return back();
    }
}
