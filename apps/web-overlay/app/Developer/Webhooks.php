<?php

declare(strict_types=1);

namespace App\Developer;

use App\Actions\Tenancy\CreateWorkspace;
use App\Links\Secrets;
use App\Studio\StudioService;

/**
 * Outgoing webhooks: a person adds an https address and gets a signing secret (shown once).  The worker sends "run.finished" messages (ids, status, credits - no outputs).
 * Safety: public https addresses only, re-checked at send time against what the name points to right now; no redirects are followed; a failing address is retried then switched off.
 */
final class Webhooks
{
    public function __construct(private readonly WebhookRepo $repo, private readonly Secrets $secrets, private readonly WebhookSender $sender) {}

    /** @return array{status: string, secret: ?string} ok|bad_name|bad_url|limit|failed */
    public function add(string $tenantId, string $userId, string $name, string $url): array
    {
        $name = StudioService::line($name, 200);
        if ($name === '' || mb_strlen($name) > 80) {
            return ['status' => 'bad_name', 'secret' => null];
        }
        $url = WebhookUrl::check($url);
        if ($url === null) {
            return ['status' => 'bad_url', 'secret' => null];
        }
        $secret = 'whsec_'.bin2hex(random_bytes(24));
        $enc = $this->secrets->encrypt($secret);
        $r = $this->repo->create($tenantId, $userId, CreateWorkspace::newId(), $name, $url, $enc['cipher'], $enc['version']);

        return $r === 'ok' ? ['status' => 'ok', 'secret' => $secret] : ['status' => $r === 'limit' ? 'limit' : 'failed', 'secret' => null];
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return $this->repo->delete($tenantId, $userId, $id);
    }

    public const EVENTS = ['run.finished', 'run.waiting_approval', 'pc.action.pending', 'workflow.message'];

    /** @param array<mixed> $events  @return string ok|bad_input|not_found */
    public function setEvents(string $tenantId, string $userId, string $id, array $events): string
    {
        $clean = [];
        foreach ($events as $e) {
            if (! is_string($e) || ! in_array($e, self::EVENTS, true)) {
                return 'bad_input';
            }
            $clean[$e] = true;
        }
        if ($clean === []) {
            return 'bad_input';
        }

        return $this->repo->setEvents($tenantId, $userId, $id, array_keys($clean));
    }

    public function setActive(string $tenantId, string $userId, string $id, bool $active): string
    {
        return $this->repo->setActive($tenantId, $userId, $id, $active);
    }

    /** One worker pass.  @return array{queued: int, sent: int, failed: int} */
    public function work(int $limit = 20, ?int $now = null): array
    {
        $now ??= time();
        $queued = $this->repo->enqueue();
        $sent = 0;
        $failed = 0;
        foreach ($this->repo->claim(max(1, min(100, $limit))) as $d) {
            [$ok, $code, $err] = $this->deliver($d, $now);
            $this->repo->result($d['id'], $ok, $code, $err);
            $ok ? $sent++ : $failed++;
        }

        return ['queued' => $queued, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * @param  array{id: string, url: string, cipher: string, version: int, payload: array<string, mixed>}  $d
     * @return array{0: bool, 1: ?int, 2: ?string}
     */
    private function deliver(array $d, int $now): array
    {
        try {
            $secret = $this->secrets->decrypt($d['cipher'], $d['version']);
        } catch (\Throwable) {
            return [false, null, 'secret unreadable'];
        }
        $url = WebhookUrl::check($d['url']);
        if ($url === null) {
            return [false, null, 'address not allowed'];
        }
        $host = WebhookUrl::host($url);
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->sender->resolve($host);
        if ($ips === []) {
            return [false, null, 'name did not resolve'];
        }
        foreach ($ips as $ip) {
            if (! WebhookUrl::isPublicIp($ip)) {
                return [false, null, 'points at a private address'];     // ONE private answer is enough to refuse (DNS rebinding)
            }
        }
        $body = json_encode($d['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = WebhookSigner::headers($secret, $now, $body, (string) ($d['payload']['event'] ?? 'run.finished'), $d['id']);
        try {
            $r = $this->sender->post($url, $ips[0], $headers, $body);
        } catch (\Throwable) {
            return [false, null, 'send failed'];
        }
        if ($r['code'] >= 200 && $r['code'] < 300) {
            return [true, $r['code'], null];
        }

        return [false, $r['code'] === 0 ? null : $r['code'], $r['error'] ?? 'HTTP '.$r['code']];
    }
}
