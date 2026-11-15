<?php

declare(strict_types=1);

namespace App\Proposals;

use App\Crm\CrmStore;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/** What may be done with a proposal and in what shape. No HTTP, no SQL. */
final class ProposalService
{
    public const STATUSES = ['draft', 'sent', 'accepted', 'declined'];

    public const MAX_SECTIONS = 30;

    public const MAX_PORTFOLIO = 12;

    /** @param Closure(): DateTimeImmutable|null $clock */
    public function __construct(
        private readonly ProposalRepo $repo,
        private readonly CrmStore $crm,
        private readonly ProposalEngine $engine,
        private readonly ?Closure $clock = null,
    ) {}

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @return array{ok: true, id: string}|array{ok: false, error: string} */
    public function createFromTemplate(string $tenantId, string $userId, string $templateId, ?string $leadId, ?string $title = null): array
    {
        $tpl = $this->repo->template($templateId);
        if ($tpl === null) {
            return ['ok' => false, 'error' => 'template_not_found'];
        }
        $lang = $tpl['language'] === 'en' ? 'en' : 'bn';
        $lead = null;
        if ($leadId !== null) {
            $lead = $this->repo->leadSummary($leadId);
            if ($lead === null) {
                return ['ok' => false, 'error' => 'lead_not_found'];
            }
        }
        $brand = $this->repo->brand();
        $client = $lead['name'] ?? '';
        $vars = [
            'client_name' => $client !== '' ? $client : ($lang === 'en' ? 'Sir/Madam' : 'Sir/Madam'),
            'domain' => $lead['domain'] ?? '',
            'brand_name' => (string) ($brand['name'] ?? ''),
            'gaps_list' => TemplateFiller::gapsList($lead['analysis'] ?? [], $lang),
        ];
        $title = trim((string) $title) !== '' ? mb_substr(trim((string) $title), 0, 200)
            : mb_substr(($lang === 'en' ? 'Proposal' : 'Proposals').($client !== '' ? ' — '.$client : ''), 0, 200);
        $data = [
            'client_name' => mb_substr($client, 0, 200),
            'sections' => TemplateFiller::fill($tpl['sections'], $vars),
            'pricing' => ['items' => [], 'discount_bp' => 0, 'vat_bp' => 0, 'note' => ''],
            'portfolio_ids' => [],
        ];
        $id = $this->repo->create($tenantId, $userId, $leadId, $tpl['id'], $brand['id'] ?? null, $title, $lang, $data);
        if ($leadId !== null) {
            $this->crm->addActivity($tenantId, $leadId, $userId, 'proposal', $title, ['event' => 'created', 'proposal_id' => $id]);
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param  array{title?: string, language?: string, client_name?: string, sections?: array, pricing_text?: string, discount?: string, vat?: string, note?: string, portfolio_ids?: array}  $in
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function save(string $id, array $in): array
    {
        $p = $this->repo->find($id);
        if ($p === null) {
            return ['ok' => false, 'errors' => ['_' => 'not_found']];
        }
        $err = [];
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            $err['title'] = 'Give a title (max 200 characters).';
        }
        $lang = (string) ($in['language'] ?? $p['language']);
        if (! in_array($lang, ['bn', 'en'], true)) {
            $err['language'] = 'The language is not valid.';
        }
        $client = trim((string) ($in['client_name'] ?? ''));
        if (mb_strlen($client) > 200) {
            $err['client_name'] = 'The client name is over 200 characters.';
        }

        $sections = [];
        foreach ((array) ($in['sections'] ?? []) as $s) {
            $h = trim((string) ($s['heading'] ?? ''));
            $b = trim((string) ($s['body'] ?? ''));
            if ($h === '' && $b === '') {
                continue;
            }
            if (mb_strlen($h) > 160 || mb_strlen($b) > 8000) {
                $err['sections'] = 'The title is at most 160 and the text 8000 characters.';

                continue;
            }
            $sections[] = ['heading' => $h, 'body' => $b];
        }
        if (count($sections) > self::MAX_SECTIONS) {
            $err['sections'] = 'max '.Money::toBnDigits((string) self::MAX_SECTIONS).' sections.';
        }

        $parsed = Pricing::parseLines((string) ($in['pricing_text'] ?? ''));
        if ($parsed['errors'] !== []) {
            $err['pricing'] = implode('; ', array_map(fn ($e) => 'Line '.$e['line'].': '.$e['reason'], array_slice($parsed['errors'], 0, 5)));
        }
        $discount = Pricing::parsePercent((string) ($in['discount'] ?? ''));
        if ($discount === null) {
            $err['discount'] = 'The discount must be between 0 and 100.';
        }
        $vat = Pricing::parsePercent((string) ($in['vat'] ?? ''));
        if ($vat === null) {
            $err['vat'] = 'The VAT must be between 0 and 100.';
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 1000) {
            $err['note'] = 'The note is over 1000 characters.';
        }
        if ($err !== []) {
            return ['ok' => false, 'errors' => $err];
        }

        $ids = array_slice(array_values(array_unique(array_filter(array_map('strval', (array) ($in['portfolio_ids'] ?? [])), fn ($x) => preg_match('/\A[0-9a-z]{26}\z/', $x) === 1))), 0, self::MAX_PORTFOLIO);
        $kept = array_column($this->repo->portfolioByIds($ids), 'id');
        $this->repo->update($id, $title, $lang, [
            'client_name' => $client,
            'sections' => $sections,
            'pricing' => ['items' => $parsed['items'], 'discount_bp' => $discount, 'vat_bp' => $vat, 'note' => $note],
            'portfolio_ids' => array_values(array_intersect($ids, $kept)),
        ]);

        return ['ok' => true, 'errors' => []];
    }

    /** @return array{ok: bool, error?: string} */
    public function setStatus(string $id, string $status, string $userId): array
    {
        if (! in_array($status, self::STATUSES, true)) {
            return ['ok' => false, 'error' => 'bad_status'];
        }
        $p = $this->repo->find($id);
        if ($p === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ($p['status'] === $status) {
            return ['ok' => true];
        }
        $this->repo->setStatus($id, $status);
        if ($p['lead_id'] !== null) {
            $this->crm->addActivity($p['tenant_id'], $p['lead_id'], $userId, 'proposal', $p['title'], ['event' => $status, 'proposal_id' => $id]);
        }

        return ['ok' => true];
    }

    /** @return array<string, mixed>|null the engine payload */
    public function document(string $id, string $tz): ?array
    {
        $p = $this->repo->find($id);
        if ($p === null) {
            return null;
        }
        $d = $p['data'];
        $pins = array_values(array_filter((array) ($d['portfolio_ids'] ?? []), 'is_string'));

        return DocumentBuilder::build([
            'title' => $p['title'],
            'language' => $p['language'],
            'client_name' => (string) ($d['client_name'] ?? ''),
            'sections' => (array) ($d['sections'] ?? []),
            'pricing' => (array) ($d['pricing'] ?? []),
            'portfolio' => $this->repo->portfolioByIds($pins),
            'brand' => $this->repo->brand(),
            'date' => $this->now()->setTimezone(new DateTimeZone(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Asia/Dhaka')),
        ]);
    }

    /** @param 'pdf'|'docx' $format @return array{ok: true, bytes: string, filename: string, mime: string}|array{ok: false, error: string} */
    public function render(string $tenantId, string $userId, string $id, string $format, string $tz): array
    {
        $doc = $this->document($id, $tz);
        if ($doc === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        try {
            $bytes = $this->engine->render($tenantId, $userId, $doc, $format);
        } catch (ProposalEngineUnavailable) {
            return ['ok' => false, 'error' => 'unavailable'];
        }

        return [
            'ok' => true, 'bytes' => $bytes, 'filename' => 'proposal-'.substr($id, 0, 8).'.'.$format,
            'mime' => $format === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf',
        ];
    }
}
