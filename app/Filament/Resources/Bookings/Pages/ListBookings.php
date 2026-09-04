<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Support\BookingLocalDateRange;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\ResolveSpecialistViewerTimezone;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected static ?string $title = 'Записи на приём';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $actor = auth()->user();
        $timezone = $actor instanceof User
            ? app(ResolveSpecialistViewerTimezone::class)->forUser($actor)
            : app(OrganizationContext::class)->defaultTimezone();

        return [
            'all' => Tab::make('Все'),
            'today' => Tab::make('Сегодня')
                ->modifyQueryUsing(function (Builder $query) use ($timezone): Builder {
                    $today = CarbonImmutable::now($timezone)->toDateString();

                    return BookingLocalDateRange::apply($query, $today, $today, $timezone);
                }),
            'upcoming' => Tab::make('Предстоящие')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('starts_at', '>=', CarbonImmutable::now('UTC'))
                    ->whereNotIn('status', BookingStatus::terminalValues())),
            'pending_confirmation' => Tab::make('Ожидают подтверждения')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', BookingStatus::Requested->value)),
        ];
    }
}
