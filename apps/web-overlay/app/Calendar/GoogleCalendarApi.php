<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Outreach\HttpJson;
use DateTimeImmutable;

final class GoogleCalendarApi implements CalendarApi
{
    private const BASE = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    public function __construct(private readonly HttpJson $http) {}

    public function events(string $accessToken, DateTimeImmutable $from, DateTimeImmutable $to, \DateTimeZone $zone): array
    {
        $q = http_build_query(['timeMin' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'timeMax' => $to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 100], '', '&', PHP_QUERY_RFC3986);
        $r = $this->http->request('GET', self::BASE.'?'.$q, ['Authorization' => 'Bearer '.$accessToken]);
        self::check($r['status']);
        $out = [];
        foreach (is_array($r['json']['items'] ?? null) ? $r['json']['items'] : [] as $i) {
            if (! is_array($i) || ($i['status'] ?? '') === 'cancelled') {
                continue;
            }
            $s = $i['start'] ?? [];
            $e = $i['end'] ?? [];
            try {
                if (is_string($s['date'] ?? null)) {                                      // all-day: the end date is exclusive
                    $start = new DateTimeImmutable($s['date'], $zone);
                    $end = new DateTimeImmutable(is_string($e['date'] ?? null) ? $e['date'] : $s['date'], $zone);
                    $allDay = true;
                } elseif (is_string($s['dateTime'] ?? null) && is_string($e['dateTime'] ?? null)) {
                    $start = new DateTimeImmutable($s['dateTime']);
                    $end = new DateTimeImmutable($e['dateTime']);
                    $allDay = false;
                } else {
                    continue;
                }
            } catch (\Exception) {
                continue;                                                                  // one odd event never hides the others
            }
            $out[] = ['start' => $start, 'end' => $end, 'all_day' => $allDay, 'summary' => self::text($i['summary'] ?? '', 200), 'location' => self::text($i['location'] ?? '', 200)];
        }

        return $out;
    }

    public function create(string $accessToken, string $title, DateTimeImmutable $start, DateTimeImmutable $end, string $location): string
    {
        $utc = new \DateTimeZone('UTC');
        $body = ['summary' => $title, 'start' => ['dateTime' => $start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), 'timeZone' => 'UTC'], 'end' => ['dateTime' => $end->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), 'timeZone' => 'UTC']];
        if ($location !== '') {
            $body['location'] = $location;
        }
        $r = $this->http->request('POST', self::BASE.'?sendUpdates=none', ['Authorization' => 'Bearer '.$accessToken, 'Content-Type' => 'application/json'], null, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::check($r['status']);
        if (! is_string($r['json']['id'] ?? null) || $r['json']['id'] === '') {
            throw new CalendarApiException('bad_reply');
        }

        return $r['json']['id'];
    }

    private static function check(int $status): void
    {
        if ($status === 401 || $status === 403) {
            throw new CalendarApiException('auth');
        }
        if ($status < 200 || $status >= 300) {
            throw new CalendarApiException('api');
        }
    }

    private static function text(mixed $v, int $max): string
    {
        return is_string($v) ? trim(mb_substr((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $v), 0, $max)) : '';
    }
}
