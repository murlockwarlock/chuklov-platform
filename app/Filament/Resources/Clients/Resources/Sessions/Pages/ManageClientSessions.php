<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\ListClientSessions;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageClientSessions extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static ?string $relatedResource = MedicalSessionResource::class;

    protected static ?string $title = 'Сеансы клиента';

    protected static ?string $navigationLabel = 'Сеансы';

    protected static ?string $breadcrumb = 'Сеансы';

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $client = $this->getOwnerRecord();

        abort_unless($actor instanceof User, 403);
        abort_unless($client instanceof Client, 404);

        return $table->modifyQueryUsing(
            fn (Builder $query): Builder => app(ListClientSessions::class)->apply($actor, $client, $query),
        );
    }
}
