<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Workflow\Risk;
use App\Workflow\ToolException;

final class PcWriteFileTool extends PcActionTool
{
    public function key(): string
    {
        return 'pc.write_file';
    }

    public function label(): string
    {
        return 'Request to write a file on your own computer';
    }

    public function risk(): string
    {
        return Risk::EXTERNAL;
    }

    public function args(): array
    {
        return ['path' => 'Full path of the file', 'content' => 'File text (max 2500 characters)', 'device?' => 'Computer name (not needed if only one is paired)'];
    }

    protected function kind(): string
    {
        return 'write_file';
    }

    protected function params(array $args): array
    {
        return ['path' => self::path($args), 'content' => self::content($args)];
    }

    /** @param array<string, string> $args */
    private static function content(array $args): string
    {
        $t = (string) ($args['content'] ?? '');
        if ($t === '' || mb_strlen($t) > 2500 || str_contains($t, "\0")) {
            throw new ToolException('The file text is blank, over 2500 characters, or has invalid characters.');
        }

        return $t;
    }
}
