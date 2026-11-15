<?php

declare(strict_types=1);

namespace App\Voice;

/**
 * Turns a transcript like "সাপ্তাহিক মার্কেটিং ম্যানেজার চালাও" / "run weekly report" into "start the workflow called ...", and finds that workflow among the person's own.
 * Pure: no AI, no cost.  A speech transcript can be garbled, so this is strict: the sentence must be ONLY a start-verb and a name (no longer than 8 words),
 * the name must match exactly, or be contained in exactly one workflow name (at least 3 characters), and a workflow that needs typed input is never started by voice.
 */
final class VoiceCommand
{
    public const MAX_WORDS = 8;

    public const MIN_PART = 3;

    private const FILLER = ['জার্ভিস', 'jarvis', 'হে', 'hey', 'ওহে', 'please', 'প্লিজ', 'দয়া', 'করে', 'একটু'];

    private const NOISE = ['ওয়ার্কফ্লো', 'ওয়ার্কফ্লোটি', 'ওয়ার্কফ্লোটা', 'workflow', 'এজেন্ট', 'এজেন্টটি', 'এজেন্টটা', 'agent', 'the', 'my', 'টি', 'টা', 'টাকে', 'টিকে', 'আমার', 'এই'];

    private const VERBS_AFTER = ['চালিয়ে দাও', 'চালিয়ে দিন', 'চালাও', 'চালান', 'চালু করো', 'চালু করুন', 'রান করো', 'রান করুন', 'রান কর', 'শুরু করো', 'শুরু করুন', 'শুরু কর'];

    private const VERBS_BEFORE = ['run', 'start', 'launch', 'execute', 'চালাও', 'চালান', 'রান করো', 'শুরু করো'];

    public static function normalise(string $s): string
    {
        $s = mb_strtolower($s);
        $s = (string) preg_replace('/[\p{P}\p{S}\x{200c}\x{200d}]+/u', ' ', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** The name the person asked to run, or null when the sentence is not a start command. */
    public static function target(string $transcript): ?string
    {
        $t = self::normalise($transcript);
        if ($t === '' || count(explode(' ', $t)) > self::MAX_WORDS) {
            return null;
        }
        $words = explode(' ', $t);
        while ($words !== [] && in_array($words[0], self::FILLER, true)) {
            array_shift($words);
        }
        $t = implode(' ', $words);
        $name = null;
        foreach (self::VERBS_AFTER as $v) {                         // Bengali: name first, verb last
            if (str_ends_with($t, ' '.$v)) {
                $name = substr($t, 0, -strlen(' '.$v));
                break;
            }
        }
        if ($name === null) {
            foreach (self::VERBS_BEFORE as $v) {                    // English (or "চালাও X")
                if (str_starts_with($t, $v.' ')) {
                    $name = substr($t, strlen($v) + 1);
                    break;
                }
            }
        }
        if ($name === null) {
            return null;
        }
        $parts = array_values(array_filter(explode(' ', $name), fn ($w) => $w !== '' && ! in_array($w, self::NOISE, true)));
        $name = implode(' ', $parts);

        return $name === '' ? null : $name;
    }

    /**
     * @param  list<array{id: string, name: string, enabled: bool, needs_input: bool}>  $workflows
     * @return array{status: string, workflow: ?array{id: string, name: string, enabled: bool, needs_input: bool}, names: list<string>} status found|none|ambiguous|too_short
     */
    public static function find(string $target, array $workflows): array
    {
        $want = self::normalise($target);
        $exact = [];
        $partial = [];
        foreach ($workflows as $w) {
            $n = self::normalise($w['name']);
            if ($n === $want) {
                $exact[] = $w;
            } elseif (mb_strlen($want) >= self::MIN_PART && (str_contains($n, $want) || (mb_strlen($n) >= self::MIN_PART && str_contains($want, $n)))) {
                $partial[] = $w;
            }
        }
        $hits = $exact !== [] ? $exact : $partial;
        if (count($hits) === 1) {
            return ['status' => 'found', 'workflow' => $hits[0], 'names' => []];
        }
        if ($hits === []) {
            return ['status' => mb_strlen($want) < self::MIN_PART ? 'too_short' : 'none', 'workflow' => null, 'names' => []];
        }

        return ['status' => 'ambiguous', 'workflow' => null, 'names' => array_map(fn ($w) => $w['name'], array_slice($hits, 0, 3))];
    }

    /** @return array{command: string, text: string} the outcome and what to tell (and read to) the person */
    public static function run(string $transcript, string $tenantId, string $userId, VoiceActions $actions): ?array
    {
        $target = self::target($transcript);
        if ($target === null) {
            return null;
        }
        $f = self::find($target, $actions->workflows($userId));
        $say = fn (string $c, string $t) => ['command' => $c, 'text' => $t];
        if ($f['status'] === 'ambiguous') {
            return $say('ambiguous', 'Several workflows matched: '.implode(', ', $f['names']).'. Say the full name.');
        }
        if ($f['status'] !== 'found') {
            return $say('not_found', 'None of your workflows was found with this name.');
        }
        $w = $f['workflow'];
        if ($w['needs_input']) {
            return $say('needs_input', "‘{$w['name']}’ Running it requires input text — run it from the workflow page.");
        }
        if (! $w['enabled']) {
            return $say('disabled', "‘{$w['name']}’ is off — turn it on first.");
        }
        $r = $actions->startWorkflow($tenantId, $userId, $w['id']);

        return match ($r) {
            'ok' => $say('started', "‘{$w['name']}’ started. See the result on the workflow page; if approval is needed it will ask there."),
            'busy' => $say('busy', "‘{$w['name']}’-already has a run in progress or awaiting approval."),
            'disabled' => $say('disabled', "‘{$w['name']}’ is off — turn it on first."),
            'missing_input' => $say('needs_input', "‘{$w['name']}’ Running it requires input text — run it from the workflow page."),
            'not_found' => $say('not_found', 'None of your workflows was found with this name.'),
            default => $say('failed', 'The workflow could not be started. Try again.'),
        };
    }
}
