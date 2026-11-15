<?php

declare(strict_types=1);

namespace App\Outreach;

use Closure;

/**
 * Reads replies / bounce notices over IMAP for a plain SMTP mailbox.  Read-only: the mailbox is opened with EXAMINE and bodies
 * are fetched with BODY.PEEK, so nothing is marked read, moved or deleted.  Only the headers that matter and the first 2000
 * bytes of the text are downloaded - never attachments.
 */
final class ImapInbox implements InboxReader
{
    private const FIELDS = 'FROM SUBJECT MESSAGE-ID IN-REPLY-TO CONTENT-TYPE CONTENT-TRANSFER-ENCODING AUTO-SUBMITTED PRECEDENCE X-AUTO-RESPONSE-SUPPRESS X-FAILED-RECIPIENTS';

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    private int $tag = 0;

    private bool $opened = false;

    /** @param Closure(): ImapConnection $connect */
    public function __construct(private readonly Closure $connect, private readonly int $max = 60) {}

    public function fetch(array $conn, string $sinceIso, callable $isSeen): array
    {
        $c = ($this->connect)();
        $this->opened = false;
        try {
            return $this->run($c, $conn, $sinceIso, $isSeen);
        } finally {
            if ($this->opened) {
                try {
                    $c->write("Z LOGOUT\r\n");
                } catch (\Throwable) {
                }
            }
            $c->close();
        }
    }

