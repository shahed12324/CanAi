<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Pc\PcRepo;
use App\Pc\PcService;
use App\Workflow\Tool;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;

/**
 * Asks the person's own paired computer to do one file action.  This only files a REQUEST: the computer-control rules (folders the person allowed, "ask" before every
 * change, emergency stop, 10-minute expiry) decide, and the person approves on /app/computer - the workflow can never approve its own request.
 */
abstract class PcActionTool implements Tool
{
    public function __construct(protected readonly PcService $pc, protected readonly PcRepo $repo) {}

    /** The pc action kind: read_file | list_dir | write_file. */
    abstract protected function kind(): string;

    /** @param array<string, string> $args @return array<string, mixed> */
    abstract protected function params(array $args): array;

    public function run(ToolContext $c, array $args): string
    {
        $devices = $this->repo->devices($c->tenantId, $c->userId);
        $want = mb_strtolower(trim($args['device'] ?? ''));
        $dev = null;
        foreach ($devices as $d) {
            if ($want === '' ? count($devices) === 1 : mb_strtolower($d['name']) === $want) {
                $dev = $d;
                break;
            }
        }
        if ($dev === null) {
            throw new ToolException($devices === [] ? 'No computer is paired (pair one at /app/computer).' : 'Which computer — "device" Give a name (more than one is paired or the name did not match).');
        }
        $r = $this->pc->request($c->tenantId, $c->userId, $dev['id'], $this->kind(), $this->params($args));
        if (! $r['ok'] || ($r['id'] ?? null) === null) {
            throw new ToolException($r['message']);
        }

        return 'The request was submitted. id='.$r['id'].' status='.$r['status'].' ('.$r['message'].')';
    }

    /** @param array<string, string> $args */
    protected static function path(array $args): string
    {
        $p = trim($args['path'] ?? '');
        if ($p === '' || mb_strlen($p) > 520 || preg_match('/[\x00-\x1F\x7F]/', $p) === 1) {
            throw new ToolException('The file path is not valid.');
        }

        return $p;
    }
}
