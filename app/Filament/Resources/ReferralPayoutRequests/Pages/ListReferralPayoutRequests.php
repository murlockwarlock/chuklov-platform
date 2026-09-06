<?php

namespace App\Filament\Resources\ReferralPayoutRequests\Pages;

use App\Filament\Resources\ReferralPayoutRequests\ReferralPayoutRequestResource;
use Filament\Resources\Pages\ListRecords;

final class ListReferralPayoutRequests extends ListRecords
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