    /** @param array<string, mixed> $conn */
    private function run(ImapConnection $c, array $conn, string $sinceIso, callable $isSeen): array
    {
        $host = (string) ($conn['host'] ?? '');
        $user = (string) ($conn['username'] ?? '');
        $pass = (string) ($conn['password'] ?? '');
        if ($host === '' || ! self::plain($user) || ! self::plain($pass)) {
            throw new InboxFailure('imap_auth');            // refused before anything is sent: no way to smuggle a second command
        }
        $this->opened = true;
        $c->open($host, (int) ($conn['port'] ?? 993), ($conn['security'] ?? 'tls') === 'tls');
        if (preg_match('/\A\* (OK|PREAUTH)\b/i', (string) $c->line()) !== 1) {
            throw new InboxFailure('inbox_unavailable');
        }
        if (($conn['security'] ?? 'tls') === 'starttls') {
            if ($this->cmd($c, 'STARTTLS')['status'] !== 'OK') {
                throw new InboxFailure('inbox_unavailable');
            }
            $c->startTls();
        }
        if ($this->cmd($c, 'LOGIN '.self::quote($user).' '.self::quote($pass))['status'] !== 'OK') {
            throw new InboxFailure('imap_auth');
        }
        $ex = $this->cmd($c, 'EXAMINE INBOX');
        if ($ex['status'] !== 'OK') {
            throw new InboxFailure('inbox_unavailable');
        }
        $validity = '0';
        foreach ($ex['items'] as $it) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $it['line'], $m) === 1) {
                $validity = $m[1];
            }
        }
        $day = max(0, (int) strtotime($sinceIso) - 86400);              // SINCE looks at the date only, in the server's zone: start a day early, "seen" ids make repeats free
        $s = $this->cmd($c, 'UID SEARCH SINCE '.gmdate('j', $day).'-'.self::MONTHS[(int) gmdate('n', $day) - 1].'-'.gmdate('Y', $day));
        if ($s['status'] !== 'OK') {
            throw new InboxFailure('inbox_unavailable');
        }
        $uids = [];
        foreach ($s['items'] as $it) {
            if (preg_match('/\A\* SEARCH ?([0-9 ]*)\z/i', $it['line'], $m) === 1) {
                foreach (preg_split('/ +/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $u) {
                    $uids[] = (int) $u;
                }
            }
        }
        sort($uids);
        $out = [];
        foreach (array_slice(array_values(array_unique($uids)), -$this->max) as $uid) {
            $id = $validity.'-'.$uid;
            if ($isSeen($id)) {
                continue;
            }
            $f = $this->cmd($c, 'UID FETCH '.$uid.' (INTERNALDATE BODY.PEEK[HEADER.FIELDS ('.self::FIELDS.')] BODY.PEEK[TEXT]<0.2000>)');
            if ($f['status'] !== 'OK') {
                throw new InboxFailure('inbox_unavailable');
            }
            $m = $this->message($id, $f['items']);
            if ($m !== null) {
                $out[] = $m;
            }
        }

        return $out;
    }

    /** @param list<array{line: string, lits: list<array{label: string, data: string}>}> $items */
    private function message(string $id, array $items): ?array
    {
        $headers = null;
        $text = '';
        $date = null;
        foreach ($items as $it) {
            if (preg_match('/\bFETCH\b/i', $it['line']) !== 1) {
                continue;
            }
            if (preg_match('/INTERNALDATE "([^"]+)"/i', $it['line'], $d) === 1 && ($t = strtotime($d[1])) !== false) {
                $date = gmdate('c', $t);
            }
            foreach ($it['lits'] as $l) {
                if (stripos($l['label'], 'HEADER') !== false) {
                    $headers = $l['data'];
                } elseif (stripos($l['label'], 'TEXT') !== false) {
                    $text = $l['data'];
                }
            }
        }
        if ($headers === null) {
            return null;                                              // deleted between SEARCH and FETCH
        }
        $h = self::headers($headers);
        $cte = strtolower(trim($h['content-transfer-encoding'] ?? ''));
        $ct = strtolower($h['content-type'] ?? '');
        if (! str_contains($ct, 'multipart')) {
            $text = $cte === 'base64' ? (string) base64_decode($text, false) : ($cte === 'quoted-printable' ? quoted_printable_decode($text) : $text);
        }
        $text = mb_substr((string) mb_convert_encoding($text, 'UTF-8', 'UTF-8'), 0, 2000);

        return [
            'id' => $id, 'from' => GmailInbox::email(self::decode($h['from'] ?? '')), 'subject' => self::decode($h['subject'] ?? ''), 'thread_id' => null,
            'in_reply_to' => $h['in-reply-to'] ?? null, 'content_type' => $h['content-type'] ?? null, 'auto_submitted' => $h['auto-submitted'] ?? null, 'precedence' => $h['precedence'] ?? null,
            'x_auto_response_suppress' => $h['x-auto-response-suppress'] ?? null, 'x_failed_recipients' => $h['x-failed-recipients'] ?? null, 'text' => $text, 'received_at' => $date,
        ];
    }

    /** @return array<string, string> lower-case name => unfolded value (first one wins) */
    public static function headers(string $raw): array
    {
        $raw = (string) preg_replace('/\r?\n[ \t]+/', ' ', $raw);
        $h = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (preg_match('/\A([A-Za-z][A-Za-z0-9-]*):\s*(.*)\z/s', $line, $m) === 1 && ! isset($h[strtolower($m[1])])) {
                $h[strtolower($m[1])] = trim($m[2]);
            }
        }

        return $h;
    }

    private static function decode(string $v): string
    {
        return trim(str_contains($v, '=?') ? (string) mb_decode_mimeheader($v) : $v);
    }

    /** printable ASCII only: no CR, LF, NUL, no 8-bit (those would need IMAP literals) */
    private static function plain(string $s): bool
    {
        return $s !== '' && strlen($s) <= 500 && preg_match('/\A[\x20-\x7E]+\z/', $s) === 1;
    }

    private static function quote(string $s): string
    {
        return '"'.addcslashes($s, '"\\').'"';
    }

    /** @return array{status: string, items: list<array{line: string, lits: list<array{label: string, data: string}>}>} */
    private function cmd(ImapConnection $c, string $line): array
    {
        $tag = 'A'.str_pad((string) ++$this->tag, 3, '0', STR_PAD_LEFT);
        $c->write($tag.' '.$line."\r\n");
        $items = [];
        for ($guard = 0; $guard < 5000; $guard++) {
            $l = $c->line();
            if ($l === null) {
                throw new InboxFailure('inbox_unavailable');
            }
            if (str_starts_with($l, $tag.' ')) {
                return ['status' => strtoupper(explode(' ', substr($l, strlen($tag) + 1), 2)[0]), 'items' => $items];
            }
            $full = '';
            $lits = [];
            while (preg_match('/\A(.*)\{(\d+)\}\z/s', $l, $m) === 1) {
                $n = (int) $m[2];
                if ($n > 1_000_000) {
                    throw new InboxFailure('inbox_unavailable');
                }
                $full .= $m[1];
                $lits[] = ['label' => $m[1], 'data' => $c->read($n)];
                $l = $c->line() ?? throw new InboxFailure('inbox_unavailable');
            }
            $items[] = ['line' => $full.$l, 'lits' => $lits];
        }
        throw new InboxFailure('inbox_unavailable');
    }
}
