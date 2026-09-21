<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Clients\Resources\Sessions\Tables\SessionsTable;
use App\Filament\Support\LocalizedRelationManager;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\ListClientSessions;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ClientSessionsRelationManager extends LocalizedRelationManager
{
    protected static string $relationship = 'sessions';

    protected static ?string $title = 'Сеансы';

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $client = $this->getOwnerRecord();

        abort_unless($actor instanceof User, 403);
        abort_unless($client instanceof Client, 404);

        return SessionsTable::configure($table)
            ->stackedOnMobile()
            ->modifyQueryUsing(
                fn (Builder $query): Builder => app(ListClientSessions::class)->apply($actor, $client, $query),
            )
            ->emptyStateHeading(__('Сеансов пока нет'))
            ->emptyStateDescription(__('Добавьте первый сеанс из этого клиентского контекста.'));
    }
}
