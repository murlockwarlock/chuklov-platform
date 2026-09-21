<?php

namespace App\Filament\Resources\BroadcastCampaigns\Pages;

use App\Filament\Resources\BroadcastCampaigns\BroadcastCampaignResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

final class ListBroadcastCampaigns extends LocalizedListRecords
{
    protected static string $resource = BroadcastCampaignResource::class;

    protected static ?string $title = 'Рассылки';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
