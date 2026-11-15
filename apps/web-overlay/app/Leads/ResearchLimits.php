<?php

declare(strict_types=1);

namespace App\Leads;

use Illuminate\Support\Facades\DB;

/** Guards against runaway jobs. Crawling costs no credits in v1, so volume is limited here instead. */
final class ResearchLimits
{
    public function __construct(
        private readonly int $maxUrlsPerJob = 200,
        private readonly int $maxJobsPerDay = 20,
        private readonly int $maxActiveJobs = 2,
    ) {}

    public static function fromConfig(): self
    {
        return new self((int) config('leads.max_urls_per_job', 200), (int) config('leads.max_jobs_per_day', 20), (int) config('leads.max_active_jobs', 2));
    }

    public function maxUrls(): int
    {
        return $this->maxUrlsPerJob;
    }

    /** @return string|null a user-facing reason, or null when a new job may start (tenant context must be set) */
    public function deny(): ?string
    {
        $active = (int) DB::scalar("select count(*) from lead_research_jobs where status in ('queued', 'running')");
        if ($active >= $this->maxActiveJobs) {
            return "At most {$this->maxActiveJobs} researches can run at once. Try again when one finishes.";
        }
        $today = (int) DB::scalar("select count(*) from lead_research_jobs where created_at > now() - interval '24 hours'");
        if ($today >= $this->maxJobsPerDay) {
            return "At most {$this->maxJobsPerDay} researches can be started in 24 hours.";
        }

        return null;
    }
}
