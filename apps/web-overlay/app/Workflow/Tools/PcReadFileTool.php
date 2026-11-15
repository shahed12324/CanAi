<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Workflow\Risk;
use App\Workflow\ToolException;

final class PcReadFileTool extends PcActionTool
{
    public function key(): string
    {
        return 'pc.read_file';
    }

    public function label(): string
    {
        return 'Request to read a file on your own computer';
    }

    public function risk(): string
    {
        return Risk::WRITE_OWN;
    }

    public function args(): array
    {
        return ['path' => 'Full path of the file', 'device?' => 'Computer name (not needed if only one is paired)'];
    }

    protected function kind(): string
    {
        return 'read_file';
    }

    protected function params(array $args): array
    {
        return ['path' => self::path($args)];
    }
}
