<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Tables\BookingsTable;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Application\ListClientBookingsForCrm;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ClientBookingsRelationManager extends RelationManager
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

        return BookingsTable::configure($table, includeAttention: false, includeClient: false)
            ->heading('Записи на приём')
            ->stackedOnMobile()
            ->modifyQueryUsing(
                fn (Builder $query): Builder => app(ListClientBookingsForCrm::class)->apply($actor, $client, $query),
            )
            ->paginated([10, 25])
            ->emptyStateHeading('Записей на приём пока нет')
            ->emptyStateDescription('Записи этого клиента появятся здесь.');
    }
}
