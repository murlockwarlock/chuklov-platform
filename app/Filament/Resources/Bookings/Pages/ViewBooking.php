<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\Actions\BookingLifecycleActions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Support\FinancePaymentActions;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected static ?string $title = 'Запись на приём';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back_to_journal')
                ->label('Вернуться в журнал')
                ->icon('heroicon-o-arrow-left')
                ->url(fn (): string => $this->journalReturnUrl())
                ->visible(fn (): bool => request()->boolean('return_to_journal')),
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

    private function journalReturnUrl(): string
    {
        $parameters = [];
        $week = request()->query('week');
        if (is_string($week) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) === 1) {
            $parameters['week'] = $week;
        }
        $parameters['view'] = request()->query('view') === 'list' ? 'list' : 'week';
        $specialistId = request()->query('specialist_id');
        if (is_numeric($specialistId) && Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('is_active', true)
            ->whereKey((int) $specialistId)
            ->exists()) {
            $parameters['specialist_id'] = (int) $specialistId;
        }

        return ListBookings::getUrl().($parameters === [] ? '' : '?'.http_build_query($parameters));
    }
}
