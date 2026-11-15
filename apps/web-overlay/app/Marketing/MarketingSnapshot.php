<?php

declare(strict_types=1);

namespace App\Marketing;

/**
 * The numbers the Marketing Manager agent reasons about, as plain Bengali text.  Pure: no AI, no network, no cost.  Honesty rules (the AI is told to rely on this text only):
 *   - amounts of different currencies are never added; every ad account is reported in its own currency;
 *   - a change against the previous period is shown only when that period has a non-zero base;
 *   - an account whose copied data is old (or was never synced) gets an explicit warning;
 *   - "spent money but no results" campaigns are flagged by a rule, not guessed by the AI;
 *   - no data means the text says so, so the AI cannot make numbers up.
 */
final class MarketingSnapshot
{
    private const TOP = 3;

    private const PLATFORMS = ['meta' => 'Meta (Facebook/Instagram)', 'google' => 'Google Ads'];

    /**
     * @param  array{range: array{from: string, to: string, prev_from: ?string, prev_to: ?string, days: int}, now: \DateTimeImmutable, ads: ?array{meta: list<array<string, mixed>>, google: list<array<string, mixed>>}, prev?: array{meta?: ?list<array<string, mixed>>, google?: ?list<array<string, mixed>>}, social: ?array<string, mixed>}  $in
     */
    public static function text(array $in): string
    {
        $r = $in['range'];
        $out = ["Period: {$r['from']} from {$r['to']} ({$r['days']} days)"];

        if ($in['ads'] === null) {
            $out[] = "\nAds: (this user has no permission to view ad data)";
        } else {
            $out[] = "\nAd";
            $any = false;
            foreach (['meta', 'google'] as $p) {
                $prev = $in['prev'][$p] ?? null;
                $prevBy = $prev === null ? null : self::byAccount($prev);
                foreach (self::byAccount($in['ads'][$p]) as $id => $acc) {
                    $any = true;
                    $out[] = self::account($p, $acc, $prevBy[$id] ?? null, $prev !== null, $in['now']);
                }
            }
            if (! $any) {
                $out[] = '(There is no ad data in this period)';
            }
        }

        if (array_key_exists('social', $in) && $in['social'] === null) {
            $out[] = "\nSocial media: (no permission to view)";
        } elseif (($in['social'] ?? null) !== null) {
            $out[] = "\n".self::social($in['social']);
        }

        return implode("\n", $out);
    }

    /** @param list<array<string, mixed>> $rows @return array<string, array{account: string, currency: string, synced_at: ?string, rows: list<array<string, mixed>>}> */
    private static function byAccount(array $rows): array
    {
        $o = [];
        foreach ($rows as $x) {
            $o[(string) $x['account_id']] ??= ['account' => (string) $x['account'], 'currency' => (string) $x['currency'], 'synced_at' => $x['synced_at'] ?? null, 'rows' => []];
            $o[(string) $x['account_id']]['rows'][] = $x;
        }

        return $o;
    }

    /** @param list<array<string, mixed>> $rows @return array{spend: float, impressions: int, clicks: int, results: float} */
    private static function totals(array $rows): array
    {
        $t = ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'results' => 0.0];
        foreach ($rows as $x) {
            $t['spend'] += (float) $x['spend'];
            $t['impressions'] += (int) $x['impressions'];
            $t['clicks'] += (int) $x['clicks'];
            $t['results'] += (float) $x['results'];
        }

