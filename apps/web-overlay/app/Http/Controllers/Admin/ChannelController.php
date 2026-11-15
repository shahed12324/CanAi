<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Tenancy\CreateWorkspace;
use App\Admin\AdminAudit;
use App\Ai\CredentialVault;
use App\Messaging\ChannelSendException;
use App\Messaging\TelegramApi;
use App\Messaging\WhatsAppCredentials;
use Illuminate\Http\Request;

/** Platform Telegram bot: add, verify, switch on/off (webhook), rotate secret, health, recent events. Needs providers.manage. */
final class ChannelController extends AdminController
{
    public function __construct(private readonly CredentialVault $vault, private readonly TelegramApi $api) {}

    public function index()
    {
        $this->allow('providers.manage');

        $accounts = $this->db()->table('channel_accounts as a')->orderBy('a.created_at')->get(['a.id', 'a.channel', 'a.name', 'a.bot_username', 'a.status', 'a.last_webhook_at', 'a.last_error', 'a.last_error_at', 'a.created_at']);
        $wa = [];
        foreach ($accounts as $a) {
            if ($a->channel === 'whatsapp') {
                $c = WhatsAppCredentials::fromJson($this->vault->decrypt(...$this->tokenParts($a->id)));
                $wa[$a->id] = ['callback' => route('whatsapp.webhook', ['account' => $a->id], true), 'verify' => $c?->verifyToken()];
            }
        }

        return view('admin.channels.index', [
            'wa' => $wa,
            'accounts' => $accounts,
            'links' => $this->db()->table('channel_links')->whereNull('revoked_at')->selectRaw('account_id, count(*) as n')->groupBy('account_id')->pluck('n', 'account_id'),
            'events' => $this->db()->table('channel_events as e')->join('channel_accounts as a', 'a.id', '=', 'e.account_id')->orderByDesc('e.id')->limit(50)
                ->get(['e.received_at', 'a.name as bot', 'e.kind', 'e.outcome', 'e.error_code', 'e.attempts']),
            'summary' => $this->db()->table('channel_events')->where('received_at', '>', now()->subDay())
                ->selectRaw("count(*) as total, count(*) filter (where outcome = 'processed') as ok, count(*) filter (where outcome = 'ignored') as ignored, count(*) filter (where outcome = 'error') as failed")->first(),
        ]);
    }

    public function store(Request $request)
    {
        $this->allow('providers.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'token' => ['required', 'string', 'regex:/\A\d{6,12}:[A-Za-z0-9_-]{30,50}\z/'],
        ], ['token.regex' => 'The token format is not valid (the 123456:ABC… kind given by BotFather).']);

        try {
            $me = $this->api->call($data['token'], 'getMe');
        } catch (ChannelSendException $e) {
            return back()->withInput($request->except('token'))->withErrors(['token' => 'The token was not verified: '.$e->getMessage()]);
        }
        $enc = $this->vault->encrypt($data['token']);
        $secret = bin2hex(random_bytes(24));
        $id = CreateWorkspace::newId();
        $this->db()->transaction(function () use ($id, $data, $me, $enc, $secret): void {
            $this->db()->table('channel_accounts')->insert([
                'id' => $id, 'channel' => 'telegram', 'name' => $data['name'], 'bot_username' => isset($me['username']) ? mb_substr((string) $me['username'], 0, 64) : null,
                'token_encrypted' => $enc['cipher'], 'token_key_version' => $enc['version'], 'webhook_secret_hash' => hash('sha256', $secret), 'status' => 'disabled',
            ]);
            AdminAudit::record('admin.channel.created', 'channel_account', $id, null, ['name' => $data['name'], 'bot' => $me['username'] ?? null]);
        });

