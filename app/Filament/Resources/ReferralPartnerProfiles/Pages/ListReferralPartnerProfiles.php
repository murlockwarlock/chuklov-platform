<?php

namespace App\Filament\Resources\ReferralPartnerProfiles\Pages;

use App\Filament\Resources\ReferralPartnerProfiles\ReferralPartnerProfileResource;
use Filament\Resources\Pages\ListRecords;

final class ListReferralPartnerProfiles extends ListRecords
{
    protected static string $resource = ReferralPartnerProfileResource::class;

    protected static ?string $title = 'Партнёры';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
