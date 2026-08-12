<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('full_name')->label('Full name'),
                TextEntry::make('email')->placeholder('Not provided'),
                TextEntry::make('phone')->placeholder('Not provided'),
                TextEntry::make('language'),
                TextEntry::make('timezone'),
                TextEntry::make('lead_source')->placeholder('Not provided'),
                TextEntry::make('referral_code')->placeholder('Not provided'),
                TextEntry::make('channel_identities_count')->label('Channel identities'),
                TextEntry::make('channel_identity_summary')
                    ->label('Channel status')
                    ->state(fn (Client $record): string => $record->channelIdentities
                        ->map(fn (ClientChannelIdentity $identity): string => $identity->channel.' — '.$identity->verification_status->value)
                        ->implode(', '))
                    ->placeholder('No channel identities'),
                TextEntry::make('activeBookingRestriction.reason')
                    ->label('Self-service booking restriction')
                    ->placeholder('Not blocked'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with('activeBookingRestriction')
            ->with('channelIdentities')
            ->withCount('channelIdentities');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
