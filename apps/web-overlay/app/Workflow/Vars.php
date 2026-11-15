<?php

declare(strict_types=1);

namespace App\Workflow;

/** {{name}} placeholders. Values are inserted as plain text and cut short; a name that does not exist becomes empty. Pure. */
final class Vars
{
    public const MAX_VALUE = 8000;

    public const NAME = '/\A[a-z][a-z0-9_]{0,39}\z/';

    /** @param array<string, mixed> $vars */
    public static function render(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/', function (array $m) use ($vars): string {
            $v = $vars[$m[1]] ?? '';
            $s = is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE);

            return mb_substr($s, 0, self::MAX_VALUE);
        }, $template);
    }

    public static function validName(string $n): bool
    {
        return preg_match(self::NAME, $n) === 1 && ! str_starts_with($n, '__');
    }
}
