<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Integration\Domain\Enums\IntegrationEventStatus;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Referrals\Jobs\ProcessReferralIntegrationEvent;
use Carbon\CarbonImmutable;

final class ScheduleReferralIntegrationEvents
{
    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $staleAt = $now->subSeconds((int) config('referrals.events.stale_after_seconds', 300));
        $limit = max(1, min(500, (int) config('referrals.events.batch_size', 100)));
        $ids = IntegrationEvent::query()
            ->where('event_type', IntegrationEventType::FinanceObligationSettled->value)
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
            ProcessReferralIntegrationEvent::dispatch((int) $id)
                ->onQueue((string) config('referrals.queue', 'referrals'));
        }

        return $ids->count();
    }
}
