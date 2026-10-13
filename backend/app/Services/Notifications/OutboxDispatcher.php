<?php

namespace App\Services\Notifications;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\OutboxEvent;
use App\Models\PointRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

final class OutboxDispatcher
{
    public function __construct(private readonly FcmGateway $gateway) {}

    public function dispatch(OutboxEvent $event): void
    {
        if ($event->processed_at) {
            return;
        }

        [$message, $recipients] = $this->recipients($event);
        foreach (array_unique(array_filter($recipients)) as $userId) {
            Notification::firstOrCreate([
                'user_id' => $userId,
                'event_key' => $event->event_key,
            ], [
                'type' => $event->type,
                'resource_id' => $event->resource_id,
                'public_message' => $message,
            ]);
        }

        foreach (array_unique(array_filter($recipients)) as $userId) {
            $user = User::query()->with('student')->whereKey($userId)->first();
            if (! $user || $user->account_status !== 'active'
                || ($user->role === 'student' && $user->student?->education_status !== 'active')) {
                continue;
            }

            $tokens = DeviceToken::query()
                ->where('user_id', $user->id)
                ->where('app', $user->role)
                ->where('active', true)
                ->whereNotNull('personal_access_token_id')
                ->get();
            foreach ($tokens as $device) {
                $session = PersonalAccessToken::query()->find($device->personal_access_token_id);
                if (! $this->sessionIsCurrent($session, $user)) {
                    $device->update(['active' => false, 'personal_access_token_id' => null]);

                    continue;
                }

                try {
                    $this->gateway->send($device->token, [
                        'title' => 'Aktivitas Civora',
                        'body' => $message,
                        'data' => ['type' => $event->type, 'resource_id' => $event->resource_id],
                    ]);
                } catch (InvalidDeviceToken) {
                    $device->update(['active' => false, 'personal_access_token_id' => null]);
                }
            }
        }

        OutboxEvent::query()->whereKey($event->id)->whereNull('processed_at')->update([
            'processed_at' => CarbonImmutable::now(),
            'next_attempt_at' => null,
        ]);
    }

    /** @return array{string, list<string>} */
    private function recipients(OutboxEvent $event): array
    {
        $record = PointRecord::query()->with(['student.user', 'teacher.user'])->whereKey($event->resource_id)->first();
        $studentUserId = $record?->student?->user_id;
        $teacherUserId = $record?->teacher?->user_id;
        $payload = $event->payload ?? [];

        return match ($event->type) {
            'point_record_created' => ['Catatan poin baru tersedia.', array_filter([$studentUserId])],
            'point_record_revised' => ['Perubahan catatan tersedia.', array_filter([$studentUserId, $teacherUserId])],
            'review_submitted' => ['Permintaan pemeriksaan baru menunggu ditinjau.', array_filter([
                isset($payload['requester_user_id']) && $payload['requester_user_id'] !== $teacherUserId ? $teacherUserId : null,
            ])],
            'review_decided' => ['Permintaan pemeriksaan telah diputuskan.', array_filter([$studentUserId, $payload['requester_user_id'] ?? null, $teacherUserId])],
            default => ['Pembaruan tersedia.', []],
        };
    }

    private function sessionIsCurrent(?PersonalAccessToken $session, User $user): bool
    {
        if (! $session || $session->tokenable_type !== User::class || $session->tokenable_id !== $user->id
            || ($session->expires_at && $session->expires_at->lessThanOrEqualTo(CarbonImmutable::now()))) {
            return false;
        }
        $expiration = config('sanctum.expiration');

        return ! $expiration || $session->created_at->addMinutes($expiration)->greaterThan(CarbonImmutable::now());
    }
}
