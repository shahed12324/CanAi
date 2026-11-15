<?php

declare(strict_types=1);

namespace App\Mobile;

use App\Outreach\HttpJson;

/**
 * Expo Push Service (it relays to Google FCM for Android and to Apple for iOS, using the credentials uploaded to EAS).
 * Payloads carry only a title, a short body and an id — never message bodies, PINs or tokens.
 */
final class ExpoPush implements PushSender
{
    public const URL = 'https://exp.host/--/api/v2/push/send';

    public function __construct(private readonly HttpJson $http, private readonly ?string $accessToken = null) {}

    public function send(array $messages): array
    {
        $results = [];
        foreach (array_chunk($messages, 100) as $chunk) {
            $payload = array_map(fn (array $m) => ['to' => $m['to'], 'title' => $m['title'], 'body' => $m['body'], 'data' => $m['data'], 'sound' => 'default', 'priority' => 'high', 'channelId' => 'default'], $chunk);
            $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
            if ($this->accessToken !== null && $this->accessToken !== '') {
                $headers['Authorization'] = 'Bearer '.$this->accessToken;
            }
            try {
                $res = $this->http->request('POST', self::URL, $headers, null, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (\Throwable) {
                $res = ['status' => 0, 'json' => []];
            }
            $tickets = $res['status'] === 200 && is_array($res['json']['data'] ?? null) ? array_values($res['json']['data']) : [];
            foreach ($chunk as $i => $_) {
                $t = $tickets[$i] ?? null;
                if (! is_array($t)) {
                    $results[] = 'error:http_'.$res['status'];
                } elseif (($t['status'] ?? '') === 'ok') {
                    $results[] = 'ok';
                } elseif (($t['details']['error'] ?? '') === 'DeviceNotRegistered') {
                    $results[] = 'invalid_token';
                } else {
                    $results[] = 'error:'.substr(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($t['details']['error'] ?? 'rejected')), 0, 40);
                }
            }
        }

        return $results;
    }
}
