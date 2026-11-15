<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->userId($request);

        return response()->json(['data' => $this->repo->notifications($user, $request->boolean('unread'), (int) $request->query('limit', 50)), 'unread' => $this->repo->unreadCount($user)]);
    }

    public function read(Request $request): JsonResponse
    {
        $d = $request->validate(['all' => ['nullable', 'boolean'], 'ids' => ['nullable', 'array', 'max:100'], 'ids.*' => ['string', 'regex:/\A[0-9a-z]{26}\z/']]);
        $n = $this->repo->markRead($this->userId($request), ($d['all'] ?? false) ? null : array_values($d['ids'] ?? []));

        return response()->json(['data' => ['marked' => $n, 'unread' => $this->repo->unreadCount($this->userId($request))]]);
    }
}
