<?php

namespace App\Filament\Resources\ReferralRelationships\Pages;

use App\Filament\Resources\ReferralRelationships\ReferralRelationshipResource;
use Filament\Resources\Pages\ListRecords;

final class ListReferralRelationships extends ListRecords
{
    protected static string $resource = ReferralRelationshipResource::class;

    protected static ?string $title = 'Рекомендации';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
