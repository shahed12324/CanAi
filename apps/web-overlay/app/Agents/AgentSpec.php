<?php

declare(strict_types=1);

namespace App\Agents;

use App\Workflow\Risk;
use App\Workflow\ToolRegistry;

/**
 * Checks and cleans what a person typed into the agent form.  Pure (no database, no network).
 * Tools must exist and may never move money; tools that act outside the app need "allow_external", tools that touch the person's computer need "desktop".
 * The database re-checks the shape, the runner re-checks the permissions when it uses the agent.
 */
final class AgentSpec
{
    public const MAX_TOOLS = 20;

    public const DEFAULT_PERMISSIONS = ['allow_external' => false, 'desktop' => false];

    /**
     * @param  array<string, mixed>  $in
     * @return array{errors: list<string>, data: array<string, mixed>}
     */
    public static function normalise(array $in, ToolRegistry $tools): array
    {
        $e = [];
        $str = fn (string $k, int $max): string => mb_substr(trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', (string) ($in[$k] ?? ''))), 0, $max + 1);
        $name = preg_replace('/\s+/u', ' ', $str('name', 80));
        $desc = $str('description', 500);
        $prompt = $str('system_prompt', 8000);
        if ($name === '' || mb_strlen($name) > 80) {
            $e[] = 'The name must be 1–80 characters.';
        }
        if (mb_strlen($desc) > 500) {
            $e[] = 'The description can be at most 500 characters.';
        }
        if ($prompt === '' || mb_strlen($prompt) > 8000) {
            $e[] = 'The instructions (system prompt) must be 1–8000 characters.';
        }
        $temp = is_numeric($in['temperature'] ?? null) ? (float) $in['temperature'] : 0.3;
        if ($temp < 0 || $temp > 2) {
            $e[] = 'Temperature must be between 0 and 2.';
        }
        $max = is_numeric($in['max_tokens'] ?? null) ? (int) $in['max_tokens'] : 800;
        if ($max < 100 || $max > 4000) {
            $e[] = 'Max answer length must be 100–4000 tokens.';
        }
        $credits = is_numeric($in['max_credits'] ?? null) ? (float) $in['max_credits'] : 2.0;
        if ($credits < 0 || $credits > 1000) {
            $e[] = 'The credit limit per run must be 0–1000.';
        }
        $model = trim((string) ($in['model_id'] ?? ''));
        if ($model !== '' && preg_match('/\A[0-9a-z]{26}\z/', $model) !== 1) {
            $e[] = 'The chosen model is not valid.';
            $model = '';
        }
        $perm = self::DEFAULT_PERMISSIONS;
        foreach (array_keys($perm) as $k) {
            $perm[$k] = filter_var($in['permissions'][$k] ?? $in['perm_'.$k] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        $selected = array_values(array_unique(array_map('strval', array_filter((array) ($in['tools'] ?? []), 'is_string'))));
        if (count($selected) > self::MAX_TOOLS) {
            $e[] = 'At most '.self::MAX_TOOLS.' tools.';
        }
        foreach ($selected as $key) {
            $t = $tools->get($key);
            if ($t === null) {
                $e[] = 'Unknown tool: '.mb_substr($key, 0, 40);

                continue;
            }
            if ($t->risk() === Risk::MONEY) {
                $e[] = 'The tool "'.$key.'" moves money and can never be given to an agent.';
            } elseif ($t->risk() === Risk::EXTERNAL && $perm['allow_external'] !== true) {
                $e[] = 'The tool "'.$key.'" acts outside the app: switch on "May act outside the app (every use needs your approval)" first.';
            }
            if (str_starts_with($key, 'pc.') && $perm['desktop'] !== true) {
                $e[] = 'The tool "'.$key.'" uses your computer: switch on "May use my paired computer" first.';
            }
        }

        return ['errors' => $e, 'data' => [
            'name' => $name, 'description' => $desc, 'system_prompt' => $prompt, 'model_id' => $model === '' ? null : $model, 'temperature' => round($temp, 2), 'max_tokens' => $max,
            'tools' => $selected, 'use_knowledge' => filter_var($in['use_knowledge'] ?? false, FILTER_VALIDATE_BOOLEAN), 'use_memory' => filter_var($in['use_memory'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'permissions' => $perm, 'max_credits' => $credits, 'enabled' => filter_var($in['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ]];
    }
}
