<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Jobs\ProcessB2bProviderSyncEvent;
use App\Modules\Integration\Domain\Enums\IntegrationEventStatus;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use Carbon\CarbonImmutable;

final class ScheduleB2bProviderSyncEvents
{
    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        $staleAt = $now->subSeconds((int) config('b2b.events.stale_after_seconds'));
        $limit = max(1, min(500, (int) config('b2b.events.batch_size')));
        $ids = IntegrationEvent::query()
            ->where('event_type', IntegrationEventType::B2bSalesCallProviderSync->value)
            ->where(function ($query) use ($now, $staleAt): void {
                $query->where(function ($query) use ($now): void {
                    $query->whereIn('status', [IntegrationEventStatus::Pending->value, IntegrationEventStatus::Retryable->value])
                        ->where('available_at', '<=', $now);
                })->orWhere(function ($query) use ($staleAt): void {
                    $query->where('status', IntegrationEventStatus::Processing->value)
                        ->where('processing_started_at', '<=', $staleAt);
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessB2bProviderSyncEvent::dispatch((int) $id)
                ->onConnection('redis')
                ->onQueue((string) config('b2b.queue'));
        }

        return $ids->count();
    }
}
