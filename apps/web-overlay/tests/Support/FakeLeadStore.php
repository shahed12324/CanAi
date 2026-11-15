<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Leads\LeadStore;

final class FakeLeadStore implements LeadStore
{
    public array $jobs = [];

    public array $items = [];

    public array $companies = [];

    public array $leads = [];

    public array $contacts = [];

    private int $n = 0;

    private function id(string $p): string
    {
        return str_pad($p.(++$this->n), 26, '0', STR_PAD_LEFT);
    }

    public function createJob(string $tenantId, string $userId, string $name, string $inputType, array $items, array $options): string
    {
        $id = $this->id('J');
        $this->jobs[$id] = ['tenant' => $tenantId, 'user' => $userId, 'status' => 'queued', 'options' => $options];
        foreach ($items as $i) {
            $iid = $this->id('I');
            $this->items[$iid] = $i + ['id' => $iid, 'job_id' => $id, 'status' => 'queued', 'attempts' => 0, 'code' => null, 'company_id' => null];
        }

        return $id;
    }

    public function itemIds(string $jobId): array
    {
        return array_keys(array_filter($this->items, fn ($i) => $i['job_id'] === $jobId));
    }

    public function claimItem(string $itemId): ?array
    {
        $i = &$this->items[$itemId];
        $j = $this->jobs[$i['job_id']];
        if ($i['status'] !== 'queued' || $j['status'] === 'cancelled') {
            return null;
        }
        $i['status'] = 'running';
        $i['attempts']++;
        $this->jobs[$i['job_id']]['status'] = 'running';

        return ['id' => $itemId, 'job_id' => $i['job_id'], 'url' => $i['url'], 'domain' => $i['domain'], 'tenant_id' => $j['tenant'], 'user_id' => $j['user'], 'options' => $j['options']];
    }

    public function requeueItem(string $itemId): void
    {
        $this->items[$itemId]['status'] = 'queued';
    }

    public function finishItem(string $itemId, string $status, string $code, ?string $companyId): void
    {
        $this->items[$itemId] = ['status' => $status, 'code' => $code, 'company_id' => $companyId] + $this->items[$itemId];
        $this->items[$itemId]['status'] = $status;
        $this->items[$itemId]['code'] = $code;
        $this->items[$itemId]['company_id'] = $companyId;
    }

    public function refreshJob(string $jobId): void
    {
        $open = array_filter($this->items, fn ($i) => $i['job_id'] === $jobId && in_array($i['status'], ['queued', 'running'], true));
        if ($open === [] && $this->jobs[$jobId]['status'] !== 'cancelled') {
            $this->jobs[$jobId]['status'] = 'done';
        }
    }

    public function findCompany(string $tenantId, string $domain): ?array
    {
        foreach ($this->companies as $id => $c) {
            if ($c['tenant'] === $tenantId && $c['domain'] === $domain) {
                return ['id' => $id, 'name' => $c['name'], 'score' => $c['score']];
            }
        }

        return null;
    }

    public function createCompany(string $tenantId, array $data): string
    {
        $id = $this->id('C');
        $this->companies[$id] = ['tenant' => $tenantId, 'domain' => $data['domain'], 'name' => $data['name'], 'score' => $data['score'], 'data' => $data];

        return $id;
    }

    public function updateCompany(string $companyId, array $data): void
    {
        $this->companies[$companyId]['score'] = $data['score'];
        $this->companies[$companyId]['data'] = $data + $this->companies[$companyId]['data'];
    }

    public function ensureLead(string $tenantId, string $companyId, int $score, string $source, ?string $jobId, ?string $ownerId): bool
    {
        foreach ($this->leads as $l) {
            if ($l['company'] === $companyId) {
                return false;
            }
        }
        $this->leads[$this->id('L')] = ['tenant' => $tenantId, 'company' => $companyId, 'score' => $score, 'stage' => 'new', 'job' => $jobId];

        return true;
    }

    public function updateLeadScore(string $tenantId, string $companyId, int $score): void
    {
        foreach ($this->leads as $k => $l) {
            if ($l['company'] === $companyId) {
                $this->leads[$k]['score'] = $score;
            }
        }
    }

    public function addContact(string $tenantId, string $companyId, array $c): bool
    {
        foreach ($this->contacts as $x) {
            if ($x['tenant'] === $tenantId && isset($c['email']) && ($x['email'] ?? null) === $c['email']) {
                return false;
            }
            if ($x['company'] === $companyId && isset($c['phone']) && ($x['phone'] ?? null) === $c['phone']) {
                return false;
            }
        }
        $this->contacts[] = $c + ['tenant' => $tenantId, 'company' => $companyId];

        return true;
    }

    public function contactCount(string $companyId): int
    {
        return count(array_filter($this->contacts, fn ($c) => $c['company'] === $companyId));
    }
}
