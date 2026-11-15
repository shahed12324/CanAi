<?php

declare(strict_types=1);

namespace App\Leads;

/**
 * Runs the items of a research job one by one. The engine decides what a site is allowed to reveal (robots.txt, SSRF);
 * this class only turns outcomes into item statuses and never lets one bad site stop the job.
 */
final class ResearchRunner
{
    /** engine status -> [item status, result code] ; 'ok' is handled separately */
    private const FINAL = [
        'blocked_by_robots' => ['skipped', 'blocked_by_robots'],
        'unreachable' => ['failed', 'unreachable'],
        'not_html' => ['failed', 'not_html'],
        'unsafe_url' => ['failed', 'unsafe_url'],
        'timeout' => ['failed', 'timeout'],
    ];

    public function __construct(private readonly LeadStore $store, private readonly LeadEngine $engine, private readonly LeadIngestor $ingestor) {}

    /**
     * @return string final item status (done|failed|skipped) or 'retry' when the engine was unavailable and the item went back to the queue
     */
    public function process(string $itemId, bool $lastAttempt = false): string
    {
        $item = $this->store->claimItem($itemId);
        if ($item === null) {
            return 'skipped';                                          // already taken, finished or cancelled
        }
        $options = $item['options'];
        try {
            $result = $this->engine->crawl($item['tenant_id'], $item['user_id'], $item['url'], (int) ($options['max_pages'] ?? 4), (bool) ($options['render_js'] ?? false));
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }
            if ($lastAttempt) {
                $this->store->finishItem($itemId, 'failed', 'engine_error', null);
                $this->store->refreshJob($item['job_id']);

                return 'failed';
            }
            $this->store->requeueItem($itemId);

            return 'retry';
        }

        $status = (string) ($result['status'] ?? 'error');
        if ($status === 'ok') {
            try {
                $out = $this->ingestor->ingest($item['tenant_id'], $item['user_id'], $item['job_id'], $result);
                $this->store->finishItem($itemId, 'done', $out['company_created'] ? 'ok' : 'updated', $out['company_id']);
                $this->store->refreshJob($item['job_id']);

                return 'done';
            } catch (\Throwable $e) {
                if (function_exists('report')) {
                    report($e);
                }
                $this->store->finishItem($itemId, 'failed', 'save_failed', null);
                $this->store->refreshJob($item['job_id']);

                return 'failed';
            }
        }
        [$st, $code] = self::FINAL[$status] ?? ['failed', 'engine_error'];
        $this->store->finishItem($itemId, $st, $code, null);
        $this->store->refreshJob($item['job_id']);

        return $st;
    }
}
