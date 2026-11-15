<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Actions\Tenancy\CreateWorkspace;
use App\Links\LinkRepo;
use App\Links\Secrets;
use App\Outreach\OAuthFailure;
use App\Outreach\OAuthProvider;
use App\Outreach\OAuthRevoked;
use DateTimeImmutable;

/**
 * The person's own Google / Microsoft calendars, connected by OAuth.  Stored in user_links (kind gcal / mcal; name = e-mail; secret = the encrypted refresh token).
 * Reads their events and adds ONE event at a time to their primary calendar - never guests, never edits or deletes an existing event.  Only for the signed-in person.
 */
final class CalendarAccounts
{
    public const KINDS = ['google' => 'gcal', 'microsoft' => 'mcal'];

    public const MAX_TITLE = 200;

    public const MAX_LOCATION = 200;

    public const MIN_MINUTES = 5;

    public const MAX_MINUTES = 1440;

    public const MESSAGES = [
        'ok' => '', 'bad_input' => 'The event details are not valid: give the name, start time (within 2 years from today) and duration (5 minutes–24 hours).', 'not_found' => 'This calendar connection was not found.',
        'revoked' => 'Calendar permission was withdrawn — connect again.', 'auth' => 'The calendar did not grant permission — connect again and grant all permissions.',
        'unavailable' => 'The calendar service is not responding now — try shortly.', 'api' => 'The event was not added to the calendar. Try again shortly.',
        'not_configured' => 'The OAuth key for this connection is not set on the server.', 'limit' => 'At most 5 calendars of this type can be added.',
    ];

    /**
     * @param  array<string, OAuthProvider>  $providers  'google' | 'microsoft'
     * @param  array<string, CalendarApi>  $apis  'google' | 'microsoft'
     */
    public function __construct(private readonly LinkRepo $links, private readonly Secrets $vault, private readonly array $providers, private readonly array $apis, private readonly \Closure $now) {}

    public static function message(string $status): string
    {
        return self::MESSAGES[$status] ?? 'The action could not be done.';
    }

