<?php

namespace App\Filament\Resources\FinancialObligations\Pages;

use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Filament\Support\LocalizedListRecords;

final class ListFinancialObligations extends LocalizedListRecords
{
    protected static string $resource = FinancialObligationResource::class;

    protected static ?string $title = 'Оплаты';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
