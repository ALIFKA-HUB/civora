<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Notification $notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'resource_id' => $notification->resource_id,
                'message' => $notification->public_message,
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at->toISOString(),
            ]);

        return response()->json(['data' => $notifications])->header('Cache-Control', 'no-store, private');
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $item = Notification::query()->where('user_id', $request->user()->id)->whereKey($notification)->firstOrFail();
        if (! $item->read_at) {
            $item->update(['read_at' => now()]);
        }

        return response()->json(['data' => ['id' => $item->id, 'read_at' => $item->fresh()->read_at?->toISOString()]])
            ->header('Cache-Control', 'no-store, private');
    }
}
