<?php

declare(strict_types=1);

namespace App\Pc;

/** Asks the AI engine to turn a goal into a DRAFT plan (the engine validates it; Laravel and the database validate it again). */
interface PlanEngine
{
    /**
     * @param  list<string>  $roots
     * @param  list<string>  $kinds
     * @param  list<string>  $apps   programs the owner allowed (screen / mouse-keyboard / open_app kinds)
     * @param  list<string>  $hosts  websites the owner allowed (browser kinds)
     * @return array<string, mixed>  the engine's answer: status ok|refused|invalid, plan?, review?, reason?, input_tokens, output_tokens
     */
    public function draft(string $providerId, string $modelKey, string $goal, array $roots, array $kinds, string $tenantId, string $userId, array $apps = [], array $hosts = []): array;
}
