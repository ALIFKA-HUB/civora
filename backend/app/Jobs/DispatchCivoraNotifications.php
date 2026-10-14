<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use App\Services\Notifications\OutboxDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchCivoraNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    private const MAX_ATTEMPTS = 5;

    public function __construct(public readonly string $outboxEventId) {}

    public function handle(OutboxDispatcher $dispatcher): void
    {
        $event = DB::transaction(function (): ?OutboxEvent {
            $event = OutboxEvent::query()->whereKey($this->outboxEventId)->lockForUpdate()->first();
            if (! $event || $event->processed_at || ($event->next_attempt_at && $event->next_attempt_at->isFuture())) {
                return null;
            }

            $event->update(['next_attempt_at' => now()->addMinutes(5)]);

            return $event->fresh();
        });
        if (! $event) {
            return;
        }

        try {
            $dispatcher->dispatch($event);
        } catch (Throwable) {
            $event = OutboxEvent::query()->whereKey($event->id)->lockForUpdate()->first();
            if (! $event || $event->processed_at) {
                return;
            }

            $attempts = $event->attempts + 1;
            $exhausted = $attempts >= self::MAX_ATTEMPTS;
            $delay = min(3600, 15 * (2 ** ($attempts - 1)));
            $event->update([
                'attempts' => $attempts,
                'processed_at' => $exhausted ? now() : null,
                'next_attempt_at' => $exhausted ? null : now()->addSeconds($delay),
            ]);
        }
    }
}
