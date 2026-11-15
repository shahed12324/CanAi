<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Workflow\Risk;
use App\Workflow\ToolException;

final class PcListDirTool extends PcActionTool
{
    public function key(): string
    {
        return 'pc.list_dir';
    }

    public function label(): string
    {
        return 'Request to list a folder on your own computer';
    }

    public function risk(): string
    {
        return Risk::WRITE_OWN;
    }

    public function args(): array
    {
        return ['path' => 'Full path of the folder', 'device?' => 'Computer name (not needed if only one is paired)'];
    }

    protected function kind(): string
    {
        return 'list_dir';
    }

    protected function params(array $args): array
    {
        return ['path' => self::path($args)];
    }
}
