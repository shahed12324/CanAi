<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Pc\PcRepo;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;

/** Looks up what became of a computer request the workflow filed earlier (status and, when done, the text the computer sent back).  Only the person's own requests. */
final class PcResultTool implements Tool
{
    public function __construct(private readonly PcRepo $repo) {}

    public function key(): string
    {
        return 'pc.result';
    }

    public function label(): string
    {
        return 'View the result of a computer request';
    }

    public function risk(): string
    {
        return Risk::READ;
    }

    public function args(): array
    {
        return ['id' => 'Request id (the result of the previous step also works)'];
    }

    public function run(ToolContext $c, array $args): string
    {
        if (preg_match('/[0-9A-Za-z]{26}/', (string) ($args['id'] ?? ''), $m) !== 1) {
            throw new ToolException('The request id is not valid.');
        }
        foreach ($this->repo->actions($c->tenantId, $c->userId, 200) as $a) {
            if (strtolower($a['id']) === strtolower($m[0])) {
                $out = 'Status: '.$a['status'];
                if (($a['reason'] ?? '') !== '') {
                    $out .= ' ('.$a['reason'].')';
                }
                if (($a['result'] ?? '') !== '') {
                    $out .= "\n".mb_substr((string) $a['result'], 0, 2000);
                }

                return $out;
            }
        }

        return 'Status: not_found (The request was not found, or is very old)';
    }
}
