<?php

declare(strict_types=1);

namespace App\Developer;

/** Sends with curl: no redirects, no proxy, 8 seconds, TLS verified, and the connection pinned to the checked IP address. */
final class CurlWebhookSender implements WebhookSender
{
    public function post(string $url, string $ip, array $headers, string $body): array
    {
        $p = parse_url($url);
        $host = (string) ($p['host'] ?? '');
        $port = (int) ($p['port'] ?? 443);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k.': '.$v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"], CURLOPT_MAXFILESIZE => 1_000_000, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return ['code' => $code, 'error' => $err];
    }

    public function resolve(string $host): array
    {
        $ips = gethostbynamel($host);

        return $ips === false ? [] : array_values($ips);
    }
}
