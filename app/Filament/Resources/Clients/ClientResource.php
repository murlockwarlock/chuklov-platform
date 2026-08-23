<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\ClientCompanionHistory;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ClientAttachmentsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ClientBookingsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ClientSessionsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ClientSurveysRelationManager;
use App\Filament\Resources\Clients\Resources\Sessions\Pages\ManageClientSessions;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Schemas\ClientWorkspaceInfolist;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Models\User;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'База клиентов';

    protected static string|\UnitEnum|null $navigationGroup = 'Клиенты';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'клиент';

    protected static ?string $pluralModelLabel = 'клиенты';

    protected static ?string $breadcrumb = 'База клиентов';

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static int $globalSearchResultsLimit = 20;

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ClientWorkspaceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ClientSessionsRelationManager::class,
            ClientBookingsRelationManager::class,
            ClientSurveysRelationManager::class,
            ClientAttachmentsRelationManager::class,
        ];
    }

    public static function getRecordTitle(?Model $record): string
    {
        if (! $record instanceof Client) {
            return static::getModelLabel();
        }

        $fullName = trim((string) $record->getAttribute('full_name'));

        return $fullName !== '' ? $fullName : '#'.$record->getKey();
    }

    /** @return Collection<int, GlobalSearchResult> */
    public static function getGlobalSearchResults(string $search): Collection
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return collect();
        }

        return app(ClientSearch::class)
            ->query($actor, $search)
            ->select(['id', 'organization_id', 'full_name', 'email', 'phone'])
            ->limit(static::getGlobalSearchResultsLimit())
            ->get()
            ->map(static fn (Client $client): GlobalSearchResult => new GlobalSearchResult(
                title: static::getRecordTitle($client),
                url: static::getUrl('view', ['record' => $client]),
                details: array_filter([
                    'ID' => '#'.$client->getKey(),
                    'Email' => $client->email,
                    'Телефон' => $client->phone,
                ]),
            ));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with('activeBookingRestriction')
            ->withCount('channelIdentities');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'companion' => ClientCompanionHistory::route('/{record}/companion'),
            'edit' => EditClient::route('/{record}/edit'),
            'sessions' => ManageClientSessions::route('/{record}/sessions'),
        ];
    }
}
