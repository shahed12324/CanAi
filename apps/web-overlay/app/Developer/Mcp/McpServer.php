<?php

declare(strict_types=1);

namespace App\Developer\Mcp;

/**
 * A small Model Context Protocol server (JSON-RPC 2.0 over one HTTP POST per message), so an AI app can use a person's Jarvis workflows as tools.
 * It exposes only what the API key's scopes allow, never approves a run, and never lets a tool see or change anything else.
 */
final class McpServer
{
    public const PROTOCOL = '2025-06-18';

    public const MAX_BODY = 65536;

    /** tool => scope it needs */
    private const SCOPE = ['list_workflows' => 'workflows:read', 'get_run' => 'workflows:read', 'run_workflow' => 'workflows:run', 'get_credits' => 'credits:read'];

    /** @param list<string> $scopes */
    public function __construct(private readonly McpBackend $backend, private readonly array $scopes) {}

    /** @return list<array<string, mixed>> */
    public function tools(): array
    {
        $all = [
            'list_workflows' => ['description' => 'List my workflows: id, name, whether switched on, and the input names each one needs.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false]],
            'run_workflow' => ['description' => 'Start one of my switched-on workflows. Steps that send something outside still wait for my approval. With dry_run=true nothing starts; the inputs are only checked.',
                'inputSchema' => ['type' => 'object', 'properties' => ['workflow_id' => ['type' => 'string'], 'inputs' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']], 'dry_run' => ['type' => 'boolean']], 'required' => ['workflow_id'], 'additionalProperties' => false]],
            'get_run' => ['description' => 'Check the status and credits spent of a run I started.', 'inputSchema' => ['type' => 'object', 'properties' => ['run_id' => ['type' => 'string']], 'required' => ['run_id'], 'additionalProperties' => false]],
            'get_credits' => ['description' => 'Show my credit balance.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false]],
        ];
        $out = [];
        foreach ($all as $name => $def) {
            if (in_array(self::SCOPE[$name], $this->scopes, true)) {
                $out[] = ['name' => $name] + $def;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $msg  the decoded JSON body
     * @return ?array<string, mixed>  null = a notification, answer with 202 and no body
     */
    public function handle(mixed $msg): ?array
    {
        if (! is_array($msg) || array_is_list($msg) || ($msg['jsonrpc'] ?? null) !== '2.0' || ! is_string($msg['method'] ?? null)) {
            return self::error(null, -32600, 'Invalid Request');
        }
        $hasId = array_key_exists('id', $msg);
        $id = $msg['id'] ?? null;
        if ($hasId && ! is_int($id) && ! is_string($id)) {
            return self::error(null, -32600, 'Invalid Request');
        }
        $params = $msg['params'] ?? [];
        if (! is_array($params)) {
            return $hasId ? self::error($id, -32602, 'Invalid params') : null;
        }
        if (! $hasId) {
            return null;                                         // notifications (e.g. notifications/initialized) get no answer
        }

        return match ($msg['method']) {
            'initialize' => self::ok($id, ['protocolVersion' => self::PROTOCOL, 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => ['name' => 'jarvis-one', 'version' => '1.0.0']]),
            'ping' => self::ok($id, new \stdClass),
            'tools/list' => self::ok($id, ['tools' => $this->tools()]),
            'tools/call' => $this->call($id, $params),
            default => self::error($id, -32601, 'Method not found'),
        };
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function call(int|string $id, array $p): array
    {
        $name = $p['name'] ?? null;
        $args = $p['arguments'] ?? [];
        if (! is_string($name) || ! is_array($args) || ! isset(self::SCOPE[$name])) {
            return self::error($id, -32602, 'Unknown tool');
        }
        if (! in_array(self::SCOPE[$name], $this->scopes, true)) {
            return self::ok($id, self::text('This key does not have the "'.self::SCOPE[$name].'" scope.', true));
        }

        return self::ok($id, match ($name) {
            'list_workflows' => self::json($this->backend->workflows()),
            'get_credits' => self::json($this->backend->credits()),
            'get_run' => $this->getRun($args),
            default => $this->runWorkflow($args),
        });
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function getRun(array $a): array
    {
        $rid = $a['run_id'] ?? null;
        if (! is_string($rid) || preg_match('/\A[0-9a-z]{26}\z/', $rid) !== 1) {
            return self::text('run_id is not valid.', true);
        }
        $r = $this->backend->runStatus($rid);

        return $r === null ? self::text('No such run.', true) : self::json(['run_id' => $rid] + $r);
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function runWorkflow(array $a): array
    {
        $wid = $a['workflow_id'] ?? null;
        $in = $a['inputs'] ?? [];
        $dry = $a['dry_run'] ?? false;
        if (! is_string($wid) || preg_match('/\A[0-9a-z]{26}\z/', $wid) !== 1 || ! is_array($in) || ! is_bool($dry)) {
            return self::text('workflow_id or inputs are not valid.', true);
        }
        $clean = [];
        foreach ($in as $k => $v) {
            if (! is_string($k) || ! is_scalar($v)) {
                return self::text('"inputs" must be an object of text values.', true);
            }
            $clean[$k] = (string) $v;
        }
        $r = $this->backend->run($wid, $clean, $dry);
        if ($r['status'] !== 'ok') {
            return self::text(match ($r['status']) {
                'not_found' => 'No such workflow.', 'disabled' => 'The workflow is switched off.', 'busy' => 'It is already running; try again later.',
                'missing_input' => 'A required input is missing.', 'too_long' => 'An input is too long.', default => 'Could not start the workflow.',
            }, true);
        }

        return self::json($dry ? ['dry_run' => true, 'ok' => true] : ['run_id' => $r['run_id'], 'status' => 'queued']);
    }

    /** @return array<string, mixed> */
    private static function text(string $t, bool $isError): array
    {
        return ['content' => [['type' => 'text', 'text' => $t]], 'isError' => $isError];
    }

    /** @return array<string, mixed> */
    private static function json(mixed $data): array
    {
        return self::text((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
    }

    /** @return array<string, mixed> */
    private static function ok(int|string $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private static function error(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
