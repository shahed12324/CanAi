<?php

declare(strict_types=1);

namespace App\Ai;

use App\Services\AiEngine\AiEngineClient;

/**
 * The only place that turns a stored provider + encrypted credential into an engine call.
 * The decrypted key exists only inside the payload of one request; it is never returned,
 * stored, logged or put in an exception.
 */
final class ProviderGateway
{
    public function __construct(
        private readonly ProviderStore $store,
        private readonly CredentialVault $vault,
        private readonly AiEngineClient $engine,
    ) {}

    /** @return array{ok: bool, latency_ms?: int, error_code?: string, message?: string} */
    public function testConnection(string $providerId, string $tenantId, bool $adminTest = false): array
    {
        $res = $this->engine->post('/internal/v1/providers/test', $tenantId, ['provider' => $this->spec($providerId, $adminTest)]);

        return $res['body'];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{text: string, model: string, input_tokens: ?int, output_tokens: ?int, finish_reason: ?string}
     */
    public function complete(string $providerId, string $model, array $messages, string $tenantId, ?string $userId = null, ?int $maxTokens = null, ?float $temperature = null): array
    {
        $res = $this->engine->post('/internal/v1/ai/complete', $tenantId, [
            'provider' => $this->spec($providerId),
            'request' => array_filter(['model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => $temperature], fn ($v) => $v !== null),
        ], $userId);

        if ($res['status'] >= 400) {
            $e = $res['body']['error'] ?? [];
            throw new ProviderCallException((string) ($e['code'] ?? 'upstream_error'), (string) ($e['message'] ?? 'provider error'), $res['status']);
        }

        return $res['body'];
    }

    /**
     * Streams a completion from the engine. Yields normalised delta events; throws ProviderCallException
     * (before the first yield for HTTP errors, or mid-stream when the engine reports an `error` event).
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return \Generator<int, array{text: string, input_tokens: ?int, output_tokens: ?int, finish_reason: ?string}>
     */
    public function stream(string $providerId, string $model, array $messages, string $tenantId, ?string $userId = null, ?int $maxTokens = null): \Generator
    {
        $res = $this->engine->postStream('/internal/v1/ai/stream', $tenantId, [
            'provider' => $this->spec($providerId),
            'request' => array_filter(['model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens], fn ($v) => $v !== null),
        ], $userId);

        if ($res['stream'] === null) {
            $e = $res['error']['error'] ?? [];
            throw new ProviderCallException((string) ($e['code'] ?? 'upstream_error'), (string) ($e['message'] ?? 'provider error'), $res['status']);
        }

        $parser = new \App\Chat\SseParser;
        $body = $res['stream'];
        try {
            while (! $body->eof()) {
                foreach ($parser->feed($body->read(8192)) as $ev) {
                    if ($ev['event'] === 'error') {
                        throw new ProviderCallException((string) ($ev['data']['code'] ?? 'upstream_error'), (string) ($ev['data']['message'] ?? 'provider error'));
                    }
                    if ($ev['event'] === 'done') {
                        return;
                    }
                    $d = $ev['data'];
                    yield [
                        'text' => (string) ($d['text'] ?? ''),
                        'input_tokens' => isset($d['input_tokens']) ? (int) $d['input_tokens'] : null,
                        'output_tokens' => isset($d['output_tokens']) ? (int) $d['output_tokens'] : null,
                        'finish_reason' => $d['finish_reason'] ?? null,
                    ];
                }
            }
        } finally {
            $body->close();   // also runs when the consumer stops early (user pressed Stop): drops the upstream connection
        }
    }

    /**
     * Reads one bill (image or PDF) through the engine. The engine downsizes/strips the file and returns a structured draft.
     * No timeout shorter than a vision call needs; errors from the provider come back as ProviderCallException.
     *
     * @param  list<array{code: string, name: string}>  $accounts
     * @return array<string, mixed>
     */
    public function scanBill(string $providerId, string $model, string $mime, string $bytes, array $accounts, string $tenantId, ?string $userId = null): array
    {
        $res = $this->engine->post('/internal/v1/bills/scan', $tenantId, [
            'provider' => $this->spec($providerId),
            'model' => $model,
            'mime' => $mime,
            'file_b64' => base64_encode($bytes),
            'accounts' => $accounts,
            'max_tokens' => 2000,
        ], $userId, 110);

        if ($res['status'] >= 400) {
            $e = $res['body']['error'] ?? [];
            throw new ProviderCallException((string) ($e['code'] ?? 'upstream_error'), (string) ($e['message'] ?? 'provider error'), $res['status']);
        }

        return $res['body'];
    }

    /**
     * Reads the text (mode "transcribe") or describes (mode "describe") one picture or PDF through the engine. Nothing is stored there.
     *
     * @return array<string, mixed> status ok|empty|unsupported, text, pages, source, model, input_tokens, output_tokens
     */
    public function readImage(string $providerId, string $model, string $mime, string $bytes, string $mode, string $tenantId, ?string $userId = null): array
    {
        $res = $this->engine->post('/internal/v1/vision/read', $tenantId, [
            'provider' => $this->spec($providerId),
            'model' => $model,
            'mime' => $mime,
            'file_b64' => base64_encode($bytes),
            'mode' => $mode,
            'max_tokens' => 3000,
        ], $userId, 110);

        if ($res['status'] >= 400) {
            $e = $res['body']['error'] ?? [];
            throw new ProviderCallException((string) ($e['code'] ?? 'upstream_error'), (string) ($e['message'] ?? 'provider error'), $res['status']);
        }

        return $res['body'];
    }

    /**
     * Audio in -> text (OpenAI-style speech endpoint). Nothing is kept.
     *
     * @return array<string, mixed> status ok|empty|unsupported, text, reason
     */
    public function transcribe(string $providerId, string $model, string $mime, string $bytes, ?string $language, string $tenantId, ?string $userId = null): array
    {
        $res = $this->engine->post('/internal/v1/voice/transcribe', $tenantId, [
            'provider' => $this->spec($providerId),
            'model' => $model,
            'mime' => $mime,
            'audio_b64' => base64_encode($bytes),
            'language' => $language,
        ], $userId, 110);
        if ($res['status'] >= 400) {
            $e = $res['body']['error'] ?? [];
            throw new ProviderCallException((string) ($e['code'] ?? 'upstream_error'), (string) ($e['message'] ?? 'provider error'), $res['status']);
        }

        return $res['body'];
    }

    /**
     * Text -> spoken audio (mp3, base64).
     *
     * @return array<string, mixed> status ok|unsupported, mime, audio_b64, chars, reason
     */
    public function speak(string $providerId, string $model, string $voice, string $text, string $tenantId, ?string $userId = null): array
    {
        $res = $this->engine->post('/internal/v1/voice/speak', $tenantId, [
            'provider' => $this->spec($providerId),
            'model' => $model,
            'text' => $text,
            'voice' => $voice,
        ], $userId, 110);
        if ($res['status'] >= 400) {
            $e = $res['body']['error'] ?? [];
            throw new ProviderCallException((string) ($e['code'] ?? 'upstream_error'), (string) ($e['message'] ?? 'provider error'), $res['status']);
        }

        return $res['body'];
    }

    /**
     * Asks an image model for a background picture (the engine forbids text inside it and re-encodes it as a clean PNG).
     *
     * @return array<string, mixed> status ok|unsupported, image_b64, width, height, reason
     */
    public function image(string $providerId, string $model, string $prompt, string $size, string $tenantId, ?string $userId = null): array
    {
        $res = $this->engine->post('/internal/v1/studio/generate', $tenantId, [
            'provider' => $this->spec($providerId),
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
        ], $userId, 170);
        if ($res['status'] >= 400) {
            $e = $res['body']['error'] ?? [];
            throw new ProviderCallException((string) ($e['code'] ?? 'upstream_error'), (string) ($e['message'] ?? 'provider error'), $res['status']);
        }

        return $res['body'];
    }

    /**
     * Draws brand, logo and real Bengali text over a picture (or a brand gradient) and exports the sizes.  Needs no provider and costs nothing.
     *
     * @param  array<string, mixed>  $payload  copy, brand, base_b64?, sizes, fmt
     * @return array<string, mixed> status ok|unsupported, items[], reason
     */
    public function composeDesign(array $payload, string $tenantId, ?string $userId = null): array
    {
        $res = $this->engine->post('/internal/v1/studio/compose', $tenantId, $payload, $userId, 60);
        if ($res['status'] >= 400) {
            $e = $res['body']['error'] ?? [];
            throw new ProviderCallException((string) ($e['code'] ?? 'upstream_error'), (string) ($e['message'] ?? 'engine error'), $res['status']);
        }

        return $res['body'];
    }

    /**
     * Renders a slide video on the engine (no provider needed).  Slow: waits up to 5 minutes.
     *
     * @param  array<string, mixed>  $payload  size, scenes[], brand, audio_b64?, fade, motion
     * @return array{status: string, bytes: ?string, reason: ?string} ok (mp4 bytes) | unsupported (the engine refused the request)
     *
     * @throws ProviderCallException when the engine answers with an error status
     */
    public function renderVideo(array $payload, string $tenantId, ?string $userId = null): array
    {
        return $this->videoCall('/internal/v1/video/render', $payload, $tenantId, $userId);
    }

    /**
     * Trims / de-silences / resizes an uploaded clip on the engine.  Same answer shape as renderVideo().
     *
     * @param  array<string, mixed>  $payload  video_b64, trim_start, trim_end, remove_silence, size
     * @return array{status: string, bytes: ?string, reason: ?string}
     *
     * @throws ProviderCallException
     */
    public function editVideo(array $payload, string $tenantId, ?string $userId = null): array
    {
        return $this->videoCall('/internal/v1/video/edit', $payload, $tenantId, $userId);
    }

    /**
     * Length and shape of an uploaded clip.
     *
     * @return array{status: string, duration: float, has_audio: bool, reason: ?string}
     *
     * @throws ProviderCallException
     */
    public function probeVideo(string $videoB64, string $tenantId, ?string $userId = null): array
    {
        $res = $this->engine->post('/internal/v1/video/probe', $tenantId, ['video_b64' => $videoB64], $userId, 60);
        if ($res['status'] !== 200 || ! is_array($res['body'])) {
            throw new ProviderCallException($res['status'] === 503 ? 'engine_busy' : 'upstream_error', 'video engine error', $res['status']);
        }
        $b = $res['body'];
        if (($b['status'] ?? '') !== 'ok') {
            return ['status' => 'unsupported', 'duration' => 0.0, 'has_audio' => false, 'reason' => (string) ($b['reason'] ?? 'unknown')];
        }

        return ['status' => 'ok', 'duration' => (float) ($b['duration'] ?? 0), 'has_audio' => (bool) ($b['has_audio'] ?? false), 'reason' => null];
    }

    /** @return array{status: string, bytes: ?string, reason: ?string} */
    private function videoCall(string $path, array $payload, string $tenantId, ?string $userId): array
    {
        $res = $this->engine->postRaw($path, $tenantId, $payload, $userId, 300);
        if ($res['status'] !== 200) {
            throw new ProviderCallException($res['status'] === 503 ? 'engine_busy' : 'upstream_error', 'video engine error', $res['status']);
        }
        $body = $res['body'];
        if (strlen($body) > 11 && substr($body, 4, 4) === 'ftyp') {
            return ['status' => 'ok', 'bytes' => $body, 'reason' => null];
        }
        $j = json_decode($body, true);

        return ['status' => 'unsupported', 'bytes' => null, 'reason' => is_array($j) ? (string) ($j['reason'] ?? 'unknown') : 'bad_response'];
    }

    /**
     * Goal -> draft computer plan (the engine checks it against the allowed folders and kinds).
     *
     * @param  list<string>  $roots
     * @param  list<string>  $kinds
     * @return array<string, mixed>
     */
    public function planDraft(string $providerId, string $model, string $goal, array $roots, array $kinds, string $tenantId, ?string $userId = null, array $apps = [], array $hosts = []): array
    {
        $res = $this->engine->post('/internal/v1/plan', $tenantId, ['provider' => $this->spec($providerId), 'model' => $model, 'goal' => $goal, 'roots' => $roots, 'kinds' => $kinds, 'apps' => $apps, 'hosts' => $hosts], $userId, 120);
        if ($res['status'] >= 400) {
            $e = $res['body']['error'] ?? [];
            throw new ProviderCallException((string) ($e['code'] ?? 'upstream_error'), (string) ($e['message'] ?? 'provider error'), $res['status']);
        }

        return $res['body'];
    }

    /** @return array<string, mixed> */
    private function spec(string $providerId, bool $adminTest = false): array
    {
        $p = $this->store->provider($providerId) ?? throw new ProviderCallException('not_found', 'Provider not found.');
        if ($p['status'] === 'disabled' && ! $adminTest) {
            throw new ProviderCallException('provider_disabled', 'Provider is disabled.');
        }
        $secret = $this->store->secret($providerId, $adminTest);
        $spec = [
            'adapter' => $p['adapter'],
            'base_url' => $p['base_url'],
            'timeout_s' => max(1, $p['timeout_ms'] / 1000),
            'allow_private' => $p['allow_private'],
        ];
        if ($secret !== null) {
            $spec['api_key'] = $this->vault->decrypt($secret['cipher'], $secret['version']);
        } elseif ($p['adapter'] !== 'ollama') {
            // Every hosted provider needs a key; failing here beats sending an unauthenticated call.
            throw new ProviderCallException('no_credential', 'Provider has no active credential.');
        }

        return $spec;
    }
}
