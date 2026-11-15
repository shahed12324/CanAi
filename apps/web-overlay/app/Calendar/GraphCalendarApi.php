<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Outreach\HttpJson;
use DateTimeImmutable;

final class GraphCalendarApi implements CalendarApi
{
    public function __construct(private readonly HttpJson $http) {}

    public function events(string $accessToken, DateTimeImmutable $from, DateTimeImmutable $to, \DateTimeZone $zone): array
    {
        $utc = new \DateTimeZone('UTC');
        $q = http_build_query(['startDateTime' => $from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), 'endDateTime' => $to->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            '$top' => 100, '$orderby' => 'start/dateTime', '$select' => 'subject,start,end,isAllDay,isCancelled,location'], '', '&', PHP_QUERY_RFC3986);
        $r = $this->http->request('GET', 'https://graph.microsoft.com/v1.0/me/calendarView?'.$q, ['Authorization' => 'Bearer '.$accessToken, 'Prefer' => 'outlook.timezone="UTC"']);
        self::check($r['status']);
        $out = [];
        foreach (is_array($r['json']['value'] ?? null) ? $r['json']['value'] : [] as $i) {
            if (! is_array($i) || ($i['isCancelled'] ?? false) === true || ! is_string($i['start']['dateTime'] ?? null) || ! is_string($i['end']['dateTime'] ?? null)) {
                continue;
            }
            try {
                $start = new DateTimeImmutable($i['start']['dateTime'], $utc);          // asked for UTC, so these are UTC
                $end = new DateTimeImmutable($i['end']['dateTime'], $utc);
            } catch (\Exception) {
                continue;
            }
            $allDay = ($i['isAllDay'] ?? false) === true;
            if ($allDay) {                                                              // midnight-to-midnight in UTC: keep the dates, show them in the person's zone as dates
                $start = new DateTimeImmutable($start->format('Y-m-d'), $zone);
                $end = new DateTimeImmutable($end->format('Y-m-d'), $zone);
            }
            $loc = is_array($i['location'] ?? null) ? ($i['location']['displayName'] ?? '') : '';
            $out[] = ['start' => $start, 'end' => $end, 'all_day' => $allDay, 'summary' => self::text($i['subject'] ?? '', 200), 'location' => self::text($loc, 200)];
        }

        return $out;
    }

    public function create(string $accessToken, string $title, DateTimeImmutable $start, DateTimeImmutable $end, string $location): string
    {
        $utc = new \DateTimeZone('UTC');
        $body = ['subject' => $title, 'start' => ['dateTime' => $start->setTimezone($utc)->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'], 'end' => ['dateTime' => $end->setTimezone($utc)->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC']];
        if ($location !== '') {
            $body['location'] = ['displayName' => $location];
        }
        $r = $this->http->request('POST', 'https://graph.microsoft.com/v1.0/me/events', ['Authorization' => 'Bearer '.$accessToken, 'Content-Type' => 'application/json'], null, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
