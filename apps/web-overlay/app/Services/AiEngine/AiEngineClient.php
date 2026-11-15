<?php

declare(strict_types=1);

namespace App\Services\AiEngine;

use Illuminate\Http\Client\Factory as Http;

/**
 * The only class that talks to the Python AI engine. Every call is signed.
 */
final class AiEngineClient
{
    public function __construct(
        private readonly Http $http,
        private readonly RequestSigner $signer,
        private readonly string $baseUrl,
        private readonly int $timeout = 10,
    ) {}

    /** @return array<string, mixed> */
    public function health(string $tenantId): array
    {
        return $this->get('/internal/v1/health', $tenantId);
    }

    /**
     * Signed JSON POST. Never throws on HTTP errors: the caller gets the status and the (engine-sanitised)
     * body. Transport failures become a generic exception without request data, so no payload
     * (which may carry a provider key) can reach logs or error pages.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function post(string $path, string $tenantId, array $payload, ?string $userId = null, ?int $timeout = null): array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = $this->signer->headers('POST', $path, '', $json, $tenantId, $userId);

        try {
            $res = $this->http->withHeaders($headers)
                ->timeout($timeout ?? max($this->timeout, 70))
                ->acceptJson()
                ->withBody($json, 'application/json')
                ->post(rtrim($this->baseUrl, '/').$path);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new \RuntimeException('AI engine unreachable.');
        }

        return ['status' => $res->status(), 'body' => $res->json() ?? []];
    }

    /**
     * Signed JSON POST whose answer is a file (PDF/DOCX). On HTTP errors the body is the raw error text, never thrown.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string}
     */
    public function postRaw(string $path, string $tenantId, array $payload, ?string $userId = null, int $timeout = 90): array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = $this->signer->headers('POST', $path, '', $json, $tenantId, $userId);

        try {
            $res = $this->http->withHeaders($headers)
                ->timeout($timeout)
                ->withBody($json, 'application/json')
                ->post(rtrim($this->baseUrl, '/').$path);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new \RuntimeException('AI engine unreachable.');
        }

        return ['status' => $res->status(), 'body' => $res->status() === 200 ? $res->body() : ''];
    }

    /**
     * Signed JSON POST whose answer is one file. Unlike postRaw, a 4xx keeps the engine's safe error message
     * (it only ever describes the user's own file or settings).
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string, ext: string, mime: string, message: string}
     */
    public function postFile(string $path, string $tenantId, array $payload, ?string $userId = null, int $timeout = 120): array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = $this->signer->headers('POST', $path, '', $json, $tenantId, $userId);

        try {
            $res = $this->http->withHeaders($headers)->timeout($timeout)->withBody($json, 'application/json')->post(rtrim($this->baseUrl, '/').$path);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new \RuntimeException('AI engine unreachable.');
        }
        $ok = $res->status() === 200;

        return [
            'status' => $res->status(), 'body' => $ok ? $res->body() : '', 'ext' => $ok ? preg_replace('/[^a-z0-9]/', '', strtolower((string) $res->header('X-File-Ext'))) : '',
            'mime' => $ok ? (string) $res->header('Content-Type') : '', 'message' => $ok ? '' : (string) ($res->json('message') ?? ''),
        ];
    }

    /**
     * Signed POST whose response body is read incrementally (Server-Sent Events).
     * On HTTP >= 400 the (engine-sanitised) JSON error is returned instead of a stream.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, stream: ?\Psr\Http\Message\StreamInterface, error: array<string, mixed>}
     */
    public function postStream(string $path, string $tenantId, array $payload, ?string $userId = null, int $timeout = 180): array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = $this->signer->headers('POST', $path, '', $json, $tenantId, $userId);

        try {
            $res = $this->http->withHeaders($headers)
                ->timeout($timeout)
                ->withOptions(['stream' => true])
                ->withBody($json, 'application/json')
                ->post(rtrim($this->baseUrl, '/').$path);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new \RuntimeException('AI engine unreachable.');
        }
        if ($res->status() >= 400) {
            return ['status' => $res->status(), 'stream' => null, 'error' => $res->json() ?? []];
        }

        return ['status' => $res->status(), 'stream' => $res->toPsrResponse()->getBody(), 'error' => []];
    }

    /** @return array<string, mixed> */
    private function get(string $path, string $tenantId): array
    {
        $headers = $this->signer->headers('GET', $path, '', '', $tenantId);

        return $this->http
            ->withHeaders($headers)
            ->timeout($this->timeout)
            ->acceptJson()
            ->get(rtrim($this->baseUrl, '/').$path)
            ->throw()
            ->json();
    }
}