    /** @return list<array{id: string, kind: string, name: string, host: string, last_error: ?string}> */
    public function list(string $userId): array
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            foreach ($this->links->list($userId, $kind) as $l) {
                $out[] = ['id' => $l['id'], 'kind' => $l['kind'], 'name' => $l['name'], 'host' => $l['host'], 'last_error' => $l['last_error']];
            }
        }

        return $out;
    }

    public function configured(string $provider): bool
    {
        return isset($this->providers[$provider]) && $this->providers[$provider]->configured();
    }

    /** Saves a just-authorised calendar; connecting the same e-mail again replaces the old one (a new refresh token). @return string ok|limit|bad_input */
    public function connect(string $tenantId, string $userId, string $provider, string $email, string $refreshToken): string
    {
        $kind = self::KINDS[$provider] ?? null;
        if ($kind === null || $email === '' || $refreshToken === '') {
            return 'bad_input';
        }
        $enc = $this->vault->encrypt($refreshToken);
        $old = $this->links->findByName($userId, $kind, $email);
        if ($old !== null) {
            return $this->links->updateSecret($tenantId, $userId, $old['id'], $enc['cipher'], $enc['version']) === 'ok' ? 'ok' : 'bad_input';
        }

        $r = $this->links->add($tenantId, $userId, CreateWorkspace::newId(), $kind, mb_substr($email, 0, 60), $enc['cipher'], $enc['version'], $provider);

        return in_array($r, ['ok', 'limit'], true) ? $r : 'bad_input';
    }

    /**
     * Events of every connected calendar between two moments, in the same shape the ICS feeds give.  A calendar that fails is reported and skipped; the others still show.
     *
     * @return array{events: list<array{start: DateTimeImmutable, end: DateTimeImmutable, all_day: bool, summary: string, location: string, calendar: string}>, errors: list<string>, count: int}
     */
    public function events(string $tenantId, string $userId, DateTimeImmutable $from, DateTimeImmutable $to, \DateTimeZone $zone): array
    {
        $events = [];
        $errors = [];
        $links = [];
        foreach (self::KINDS as $kind) {
            array_push($links, ...$this->links->list($userId, $kind));
        }
        foreach ($links as $l) {
            $status = 'ok';
            try {
                $api = $this->apiFor($l);
                $token = $this->token($tenantId, $userId, $l);
                foreach ($api->events($token, $from, $to, $zone) as $e) {
                    $events[] = $e + ['calendar' => $l['name']];
                }
            } catch (CalendarFailure $f) {
                $status = $f->status;
            } catch (CalendarApiException $e) {
                $status = $e->getMessage() === 'auth' ? 'auth' : 'api';
            }
            if ($status !== 'ok') {
                $errors[] = $l['name'].': '.self::message($status);
                $this->mark($tenantId, $userId, $l['id'], $status);
            } elseif ($l['last_error'] !== null) {
                $this->links->touch($tenantId, $userId, $l['id'], null);
            }
        }

        return ['events' => $events, 'errors' => $errors, 'count' => count($links)];
    }

    /**
     * Adds an event.  $startLocal is "YYYY-MM-DD HH:MM" or "YYYY-MM-DDTHH:MM" in the person's own zone.
     *
     * @return array{status: string, id: ?string} status ok|bad_input|not_found|revoked|auth|unavailable|api|not_configured
     */
    public function create(string $tenantId, string $userId, ?string $linkId, string $title, string $startLocal, int $minutes, string $location, \DateTimeZone $zone): array
    {
        $title = self::clean($title, self::MAX_TITLE);
        $location = self::clean($location, self::MAX_LOCATION);
        $start = self::parse($startLocal, $zone);
        $now = ($this->now)();
        if ($title === '' || $start === null || $minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES
            || $start < $now->modify('-1 day') || $start > $now->modify('+2 years')) {
            return ['status' => 'bad_input', 'id' => null];
        }
        $link = null;
        if ($linkId === null || $linkId === '') {                      // the first connected calendar
            foreach (self::KINDS as $kind) {
                $link ??= $this->links->list($userId, $kind)[0] ?? null;
            }
        } else {
            $link = $this->links->find($userId, $linkId);
            $link = $link !== null && in_array($link['kind'], self::KINDS, true) ? $link : null;
        }
        if ($link === null) {
            return ['status' => 'not_found', 'id' => null];
        }
        try {
            $api = $this->apiFor($link);
            $token = $this->token($tenantId, $userId, $link);
            $id = $api->create($token, $title, $start, $start->modify("+{$minutes} minutes"), $location);
        } catch (CalendarFailure $f) {
            $this->mark($tenantId, $userId, $link['id'], $f->status);

            return ['status' => $f->status, 'id' => null];
        } catch (CalendarApiException $e) {
            $s = $e->getMessage() === 'auth' ? 'auth' : 'api';
            $this->mark($tenantId, $userId, $link['id'], $s);

            return ['status' => $s, 'id' => null];
        }
        $this->links->touch($tenantId, $userId, $link['id'], null);

        return ['status' => 'ok', 'id' => $id];
    }

    /** @param array{host: string} $link */
    private function apiFor(array $link): CalendarApi
    {
        return $this->apis[$link['host']] ?? throw new CalendarFailure('not_configured');
    }

    /**
     * A short-lived access token from the stored refresh token.
     *
     * @param  array{id: string, host: string, secret_ct: string, secret_ver: int}  $link
     *
     * @throws CalendarFailure
     */
    private function token(string $tenantId, string $userId, array $link): string
    {
        $p = $this->providers[$link['host']] ?? null;
        if ($p === null || ! $p->configured()) {
            throw new CalendarFailure('not_configured');
        }
        try {
            $refresh = $this->vault->decrypt($link['secret_ct'], $link['secret_ver']);
            $t = $p->refresh($refresh);
        } catch (OAuthRevoked) {
            throw new CalendarFailure('revoked');
        } catch (OAuthFailure|\RuntimeException) {
            throw new CalendarFailure('unavailable');                  // a passing outage: the link keeps no error mark
        }
        if ($t['refresh_token'] !== null && $t['refresh_token'] !== $refresh) {
            $e = $this->vault->encrypt($t['refresh_token']);
            $this->links->updateSecret($tenantId, $userId, $link['id'], $e['cipher'], $e['version']);
        }

        return $t['access_token'];
    }

    /** Remembers why a calendar failed, so the page can show it - except for a passing outage or a missing server key, which say nothing about the person's connection. */
    private function mark(string $tenantId, string $userId, string $id, string $status): void
    {
        if (! in_array($status, ['unavailable', 'not_configured'], true)) {
            $this->links->touch($tenantId, $userId, $id, $status);
        }
    }

    private static function clean(string $s, int $max): string
    {
        return trim(mb_substr((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $s), 0, $max));
    }

    private static function parse(string $s, \DateTimeZone $zone): ?DateTimeImmutable
    {
        $s = trim($s);
        if (preg_match('/\A(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})\z/', $s, $m) !== 1) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $m[1].' '.$m[2], $zone);
        $err = DateTimeImmutable::getLastErrors();

        return $d === false || ($err !== false && ($err['warning_count'] > 0 || $err['error_count'] > 0)) ? null : $d;
    }
}
