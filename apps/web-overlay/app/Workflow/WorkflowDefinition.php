<?php

declare(strict_types=1);

namespace App\Workflow;

/**
 * Checks a workflow definition before it is saved (the runner checks again at every step).  Pure.
 *
 * {"start":"a","inputs":[{"key":"email","label":"Email"}],"nodes":{
 *   "a":{"type":"tool","tool":"plan.today","args":{},"save_as":"plan","next":"b"},
 *   "b":{"type":"ai","prompt":"... {{plan}}","save_as":"msg","max_tokens":600,"next":"c"},
 *   "c":{"type":"condition","var":"msg","op":"not_empty","value":"","then":"d","else":"e"},
 *   "d":{"type":"approval","message":"...","next":"e"},
 *   "e":{"type":"output","text":"{{msg}}"}}}
 * No loops (every path must end at an output); tools that act outside the app need an approval step before them on every path; money tools never.
 */
final class WorkflowDefinition
{
    public const MAX_NODES = 30;

    public const TYPES = ['ai', 'tool', 'condition', 'approval', 'delay', 'parallel', 'output'];

    public const MAX_BRANCHES = 4;

    public const MAX_BRANCH_NODES = 5;

    public const MAX_DELAY_MINUTES = 1440;

    public const MAX_DELAYS = 3;

    public const MAX_EACH = 10;

    public const OPS = ['empty', 'not_empty', 'contains', 'equals', 'gt', 'lt'];

    public const MAX_PROMPT = 4000;

    public const MIN_TOKENS = 100;

    public const MAX_TOKENS = 1500;

    /** @param array<string, mixed> $d @return list<string> Bengali problems; empty = fine */
    public static function validate(array $d, ToolRegistry $tools): array
    {
        $e = [];
        $nodes = $d['nodes'] ?? null;
        $start = $d['start'] ?? null;
        if (! is_array($nodes) || $nodes === [] || array_is_list($nodes)) {
            return ['The list of steps (nodes) is missing or not valid.'];
        }
        if (count($nodes) > self::MAX_NODES) {
            return ['max '.self::MAX_NODES.' steps can be kept.'];
        }
        foreach (array_keys($nodes) as $id) {
            if (! is_string($id) || preg_match('/\A[a-z0-9_]{1,40}\z/', $id) !== 1) {
                $e[] = 'A step ID can be only lowercase English letters, digits and _ (max 40): '.(is_string($id) ? $id : '?');
            }
        }
        if (! is_string($start) || ! isset($nodes[$start])) {
            $e[] = 'The start step (start) is missing or there is no step with that ID.';
        }
        $inputs = $d['inputs'] ?? [];
        if (! is_array($inputs) || ! array_is_list($inputs) || count($inputs) > 5) {
            $e[] = 'At most 5 inputs are allowed.';
        } else {
            foreach ($inputs as $in) {
                if (! is_array($in) || ! is_string($in['key'] ?? null) || ! Vars::validName($in['key']) || ! is_string($in['label'] ?? null) || trim($in['label']) === '' || mb_strlen($in['label']) > 60) {
                    $e[] = 'Give the input’s key (lowercase English name) and label (display name).';
                }
            }
        }
        foreach ($nodes as $id => $n) {
            if (! is_array($n)) {
                $e[] = "Stage {$id} is not valid.";

                continue;
            }
            array_push($e, ...self::node((string) $id, $n, $nodes, $tools));
        }
        if (count(array_filter($nodes, fn ($n) => is_array($n) && ($n['type'] ?? null) === 'delay')) > self::MAX_DELAYS) {
            $e[] = 'max '.self::MAX_DELAYS.' wait (delay) steps can be kept.';
        }
        if ($e !== []) {
            return array_values(array_unique($e));
        }

        return array_values(array_unique(self::paths((string) $start, $nodes, $tools)));
    }

