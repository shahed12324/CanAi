<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Mobile\MobileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class DeviceController extends MobileController
{
    public function register(Request $request, MobileService $svc): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'platform' => ['required', 'in:android,ios'],
            'push_token' => ['nullable', 'string', 'max:200'], 'app_version' => ['nullable', 'string', 'max:30'],
        ]);
        abort_if($this->tokenId($request) === 0, 403, 'Log in with the app’s token.');

        return response()->json(['data' => $svc->register($this->tenant->idOrFail(), $this->userId($request), $this->tokenId($request), $d['name'], $d['platform'], $d['push_token'] ?? null, $d['app_version'] ?? null)]);
    }

    public function push(Request $request): JsonResponse
    {
        $d = $request->validate(['push_token' => ['nullable', 'string', 'max:200']]);
        $token = $d['push_token'] ?? null;
        $this->repo->setPushToken($this->device($request)['id'], $token !== null && MobileService::validPushToken($token) ? $token : null);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function revoke(Request $request): JsonResponse
    {
        $this->repo->revokeDevice($this->device($request)['id']);

        return response()->json(['data' => ['revoked' => true]]);
    }

    /** Setting or changing the PIN needs the account password, so a stolen unlocked phone cannot replace it. */
    public function setPin(Request $request, MobileService $svc): JsonResponse
    {
        $d = $request->validate(['pin' => ['required', 'string', 'max:12'], 'password' => ['required', 'string', 'max:200']]);
        if (! Hash::check($d['password'], (string) $request->user()->password)) {
            return $this->fail('bad_password', 'The password is not correct.', 422);
        }
        $r = $svc->setPin($this->device($request), $this->userId($request), $d['pin']);

        return $r['ok'] ? response()->json(['data' => ['pin_set' => true]]) : $this->fail($r['error'], $r['message'], 422);
    }

    public function stepUp(Request $request, MobileService $svc): JsonResponse
    {
        $d = $request->validate(['pin' => ['required', 'string', 'max:12']]);
        $r = $svc->stepUp($this->device($request), $this->userId($request), $d['pin']);

        return match ($r['result']) {
            'ok' => response()->json(['data' => ['verified' => true, 'expires_in' => $r['expires_in']]]),
            'bad' => $this->fail('bad_pin', 'The PIN is not correct.', 422),
            'locked' => $this->fail('pin_locked', 'Too many wrong attempts. Try after 15 minutes.', 423),
            default => $this->fail('no_pin', 'First set a PIN.', 409),
        };
    }
}
