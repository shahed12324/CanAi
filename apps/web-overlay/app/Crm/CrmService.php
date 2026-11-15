<?php

declare(strict_types=1);

namespace App\Crm;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/** The rules of the CRM: what may be written to a lead, in what shape. No HTTP, no SQL — easy to test. */
final class CrmService
{
    public const STAGES = ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'];

    public const NOTE_MAX = 2000;

    public const TASK_MAX = 200;

    /** @param Closure(): DateTimeImmutable|null $clock */
    public function __construct(private readonly CrmStore $crm, private readonly ?Closure $clock = null) {}

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @return array{ok: true, from: string}|array{ok: false, error: string} */
    public function moveStage(string $leadId, string $stage, string $userId): array
    {
        if (! in_array($stage, self::STAGES, true)) {
            return ['ok' => false, 'error' => 'bad_stage'];
        }
        $from = $this->crm->moveStage($leadId, $stage, $userId);

        return $from === null ? ['ok' => false, 'error' => 'not_found'] : ['ok' => true, 'from' => $from];
    }

    /** @return array{ok: bool, error?: string} */
    public function addNote(string $leadId, string $userId, string $body): array
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::NOTE_MAX) {
            return ['ok' => false, 'error' => 'bad_note'];
        }
        $lead = $this->crm->lead($leadId);
        if ($lead === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $this->crm->addActivity($lead['tenant_id'], $leadId, $userId, 'note', $body);

        return ['ok' => true];
    }

    /**
     * @param  string|null  $due  "2026-11-05" or "2026-11-05T14:30", in the workspace's timezone
     * @return array{ok: bool, error?: string, id?: string}
     */
    public function addTask(string $leadId, string $userId, string $title, ?string $due, string $tz): array
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > self::TASK_MAX) {
            return ['ok' => false, 'error' => 'bad_title'];
        }
        $dueUtc = null;
        if ($due !== null && trim($due) !== '') {
            $dueUtc = $this->localToUtc(trim($due), $tz);
            if ($dueUtc === null) {
                return ['ok' => false, 'error' => 'bad_date'];
            }
        }
        $lead = $this->crm->lead($leadId);
        if ($lead === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $id = $this->crm->addTask($lead['tenant_id'], $leadId, $userId, $title, $dueUtc, $userId);
        $this->crm->addActivity($lead['tenant_id'], $leadId, $userId, 'task', $title, ['event' => 'created']);

        return ['ok' => true, 'id' => $id];
    }

    /** @return array{ok: bool, error?: string} */
    public function completeTask(string $taskId, string $userId): array
    {
        $t = $this->crm->completeTask($taskId);
        if ($t === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $this->crm->addActivity($t['tenant_id'], $t['lead_id'], $userId, 'task', $t['title'], ['event' => 'done']);

        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string, tags?: list<string>, truncated?: bool} */
    public function setTags(string $leadId, string $userId, string $input): array
    {
        $lead = $this->crm->lead($leadId);
        if ($lead === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $p = TagNormalizer::parse($input);
        $this->crm->setTags($leadId, $p['tags']);
        $this->crm->addActivity($lead['tenant_id'], $leadId, $userId, 'tag', implode(', ', $p['tags']), ['tags' => $p['tags']]);

        return ['ok' => true, 'tags' => $p['tags'], 'truncated' => $p['truncated']];
    }

    /**
     * Empty/null clears the follow-up. A closed lead (won/lost) cannot have one, and a date cannot be in the past (workspace timezone).
     *
     * @return array{ok: bool, error?: string}
     */
    public function setFollowUp(string $leadId, string $userId, ?string $date, string $tz): array
    {
        $lead = $this->crm->lead($leadId);
        if ($lead === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $date = $date === null ? '' : trim($date);
        if ($date === '') {
            $this->crm->setFollowUp($leadId, null);
            $this->crm->addActivity($lead['tenant_id'], $leadId, $userId, 'follow_up', '', ['at' => null]);

            return ['ok' => true];
        }
        if (in_array($lead['stage'], ['won', 'lost'], true)) {
            return ['ok' => false, 'error' => 'lead_closed'];
        }
        $local = $this->parseLocal($date, $tz, true);
        if ($local === null) {
            return ['ok' => false, 'error' => 'bad_date'];
        }
        $today = $this->now()->setTimezone(new DateTimeZone($tz))->setTime(0, 0);
        if ($local->setTime(0, 0) < $today) {
            return ['ok' => false, 'error' => 'past_date'];
        }
        $utc = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        $this->crm->setFollowUp($leadId, $utc);
        $this->crm->addActivity($lead['tenant_id'], $leadId, $userId, 'follow_up', '', ['at' => $utc]);

        return ['ok' => true];
    }

    private function localToUtc(string $s, string $tz): ?string
    {
        $d = $this->parseLocal($s, $tz, false);

        return $d?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }

    /** Date-only input means 09:00 when $morning, otherwise the end of that working day is not guessed: 09:00 too. */
    private function parseLocal(string $s, string $tz, bool $morning): ?DateTimeImmutable
    {
        if (! in_array($tz, timezone_identifiers_list(), true)) {
            $tz = 'UTC';
        }
        $zone = new DateTimeZone($tz);
        foreach (['!Y-m-d\TH:i' => false, '!Y-m-d' => true] as $format => $dateOnly) {
            $d = DateTimeImmutable::createFromFormat($format, $s, $zone);
            $err = DateTimeImmutable::getLastErrors();
            if ($d !== false && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
                if ((int) $d->format('Y') < 2000 || (int) $d->format('Y') > 2100) {
                    return null;
                }

                return $dateOnly ? $d->setTime(9, 0) : $d;
            }
        }

        return null;
    }
}
