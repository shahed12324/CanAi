<?php

declare(strict_types=1);

namespace App\Workflow;

interface Tool
{
    public function key(): string;

    /** Bengali name for the screen. */
    public function label(): string;

    /** One of Risk::*. */
    public function risk(): string;

    /** @return array<string, string> argument name => short Bengali description; a name ending in "?" is optional */
    public function args(): array;

    /**
     * @param  array<string, string>  $args  already filled in with the run's variables; plain text
     * @return string what the tool did / found, plain text (cut to 8000 characters by the runner)
     *
     * @throws ToolException
     */
    public function run(ToolContext $c, array $args): string;
}
