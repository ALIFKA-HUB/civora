<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FcmGateway
{
    public function send(string $deviceToken, array $publicPayload): void
    {
        $credentialsPath = config('services.fcm.service_account_path');
        if (! is_string($credentialsPath) || ! is_file($credentialsPath)) {
            throw new RuntimeException('FCM service account is not configured.');
        }

        $credentials = json_decode(file_get_contents($credentialsPath), true, flags: JSON_THROW_ON_ERROR);
        $projectId = config('services.fcm.project_id') ?: ($credentials['project_id'] ?? null);
        if (! is_string($projectId) || ! preg_match('/\A[a-zA-Z0-9-]{1,150}\z/', $projectId)) {
            throw new RuntimeException('FCM project is not configured.');
        }

        $response = Http::acceptJson()
            ->withToken($this->accessToken($credentials))
            ->connectTimeout(5)
            ->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $deviceToken,
                    'notification' => [
                        'title' => $publicPayload['title'],
                        'body' => $publicPayload['body'],
                    ],
                    'data' => array_map('strval', $publicPayload['data'] ?? []),
                ],
            ]);

        if ($response->successful()) {
            return;
        }

        $details = $response->json('error.details', []);
        foreach (is_array($details) ? $details : [] as $detail) {
            if (($detail['@type'] ?? null) === 'type.googleapis.com/google.firebase.fcm.v1.FcmError'
                && ($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                throw new InvalidDeviceToken('FCM device token is no longer registered.');
            }
        }
        throw new RuntimeException('FCM request failed with status '.$response->status().'.');
    }

    private function accessToken(array $credentials): string
    {
        $email = $credentials['client_email'] ?? null;
        $privateKey = $credentials['private_key'] ?? null;
        $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        if (! is_string($email) || ! is_string($privateKey)
            || ! in_array($tokenUri, ['https://oauth2.googleapis.com/token', 'https://accounts.google.com/o/oauth2/token'], true)) {
            throw new RuntimeException('FCM service account credentials are invalid.');
        }

        $cacheKey = 'civora:fcm:'.hash('sha256', $email.'|'.$tokenUri);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($email, $privateKey, $tokenUri): string {
            $issuedAt = time();
            $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $unsigned = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'.$encode(json_encode([
                'iss' => $email,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $issuedAt,
                'exp' => $issuedAt + 3600,
            ], JSON_THROW_ON_ERROR));
            $key = openssl_pkey_get_private($privateKey);
            if (! $key || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('FCM service account key is invalid.');
            }

            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(10)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.$encode($signature),
            ]);
            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new RuntimeException('FCM access token request failed.');
            }

            return $token;
        });
    }
}