        return $t;
    }

    /** @param array{account: string, currency: string, synced_at: ?string, rows: list<array<string, mixed>>} $acc @param ?array{account: string, currency: string, synced_at: ?string, rows: list<array<string, mixed>>} $prevAcc */
    private static function account(string $platform, array $acc, ?array $prevAcc, bool $prevKnown, \DateTimeImmutable $now): string
    {
        $t = self::totals($acc['rows']);
        $cur = self::clean($acc['currency'], 8);
        $ctr = $t['impressions'] > 0 ? round($t['clicks'] / $t['impressions'] * 100, 2) : null;
        $line = '- '.self::PLATFORMS[$platform].' — '.self::clean($acc['account'], 80)." ({$cur}): Expenses ".self::n($t['spend'], 2).", Clicks {$t['clicks']}, Impressions {$t['impressions']}"
            .($ctr !== null ? ', Click-through rate '.self::n($ctr, 2).'%' : '').', Results '.self::n($t['results'], 1)
            .($t['results'] > 0 ? ', Cost per result '.self::n($t['spend'] / $t['results'], 2) : '');
        if ($prevKnown) {
            $p = $prevAcc === null ? null : self::totals($prevAcc['rows']);
            $cmp = [];
            foreach (['spend' => 'Expenses', 'clicks' => 'Clicks', 'results' => 'Results'] as $k => $label) {
                $pct = $p === null ? null : self::pct((float) $t[$k], (float) $p[$k]);
                if ($pct !== null) {
                    $cmp[] = $label.' '.$pct;
                }
            }
            $line .= $cmp === [] ? "\n  (Comparison with the previous week: not enough data in the previous week)" : "\n  Compared with the previous week: ".implode(', ', $cmp);
        }
        if (self::stale($acc['synced_at'], $now)) {
            $line .= "\n  ⚠ Warning: this account’s data was updated long ago (or never); the numbers may be incomplete.";
        }
        $rows = $acc['rows'];
        usort($rows, fn ($a, $b) => (float) $b['spend'] <=> (float) $a['spend']);
        foreach (array_slice($rows, 0, self::TOP) as $x) {
            $line .= "\n  • ".self::clean((string) $x['name'], 80).": Expenses ".self::n((float) $x['spend'], 2).", Clicks {$x['clicks']}, Results ".self::n((float) $x['results'], 1);
            if ((float) $x['spend'] > 0 && (float) $x['results'] <= 0.0) {
                $line .= ' ⚠ Spend but no results';
            }
        }

        return $line;
    }

    /** @param array<string, mixed> $s */
    private static function social(array $s): string
    {
        $posts = array_sum(array_map(fn ($p) => (int) $p['count'], $s['posts'] ?? []));
        if ($posts === 0) {
            return 'Social media: (no posts were published in this period)';
        }
        $by = implode(', ', array_map(fn ($p) => self::clean((string) $p['platform'], 20).' '.(int) $p['count'], $s['posts']));
        $l = "Social media: {$posts} posts ({$by}); Reactions ".(int) $s['reactions'].', Comments '.(int) $s['comments'].', Shares '.(int) $s['shares'].', Reach '.(int) $s['reach'];
        if (is_array($s['top'] ?? null)) {
            $l .= "\n  • Post with the most engagement: ".self::clean((string) $s['top']['title'], 80).' ('.self::clean((string) $s['top']['platform'], 20).', Reactions '.(int) $s['top']['reactions'].')';
        }

        return $l;
    }

    /** "+12%" / "-5%"; null when the previous period has no base (never a division by zero or a fake "+∞%"). */
    private static function pct(float $now, float $prev): ?string
    {
        if ($prev <= 0.0) {
            return null;
        }
        $v = (int) round(($now - $prev) / $prev * 100);

        return ($v > 0 ? '+' : '').$v.'%';
    }

    private static function stale(?string $syncedAt, \DateTimeImmutable $now): bool
    {
        $ts = $syncedAt === null ? false : strtotime($syncedAt);

        return $ts === false || $ts < $now->getTimestamp() - 2 * 86400;
    }

    private static function n(float $v, int $dec): string
    {
        return rtrim(rtrim(number_format($v, $dec, '.', ''), '0'), '.') ?: '0';
    }

    private static function clean(string $s, int $max): string
    {
        return trim(mb_substr((string) preg_replace('/[\x00-\x1f\x7f"]+/u', ' ', $s), 0, $max));
    }
}
