<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Studio\StudioService;
use App\Workflow\Risk;
use App\Workflow\ToolRegistry;
use App\Workflow\WorkflowDefinition;
use App\Workflow\WorkflowService;

/**
 * A plugin manifest: {"workflows": [{"name", "description"?, "category", "definition"}]}, one to five workflows.
 * Nothing in it can run code - a workflow is the same declarative thing a person could build by hand, checked by the same rules
 * (known tools only, no money tools, an approval step before anything external).
 */
final class PluginManifest
{
    public const MAX_WORKFLOWS = 5;

    public const SLUG = '/\A[a-z][a-z0-9-]{2,39}\z/';

    /**
     * @param  array<string, mixed>  $m
     * @return array{errors: list<string>, workflows: list<array{name: string, description: string, category: string, definition: array<string, mixed>}>, tools: list<string>}
     */
    public static function check(array $m, ToolRegistry $tools): array
    {
        $errors = [];
        $out = [];
        $used = [];
        $list = $m['workflows'] ?? null;
        if (! is_array($list) || ! array_is_list($list) || $list === [] || count($list) > self::MAX_WORKFLOWS) {
            return ['errors' => ['The manifest must have 1 to '.self::MAX_WORKFLOWS.' workflows (a workflows list).'], 'workflows' => [], 'tools' => []];
        }
        foreach ($list as $i => $w) {
            $n = $i + 1;
            if (! is_array($w)) {
                $errors[] = "Workflow {$n}: must be an object.";

                continue;
            }
            $name = StudioService::line(is_string($w['name'] ?? null) ? $w['name'] : '', 200);
            $desc = StudioService::line(is_string($w['description'] ?? null) ? $w['description'] : '', 1000);
            $cat = is_string($w['category'] ?? null) ? $w['category'] : '';
            if ($name === '' || mb_strlen($name) > 80) {
                $errors[] = "Workflow {$n}: The name must be 1–80 characters.";
            }
            if (mb_strlen($desc) > 400) {
                $errors[] = "Workflow {$n}: The description is over 400 characters.";
            }
            if (! isset(WorkflowService::CATEGORIES[$cat])) {
                $errors[] = "Workflow {$n}: The category is not valid.";
            }
            $def = $w['definition'] ?? null;
            if (! is_array($def)) {
                $errors[] = "Workflow {$n}: definition None.";

                continue;
            }
            foreach (WorkflowDefinition::validate($def, $tools) as $e) {
                $errors[] = "Workflow {$n}: {$e}";
            }
            foreach ((array) ($def['nodes'] ?? []) as $node) {
                if (is_array($node) && ($node['type'] ?? '') === 'tool' && is_string($node['tool'] ?? null)) {
                    $used[$node['tool']] = true;
                }
            }
            $out[] = ['name' => $name, 'description' => $desc, 'category' => $cat, 'definition' => $def];
        }
        $keys = array_keys($used);
        sort($keys);

        return ['errors' => $errors, 'workflows' => $errors === [] ? $out : [], 'tools' => $errors === [] ? $keys : []];
    }

    /**
     * What the plugin may do, for the person to read before installing.
     *
     * @param  list<string>  $toolKeys
     * @return list<array{key: string, label: string, risk: string, risk_label: string}>
     */
    public static function permissions(array $toolKeys, ToolRegistry $tools): array
    {
        $out = [];
        foreach ($toolKeys as $k) {
            $t = $tools->get($k);
            $risk = $t?->risk() ?? Risk::EXTERNAL;            // a tool that no longer exists is shown as the most careful kind
            $out[] = ['key' => $k, 'label' => $t?->label() ?? $k, 'risk' => $risk, 'risk_label' => Risk::LABELS[$risk] ?? $risk];
        }

        return $out;
    }
}
