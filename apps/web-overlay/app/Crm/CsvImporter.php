<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\LeadIngestor;
use App\Leads\LeadStore;

/** Puts parsed CSV rows into the lead tables without creating duplicates. Existing leads keep their stage and tags. */
final class CsvImporter
{
    public function __construct(private readonly LeadStore $leads, private readonly CrmStore $crm) {}

    /**
     * @param  array{rows: list<array<string, mixed>>}  $parsed  output of LeadCsv::parse
     * @return array{created: int, existing: int, contacts_added: int, contacts_skipped: int}
     */
    public function import(string $tenantId, string $userId, array $parsed): array
    {
        $r = ['created' => 0, 'existing' => 0, 'contacts_added' => 0, 'contacts_skipped' => 0];
        foreach ($parsed['rows'] as $row) {
            $domain = (string) $row['domain'];
            $company = $this->leads->findCompany($tenantId, $domain);
            $companyId = $company['id'] ?? $this->leads->createCompany($tenantId, [
                'name' => $row['name'] !== '' ? $row['name'] : $domain,
                'domain' => $domain,
                'website' => 'https://'.$domain.'/',
                'description' => null,
                'address' => null,
                'analysis' => [],
                'score' => null,
                'analyzed' => false,
            ]);
            $isNew = $this->leads->ensureLead($tenantId, $companyId, 0, 'import', null, $userId);
            $leadId = $this->crm->leadForCompany($companyId);
            if ($leadId === null) {
                continue;
            }

            foreach ($row['emails'] as $email) {
                $this->contact($tenantId, $companyId, ['email' => $email, 'email_status' => 'unverified'], $r);
            }
            foreach ($row['phones'] as $phone) {
                $this->contact($tenantId, $companyId, ['phone' => $phone], $r);
            }

            if (! $isNew) {
                $r['existing']++;
            } else {
                $r['created']++;
                $this->crm->addActivity($tenantId, $leadId, $userId, 'import', 'CSV Added from');
                if (($row['stage'] ?? null) !== null && $row['stage'] !== 'new') {
                    $this->crm->moveStage($leadId, (string) $row['stage'], $userId);
                }
                if ($row['tags'] !== []) {
                    $this->crm->setTags($leadId, $row['tags']);
                }
            }
            if ($row['note'] !== '') {
                $this->crm->addActivity($tenantId, $leadId, $userId, 'note', (string) $row['note']);
            }
        }

        return $r;
    }

    /** @param array<string, mixed> $c @param array<string, int> $r */
    private function contact(string $tenantId, string $companyId, array $c, array &$r): void
    {
        if ($this->leads->contactCount($companyId) >= LeadIngestor::MAX_CONTACTS_PER_COMPANY) {
            $r['contacts_skipped']++;

            return;
        }
        $this->leads->addContact($tenantId, $companyId, $c) ? $r['contacts_added']++ : $r['contacts_skipped']++;
    }
}
