<?php

namespace App\Filament\Resources\ReferralPayoutRequests\Pages;

use App\Filament\Resources\ReferralPayoutRequests\ReferralPayoutRequestResource;
use Filament\Resources\Pages\ListRecords;

final class ListReferralPayoutRequests extends ListRecords
{
    protected static string $resource = ReferralPayoutRequestResource::class;

    protected static ?string $title = 'Запросы выплат';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