        return redirect()->route('admin.channels.index')->with('status', 'The bot was added (disabled). Now "Activate" Press.');
    }

    /** WhatsApp Cloud API number (Meta). Nothing is verified with Meta here; Meta verifies us when the callback URL is saved there. */
    public function storeWhatsapp(Request $request)
    {
        $this->allow('providers.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone_number_id' => ['required', 'string', 'regex:/\A\d{5,30}\z/'],
            'display_number' => ['nullable', 'string', 'max:32'],
            'access_token' => ['required', 'string', 'min:20', 'max:600'],
            'app_secret' => ['required', 'string', 'regex:/\A[0-9a-fA-F]{16,64}\z/'],
        ], ['phone_number_id.regex' => 'Phone number ID is digits only (from Meta → WhatsApp → API setup).', 'app_secret.regex' => 'App secret is the hex string from Meta → App settings → Basic.']);
        $creds = new WhatsAppCredentials(['phone_number_id' => $data['phone_number_id'], 'access_token' => trim($data['access_token']),
            'app_secret' => $data['app_secret'], 'verify_token' => bin2hex(random_bytes(16))]);
        $enc = $this->vault->encrypt($creds->toJson());
        $id = CreateWorkspace::newId();
        $this->db()->transaction(function () use ($id, $data, $enc): void {
            $this->db()->table('channel_accounts')->insert([
                'id' => $id, 'channel' => 'whatsapp', 'name' => $data['name'], 'bot_username' => isset($data['display_number']) ? mb_substr($data['display_number'], 0, 64) : null,
                'token_encrypted' => $enc['cipher'], 'token_key_version' => $enc['version'], 'webhook_secret_hash' => hash('sha256', bin2hex(random_bytes(24))), 'status' => 'disabled',
            ]);
            AdminAudit::record('admin.channel.created', 'channel_account', $id, null, ['name' => $data['name'], 'channel' => 'whatsapp']);
        });

        return redirect()->route('admin.channels.index')->with('status', 'WhatsApp number added (disabled). Copy the callback URL and verify token into Meta, then press Activate.');
    }

    /** Turn on: new secret -> setWebhook -> mark active. If Telegram refuses, nothing changes. */
    public function activate(string $id)
    {
        $this->allow('providers.manage');
        $account = $this->find($id);
        if ($account->channel === 'whatsapp') {
            return $this->setStatus($id, $account, 'active', 'WhatsApp number is on. Send it a message from your phone.');
        }
        $url = route('telegram.webhook', ['account' => $id], true);
        if (! str_starts_with($url, 'https://')) {
            return back()->withErrors(['channel' => 'Telegram accepts only HTTPS webhooks. APP_URL https:// set it with.']);
        }
        $secret = bin2hex(random_bytes(24));
        $token = $this->token($id);
        $this->db()->transaction(function () use ($id, $secret): void {          // active + new secret first, so the very first update can authenticate
            $this->db()->table('channel_accounts')->where('id', $id)->update(['status' => 'active', 'webhook_secret_hash' => hash('sha256', $secret), 'updated_at' => now()]);
        });
        try {
            $this->api->call($token, 'setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => false]);
        } catch (ChannelSendException $e) {
            $this->db()->table('channel_accounts')->where('id', $id)->update(['status' => $account->status, 'updated_at' => now()]);

            return back()->withErrors(['channel' => 'webhook Not set: '.$e->getMessage()]);
        }
        AdminAudit::record('admin.channel.activated', 'channel_account', $id, ['status' => $account->status], ['status' => 'active']);

        return back()->with('status', 'The bot is on. Go to Telegram and write /start to see.');
    }

    public function deactivate(string $id)
    {
        $this->allow('providers.manage');
        $account = $this->find($id);
        if ($account->channel === 'whatsapp') {
            return $this->setStatus($id, $account, 'disabled', 'WhatsApp number was turned off.');
        }
        $this->db()->table('channel_accounts')->where('id', $id)->update(['status' => 'disabled', 'updated_at' => now()]);   // stop accepting first
        $note = '';
        try {
            $this->api->call($this->token($id), 'deleteWebhook', ['drop_pending_updates' => true]);
        } catch (ChannelSendException $e) {
            $note = ' (Telegram-The webhook could not be deleted at '.$e->getMessage().' — but the bot will now accept nothing.)';
        }
        AdminAudit::record('admin.channel.deactivated', 'channel_account', $id, ['status' => $account->status], ['status' => 'disabled']);

        return back()->with('status', 'The bot was turned off.'.$note);
    }

    public function rotateSecret(string $id)
    {
        $this->allow('providers.manage');
        $account = $this->find($id);
        abort_unless($account->status === 'active' && $account->channel === 'telegram', 422);

        return $this->activate($id);                                               // same steps: new secret, setWebhook, active
    }

    /** Ask Telegram what it sees (pending updates, last delivery error). */
    public function check(string $id)
    {
        $this->allow('providers.manage');
        abort_unless($this->find($id)->channel === 'telegram', 422);
        try {
            $i = $this->api->call($this->token($id), 'getWebhookInfo');
        } catch (ChannelSendException $e) {
            return back()->withErrors(['channel' => $e->getMessage()]);
        }
        $msg = 'webhook: '.(($i['url'] ?? '') === '' ? 'Not set' : 'Set').' · Pending: '.(int) ($i['pending_update_count'] ?? 0);
        if (! empty($i['last_error_message'])) {
            $msg .= ' · Last error: '.mb_substr((string) $i['last_error_message'], 0, 150);
        }

        return back()->with('status', $msg);
    }

    private function find(string $id): object
    {
        abort_unless(preg_match('/\A[0-9A-Za-z]{26}\z/', $id) === 1, 404);

        return $this->db()->table('channel_accounts')->where('id', $id)->first(['id', 'channel', 'name', 'status']) ?? abort(404);
    }

    private function setStatus(string $id, object $account, string $status, string $message)
    {
        $this->db()->table('channel_accounts')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
        AdminAudit::record($status === 'active' ? 'admin.channel.activated' : 'admin.channel.deactivated', 'channel_account', $id, ['status' => $account->status], ['status' => $status]);

        return back()->with('status', $message);
    }

    /** @return array{0: string, 1: int} */
    private function tokenParts(string $id): array
    {
        $r = $this->db()->selectOne('select * from channel_account_token_for_admin(?::char(26))', [$id]);
        abort_if($r === null, 404);

        return [$r->token_encrypted, (int) $r->token_key_version];
    }

    private function token(string $id): string
    {
        return $this->vault->decrypt(...$this->tokenParts($id));
    }
}