    /** @param array<string, mixed> $n @param array<string, mixed> $nodes @return list<string> */
    private static function node(string $id, array $n, array $nodes, ToolRegistry $tools): array
    {
        $e = [];
        $type = $n['type'] ?? null;
        $ref = function (mixed $to, string $what) use ($id, $nodes, &$e): void {
            if (! is_string($to) || ! isset($nodes[$to])) {
                $e[] = "Stage {$id}: {$what} must be the ID of another step.";
            }
        };
        $save = function () use ($id, $n, &$e): void {
            if (isset($n['save_as']) && (! is_string($n['save_as']) || ! Vars::validName($n['save_as']))) {
                $e[] = "Stage {$id}: save_as must be a lowercase English name.";
            }
        };
        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            return ["Stage {$id}: The type is not valid."];
        }
        switch ($type) {
            case 'ai':
                if (! is_string($n['prompt'] ?? null) || trim($n['prompt']) === '' || mb_strlen($n['prompt']) > self::MAX_PROMPT) {
                    $e[] = "Stage {$id}: prompt blank or ".self::MAX_PROMPT.' characters long at most.';
                }
                if (! is_string($n['save_as'] ?? null)) {
                    $e[] = "Stage {$id}: save_as give.";
                }
                if (isset($n['max_tokens']) && (! is_int($n['max_tokens']) || $n['max_tokens'] < self::MIN_TOKENS || $n['max_tokens'] > self::MAX_TOKENS)) {
                    $e[] = "Stage {$id}: max_tokens ".self::MIN_TOKENS.' from '.self::MAX_TOKENS.' must be within.';
                }
                $save();
                $ref($n['next'] ?? null, 'next');
                break;
            case 'tool':
                $t = is_string($n['tool'] ?? null) ? $tools->get($n['tool']) : null;
                if ($t === null) {
                    $e[] = "Stage {$id}: There is no tool with this name.";
                } elseif ($t->risk() === Risk::MONEY) {
                    $e[] = "Stage {$id}: Money-related tools cannot be used in a workflow.";
                } else {
                    $args = $n['args'] ?? [];
                    if (! is_array($args)) {
                        $e[] = "Stage {$id}: args must be an object.";
                    } else {
                        $known = array_map(fn ($k) => rtrim($k, '?'), array_keys($t->args()));
                        foreach ($t->args() as $k => $_) {
                            if (! str_ends_with($k, '?') && (! isset($args[$k]) || ! is_scalar($args[$k]) || trim((string) $args[$k]) === '')) {
                                $e[] = "Stage {$id}: '{$k}' arguments must be given.";
                            }
                        }
                        foreach ($args as $k => $v) {
                            if (! in_array((string) $k, $known, true) || ! is_scalar($v) || mb_strlen((string) $v) > 2000) {
                                $e[] = "Stage {$id}: Argument '{$k}' is not valid.";
                            }
                        }
                    }
                }
                if (isset($n['each'])) {                       // run the tool once per line of a variable (bounded; never for tools that act outside the app)
                    if (! is_string($n['each']) || ! Vars::validName($n['each'])) {
                        $e[] = "Stage {$id}: each must be a variable name.";
                    }
                    if (isset($n['max_items']) && (! is_int($n['max_items']) || $n['max_items'] < 1 || $n['max_items'] > self::MAX_EACH)) {
                        $e[] = "Stage {$id}: max_items 1 to ".self::MAX_EACH.' must be within.';
                    }
                    if ($t !== null && $t->risk() !== Risk::READ && $t->risk() !== Risk::WRITE_OWN) {
                        $e[] = "Stage {$id}: A send-outside tool does not run together with run-on-every-line (each).";
                    }
                } elseif (isset($n['max_items'])) {
                    $e[] = "Stage {$id}: max_items runs only with each.";
                }
                $save();
                $ref($n['next'] ?? null, 'next');
                break;
            case 'parallel':
                array_push($e, ...self::parallel($id, $n, $nodes, $tools));
                break;
            case 'delay':
                if (! is_int($n['minutes'] ?? null) || $n['minutes'] < 1 || $n['minutes'] > self::MAX_DELAY_MINUTES) {
                    $e[] = "Stage {$id}: Wait minutes (minutes) must be a whole number from 1 to ".self::MAX_DELAY_MINUTES.' within.';
                }
                $ref($n['next'] ?? null, 'next');
                break;
            case 'condition':
                if (! is_string($n['var'] ?? null) || ! Vars::validName($n['var'])) {
                    $e[] = "Stage {$id}: var is not valid.";
                }
                if (! in_array($n['op'] ?? null, self::OPS, true)) {
                    $e[] = "Stage {$id}: op must be: ".implode(', ', self::OPS);
                }
                if (isset($n['value']) && ! is_scalar($n['value'])) {
                    $e[] = "Stage {$id}: value must be plain text or a number.";
                }
                $ref($n['then'] ?? null, 'then');
                $ref($n['else'] ?? null, 'else');
                break;
            case 'approval':
                if (! is_string($n['message'] ?? null) || trim($n['message']) === '' || mb_strlen($n['message']) > 1000) {
                    $e[] = "Stage {$id}: Give the approval message (message) (max 1000 characters).";
                }
                $ref($n['next'] ?? null, 'next');
                break;
            case 'output':
                if (! is_string($n['text'] ?? null) || mb_strlen($n['text']) > 4000) {
                    $e[] = "Stage {$id}: text give (max 4000 characters).";
                }
                break;
        }

