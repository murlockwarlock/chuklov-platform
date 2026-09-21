<?php

namespace App\Filament\Resources\ReferralPayoutRequests\Pages;

use App\Filament\Resources\ReferralPayoutRequests\ReferralPayoutRequestResource;
use App\Filament\Support\LocalizedListRecords;

final class ListReferralPayoutRequests extends LocalizedListRecords
{
    protected static string $resource = ReferralPayoutRequestResource::class;

    protected static ?string $title = 'Запросы выплат';

    public int $statusPollingStartedAt = 0;

    public function mount(): void
    {
        parent::mount();
        $this->statusPollingStartedAt = now()->getTimestamp();
    }

    public function shouldPollStatus(): bool
    {
        return $this->statusPollingStartedAt > 0
            && now()->getTimestamp() < $this->statusPollingStartedAt + 120;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
