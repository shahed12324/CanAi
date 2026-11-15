<?php

declare(strict_types=1);

namespace App\Skills;

use Illuminate\Support\Str;

/** Asks the model to draft a skill from a task that worked, or to improve one from feedback. Returns drafts only: nothing is saved here. */
final class SkillAuthor
{
    public const MESSAGES = [
        'no_model' => 'No AI model is set up yet. Ask your admin to add one.', 'no_credits' => 'You do not have enough AI credits for this.',
        'provider' => 'The AI provider did not answer. Try again.', 'failed' => 'Something went wrong. Try again.',
        'unreadable' => 'The AI did not return a usable skill. Try again, or write the skill by hand.',
    ];

    public function __construct(private readonly PaidCompletion $ai) {}

    /** @return array{status: string, draft: ?array{name: string, description: string, instructions: string}} */
    public function learn(string $tenantId, string $userId, string $source): array
    {
        $source = SkillText::clean($source, SkillText::MAX_SOURCE);
        if (mb_strlen($source) < 20) {
            return ['status' => 'too_short', 'draft' => null];
        }

        return $this->draft($this->ai->run($tenantId, $userId, 'skill_learn', SkillText::learnMessages($source), 1500, (string) Str::ulid()));
    }

    /** @param array{name: string, description: string, instructions: string} $skill @param list<array{rating: int, note: ?string, context: ?string}> $feedback */
    public function improve(string $tenantId, string $userId, array $skill, array $feedback): array
    {
        return $this->draft($this->ai->run($tenantId, $userId, 'skill_improve', SkillText::improveMessages($skill, $feedback), 1500, (string) Str::ulid()));
    }

    /** @param array{status: string, text: string} $r */
    private function draft(array $r): array
    {
        if ($r['status'] !== 'ok') {
            return ['status' => $r['status'], 'draft' => null];
        }
        $d = SkillText::parseDraft($r['text']);

        return $d === null ? ['status' => 'unreadable', 'draft' => null] : ['status' => 'ok', 'draft' => $d];
    }
}