        return $e;
    }

    /**
     * {"type":"parallel","branches":["a1","b1"],"join":"j"}: independent branches (each a short chain of ai / tool steps, ending at the join step).  A branch never reads what a sibling
     * branch saves, never contains an approval, delay, condition, output, another parallel step, an each-loop, an external or a money tool.  Pure.
     *
     * @param  array<string, mixed>  $n
     * @param  array<string, mixed>  $nodes
     * @return list<string>
     */
    private static function parallel(string $id, array $n, array $nodes, ToolRegistry $tools): array
    {
        $e = [];
        $branches = $n['branches'] ?? null;
        $join = $n['join'] ?? null;
        if (! is_array($branches) || ! array_is_list($branches) || count($branches) < 2 || count($branches) > self::MAX_BRANCHES) {
            return ["Stage {$id}: branches 2 to ".self::MAX_BRANCHES.' must be a list of branches.'];
        }
        if (! is_string($join) || ! isset($nodes[$join])) {
            return ["Stage {$id}: join must be the ID of another step."];
        }
        $owner = [];                                              // node id => branch index
        $saved = [];                                              // branch index => names it saves
        $texts = [];                                              // branch index => all templates it renders
        foreach ($branches as $bi => $start) {
            $saved[$bi] = [];
            $texts[$bi] = [];
            $cur = $start;
            for ($k = 0; ; $k++) {
                if (! is_string($cur) || ! isset($nodes[$cur]) || ! is_array($nodes[$cur])) {
                    $e[] = "Stage {$id}: Branch ".($bi + 1).' did not end properly at a join step.';
                    break;
                }
                if ($cur === $join) {
                    break;
                }
                if ($k >= self::MAX_BRANCH_NODES) {
                    $e[] = "Stage {$id}: At most per branch ".self::MAX_BRANCH_NODES.' steps.';
                    break;
                }
                if (isset($owner[$cur])) {
                    $e[] = "Stage {$id}: Stage {$cur} is in several branches (or twice in the same branch).";
                    break;
                }
                $owner[$cur] = $bi;
                $b = $nodes[$cur];
                $type = $b['type'] ?? null;
                if ($type === 'ai') {
                    $texts[$bi][] = (string) ($b['prompt'] ?? '');
                } elseif ($type === 'tool') {
                    $t = is_string($b['tool'] ?? null) ? $tools->get($b['tool']) : null;
                    if ($t === null || ! in_array($t->risk(), [Risk::READ, Risk::WRITE_OWN], true) || isset($b['each'])) {
                        $e[] = "Stage {$id}: Branch steps {$cur} — only look-only/write-own-data tools run, and not with each.";
                    }
                    foreach ((array) ($b['args'] ?? []) as $v) {
                        $texts[$bi][] = is_scalar($v) ? (string) $v : '';
                    }
                } else {
                    $e[] = "Stage {$id}: only ai and tool steps run in a branch ({$cur}).";
                    break;
                }
                if (isset($b['save_as']) && is_string($b['save_as'])) {
                    $saved[$bi][] = $b['save_as'];
                }
                $cur = $b['next'] ?? null;
            }
        }
        $all = array_merge(...array_values($saved));
        if (count($all) !== count(array_unique($all))) {
            $e[] = "Stage {$id}: two branches have the same name (save_as) cannot keep a result.";
        }
        foreach ($texts as $bi => $list) {
            $others = array_merge(...array_values(array_diff_key($saved, [$bi => 1])));
            foreach ($list as $t) {
                if (preg_match_all('/\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/', $t, $m) > 0 && array_intersect($m[1], $others) !== []) {
                    $e[] = "Stage {$id}: Branch ".($bi + 1).' cannot use another branch’s result (branches are independent).';
                    break;
                }
            }
        }

        return $e;
    }

    /** No loops; every path ends at an output; external tools need an approval before them. @param array<string, mixed> $nodes @return list<string> */
    private static function paths(string $start, array $nodes, ToolRegistry $tools): array
    {
        $e = [];
        $seen = [];
        $walk = function (string $id, bool $approved, array $stack) use (&$walk, &$e, &$seen, $nodes, $tools): void {
            if (in_array($id, $stack, true)) {
                $e[] = 'The steps loop back to an earlier step — loops are not supported in this version: '.$id;

                return;
            }
            if (isset($seen[$id.($approved ? '1' : '0')])) {
                return;
            }
            $seen[$id.($approved ? '1' : '0')] = true;
            $n = $nodes[$id];
            $stack[] = $id;
            switch ($n['type']) {
                case 'tool':
                    if (($tools->get($n['tool'])?->risk()) === Risk::EXTERNAL && ! $approved) {
                        $e[] = "Stage {$id}: An approval step is needed on every path before a tool that sends outside.";
                    }
                    $walk($n['next'], $approved, $stack);
                    break;
                case 'ai':
                case 'delay':
                    $walk($n['next'], $approved, $stack);
                    break;
                case 'parallel':
                    $walk($n['join'], $approved, $stack);
                    break;
                case 'approval':
                    $walk($n['next'], true, $stack);
                    break;
                case 'condition':
                    $walk($n['then'], $approved, $stack);
                    $walk($n['else'], $approved, $stack);
                    break;
            }
        };
        $walk($start, false, []);

        return $e;
    }

    /** The run form fields. @param array<string, mixed> $d @return list<array{key: string, label: string}> */
    public static function inputs(array $d): array
    {
        $out = [];
        foreach (is_array($d['inputs'] ?? null) ? $d['inputs'] : [] as $in) {
            if (is_array($in) && is_string($in['key'] ?? null) && is_string($in['label'] ?? null)) {
                $out[] = ['key' => $in['key'], 'label' => $in['label']];
            }
        }

        return $out;
    }
}
