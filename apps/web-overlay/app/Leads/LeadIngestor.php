<?php

declare(strict_types=1);

namespace App\Leads;

/**
 * Turns one crawl result into company + contacts + lead, without ever creating duplicates:
 * same domain -> same company; one live lead per company; an email/phone already known is skipped.
 */
final class LeadIngestor
{
    public const MAX_CONTACTS_PER_COMPANY = 10;

    public function __construct(private readonly LeadStore $store) {}

    /**
     * @param  array<string, mixed>  $r  engine result with status ok
     * @return array{company_id: string, company_created: bool, lead_created: bool, contacts_added: int, contacts_duplicate: int}
     */
    public function ingest(string $tenantId, ?string $userId, ?string $jobId, array $r): array
    {
        $domain = DomainNormalizer::validHost(strtolower((string) ($r['domain'] ?? ''))) ?? throw new \InvalidArgumentException('result without a valid domain');
        $score = max(0, min(100, (int) ($r['score'] ?? 0)));
        $co = is_array($r['company'] ?? null) ? $r['company'] : [];
        $analysis = [
            'gaps' => array_values(array_filter((array) ($r['gaps'] ?? []), 'is_array')),
            'social' => is_array($r['social'] ?? null) ? $r['social'] : [],
            'tech' => array_values(array_filter((array) ($r['tech'] ?? []), 'is_string')),
            'tracking' => is_array($r['tracking'] ?? null) ? $r['tracking'] : [],
            'has_form' => (bool) ($r['has_form'] ?? false),
            'pages_fetched' => (int) ($r['pages_fetched'] ?? 0),
        ];
        $data = [
            'name' => self::cut((string) ($co['name'] ?? ''), 200) ?: $domain,
            'website' => self::cut((string) ($r['final_url'] ?? $r['url'] ?? 'https://'.$domain.'/'), 2048),
            'description' => self::cut((string) ($co['description'] ?? ''), 500) ?: null,
            'address' => self::cut((string) ($co['address'] ?? ''), 300) ?: null,
            'analysis' => $analysis,
            'score' => $score,
        ];

        $existing = $this->store->findCompany($tenantId, $domain);
        $created = $existing === null;
        if ($created) {
            $companyId = $this->store->createCompany($tenantId, $data + ['domain' => $domain]);
        } else {
            $companyId = $existing['id'];
            unset($data['name']);                                       // a name the user may have edited is never overwritten
            $this->store->updateCompany($companyId, $data);
        }

        $leadCreated = $this->store->ensureLead($tenantId, $companyId, $score, 'research', $jobId, $userId);
        if (! $leadCreated) {
            $this->store->updateLeadScore($tenantId, $companyId, $score);
        }

        $added = 0;
        $dup = 0;
        $room = self::MAX_CONTACTS_PER_COMPANY - $this->store->contactCount($companyId);
        foreach ((array) ($r['emails'] ?? []) as $e) {
            if (! is_array($e) || ! isset($e['email'])) {
                continue;
            }
            $email = strtolower(trim((string) $e['email']));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
                continue;
            }
            if ($room <= 0) {
                break;
            }
            $status = in_array($e['status'] ?? '', ['syntax_ok', 'mx_ok', 'no_mx'], true) ? $e['status'] : 'syntax_ok';
            if ($this->store->addContact($tenantId, $companyId, ['email' => $email, 'email_status' => $status, 'source_url' => self::cut((string) ($e['source_url'] ?? ''), 2048)])) {
                $added++;
                $room--;
            } else {
                $dup++;
            }
        }
        foreach ((array) ($r['phones'] ?? []) as $p) {
            if (! is_array($p) || preg_match('/\A\+[1-9][0-9]{6,14}\z/', (string) ($p['e164'] ?? '')) !== 1) {
                continue;
            }
            if ($room <= 0) {
                break;
            }
            if ($this->store->addContact($tenantId, $companyId, ['phone' => $p['e164'], 'phone_type' => self::cut((string) ($p['type'] ?? ''), 10), 'source_url' => self::cut((string) ($p['source_url'] ?? ''), 2048)])) {
                $added++;
                $room--;
            } else {
                $dup++;
            }
        }

        return ['company_id' => $companyId, 'company_created' => $created, 'lead_created' => $leadCreated, 'contacts_added' => $added, 'contacts_duplicate' => $dup];
    }

    private static function cut(string $s, int $max): string
    {
        return mb_substr(trim($s), 0, $max);
    }
}
