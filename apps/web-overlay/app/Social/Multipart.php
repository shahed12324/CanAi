<?php

declare(strict_types=1);

namespace App\Social;

/** Builds a multipart/form-data body by hand (the shared HTTP wrapper only sends forms or raw bodies).  Pure. */
final class Multipart
{
    /**
     * @param  array<string, string>  $fields
     * @return array{body: string, contentType: string}
     */
    public static function build(array $fields, string $fileField, string $fileName, string $mime, string $bytes, ?string $boundary = null): array
    {
        $b = $boundary ?? 'jv'.bin2hex(random_bytes(12));
        $out = '';
        foreach ($fields as $k => $v) {
            $out .= "--{$b}\r\nContent-Disposition: form-data; name=\"".self::clean($k)."\"\r\n\r\n{$v}\r\n";
        }
        $out .= "--{$b}\r\nContent-Disposition: form-data; name=\"".self::clean($fileField).'"; filename="'.self::clean($fileName)."\"\r\nContent-Type: ".self::clean($mime)."\r\n\r\n{$bytes}\r\n--{$b}--\r\n";

        return ['body' => $out, 'contentType' => "multipart/form-data; boundary={$b}"];
    }

    private static function clean(string $s): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._\/+-]/', '_', $s);
    }
}
