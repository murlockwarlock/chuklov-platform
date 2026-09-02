<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class BookingProviderMutationGuard
{
    public function assertAllowed(Booking $booking): void
    {
        if (! $booking->hasProviderLease()) {
            return;
        }

        if ($booking->provider_lease_expires_at !== null
            && CarbonImmutable::parse((string) $booking->provider_lease_expires_at)->greaterThan(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages([
                'provider' => 'Синхронизация Zoom уже выполняется. Повторите действие позже.',
            ]);
        }

        $booking->forceFill([
            'provider_sync_status' => VideoMeetingSyncStatus::ReconciliationRequired,
            'provider_error_code' => 'provider_worker_lost',
            ...$this->clearLease(),
        ])->save();

        throw ValidationException::withMessages([
            'provider' => 'Предыдущая синхронизация Zoom прервана. Требуется сверка текущего поколения.',
        ]);
    }

    /** @return array{provider_lease_token: null, provider_lease_expires_at: null, provider_lease_event_id: null, provider_lease_processing_token: null} */
    private function clearLease(): array
    {
        return [
            'provider_lease_token' => null,
            'provider_lease_expires_at' => null,
            'provider_lease_event_id' => null,
            'provider_lease_processing_token' => null,
        ];
    }
}
