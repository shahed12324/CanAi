<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Messaging\LinkCode;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A signed-in user connects (or disconnects) their own Telegram or WhatsApp (route default 'channel'). Codes are single-use, hashed, valid 10 minutes. */
final class ChannelLinkController extends Controller
{
    public function show(Request $request, TenantContext $tenant)
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $ch = $this->channel($request);
        $bot = DB::table('channel_accounts')->where('channel', $ch)->where('status', 'active')->orderBy('created_at')->first(['id', 'name', 'bot_username']);
        $link = $this->links($tenant, $userId, $ch)->first(['l.external_username', 'l.verified_at', 'l.last_seen_at']);

        return view('settings.'.$ch, ['bot' => $bot, 'link' => $link, 'code' => session('link_code') ? LinkCode::pretty((string) session('link_code')) : null]);
    }

    public function code(Request $request, TenantContext $tenant)
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $ch = $this->channel($request);
        $label = $ch === 'whatsapp' ? 'WhatsApp' : 'Telegram';
        if (DB::table('channel_accounts')->where('channel', $ch)->where('status', 'active')->doesntExist()) {
            return back()->withErrors([$ch => $label.' has not been enabled yet.']);
        }
        if ($this->links($tenant, $userId, $ch)->exists()) {
            return back()->withErrors([$ch => 'Your '.$label.' is already linked. To link again, unlink first.']);
        }
        $code = LinkCode::generate();
        DB::table('channel_link_codes')->insert([
            'id' => CreateWorkspace::newId(), 'tenant_id' => $tenant->idOrFail(), 'user_id' => $userId, 'channel' => $ch,
            'code_hash' => LinkCode::hash($code), 'expires_at' => now()->addMinutes(10),
        ]);

        return back()->with('link_code', $code);
    }

    public function unlink(Request $request, TenantContext $tenant)
    {
        $ch = $this->channel($request);
        $ids = $this->links($tenant, (string) $request->user()->getAuthIdentifier(), $ch)->pluck('l.id')->all();
        DB::table('channel_links')->where('tenant_id', $tenant->idOrFail())->whereIn('id', $ids)->update(['revoked_at' => now()]);

        return back()->with('status', ($ch === 'whatsapp' ? 'WhatsApp' : 'Telegram').' unlinked.');
    }

    private function channel(Request $request): string
    {
        return $request->route('channel') === 'whatsapp' ? 'whatsapp' : 'telegram';
    }

    /** The user's active links on ONE channel (a person may have both). */
    private function links(TenantContext $tenant, string $userId, string $channel)
    {
        return DB::table('channel_links as l')->join('channel_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.tenant_id', $tenant->idOrFail())->where('l.user_id', $userId)->whereNull('l.revoked_at')->where('a.channel', $channel);
    }
}
