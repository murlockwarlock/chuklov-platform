<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\Actions\BookingLifecycleActions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Support\FinancePaymentActions;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected static ?string $title = 'Запись на приём';

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ...BookingLifecycleActions::all(),
                FinancePaymentActions::openForBooking(),
                FinancePaymentActions::forBooking(),
            ])
                ->label('Действия')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button()
                ->dropdownAutoPlacement(),
        ];
    }
}
