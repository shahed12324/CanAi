<?php

declare(strict_types=1);

namespace App\Outreach;

/**
 * Looks at the sending domain's SPF, DKIM and DMARC records and says in plain words what is missing.
 * status: ok | warn | missing. It only reads public DNS; it can not prove mail will land in the inbox.
 */
final class DnsAuth
{
    public function __construct(private readonly DnsResolver $dns) {}

    /** @return array{spf: array{status: string, note: string}, dkim: array{status: string, note: string}, dmarc: array{status: string, note: string}} */
    public function check(string $domain, ?string $dkimSelector = null): array
    {
        $domain = strtolower(trim($domain));

        return ['spf' => $this->spf($domain), 'dkim' => $this->dkim($domain, $dkimSelector), 'dmarc' => $this->dmarc($domain)];
    }

    /** @return array{status: string, note: string} */
    private function spf(string $d): array
    {
        $recs = array_values(array_filter($this->dns->txt($d), fn ($t) => preg_match('/^v=spf1(\s|$)/i', trim($t)) === 1));
        if ($recs === []) {
            return ['status' => 'missing', 'note' => 'SPF No record — high risk of email going to spam.'];
        }
        if (count($recs) > 1) {
            return ['status' => 'warn', 'note' => 'There are multiple SPF records — only one must be kept, otherwise all fail.'];
        }
        $r = strtolower(trim($recs[0]));
        if (preg_match('/\s\+all\b/', $r) || preg_match('/\s\?all\b/', $r)) {
            return ['status' => 'warn', 'note' => 'SPF It allows everyone (+all/?all) — this gives no security.'];
        }
        if (! preg_match('/\s[-~]all\b/', $r)) {
            return ['status' => 'warn', 'note' => 'SPF-At its end -all or ~all None.'];
        }

        return ['status' => 'ok', 'note' => 'SPF is present.'];
    }

    /** @return array{status: string, note: string} */
    private function dkim(string $d, ?string $selector): array
    {
        $selector = $selector === null ? '' : strtolower(trim($selector));
        if ($selector === '') {
            return ['status' => 'warn', 'note' => 'DKIM A selector is needed to see it (for example google, default, selector1) — you will find it in your mail provider’s instructions.'];
        }
        if (preg_match('/\A[a-z0-9]([a-z0-9._-]{0,61}[a-z0-9])?\z/', $selector) !== 1) {
            return ['status' => 'warn', 'note' => 'selector-is misspelled.'];
        }
        foreach ($this->dns->txt($selector.'._domainkey.'.$d) as $t) {
            if (preg_match('/\bp=([A-Za-z0-9+\/=]+)/', $t, $m) === 1) {
                return ['status' => 'ok', 'note' => 'DKIM Key found.'];
            }
            if (preg_match('/\bp=\s*(;|$)/', $t) === 1) {
                return ['status' => 'warn', 'note' => 'DKIM Key revoked (p= empty).'];
            }
        }

        return ['status' => 'missing', 'note' => "{$selector}._domainkey.{$d} No DKIM record under the name."];
    }

    /** @return array{status: string, note: string} */
    private function dmarc(string $d): array
    {
        foreach ($this->dns->txt('_dmarc.'.$d) as $t) {
            if (preg_match('/^v=DMARC1\s*;/i', trim($t)) !== 1) {
                continue;
            }
            if (preg_match('/;\s*p=(none|quarantine|reject)\s*(;|$)/i', $t, $m) !== 1) {
                return ['status' => 'warn', 'note' => 'DMARC exists but the p= policy is not right.'];
            }

            return strtolower($m[1]) === 'none'
                ? ['status' => 'warn', 'note' => 'DMARC exists (p=none, observation only). Move to quarantine after a while.']
                : ['status' => 'ok', 'note' => 'DMARC exists (p='.strtolower($m[1]).').'];
        }

        return ['status' => 'missing', 'note' => 'DMARC No record (_dmarc.'.$d.').'];
    }
}
