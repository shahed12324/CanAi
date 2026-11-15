<?php

declare(strict_types=1);

namespace App\DocTools;

use App\Assist\TranslateService;
use App\Services\AiEngine\AiEngineClient;
use App\Vision\VisionReader;
use Illuminate\Support\Str;

/** Runs one catalog tool on already-read files. Shared by the tool pages and the Photoshop AI agent. */
final class ToolRunner
{
    private const PATH = '/internal/v1/doctools/run';

    public function __construct(private readonly AiEngineClient $engine, private readonly VisionReader $vision, private readonly TranslateService $translate) {}

    /** Friendly message when the files do not suit the tool, or null. @param array<string, mixed> $t @param list<string> $names */
    public static function check(array $t, array $names): ?string
    {
        $n = count($names);
        if ($n < $t['min']) {
            return $t['min'] === 1 ? 'Attach a file first.' : 'This needs at least '.$t['min'].' files.';
        }
        if ($n > $t['max']) {
            return $t['max'] === 0 ? 'This tool does not take a file.' : 'This takes at most '.$t['max'].' file(s).';
        }
        foreach ($names as $name) {
            if (! Catalog::accepts($t, $name)) {
                return "“{$name}” is not a type this tool takes (".implode(', ', Catalog::EXT[$t['accept']]).').';
            }
        }

        return null;
    }

    /**
     * @param  list<array{name: string, bytes: string}>  $files
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, error?: string, file?: array{body: string, mime: string, ext: string}, text?: string}
     */
    public function run(string $key, array $files, array $params, string $tenantId, string $userId): array
    {
        $t = Catalog::get($key);
        if ($t === null) {
            return ['ok' => false, 'error' => 'Unknown tool.'];
        }
        if (($err = self::check($t, array_column($files, 'name'))) !== null) {
            return ['ok' => false, 'error' => $err];
        }
        try {
            return match ($t['runner'] ?? 'engine') {
                'vision' => $this->handwriting($files[0]['bytes'], $tenantId, $userId),
                'translate' => $this->translateDoc($files[0], (string) ($params['target'] ?? 'Bengali'), $tenantId, $userId),
                default => $this->engineRun($t['op'], $files, $params, $tenantId, $userId),
            };
        } catch (\RuntimeException) {
            return ['ok' => false, 'error' => 'The conversion service is not reachable right now. Try again in a minute.'];
        }
    }

    /** @param list<array{name: string, bytes: string}> $files @param array<string, mixed> $params @return array<string, mixed> */
    private function engineRun(string $op, array $files, array $params, string $tenantId, string $userId): array
    {
        $r = $this->engine->postFile(self::PATH, $tenantId, [
            'op' => $op, 'params' => (object) $params,
            'files' => array_map(fn ($f) => ['name' => Str::limit((string) preg_replace('/[^\w.\- ]/u', '_', $f['name']), 80, ''), 'b64' => base64_encode($f['bytes'])], $files),
        ], $userId);
        if ($r['status'] !== 200) {
            return ['ok' => false, 'error' => $r['status'] === 422 && $r['message'] !== '' ? $r['message'] : 'The file could not be processed.'];
        }

        return ['ok' => true, 'file' => ['body' => $r['body'], 'mime' => $r['mime'] !== '' ? $r['mime'] : 'application/octet-stream', 'ext' => $r['ext'] !== '' ? $r['ext'] : 'bin']];
    }

    /** @return array<string, mixed> */
    private function handwriting(string $bytes, string $tenantId, string $userId): array
    {
        $r = $this->vision->read($tenantId, $userId, $bytes, 'transcribe', (string) Str::ulid());
        $msg = [
            'no_model' => 'No AI vision model is set up yet. Ask your admin to add one, or use the free OCR tools.',
            'no_credits' => 'You do not have enough AI credits for this.', 'rejected' => 'That file cannot be read (use a picture or PDF, up to a few MB).',
            'unsupported' => 'The selected AI model cannot read pictures.', 'provider' => 'The AI provider did not answer. Try again.', 'failed' => 'Something went wrong. Try again.',
            'empty' => 'No readable text was found.',
        ];
        if ($r['status'] !== 'ok') {
            return ['ok' => false, 'error' => $msg[$r['status']] ?? $msg['failed']];
        }

        return ['ok' => true, 'text' => (string) $r['text']];
    }

    /** @param array{name: string, bytes: string} $file @return array<string, mixed> */
    private function translateDoc(array $file, string $target, string $tenantId, string $userId): array
    {
        $ex = $this->engineRun('extract_text', [$file], ['lang' => 'eng+ben'], $tenantId, $userId);
        if (! $ex['ok']) {
            return $ex;
        }
        $r = $this->translate->translate($tenantId, $userId, $ex['file']['body'], $target, (string) Str::ulid());
        $msg = [
            'too_long' => 'This document is longer than '.number_format(TranslateService::MAX_CHARS).' characters. Split it first (PDF split) and translate the parts.',
            'no_model' => 'No AI model is set up yet. Ask your admin to add one.', 'no_credits' => 'You do not have enough AI credits for this.',
            'provider' => 'The AI provider did not answer. Try again.', 'empty' => 'No readable text was found.', 'bad_target' => 'Choose a language from the list.',
        ];
        if (! in_array($r['status'], ['ok', 'partial'], true)) {
            return ['ok' => false, 'error' => $msg[$r['status']] ?? 'The translation failed. Try again.'];
        }
        $doc = $this->engineRun('text_to_docx', [], ['text' => $r['text'], 'title' => ''], $tenantId, $userId);
        if ($doc['ok'] && $r['status'] === 'partial') {
            $doc['note'] = "Only {$r['done']} of {$r['total']} parts were translated (credits ran out or the AI stopped). The rest is missing from the file.";
        }

        return $doc;
    }
}
