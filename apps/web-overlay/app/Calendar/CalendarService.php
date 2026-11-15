<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Actions\Tenancy\CreateWorkspace;
use App\Links\LinkRepo;
use App\Links\Secrets;
use App\Productivity\TaskInput;
use Closure;
use DateTimeImmutable;

/** A person's calendar feeds (secret ICS addresses, kept encrypted) and what is coming up in them.  Read-only here: nothing is written to a calendar by this class (adding an event is CalendarAccounts). */
final class CalendarService
{
    public const MAX_DAYS = 30;

    public const MAX_EVENTS = 100;

    public const CACHE_SECONDS = 600;

    /** @param Closure(string, int, Closure(): string): string $cache key, seconds, producer -> text */
    public function __construct(private readonly LinkRepo $links, private readonly Secrets $vault, private readonly IcsFetcher $fetcher, private readonly Closure $cache, private readonly ?CalendarAccounts $accounts = null) {}

    /** @return array{status: string, message: ?string} status ok|bad_url|fetch|bad_input|limit|duplicate */
    public function addFeed(string $tenantId, string $userId, string $name, string $url): array
    {
        $url = trim($url);
        $url = str_starts_with(strtolower($url), 'webcal://') ? 'https://'.substr($url, 9) : $url;     // Apple/Outlook style
        $host = HttpIcsFetcher::check($url);
        if ($host === null) {
            return ['status' => 'bad_url', 'message' => 'The link is not valid — give a full address starting with https://.'];
        }
        try {
            $this->fetcher->fetch($url);
        } catch (IcsException $e) {
            return ['status' => 'fetch', 'message' => $e->getMessage()];
        }
        $enc = $this->vault->encrypt($url);
        $r = $this->links->add($tenantId, $userId, CreateWorkspace::newId(), 'ics', $name, $enc['cipher'], $enc['version'], mb_substr($host, 0, 100));

        return ['status' => $r, 'message' => null];
    }

    /**
     * @return array{events: list<array{start: string, end: string, all_day: bool, summary: string, location: string, calendar: string}>, errors: list<string>, feeds: int}
     *                                                                                                                                                      start/end are ISO times in the person's own zone
     */
    public function upcoming(string $tenantId, string $userId, string $tz, DateTimeImmutable $now, int $days = 7): array
    {
        $zone = TaskInput::zone($tz);
        $days = max(1, min(self::MAX_DAYS, $days));
        $from = $now->setTimezone($zone)->setTime(0, 0);       // from the start of today, so today's earlier events still show
        $to = $from->modify("+{$days} days");
        $all = [];
        $errors = [];
        $feeds = $this->links->list($userId, 'ics');
        foreach ($feeds as $f) {
            try {
                $url = $this->vault->decrypt($f['secret_ct'], $f['secret_ver']);
                $text = ($this->cache)('ics:'.$f['id'], self::CACHE_SECONDS, fn () => $this->fetcher->fetch($url));
                foreach (Ics::between(Ics::parse($text, $tz), $from, $to) as $e) {
                    $all[] = $e + ['calendar' => $f['name']];
                }
                if ($f['last_error'] !== null) {
                    $this->links->touch($tenantId, $userId, $f['id'], null);
                }
            } catch (IcsException $e) {
                $errors[] = $f['name'].': '.$e->getMessage();
                $this->links->touch($tenantId, $userId, $f['id'], mb_substr($e->getMessage(), 0, 180));
            } catch (\Throwable $e) {
                $errors[] = $f['name'].': The calendar could not be read.';
                $this->links->touch($tenantId, $userId, $f['id'], 'read');
            }
        }
        $connected = 0;
        if ($this->accounts !== null) {                          // the person's OAuth-connected Google / Microsoft calendars
            $oauth = $this->accounts->events($tenantId, $userId, $from, $to, $zone);
            array_push($all, ...$oauth['events']);
            array_push($errors, ...$oauth['errors']);
            $connected = $oauth['count'];
        }
        usort($all, fn ($a, $b) => [$a['start']->getTimestamp(), $a['summary']] <=> [$b['start']->getTimestamp(), $b['summary']]);
        $events = [];
        $seen = [];
        $unique = [];
        foreach ($all as $e) {                                  // the same meeting can arrive from an ICS feed and from OAuth: show it once
            $k = $e['start']->getTimestamp().'|'.$e['end']->getTimestamp().'|'.mb_strtolower($e['summary']);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $unique[] = $e;
        }
        foreach (array_slice($unique, 0, self::MAX_EVENTS) as $e) {
            $events[] = ['start' => $e['start']->setTimezone($zone)->format('Y-m-d\TH:i'), 'end' => $e['end']->setTimezone($zone)->format('Y-m-d\TH:i'), 'all_day' => $e['all_day'],
                'summary' => $e['summary'] === '' ? '(Untitled)' : $e['summary'], 'location' => $e['location'], 'calendar' => $e['calendar']];
        }

        return ['events' => $events, 'errors' => $errors, 'feeds' => count($feeds) + $connected];
    }

    public function removeFeed(string $tenantId, string $userId, string $id): string
    {
        return $this->links->delete($tenantId, $userId, $id);
    }

    /** Plain text for people and for the AI: one line per event. @param list<array{start: string, all_day: bool, summary: string, location: string, calendar: string}> $events */
    public static function lines(array $events): string
    {
        $out = [];
        foreach ($events as $e) {
            $when = $e['all_day'] ? substr($e['start'], 0, 10).' (All day)' : str_replace('T', ' ', $e['start']);
            $out[] = "- {$when} {$e['summary']}".($e['location'] !== '' ? " @ {$e['location']}" : '');
        }

        return $out === [] ? '(No events in this period)' : implode("\n", $out);
    }
}
