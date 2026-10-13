<?php

namespace Tests\Feature;

use App\Jobs\DispatchCivoraNotifications;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\OutboxEvent;
use App\Models\PointRecord;
use App\Models\User;
use App\Services\Notifications\FcmGateway;
use App\Services\Notifications\InvalidDeviceToken;
use App\Services\Notifications\OutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->student = User::findOrFail(DB::table('students')->value('user_id'));
        $this->student->update(['account_status' => 'active', 'must_change_password' => false]);
        DB::table('notifications')->delete();
        DB::table('outbox_events')->delete();
        DB::table('device_tokens')->delete();
    }

    public function test_outbox_retry_keeps_one_in_app_notification_and_sends_only_public_payload(): void
    {
        $session = $this->student->createToken('student-device');
        DeviceToken::create([
            'user_id' => $this->student->id,
            'app' => 'student',
            'token' => 'synthetic-device-token',
            'platform' => 'android',
            'active' => true,
            'personal_access_token_id' => $session->accessToken->id,
        ]);
        $event = $this->event('point_record_created', ['student_id' => DB::table('students')->value('id'), 'teacher_id' => DB::table('teachers')->value('id')]);
        $pointCountBeforePush = PointRecord::query()->count();
        $this->mock(FcmGateway::class)->shouldReceive('send')
            ->once()
            ->with('synthetic-device-token', [
                'title' => 'Aktivitas Civora',
                'body' => 'Catatan poin baru tersedia.',
                'data' => ['type' => 'point_record_created', 'resource_id' => $event->resource_id],
            ])
            ->andThrow(new \RuntimeException('temporary FCM failure'));
        (new DispatchCivoraNotifications($event->id))->handle(app(OutboxDispatcher::class));

        self::assertSame(1, Notification::query()->where('event_key', $event->event_key)->count());
        self::assertSame($pointCountBeforePush, PointRecord::query()->count());
        self::assertSame(1, $event->fresh()->attempts);
        self::assertNotNull($event->fresh()->next_attempt_at);

        $event->update(['next_attempt_at' => now()->subSecond()]);
        $retryGateway = $this->mock(FcmGateway::class);
        $retryGateway->shouldReceive('send')->once();
        (new DispatchCivoraNotifications($event->id))->handle(app(OutboxDispatcher::class));

        self::assertSame(1, Notification::query()->where('event_key', $event->event_key)->count());
        self::assertNotNull($event->fresh()->processed_at);
    }

    public function test_notification_api_is_own_only_and_device_binding_uses_current_session(): void
    {
        $current = $this->student->createToken('student-current');
        $otherUser = User::create([
            'role' => 'student', 'name' => 'Other Student', 'password_hash' => 'synthetic password',
            'account_status' => 'active', 'must_change_password' => false,
        ]);
        $own = Notification::create([
            'user_id' => $this->student->id, 'event_key' => 'own-event', 'type' => 'point_record_created',
            'resource_id' => $this->rows['point_records']['id'], 'public_message' => 'Catatan poin baru tersedia.',
        ]);
        $foreign = Notification::create([
            'user_id' => $otherUser->id, 'event_key' => 'foreign-event', 'type' => 'point_record_created',
            'resource_id' => $this->rows['point_records']['id'], 'public_message' => 'Catatan poin baru tersedia.',
        ]);

        $this->withToken($current->plainTextToken)->postJson('/api/v1/device-tokens', [
            'app' => 'student', 'token' => 'synthetic-register-token', 'platform' => 'android',
            'personal_access_token_id' => 987654,
        ])->assertCreated()->assertJsonPath('data.app', 'student');
        self::assertDatabaseHas('device_tokens', [
            'user_id' => $this->student->id,
            'token' => 'synthetic-register-token',
            'personal_access_token_id' => $current->accessToken->id,
        ]);

        $response = $this->withToken($current->plainTextToken)->getJson('/api/v1/notifications')->assertOk();
        self::assertSame([$own->id], collect($response->json('data'))->pluck('id')->all());
        $this->postJson('/api/v1/notifications/'.$foreign->id.'/read')->assertNotFound();
        $this->postJson('/api/v1/notifications/'.$own->id.'/read')->assertOk();
        self::assertNotNull($own->fresh()->read_at);

        $this->deleteJson('/api/v1/device-tokens/'.DB::table('device_tokens')->where('token', 'synthetic-register-token')->value('id'))->assertOk();
        self::assertDatabaseHas('device_tokens', ['token' => 'synthetic-register-token', 'active' => false]);
        $event = $this->event('point_record_created', ['student_id' => DB::table('students')->value('id')]);
        $this->mock(FcmGateway::class)->shouldNotReceive('send');
        (new DispatchCivoraNotifications($event->id))->handle(app(OutboxDispatcher::class));
        self::assertNotNull($event->fresh()->processed_at);
    }

    public function test_inactive_users_and_logged_out_or_invalid_devices_do_not_receive_push(): void
    {
        $session = $this->student->createToken('student-device');
        $device = DeviceToken::create([
            'user_id' => $this->student->id, 'app' => 'student', 'token' => 'synthetic-logged-out-token',
            'platform' => 'android', 'active' => false, 'personal_access_token_id' => $session->accessToken->id,
        ]);
        $event = $this->event('point_record_created', ['student_id' => DB::table('students')->value('id')]);
        $gateway = $this->mock(FcmGateway::class);
        $gateway->shouldNotReceive('send');
        (new DispatchCivoraNotifications($event->id))->handle(app(OutboxDispatcher::class));

        self::assertSame(1, Notification::query()->where('event_key', $event->event_key)->count());
        self::assertNotNull($event->fresh()->processed_at);
        self::assertFalse($device->fresh()->active);

        DB::table('outbox_events')->delete();
        $this->student->update(['account_status' => 'inactive']);
        $device->update(['active' => true]);
        $inactiveEvent = $this->event('point_record_created', ['student_id' => DB::table('students')->value('id')]);
        $inactiveGateway = $this->mock(FcmGateway::class);
        $inactiveGateway->shouldNotReceive('send');
        (new DispatchCivoraNotifications($inactiveEvent->id))->handle(app(OutboxDispatcher::class));
        self::assertSame(1, Notification::query()->where('event_key', $inactiveEvent->event_key)->count());
        self::assertNotNull($inactiveEvent->fresh()->processed_at);

        $this->student->update(['account_status' => 'active']);
        DB::table('outbox_events')->delete();
        $activeSession = $this->student->createToken('student-active-device');
        $device->update(['token' => 'synthetic-invalid-token', 'active' => true, 'personal_access_token_id' => $activeSession->accessToken->id]);
        $invalidEvent = $this->event('point_record_created', ['student_id' => DB::table('students')->value('id')]);
        $invalidGateway = $this->mock(FcmGateway::class);
        $invalidGateway->shouldReceive('send')->once()->andThrow(new InvalidDeviceToken('unregistered'));
        (new DispatchCivoraNotifications($invalidEvent->id))->handle(app(OutboxDispatcher::class));
        self::assertDatabaseHas('device_tokens', ['id' => $device->id, 'active' => false]);
        self::assertNotNull($invalidEvent->fresh()->processed_at);

        $nextEvent = $this->event('point_record_created', ['student_id' => DB::table('students')->value('id')]);
        $this->mock(FcmGateway::class)->shouldNotReceive('send');
        (new DispatchCivoraNotifications($nextEvent->id))->handle(app(OutboxDispatcher::class));
        self::assertNotNull($nextEvent->fresh()->processed_at);
    }

    private function event(string $type, array $payload): OutboxEvent
    {
        return OutboxEvent::create([
            'event_key' => Str::uuid()->toString(),
            'type' => $type,
            'resource_id' => $this->rows['point_records']['id'],
            'payload' => $payload,
        ]);
    }
}
