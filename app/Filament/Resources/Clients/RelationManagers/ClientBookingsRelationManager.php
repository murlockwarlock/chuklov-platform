<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Tables\BookingsTable;
use App\Filament\Support\LocalizedRelationManager;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Application\ListClientBookingsForCrm;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ClientBookingsRelationManager extends LocalizedRelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Записи на приём';

    public function infolist(Schema $schema): Schema
    {
        return BookingResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $client = $this->getOwnerRecord();

        abort_unless($actor instanceof User, 403);
        abort_unless($client instanceof Client, 404);

        $query = app(ListClientBookingsForCrm::class)->query($actor, $client);
        $hasBookings = (clone $query)->exists();

        return BookingsTable::configure($table, includeAttention: false, includeClient: false, includeControls: $hasBookings)
            ->heading(__('Записи на приём'))
            ->stackedOnMobile()
            ->query(fn (): Builder => app(ListClientBookingsForCrm::class)->query($actor, $client))
            ->paginated([10, 25])
            ->emptyStateHeading(fn (): string => $this->hasActiveTableFilters()
                ? __('По выбранным фильтрам ничего не найдено')
                : __('У клиента пока нет записей на приём.'))
            ->emptyStateDescription(fn (): string => $this->hasActiveTableFilters()
                ? __('Измените параметры поиска или сбросьте фильтры.')
                : __('Создайте первую запись, чтобы видеть её в карточке клиента.'))
            ->emptyStateActions([
                Action::make('createBooking')
                    ->label(__('Создать запись'))
                    ->icon('heroicon-o-plus')
                    ->url(fn (): string => BookingResource::getUrl('create').'?client_id='.$client->getKey())
                    ->visible(fn (): bool => ! $this->hasActiveTableFilters() && BookingResource::canCreate()),
                Action::make('resetBookingFilters')
                    ->label(__('Сбросить фильтры'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function (): void {
                        $this->resetTableFiltersForm();
                        $this->resetTableSearch();
                    })
                    ->visible(fn (): bool => $this->hasActiveTableFilters()),
            ]);
    }

    private function hasActiveTableFilters(): bool
    {
        if (filled($this->tableSearch ?? null)) {
            return true;
        }

        return collect($this->tableFilters ?? [])
            ->flatten()
            ->contains(static fn (mixed $value): bool => filled($value));
    }
}
