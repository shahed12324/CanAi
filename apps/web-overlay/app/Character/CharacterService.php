<?php

declare(strict_types=1);

namespace App\Character;

/** Validation and rules around characters. Starter templates are original: no existing film/comic/game character is imitated (ARCHITECTURE R14). */
final class CharacterService
{
    public const MAX = 20;

    public function __construct(private readonly CharacterRepo $repo) {}

    /** @return list<array{name: string, kind: string, personality: string, speaking_style: string, language: string, greeting: string}> */
    public static function starters(): array
    {
        return [
            ['name' => 'Cyan', 'kind' => 'male', 'personality' => 'A calm, patient and friendly advisor; explains complex things with simple examples.', 'speaking_style' => 'Short, warm sentences; an occasional light joke.', 'language' => 'bn', 'greeting' => 'Hello! How can I help today?'],
            ['name' => 'Neela', 'kind' => 'female', 'personality' => 'Lively, curious and encouraging; loves discussing new ideas.', 'speaking_style' => 'Simple, friendly manner; moves the conversation along by asking questions.', 'language' => 'bn', 'greeting' => 'Hello! What are you thinking about today?'],
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    public function validate(array $in): array
    {
        $clean = fn (mixed $v): string => trim((string) preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', (string) $v));
        $d = [
            'name' => $clean($in['name'] ?? ''), 'kind' => (string) ($in['kind'] ?? ''), 'personality' => $clean($in['personality'] ?? ''),
            'speaking_style' => $clean($in['speaking_style'] ?? ''), 'language' => (string) ($in['language'] ?? 'bn'),
            'voice' => ($v = $clean($in['voice'] ?? '')) === '' ? null : $v, 'avatar_style' => (string) ($in['avatar_style'] ?? 'none'), 'greeting' => $clean($in['greeting'] ?? ''),
        ];
        $err = match (true) {
            $d['name'] === '' || mb_strlen($d['name']) > 60 => 'The name must be 1–60 characters.',
            ! in_array($d['kind'], ['male', 'female', 'custom'], true) => 'Pick a type (male/female/custom).',
            $d['personality'] === '' || mb_strlen($d['personality']) > 1000 => 'Personality must be 1–1000 characters.',
            mb_strlen($d['speaking_style']) > 500 => 'Speaking style must not exceed 500 characters.',
            ! in_array($d['language'], ['bn', 'en', 'auto'], true) => 'Pick a language.',
            $d['voice'] !== null && preg_match('/\A[A-Za-z0-9 ._:-]{1,60}\z/', $d['voice']) !== 1 => 'The voice name is invalid.',
            ! in_array($d['avatar_style'], ['none', '2d', '3d'], true) => 'The avatar type is invalid.',
            mb_strlen($d['greeting']) > 300 => 'The greeting message must not exceed 300 characters.',
            default => null,
        };

        return $err === null ? ['ok' => true, 'data' => $d] : ['ok' => false, 'error' => $err];
    }

    /** @param array<string, mixed> $in @return array{ok: bool, id?: string, error?: string} */
    public function create(string $tenantId, string $userId, array $in): array
    {
        $v = $this->validate($in);
        if (! $v['ok']) {
            return $v;
        }
        if ($this->repo->count($tenantId) >= self::MAX) {
            return ['ok' => false, 'error' => 'max '.self::MAX.' characters can be kept; delete one first.'];
        }

        return ['ok' => true, 'id' => $this->repo->create($tenantId, $userId, $v['data'])];
    }

    /** @param array<string, mixed> $in @return array{ok: bool, error?: string} */
    public function update(string $tenantId, string $userId, string $id, array $in): array
    {
        $v = $this->validate($in);
        if (! $v['ok']) {
            return $v;
        }

        return $this->repo->update($tenantId, $userId, $id, $v['data']) ? ['ok' => true] : ['ok' => false, 'error' => 'The character was not found, or it is not yours.'];
    }

    public function delete(string $tenantId, string $userId, string $id): bool
    {
        return $this->repo->delete($tenantId, $userId, $id);
    }
}
