<?php

declare(strict_types=1);

namespace App\Outreach;

/** A minimal, strict RFC 5322 writer for one plain-text UTF-8 mail. Header values can never contain a line break. */
final class Mime
{
    public const MAX_ATTACHMENTS = 3;

    public const MAX_ATTACHMENT_BYTES = 10_000_000;

    public static function clean(string $v): string
    {
        return trim((string) preg_replace('/[\r\n\x00]+/', ' ', $v));
    }

    /** RFC 2047 "B" words, split on character boundaries so no multi-byte letter is cut. */
    public static function encodeHeader(string $v): string
    {
        $v = self::clean($v);
        if (preg_match('/\A[\x20-\x7E]*\z/', $v) === 1) {
            return $v;
        }
        $words = [];
        $cur = '';
        foreach (mb_str_split($v, 1, 'UTF-8') as $ch) {
            if (strlen($cur.$ch) > 42) {
                $words[] = $cur;
                $cur = '';
            }
            $cur .= $ch;
        }
        $words[] = $cur;

        return implode("\r\n ", array_map(fn ($w) => '=?UTF-8?B?'.base64_encode($w).'?=', $words));
    }

    public static function address(string $email, ?string $name = null): string
    {
        $email = self::clean($email);
        if (preg_match('/\A[^\s<>"(),;:\\\\@]+@[^\s<>"(),;:\\\\@]+\.[^\s<>"(),;:\\\\@]+\z/', $email) !== 1) {
            throw new \InvalidArgumentException('bad address');
        }
        $name = $name === null ? '' : trim(str_replace(['<', '>', '"', '\\'], '', self::clean($name)));

        return $name === '' ? $email : self::encodeHeader($name).' <'.$email.'>';
    }

    /**
     * @param  array{from_email: string, from_name: ?string, to: string, subject: string, body: string, headers: array<string, string>, attachments?: list<array{name: string, mime: string, bytes: string}>}  $mail
     * @throws \InvalidArgumentException bad address, or attachments that are too big
     */
    public static function message(array $mail, string $messageId, string $date, ?string $boundary = null): string
    {
        $atts = $mail['attachments'] ?? [];
        $h = [
            'Date' => self::clean($date),
            'From' => self::address($mail['from_email'], $mail['from_name'] ?? null),
            'To' => self::address($mail['to']),
            'Subject' => self::encodeHeader($mail['subject']),
            'Message-ID' => '<'.preg_replace('/[^A-Za-z0-9._@+-]/', '', $messageId).'>',
            'MIME-Version' => '1.0',
        ];
        foreach ($mail['headers'] as $k => $v) {
            if (preg_match('/\A[A-Za-z][A-Za-z0-9-]*\z/', $k) !== 1 || isset($h[$k])) {
                continue;
            }
            $h[$k] = self::clean($v);
        }
        $body = str_replace(["\r\n", "\r"], "\n", $mail['body']);
        $text = chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n");
        if ($atts === []) {
            $h['Content-Type'] = 'text/plain; charset=UTF-8';
            $h['Content-Transfer-Encoding'] = 'base64';
            $out = '';
            foreach ($h as $k => $v) {
                $out .= $k.': '.$v."\r\n";
            }

            return $out."\r\n".$text;
        }
        if (count($atts) > self::MAX_ATTACHMENTS || array_sum(array_map(static fn (array $a): int => strlen($a['bytes']), $atts)) > self::MAX_ATTACHMENT_BYTES) {
            throw new \InvalidArgumentException('attachments too big');
        }
        $boundary ??= 'jv_'.bin2hex(random_bytes(12));
        $h['Content-Type'] = 'multipart/mixed; boundary="'.$boundary.'"';
        $out = '';
        foreach ($h as $k => $v) {
            $out .= $k.': '.$v."\r\n";
        }
        $out .= "\r\n--".$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".$text;
        foreach ($atts as $a) {
            $name = trim(str_replace(['"', '\\', '/', '<', '>'], '_', self::clean($a['name'])));
            $name = $name === '' ? 'attachment' : mb_substr($name, 0, 120);
            $mime = preg_match('/\A[a-z]+\/[a-z0-9][a-z0-9.+-]*\z/i', $a['mime']) === 1 ? $a['mime'] : 'application/octet-stream';
            $out .= "--".$boundary."\r\nContent-Type: ".$mime.'; name="'.self::encodeHeader($name)."\"\r\n"
                ."Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($name)."\r\n"
                ."Content-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($a['bytes']), 76, "\r\n");
        }

        return $out.'--'.$boundary."--\r\n";
    }

    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
