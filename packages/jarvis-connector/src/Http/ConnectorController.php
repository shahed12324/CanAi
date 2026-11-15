<?php

declare(strict_types=1);

namespace Jarvis\Connector\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jarvis\Connector\Handler;

final class ConnectorController
{
    public function __invoke(Request $request, Handler $handler): JsonResponse
    {
        $headers = [];
        foreach (['x-jarvis-timestamp', 'x-jarvis-nonce', 'x-jarvis-signature'] as $h) {
            $headers[$h] = (string) $request->headers->get($h, '');
        }
        [$status, $json] = $handler->handle($request->getMethod(), $request->getRequestUri(), $request->getContent(), $headers);

        return response()->json($json, $status, ['Cache-Control' => 'no-store']);
    }
}
