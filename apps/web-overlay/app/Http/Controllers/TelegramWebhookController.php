<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessChannelUpdate;
use App\Messaging\ChannelStore;
use App\Messaging\TelegramNormalizer;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telegram -> JARVIS. No session, no CSRF: the only credential is the secret token Telegram echoes in a header
 * (set with setWebhook). A wrong secret gets 403 and nothing else happens. Real updates always get 200 quickly;
 * the work runs in a job.
 */
final class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $account, ChannelStore $store): JsonResponse
    {
        abort_unless(TenantContext::isValidId($account), 404);
        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        abort_unless($store->authenticate($account, $secret), 403);

        $message = TelegramNormalizer::normalize($request->json()->all());
        if ($message === null) {
            return response()->json(['ok' => true]);              // not an update we can use; 200 so Telegram does not retry it
        }
        if ($store->claim($account, $message->updateId, $message->kind) !== 'new') {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            $store->health($account, null);
            ProcessChannelUpdate::dispatch($account, $message->toArray());
        } catch (\Throwable $e) {
            report($e);
            try {
                $store->health($account, 'dispatch_failed');
            } catch (\Throwable) {
            }

            return response()->json(['ok' => false], 500);        // Telegram retries later; the claim reopens after 2 minutes
        }

        return response()->json(['ok' => true]);
    }
}
