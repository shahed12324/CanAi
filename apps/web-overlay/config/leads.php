<?php

return [
    'max_urls_per_job' => (int) env('LEADS_MAX_URLS_PER_JOB', 200),
    'max_jobs_per_day' => (int) env('LEADS_MAX_JOBS_PER_DAY', 20),
    'max_active_jobs' => (int) env('LEADS_MAX_ACTIVE_JOBS', 2),
    'csv_max_kb' => 512,
];
