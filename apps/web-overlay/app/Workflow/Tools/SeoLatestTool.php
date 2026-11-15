<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Marketing\MarketingAccess;
use App\Seo\SeoRepo;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;

/** The latest finished SEO audit of each site (up to 3): score, change since the previous audit, and the most important problems.  Reads stored audits only; it never crawls a site. Needs ads.view like the SEO page. */
final class SeoLatestTool implements Tool
{
    private const SITES = 3;

    private const ISSUES = 5;

    public function __construct(private readonly SeoRepo $repo, private readonly MarketingAccess $access) {}

    public function key(): string
    {
        return 'seo.latest';
    }

    public function label(): string
    {
        return 'Latest SEO audit result (read-only)';
    }

    public function risk(): string
    {
        return Risk::READ;
    }

    public function args(): array
    {
        return ['domain?' => 'A specific site (for example example.com); if not given, the latest 3 sites'];
    }

    public function run(ToolContext $c, array $args): string
    {
        if (! $this->access->canViewAds($c->tenantId, $c->userId)) {
            return '(SEO No permission to view the data)';
        }
        $want = strtolower(trim($args['domain'] ?? ''));
        $want = (string) preg_replace('/^(https?:\/\/)?(www\.)?/', '', $want);
        $want = rtrim(explode('/', $want)[0], '.');
        $seen = [];
        $lines = [];
        foreach ($this->repo->recent(50) as $row) {
            $domain = strtolower($row['domain']);
            $bare = (string) preg_replace('/^www\./', '', $domain);
            if ($row['status'] !== 'ok' || isset($seen[$bare]) || ($want !== '' && $bare !== $want)) {
                continue;
            }
            $seen[$bare] = true;
            $a = $this->repo->find($row['id']);
            if ($a === null) {
                continue;
            }
            $line = '- '.self::clean($row['domain'], 80).': Score '.$row['score'].'/১০০ ('.$row['created_at'].')';
            if ($a['previous_score'] !== null) {
                $d = (int) $row['score'] - $a['previous_score'];
                $line .= ' — Compared with the previous audit '.($d > 0 ? '+' : '').$d;
            }
            foreach (array_slice($a['result']['issues'] ?? [], 0, self::ISSUES) as $i) {
                $line .= "\n  • [".self::sev((string) ($i['severity'] ?? '')).'] '.self::clean((string) ($i['label'] ?? ''), 120).' ('.(int) ($i['count'] ?? 0).' pages) — '.self::clean((string) ($i['advice'] ?? ''), 200);
            }
            $lines[] = $line;
            if (count($lines) >= self::SITES) {
                break;
            }
        }

        return $lines === [] ? '(No SEO audit — run an audit at /app/seo)' : implode("\n", $lines);
    }

    private static function sev(string $s): string
    {
        return ['high' => 'Urgent', 'medium' => 'Medium'][$s] ?? 'Small';
    }

    private static function clean(string $s, int $max): string
    {
        return trim(mb_substr((string) preg_replace('/[\x00-\x1f\x7f"]+/u', ' ', $s), 0, $max));
    }
}
