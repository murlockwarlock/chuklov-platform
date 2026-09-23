<?php

namespace App\Filament\Resources\FinancialObligations\Pages;

use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Filament\Support\CommerceFulfillmentActions;
use App\Filament\Support\FinancePaymentActions;
use App\Filament\Support\LocalizedViewRecord;

final class ViewFinancialObligation extends LocalizedViewRecord
{
    protected static string $resource = FinancialObligationResource::class;

    protected static ?string $title = 'Расчёт по визиту';

    protected function getHeaderActions(): array
    {
        return [
            FinancePaymentActions::forObligation(),
            CommerceFulfillmentActions::forObligation(),
        ];
    }
}
