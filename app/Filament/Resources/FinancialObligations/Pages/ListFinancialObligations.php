<?php

namespace App\Filament\Resources\FinancialObligations\Pages;

use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use Filament\Resources\Pages\ListRecords;

final class ListFinancialObligations extends ListRecords
{
    protected static string $resource = FinancialObligationResource::class;

    protected static ?string $title = 'Оплаты';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
