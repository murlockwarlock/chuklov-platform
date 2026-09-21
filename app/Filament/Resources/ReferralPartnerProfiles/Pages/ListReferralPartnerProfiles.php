<?php

namespace App\Filament\Resources\ReferralPartnerProfiles\Pages;

use App\Filament\Pages\ReferralRewardConfiguration;
use App\Filament\Resources\ReferralPartnerProfiles\ReferralPartnerProfileResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\Action;

final class ListReferralPartnerProfiles extends LocalizedListRecords
{
    protected static string $resource = ReferralPartnerProfileResource::class;

    protected static ?string $title = 'Партнёры';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openReferralSettings')
                ->label(__('Настройки партнёрской программы'))
                ->icon('heroicon-o-cog-6-tooth')
                ->url(ReferralRewardConfiguration::getUrl()),
        ];
    }

    public int $metricsPollingStartedAt = 0;

    public function mount(): void
    {
        parent::mount();
        $this->metricsPollingStartedAt = now()->getTimestamp();
    }

    public function shouldPollMetrics(): bool
    {
        return $this->metricsPollingStartedAt > 0
            && now()->getTimestamp() < $this->metricsPollingStartedAt + 120;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
