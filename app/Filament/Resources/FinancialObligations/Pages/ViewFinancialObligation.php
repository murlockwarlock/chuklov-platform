<?php

namespace App\Filament\Resources\FinancialObligations\Pages;

use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Filament\Support\FinancePaymentActions;
use Filament\Resources\Pages\ViewRecord;

final class ViewFinancialObligation extends ViewRecord
{
    protected static string $resource = FinancialObligationResource::class;

    protected static ?string $title = 'Расчёт по визиту';

    protected function getHeaderActions(): array
    {
        return [
            FinancePaymentActions::forObligation(),
        ];
    }
}
