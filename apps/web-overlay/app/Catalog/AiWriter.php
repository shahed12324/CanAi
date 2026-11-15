<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Ai\ProviderCallException;
use App\Chat\ChatEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Chat\TokenEstimator;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * Drafts a product description + SEO text with the workspace's AI credits.
 * Same money rule as chat: reserve first, settle the real cost, release on every failure.
 * Nothing is saved here — the person reviews the text, and it still goes through Draft -> Approval -> Publish.
 */
final class AiWriter
{
    public function __construct(
        private readonly ModelPicker $models,
        private readonly ChatEngine $engine,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
        private readonly int $maxOutput = 700,
    ) {}

    /**
     * @param  array{title: string, category?: ?string, tags?: list<string>, price?: ?string, notes?: ?string, language?: string, tone?: ?string}  $in
     * @return array{description: string, seo_title: string, seo_description: string, credits: string}
     *
     * @throws AiWriterException
     */
    public function write(string $tenantId, string $userId, array $in): array
    {
        $title = trim($in['title']);
        if ($title === '') {
            throw new AiWriterException('empty', 'First write the product title.');
        }
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            throw new AiWriterException('no_model', 'No usable AI model is enabled in your plan.');
        }
        $messages = self::messages($in);
        $inEst = TokenEstimator::estimate($messages[0]['content'].$messages[1]['content']);
        $max = max(64, min($model['max_output'] ?? $this->maxOutput, $this->maxOutput));
        $mult = $model['credit_multiplier'] ?? null;
        $id = bin2hex(random_bytes(13));
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount($inEst, $max, $mult), 'catalog-ai:'.$id, $id)['reservation_id'];
        } catch (CreditException $e) {
            throw new AiWriterException($e->errorCode, $e->userMessage());
        } catch (\Throwable $e) {
            self::report($e);
            throw new AiWriterException('internal', 'A temporary problem with the credit system.');
        }

        $text = '';
        $inTok = null;
        $outTok = null;
        $settled = false;
        try {
            try {
                foreach ($this->engine->stream($model['provider_id'], $model['model_key'], $messages, $tenantId, $userId, $max) as $ev) {
                    $text .= $ev['text'];
                    $inTok = $ev['input_tokens'] ?? $inTok;
                    $outTok = $ev['output_tokens'] ?? $outTok;
                }
            } catch (ProviderCallException $e) {
                throw new AiWriterException($e->errorCode, 'AI Cannot reply right now. Try again shortly.');
            } catch (AiWriterException $e) {
                throw $e;
            } catch (\Throwable $e) {
                self::report($e);
                throw new AiWriterException('engine_error', 'AI Could not connect to the service.');
            }
            $parsed = self::parse($text);
            if ($parsed === null) {
                throw new AiWriterException('bad_output', 'AI-reply could not be understood. Try again.');
            }
            $cost = $this->pricing->cost($inTok ?? $inEst, $outTok ?? TokenEstimator::estimate($text), $mult);
            $charged = '0.0000';
            try {
                $charged = $this->credits->settle($tenantId, $reservation, $cost, 'catalog-ai-settle:'.$id, [
                    'source' => 'catalog', 'user_id' => $userId, 'request_id' => $id, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'],
                    'input_tokens' => $inTok ?? $inEst, 'output_tokens' => $outTok ?? TokenEstimator::estimate($text),
                ])['charged'];
            } catch (\Throwable $e) {
                self::report($e);   // reservation expires and returns; the customer is not charged
            }
            $settled = true;

            return $parsed + ['credits' => $charged];
        } finally {
            if (! $settled) {
                try {
                    $this->credits->release($tenantId, $reservation, 'catalog-ai-release:'.$id);
                } catch (\Throwable $e) {
                    self::report($e);
                }
            }
        }
    }

    /** @return list<array{role: string, content: string}> */
    public static function messages(array $in): array
    {
        $lang = ($in['language'] ?? 'bn') === 'en' ? 'English' : 'Bangla (Bengali)';
        $facts = ['Title: '.mb_substr(trim($in['title']), 0, 250)];
        foreach (['category' => 'Category', 'price' => 'Price', 'notes' => 'Facts about the product', 'tone' => 'Tone'] as $k => $label) {
            if (! empty($in[$k])) {
                $facts[] = $label.': '.mb_substr(trim((string) $in[$k]), 0, 1500);
            }
        }
        if (! empty($in['tags'])) {
            $facts[] = 'Tags: '.mb_substr(implode(', ', $in['tags']), 0, 400);
        }

        return [
            ['role' => 'system', 'content' => "You write product copy for an online shop. Write in $lang. Use ONLY the facts given; never invent materials, sizes, certifications, warranties or numbers. "
                .'Reply with a single JSON object and nothing else: {"description": "...", "seo_title": "... (max 60 chars)", "seo_description": "... (max 155 chars)"}. The description is 60-150 words of plain text, no markdown.'],
            ['role' => 'user', 'content' => implode("\n", $facts)],
        ];
    }

    /** Lenient: finds the first {...} even inside a code fence; clips to the allowed lengths. */
    public static function parse(string $raw): ?array
    {
        $s = strpos($raw, '{');
        $e = strrpos($raw, '}');
        if ($s === false || $e === false || $e <= $s) {
            return null;
        }
        $j = json_decode(substr($raw, $s, $e - $s + 1), true);
        if (! is_array($j) || ! isset($j['description']) || ! is_string($j['description']) || trim($j['description']) === '') {
            return null;
        }
        $clip = fn ($v, int $n) => mb_substr(trim(is_string($v) ? $v : ''), 0, $n);

        return ['description' => $clip($j['description'], 5000), 'seo_title' => $clip($j['seo_title'] ?? '', 70), 'seo_description' => $clip($j['seo_description'] ?? '', 180)];
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
