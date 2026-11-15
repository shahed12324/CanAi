<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Companion\ModeRepo;
use App\Companion\ModeRules;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** The Companion / Agent switch. Companion = chat only (the computer cannot be asked to do anything); Agent = Computer Control rules as before. */
final class CompanionController extends Controller
{
    public function __construct(private readonly ModeRepo $modes, private readonly TenantContext $tenant) {}

    public function set(Request $request)
    {
        $d = $request->validate(['mode' => ['required', 'string', 'in:'.implode(',', ModeRules::MODES)], 'character_id' => ['nullable', 'string', 'size:26']]);
        $r = $this->modes->set($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $d['mode'], ($d['character_id'] ?? '') === '' ? null : $d['character_id']);
        if ($r !== 'ok') {
            return back()->withErrors(['pc' => $r === 'bad_input' ? 'That character was not found.' : 'You are not a member of this workspace.']);
        }

        return back()->with('status', $d['mode'] === 'companion'
            ? 'Companion mode is on: chat only. The computer cannot be asked to do anything, and waiting jobs were cancelled.'
            : 'Agent mode is on: computer jobs follow your device rules and need your approval.');
    }
}
