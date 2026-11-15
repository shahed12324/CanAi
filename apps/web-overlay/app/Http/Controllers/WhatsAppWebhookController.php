<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\CredentialVault;
use App\Jobs\ProcessChannelUpdate;
use App\Messaging\ChannelStore;
use App\Messaging\WhatsAppCredentials;
use App\Messaging\WhatsAppNormalizer;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * WhatsApp Cloud API -> CANAI. GET is Meta's one-time handshake (verify token); POST is signed with the app secret
 * (X-Hub-Signature-256 over the raw body). Bad signature => 403 and nothing else happens.
 */
final class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, string $account, CredentialVault $vault): Response
    {
        abort_unless(TenantContext::isValidId($account), 404);
        $loaded = WhatsAppCredentials::load($vault, $account);
        abort_if($loaded === null, 404);
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $ok = $request->query('hub_mode', $request->query('hub.mode')) === 'subscribe'
            && $token !== '' && hash_equals($loaded['creds']->verifyToken(), $token);
        abort_unless($ok, 403);

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge', '')), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, string $account, CredentialVault $vault, ChannelStore $store): JsonResponse
    {
        abort_unless(TenantContext::isValidId($account), 404);
        $loaded = WhatsAppCredentials::load($vault, $account);
        abort_if($loaded === null, 404);
        abort_unless(WhatsAppCredentials::signatureValid($request->getContent(), (string) $request->header('X-Hub-Signature-256', ''), $loaded['creds']->appSecret()), 403);
        if ($loaded['status'] !== 'active') {
            return response()->json(['ok' => true, 'inactive' => true]);
        }

        $payload = json_decode($request->getContent(), true);
        $messages = is_array($payload) ? WhatsAppNormalizer::normalize($payload, $loaded['creds']->phoneNumberId()) : [];
        foreach ($messages as $message) {
            if ($store->claim($account, $message->updateId, $message->kind) !== 'new') {
                continue;
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

                return response()->json(['ok' => false], 500);   // Meta retries; the claim reopens after 2 minutes
            }
        }

        return response()->json(['ok' => true]);
    }
}
