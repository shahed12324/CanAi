<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\Broadcaster;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Notification management: an announcement to every shop, one shop, or every shop on a plan. It appears in each member's notification list (and push). */
final class BroadcastController extends AdminController
{
    public function index()
    {
        $this->allow('settings.manage');

        return view('admin.broadcasts.index', [
            'items' => $this->db()->table('admin_broadcasts')->orderByDesc('created_at')->limit(30)->get(),
            'plans' => $this->db()->table('plans')->where('status', '!=', 'retired')->orderBy('sort')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $this->allow('settings.manage');
        $d = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'], 'body' => ['nullable', 'string', 'max:500'],
            'audience' => ['required', Rule::in(['all', 'tenant', 'plan'])], 'ref' => ['nullable', 'string', 'max:200'],
        ]);
        $ref = trim((string) ($d['ref'] ?? ''));
        if ($d['audience'] === 'tenant') {
            $ref = (string) ($this->db()->table('tenants')->whereNull('deleted_at')->where(fn ($w) => $w->where('id', $ref)->orWhere('slug', $ref))->value('id') ?? abort(422, 'Shop not found (use its slug or id).'));
        } elseif ($d['audience'] === 'plan') {
            abort_unless($this->db()->table('plans')->where('id', $ref)->exists(), 422, 'Choose a plan.');
        }

        $n = Broadcaster::send($this->db(), $d['title'], (string) ($d['body'] ?? ''), $d['audience'], $d['audience'] === 'all' ? null : $ref, (string) $request->user()->getKey());

        return back()->with('status', "Sent to $n people.");
    }
}
