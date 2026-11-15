<?php

declare(strict_types=1);

namespace App\Reports;

use App\Proposals\ProposalEngine;
use App\Proposals\ProposalEngineUnavailable;

/** Gathers the copied numbers for a period and builds / renders the client report.  Read-only: it never calls Meta, Google or any AI. */
final class ReportService
{
    public function __construct(private readonly ReportRepo $repo, private readonly ProposalEngine $engine) {}

    /**
     * @param  array{kind: string, language: string, client_name?: string, notes?: string, client_id?: ?string}  $opts
     * @return array{error: ?string, doc: array<string, mixed>, range?: array<string, mixed>}  error: bad_period | no_data
     */
    public function build(array $opts, string $zone, bool $withSocial, ?\DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone($zone)))->setTimezone(new \DateTimeZone($zone));
        $range = ReportPeriod::range((string) $opts['kind'], $today);
        if ($range === null) {
            return ['error' => 'bad_period', 'doc' => []];
        }
        $cid = isset($opts['client_id']) && $opts['client_id'] !== '' ? (string) $opts['client_id'] : null;
        $ads = ['meta' => $this->repo->ads('meta', $range['from'], $range['to'], $cid), 'google' => $this->repo->ads('google', $range['from'], $range['to'], $cid)];
        $prev = $range['prev_from'] === null ? [] : ['meta' => $this->repo->ads('meta', $range['prev_from'], (string) $range['prev_to'], $cid), 'google' => $this->repo->ads('google', $range['prev_from'], (string) $range['prev_to'], $cid)];
        $brand = $this->repo->brand($cid);
        $r = ReportBuilder::build(['language' => (string) $opts['language'], 'kind' => (string) $opts['kind'], 'range' => $range, 'today' => $today, 'client_name' => (string) ($opts['client_name'] ?? ''), 'notes' => (string) ($opts['notes'] ?? ''),
            'brand' => $brand, 'ads' => $ads, 'prev' => $prev, 'social' => $withSocial ? $this->repo->social($range['from'], $range['to'], $zone, $cid) : null]);

        return $r + ['range' => $range];
    }

    /**
     * @param  array{kind: string, language: string, client_name?: string, notes?: string, client_id?: ?string}  $opts
     * @param  'pdf'|'docx'  $format
     * @return array{ok: true, bytes: string, filename: string, mime: string}|array{ok: false, error: string}  error: bad_period | no_data | unavailable
     */
    public function render(string $tenantId, string $userId, array $opts, string $zone, bool $withSocial, string $format, ?\DateTimeImmutable $now = null): array
    {
        $b = $this->build($opts, $zone, $withSocial, $now);
        if ($b['error'] !== null) {
            return ['ok' => false, 'error' => $b['error']];
        }
        try {
            $bytes = $this->engine->render($tenantId, $userId, $b['doc'], $format);
        } catch (ProposalEngineUnavailable) {
            return ['ok' => false, 'error' => 'unavailable'];
        }

        return ['ok' => true, 'bytes' => $bytes, 'filename' => 'report-'.$opts['kind'].'-'.$b['range']['to'].'.'.$format,
            'mime' => $format === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf'];
    }
}
