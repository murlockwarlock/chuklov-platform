<?php

namespace App\Filament\Resources\BroadcastCampaigns\Pages;

use App\Filament\Resources\BroadcastCampaigns\BroadcastCampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListBroadcastCampaigns extends ListRecords
{
    protected static string $resource = BroadcastCampaignResource::class;

    protected static ?string $title = 'Рассылки';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
