<?php

namespace App\Filament\Resources\ReferralRelationships\Pages;

use App\Filament\Resources\ReferralRelationships\ReferralRelationshipResource;
use App\Filament\Support\LocalizedListRecords;

final class ListReferralRelationships extends LocalizedListRecords
{
    protected static string $resource = ReferralRelationshipResource::class;

    protected static ?string $title = 'Приглашения клиентов';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
