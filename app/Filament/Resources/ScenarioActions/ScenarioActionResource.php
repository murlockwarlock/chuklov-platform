<?php

namespace App\Filament\Resources\ScenarioActions;

use App\Filament\Resources\ScenarioActions\Pages\ListScenarioActions;
use App\Filament\Resources\ScenarioActions\Pages\ViewScenarioAction;
use App\Filament\Resources\ScenarioActions\Tables\ScenarioActionsTable;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use App\Modules\Scenarios\Domain\Models\ScenarioDeliveryAttempt;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ScenarioActionResource extends Resource
{
    protected static ?string $model = ScenarioAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Scenario history';

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')->label('Action ID'),
                TextEntry::make('event.event_name')->label('Source event'),
                TextEntry::make('event.id')->label('Source event ID'),
                TextEntry::make('rule.name')->label('Rule'),
                TextEntry::make('rule_version')->label('Rule version'),
                TextEntry::make('recipient_summary')
                    ->label('Recipient')
                    ->state(function (ScenarioAction $record): string {
                        if ($record->recipient_type === 'client') {
                            $client = $record->client;

                            return 'Client: '.($client instanceof Client ? $client->full_name : 'unavailable');
                        }

                        $user = $record->recipientUser;

                        return 'Organization member: '.($user instanceof User ? $user->name : 'unavailable');
                    }),
                TextEntry::make('purpose'),
                TextEntry::make('scheduled_for')->dateTime(),
                TextEntry::make('status')->badge(),
                TextEntry::make('delivered_at')->dateTime()->placeholder('—'),
                TextEntry::make('terminal_reason')->placeholder('—'),
                TextEntry::make('channel_order')
                    ->label('Channel order')
                    ->state(fn (ScenarioAction $record): string => implode(' → ', $record->channel_priority)),
                TextEntry::make('delivery_history')
                    ->label('Delivery history')
                    ->state(fn (ScenarioAction $record): string => $record->deliveries
                        ->sortBy('priority')
                        ->map(fn (ScenarioDelivery $delivery): string => self::formatDelivery($delivery))
                        ->implode("\n"))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ScenarioActionsTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ViewScenarios,
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with([
                'event',
                'rule',
                'client',
                'recipientUser',
                'deliveries.attempts',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScenarioActions::route('/'),
            'view' => ViewScenarioAction::route('/{record}'),
        ];
    }

    private static function formatDelivery(ScenarioDelivery $delivery): string
    {
        $attempts = $delivery->attempts
            ->sortBy('attempt_number')
            ->map(fn (ScenarioDeliveryAttempt $attempt): string => '#'.$attempt->attempt_number.' '.$attempt->outcome->value
                .($attempt->error_code === null ? '' : ' ('.$attempt->error_code.')'))
            ->implode(', ');

        return $delivery->priority.'. '.$delivery->channel.' — '.$delivery->status->value.' — '.($attempts === '' ? 'no attempts' : $attempts);
    }
}
