<?php

namespace Tests\Feature;

use App\Services\Notifications\FcmGateway;
use App\Services\Notifications\InvalidDeviceToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FcmGatewayTest extends TestCase
{
    public function test_explicit_fcm_unregistered_detail_is_an_invalid_device_token(): void
    {
        $this->withSyntheticServiceAccount([
            'error' => [
                'code' => 404,
                'status' => 'NOT_FOUND',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                    'errorCode' => 'UNREGISTERED',
                ]],
            ],
        ], function (): void {
            try {
                app(FcmGateway::class)->send('synthetic-device-token', $this->payload());
                self::fail('Expected an explicit UNREGISTERED response to invalidate the device token.');
            } catch (InvalidDeviceToken) {
                Http::assertSentCount(1);
                $this->assertFcmRequestWasSent();
            }
        });
    }

    public function test_generic_project_not_found_is_retryable_and_not_an_invalid_device_token(): void
    {
        $this->withSyntheticServiceAccount([
            'error' => [
                'code' => 404,
                'status' => 'NOT_FOUND',
                'message' => 'Requested entity was not found.',
            ],
        ], function (): void {
            try {
                app(FcmGateway::class)->send('synthetic-device-token', $this->payload());
                self::fail('Expected a generic project 404 to be retryable.');
            } catch (InvalidDeviceToken) {
                self::fail('A generic project 404 must not invalidate the device token.');
            } catch (RuntimeException $exception) {
                self::assertSame('FCM request failed with status 404.', $exception->getMessage());
                Http::assertSentCount(1);
                $this->assertFcmRequestWasSent();
            }
        });
    }

    private function withSyntheticServiceAccount(array $fcmError, callable $callback): void
    {
        $email = 'synthetic-'.uniqid('', true).'@example.invalid';
        $credentialsPath = tempnam(sys_get_temp_dir(), 'civora-fcm-test-');
        self::assertNotFalse($credentialsPath);
        file_put_contents($credentialsPath, json_encode([
            'project_id' => 'synthetic-project',
            'client_email' => $email,
            'private_key' => 'synthetic-key-not-used-by-cached-token',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        config([
            'services.fcm.service_account_path' => $credentialsPath,
            'services.fcm.project_id' => 'synthetic-project',
            'cache.default' => 'array',
        ]);
        $cacheKey = 'civora:fcm:'.hash('sha256', $email.'|https://oauth2.googleapis.com/token');
        Cache::put($cacheKey, 'synthetic-access-token', now()->addMinutes(50));
        Http::fake([
            'https://fcm.googleapis.com/v1/projects/synthetic-project/messages:send' => Http::response($fcmError, 404),
        ]);

        try {
            $callback();
        } finally {
            Cache::forget($cacheKey);
            @unlink($credentialsPath);
        }
    }

    private function assertFcmRequestWasSent(): void
    {
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fcm.googleapis.com/v1/projects/synthetic-project/messages:send'
            && ($request->data()['message']['token'] ?? null) === 'synthetic-device-token'
            && ($request->data()['message']['notification']['title'] ?? null) === 'Aktivitas Civora');
    }

    private function payload(): array
    {
        return [
            'title' => 'Aktivitas Civora',
            'body' => 'Catatan poin baru tersedia.',
            'data' => ['type' => 'point_record_created', 'resource_id' => 'synthetic-record-id'],
        ];
    }
}
