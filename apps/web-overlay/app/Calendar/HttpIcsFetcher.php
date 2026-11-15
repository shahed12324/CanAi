<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Outreach\HostGuard;
use Illuminate\Support\Facades\Http;

/**
 * Downloads a calendar feed from an address a person typed in, so it is a request our server makes on a stranger's say-so:
 * https only, standard port, no login in the address, the host must resolve to PUBLIC addresses only, the connection is pinned to the address we checked
 * (so the name cannot change to a private address between the check and the request), no redirects, 10 seconds, 2 MB.
 */
final class HttpIcsFetcher implements IcsFetcher
{
    public static function check(string $url): ?string
    {
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || ! isset($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['fragment']) || strlen($url) > 700) {
            return null;
        }

        return strtolower($p['host']);
    }

    public function fetch(string $url): string
    {
        $host = self::check($url) ?? throw new IcsException('The link is not valid — give a full address starting with https://.');
        $ips = HostGuard::resolve($host);
        if (! HostGuard::allowed($host, fn () => $ips)) {
            throw new IcsException('A calendar cannot be fetched from this address (it must be a public server).');
        }
        $pin = array_values(array_filter($ips, fn ($i) => filter_var($i, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false))[0] ?? $ips[0];
        try {
            $res = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->withHeaders(['Accept' => 'text/calendar, text/plain'])
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pin}"]], 'stream' => true])->get($url);
        } catch (\Throwable $e) {
            throw new IcsException('The calendar server could not be reached.');
        }
        if ($res->status() !== 200) {
            throw new IcsException('The calendar was not found (the server '.$res->status().' gave) — is the link still active?');
        }
        $body = '';
        $stream = $res->toPsrResponse()->getBody();
        while (! $stream->eof() && strlen($body) <= Ics::MAX_BYTES) {
            $body .= $stream->read(65536);
        }
        if (strlen($body) > Ics::MAX_BYTES) {
            throw new IcsException('The calendar file is too large (over 2 MB).');
        }
        if (! Ics::looksLikeCalendar($body)) {
            throw new IcsException('This is not a calendar file (iCal/ICS).');
        }

        return $body;
    }
}
