<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $values = $request->validate([
            'app' => ['required', 'in:student,teacher'],
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['sometimes', 'in:android'],
        ]);
        abort_unless($request->user()->role === $values['app'], 403, 'Aplikasi perangkat tidak sesuai dengan akun.');
        $session = $request->user()->currentAccessToken();
        abort_unless($session instanceof PersonalAccessToken, 401, 'Sesi tidak sah.');

        $device = DB::transaction(function () use ($request, $values, $session): DeviceToken {
            $device = DeviceToken::query()->where('token', $values['token'])->lockForUpdate()->first();
            if (! $device) {
                $device = new DeviceToken(['id' => (string) Str::uuid()]);
            }
            $device->fill([
                'user_id' => $request->user()->id,
                'app' => $values['app'],
                'token' => $values['token'],
                'platform' => $values['platform'] ?? 'android',
                'active' => true,
                'personal_access_token_id' => $session->id,
                'updated_at' => now(),
            ])->save();

            return $device;
        });

        return response()->json(['data' => ['id' => $device->id, 'app' => $device->app, 'platform' => $device->platform]], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, string $deviceToken): JsonResponse
    {
        $device = DeviceToken::query()->where('user_id', $request->user()->id)->whereKey($deviceToken)->firstOrFail();
        $device->update(['active' => false, 'personal_access_token_id' => null, 'updated_at' => now()]);

        return response()->json(['data' => ['message' => 'Perangkat berhasil dicabut.']])->header('Cache-Control', 'no-store, private');
    }
}
