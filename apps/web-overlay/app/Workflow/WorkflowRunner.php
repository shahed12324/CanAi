<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Mobile\MobileRepo;

/**
 * Runs one claimed run, step by step.  Every step is checked again here (a definition in the database is never trusted), and recorded atomically with its new variables.
 * A run stops by itself when: it finishes at an output step, a step fails, it waits for an approval, the budget is used, or it has made MAX_STEPS steps.
 */
final class WorkflowRunner
{
    public const MAX_STEPS = 40;

    public const MSG = [
        'budget' => 'This run’s credit limit is used up — raise the limit and run again.',
        'no_model' => 'No AI model is enabled.',
        'no_credits' => 'No credits.',
        'provider' => 'AI The service is not responding now. Run again later.',
        'unreadable' => 'AI Gave no reply.',
        'failed' => 'AI The step could not be done.',
    ];

    public function __construct(
        private readonly WorkflowRepo $repo,
        private readonly ToolRegistry $tools,
        private readonly AiStep $ai,
        private readonly ?MobileRepo $mobile = null,
    ) {}

    public function execute(string $tenantId, string $runId): void
    {
        $run = $this->repo->run($runId);
        if ($run === null || $run['status'] !== 'running' || $run['user_id'] === '') {
            return;
        }
        $user = $run['user_id'];
        $def = $run['definition'];
        $nodes = is_array($def['nodes'] ?? null) ? $def['nodes'] : [];
        $vars = $run['vars'];
        $spent = $run['spent'];
        $seq = $run['steps'];
        $cur = $run['current_node'];
        $ctx = new ToolContext($tenantId, $user, $run['timezone']);
        $test = ($run['trigger'] ?? 'manual') === 'test';                // test mode: real look-only steps, everything else is skipped and logged
        $simAi = $test && ($vars['__test_ai'] ?? 'real') === 'sim';
        $modelId = $run['model_id'] ?? null;
        $mode = ['test' => $test, 'sim' => $simAi, 'model' => $modelId];
        $rec = function (string $node, string $type, string $status, string $output, string $credits, ?string $next) use ($tenantId, $runId, &$vars): bool {
            return $this->repo->record($tenantId, $runId, $node, $type, $status, $output, $credits, $vars, $next) === 'ok';
        };
        $fail = function (string $node, string $type, string $msg, string $credits = '0') use ($rec, $tenantId, $runId, $user, $run, $test): void {
            if ($rec($node, $type, 'failed', $msg, $credits, null)) {
                $this->repo->end($tenantId, $runId, 'failed', $msg);
                if (! $test) {
                    $this->tell($tenantId, $user, 'Workflow failed: '.$run['workflow_name'], $msg);
                }
            }
        };

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $id = (string) $cur;
            $n = $nodes[$id] ?? null;
            if (! is_array($n) || ! in_array($n['type'] ?? null, WorkflowDefinition::TYPES, true)) {
                $fail($id, 'tool', 'The step was not found or is not valid.');

                return;
            }
            $type = $n['type'];
            $seq++;
            if ($type === 'output') {
                $vars['__output'] = mb_substr(Vars::render((string) ($n['text'] ?? ''), $vars), 0, 4000);
                if ($rec($id, $type, 'ok', $vars['__output'], '0', null)) {
                    $this->repo->end($tenantId, $runId, 'done', null);
                }

                return;
            }
            if ($type === 'approval' && $test) {                      // a test never waits for a person: say what would be asked and go on
                $msg = mb_substr(Vars::render((string) ($n['message'] ?? ''), $vars), 0, 1000);
                if (! $rec($id, $type, 'skipped', 'Test mode: you would be asked to approve: '.$msg, '0', (string) $n['next'])) {
                    return;
                }
                $cur = (string) $n['next'];

                continue;
            }
            if ($type === 'approval') {
                if (($vars['__approved:'.$id] ?? false) === true) {
                    if (! $rec($id, $type, 'ok', 'Allowed', '0', (string) $n['next'])) {
                        return;
                    }
                    $cur = (string) $n['next'];

                    continue;
                }
                $msg = mb_substr(Vars::render((string) ($n['message'] ?? ''), $vars), 0, 1000);
                if ($rec($id, $type, 'waiting', $msg, '0', $id)) {
                    $this->repo->end($tenantId, $runId, 'waiting_approval', null);
                    $this->tell($tenantId, $user, 'Approval is needed: '.$run['workflow_name'], $msg);
                }

                return;
            }
            if ($type === 'parallel') {                               // independent branches, one after another; progress is kept in a variable so a restart never repeats a finished step
                $branches = array_values(array_filter((array) ($n['branches'] ?? []), 'is_string'));
                $join = (string) ($n['join'] ?? '');
                $count = count($branches);
                if ($count < 2 || $count > WorkflowDefinition::MAX_BRANCHES || ! isset($nodes[$join])) {
                    $fail($id, $type, 'The parallel step is not valid.');

                    return;
                }
                $key = '__par:'.$id;
                [$b, $bn] = array_pad(explode('|', (string) ($vars[$key] ?? ''), 2), 2, '');
                $b = ctype_digit($b) ? (int) $b : 0;
                $bn = $bn === '' ? ($branches[$b] ?? $join) : $bn;
                $seq--;                                               // the parallel step itself is not a logged step
                while ($b < $count) {
                    if ($bn === $join) {
                        $b++;
                        $bn = $branches[$b] ?? $join;

                        continue;
                    }
                    $bnode = $nodes[$bn] ?? null;
                    if (! is_array($bnode) || ! in_array($bnode['type'] ?? null, ['ai', 'tool'], true) || $i >= self::MAX_STEPS - 1) {
                        $fail($bn, 'tool', 'The branch step was not found, is not valid, or the number of steps exceeded the limit.');

                        return;
                    }
                    $i++;
                    $seq++;
                    $r = $this->branchStep($bnode, $vars, $ctx, $tenantId, $user, $runId.'-'.$seq, max(0.0, $run['max_credits'] - $spent), $mode);
                    $spent += (float) $r['credits'];
                    if ($r['error'] !== null) {
                        $fail($bn, (string) $bnode['type'], $r['error'], $r['credits']);

                        return;
                    }
                    $bnext = (string) ($bnode['next'] ?? '');
                    if ($bnext === $join) {
                        $nb = $b + 1;
                        $nn = $branches[$nb] ?? $join;
                    } else {
                        $nb = $b;
                        $nn = $bnext;
                    }
                    $vars[$key] = $nb.'|'.$nn;
                    if (! $rec($bn, (string) $bnode['type'], 'ok', mb_substr($r['out'], 0, 4000), $r['credits'], $nb >= $count ? $join : $id)) {
                        return;
                    }
                    [$b, $bn] = [$nb, $nn];
                }
                $cur = $join;

                continue;
            }
            if ($type === 'delay' && $test) {                         // a test never waits
                if (! $rec($id, $type, 'skipped', 'Test mode: would wait '.(int) ($n['minutes'] ?? 0).' minutes', '0', (string) ($n['next'] ?? ''))) {
                    return;
                }
                $cur = (string) ($n['next'] ?? '');

                continue;
            }
            if ($type === 'delay') {                                  // park the run; a worker continues it from the next step when the time comes
                $min = $n['minutes'] ?? null;
                if (! is_int($min) || $min < 1 || $min > WorkflowDefinition::MAX_DELAY_MINUTES) {
                    $fail($id, $type, 'The wait time is not valid.');

                    return;
                }
                if ($this->repo->delay($tenantId, $runId, $id, (string) ($n['next'] ?? ''), $min, $vars) === 'bad_input') {
                    $fail($id, $type, 'The wait step is not valid.');
                }

                return;
            }
            if ($type === 'condition') {
                $yes = self::test((string) ($n['op'] ?? ''), $vars[$n['var'] ?? ''] ?? '', (string) ($n['value'] ?? ''));
                $next = (string) ($yes ? ($n['then'] ?? '') : ($n['else'] ?? ''));
                if (! $rec($id, $type, 'ok', $yes ? 'Yes' : 'No', '0', $next)) {
                    return;
                }
                $cur = $next;

                continue;
            }
            if ($type === 'tool') {
                $tool = is_string($n['tool'] ?? null) ? $this->tools->get($n['tool']) : null;
                if ($tool === null || $tool->risk() === Risk::MONEY) {
                    $fail($id, $type, 'This tool cannot be run.');

                    return;
                }
                if ($test && self::mustSkipInTest($tool)) {                  // writes / sends / the computer: never in a test
                    $out = self::simulate($tool, (array) ($n['args'] ?? []), $vars);
                    if (isset($n['save_as']) && is_string($n['save_as']) && Vars::validName($n['save_as'])) {
                        $vars[$n['save_as']] = '[test: result of '.$tool->key().']';
                    }
                    if (! $rec($id, $type, 'skipped', $out, '0', (string) $n['next'])) {
                        return;
                    }
                    $cur = (string) $n['next'];

                    continue;
                }
                if ($tool->risk() === Risk::EXTERNAL && ! self::approvedBefore($vars)) {
                    $fail($id, $type, 'Approval is needed before sending outside.');

                    return;
                }
                $each = isset($n['each']) && is_string($n['each']) && Vars::validName($n['each']) ? $n['each'] : null;
                if ($each !== null && ! in_array($tool->risk(), [Risk::READ, Risk::WRITE_OWN], true)) {
                    $fail($id, $type, 'A send-outside tool cannot be run on every line.');

                    return;
                }
                $rounds = [null];                                        // one run, or one per non-empty line of the variable (at most max_items, never more than 10)
                if ($each !== null) {
                    $cap = is_int($n['max_items'] ?? null) ? max(1, min(WorkflowDefinition::MAX_EACH, $n['max_items'])) : WorkflowDefinition::MAX_EACH;
                    $rounds = array_slice(array_values(array_filter(array_map('trim', (array) preg_split('/\R/u', (string) ($vars[$each] ?? ''))), fn ($l) => $l !== '')), 0, $cap);
                }
                $outs = [];
                try {
                    foreach ($rounds as $k => $line) {
                        $scope = $line === null ? $vars : $vars + ['item' => $line, 'index' => (string) ($k + 1)];
                        $args = [];
                        foreach ((array) ($n['args'] ?? []) as $name => $v) {
                            $args[(string) $name] = Vars::render((string) $v, $scope);
                        }
                        try {
                            $outs[] = $tool->run($ctx, $args);
                        } catch (ToolException $e) {
                            throw new ToolException(($line === null ? '' : 'Line '.($k + 1).': ').$e->getMessage());
                        }
                    }
                    $out = $each !== null && $outs === [] ? '(No items)' : mb_substr(implode("\n", $outs), 0, Vars::MAX_VALUE);
                } catch (ToolException $e) {
                    $fail($id, $type, mb_substr($e->getMessage(), 0, 300));

                    return;
                } catch (\Throwable $e) {
                    if (function_exists('report')) {
                        report($e);
                    }
                    $fail($id, $type, 'There was a problem running the tool.');

                    return;
                }
                if (isset($n['save_as']) && is_string($n['save_as']) && Vars::validName($n['save_as'])) {
                    $vars[$n['save_as']] = $out;
                }
                if (! $rec($id, $type, 'ok', mb_substr($out, 0, 4000), '0', (string) $n['next'])) {
                    return;
                }
                $cur = (string) $n['next'];

                continue;
            }
            // ai
            $max = max(WorkflowDefinition::MIN_TOKENS, min(WorkflowDefinition::MAX_TOKENS, (int) ($n['max_tokens'] ?? 600)));
            if ($simAi) {
                $vars[(string) ($n['save_as'] ?? '_')] = '[test: AI answer]';
                if (! $rec($id, $type, 'skipped', 'Test mode: the AI was not called (simulated).', '0', (string) $n['next'])) {
                    return;
                }
                $cur = (string) $n['next'];

                continue;
            }
            $r = $this->ai->run($tenantId, $user, $runId.'-'.$seq, Vars::render((string) ($n['prompt'] ?? ''), $vars), $max, max(0.0, $run['max_credits'] - $spent), $modelId);
            $spent += (float) $r['credits'];
            if ($r['status'] !== 'ok') {
                $fail($id, $type, self::MSG[$r['status']] ?? self::MSG['failed'], $r['credits']);

                return;
            }
            if (isset($n['save_as']) && is_string($n['save_as']) && Vars::validName($n['save_as'])) {
                $vars[$n['save_as']] = $r['text'];
            }
            if (! $rec($id, $type, 'ok', mb_substr($r['text'], 0, 4000), $r['credits'], (string) $n['next'])) {
                return;
            }
            $cur = (string) $n['next'];
        }
        $fail((string) $cur, 'tool', 'The number of steps exceeded the limit.');
    }

    /**
     * One ai / tool step inside a parallel branch (no each, nothing external or money).  Writes its result into $vars when it has a save_as.
     *
     * @param  array<string, mixed>  $n
     * @param  array<string, mixed>  $vars
     * @return array{out: string, credits: string, error: ?string}
     */
    private function branchStep(array $n, array &$vars, ToolContext $ctx, string $tenantId, string $user, string $aiKey, float $budget, array $mode = ['test' => false, 'sim' => false, 'model' => null]): array
    {
        if ($n['type'] === 'tool') {
            $tool = is_string($n['tool'] ?? null) ? $this->tools->get($n['tool']) : null;
            if ($tool === null || ! in_array($tool->risk(), [Risk::READ, Risk::WRITE_OWN], true) || isset($n['each'])) {
                return ['out' => '', 'credits' => '0', 'error' => 'This tool cannot be run in a branch.'];
            }
            if ($mode['test'] && self::mustSkipInTest($tool)) {
                $out = self::simulate($tool, (array) ($n['args'] ?? []), $vars);
                if (isset($n['save_as']) && is_string($n['save_as']) && Vars::validName($n['save_as'])) {
                    $vars[$n['save_as']] = '[test: result of '.$tool->key().']';
                }

                return ['out' => $out, 'credits' => '0', 'error' => null];
            }
            $args = [];
            foreach ((array) ($n['args'] ?? []) as $name => $v) {
                $args[(string) $name] = Vars::render((string) $v, $vars);
            }
            try {
                $out = mb_substr($tool->run($ctx, $args), 0, Vars::MAX_VALUE);
            } catch (ToolException $e) {
                return ['out' => '', 'credits' => '0', 'error' => mb_substr($e->getMessage(), 0, 300)];
            } catch (\Throwable $e) {
                if (function_exists('report')) {
                    report($e);
                }

                return ['out' => '', 'credits' => '0', 'error' => 'There was a problem running the tool.'];
            }
            $credits = '0';
        } else {
            if ($mode['sim']) {
                if (isset($n['save_as']) && is_string($n['save_as']) && Vars::validName($n['save_as'])) {
                    $vars[$n['save_as']] = '[test: AI answer]';
                }

                return ['out' => 'Test mode: the AI was not called (simulated).', 'credits' => '0', 'error' => null];
            }
            $max = max(WorkflowDefinition::MIN_TOKENS, min(WorkflowDefinition::MAX_TOKENS, (int) ($n['max_tokens'] ?? 600)));
            $r = $this->ai->run($tenantId, $user, $aiKey, Vars::render((string) ($n['prompt'] ?? ''), $vars), $max, $budget, $mode['model']);
            if ($r['status'] !== 'ok') {
                return ['out' => '', 'credits' => $r['credits'], 'error' => self::MSG[$r['status']] ?? self::MSG['failed']];
            }
            $out = $r['text'];
            $credits = $r['credits'];
        }
        if (isset($n['save_as']) && is_string($n['save_as']) && Vars::validName($n['save_as'])) {
            $vars[$n['save_as']] = $out;
        }

        return ['out' => $out, 'credits' => $credits, 'error' => null];
    }

    /** In a test only tools that merely LOOK (read) at the person's own data run; every writer, sender and anything that uses the paired computer is skipped. */
    public static function mustSkipInTest(Tool $tool): bool
    {
        return $tool->risk() !== Risk::READ || str_starts_with($tool->key(), 'pc.');
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $vars */
    private static function simulate(Tool $tool, array $args, array $vars): string
    {
        $shown = [];
        foreach ($args as $k => $v) {
            $shown[] = $k.'='.mb_substr(Vars::render((string) $v, $vars), 0, 120);
        }

        return 'Test mode — NOT run: '.$tool->key().($shown === [] ? '' : ' ('.implode(', ', $shown).')').'. '.(str_starts_with($tool->key(), 'pc.') ? 'Nothing was sent to your computer.' : 'Nothing was changed or sent.');
    }

    /** @param array<string, mixed> $vars */
    private static function approvedBefore(array $vars): bool
    {
        foreach ($vars as $k => $v) {
            if (str_starts_with((string) $k, '__approved:') && $v === true) {
                return true;
            }
        }

        return false;
    }

    public static function test(string $op, mixed $value, string $arg): bool
    {
        $v = trim(is_scalar($value) ? (string) $value : '');
        $a = trim($arg);

        return match ($op) {
            'empty' => $v === '',
            'not_empty' => $v !== '',
            'contains' => $a !== '' && mb_stripos($v, $a) !== false,
            'equals' => mb_strtolower($v) === mb_strtolower($a),
            'gt' => is_numeric($v) && is_numeric($a) && (float) $v > (float) $a,
            'lt' => is_numeric($v) && is_numeric($a) && (float) $v < (float) $a,
            default => false,
        };
    }

    private function tell(string $tenant, string $user, string $title, string $body): void
    {
        try {
            $this->mobile?->notify($tenant, $user, 'workflow', $title, $body, 'workflow');
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }
        }
    }
}
