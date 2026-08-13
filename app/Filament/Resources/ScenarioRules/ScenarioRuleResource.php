<?php

namespace App\Filament\Resources\ScenarioRules;

use App\Filament\Resources\ScenarioRules\Pages\CreateScenarioRule;
use App\Filament\Resources\ScenarioRules\Pages\EditScenarioRule;
use App\Filament\Resources\ScenarioRules\Pages\ListScenarioRules;
use App\Filament\Resources\ScenarioRules\Pages\ViewScenarioRule;
use App\Filament\Resources\ScenarioRules\Schemas\ScenarioRuleForm;
use App\Filament\Resources\ScenarioRules\Tables\ScenarioRulesTable;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ScenarioRuleResource extends Resource
{
    protected static ?string $model = ScenarioRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Scenario rules';

    public static function form(Schema $schema): Schema
    {
        return ScenarioRuleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('rule_key')->label('Rule key'),
                TextEntry::make('name'),
                TextEntry::make('trigger_event')->label('Trigger'),
                TextEntry::make('is_enabled')->label('Enabled'),
                TextEntry::make('delay_summary')
                    ->label('Delay')
                    ->state(fn (ScenarioRule $record): string => $record->delay_value.' '.$record->delay_unit->value),
                TextEntry::make('purpose'),
                TextEntry::make('version')->label('Rule version'),
                TextEntry::make('template_summary')
                    ->label('Pinned template')
                    ->state(fn (ScenarioRule $record): string => $record->templateVersion?->template?->template_key.' / '
                        .$record->templateVersion?->template?->locale.' / v'.$record->templateVersion?->version),
                TextEntry::make('conditions')
                    ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                    ->columnSpanFull(),
                TextEntry::make('recipient_strategy')
                    ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                    ->columnSpanFull(),
                TextEntry::make('channel_priority')
                    ->formatStateUsing(fn (mixed $state): string => implode(' → ', is_array($state) ? $state : []))
                    ->columnSpanFull(),
                TextEntry::make('actions_count')->label('Scheduled/executed actions'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('updated_at')->dateTime(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ScenarioRulesTable::configure($table);
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
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ManageScenarios,
        );
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with(['templateVersion.template'])
            ->withCount('actions');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScenarioRules::route('/'),
            'create' => CreateScenarioRule::route('/create'),
            'view' => ViewScenarioRule::route('/{record}'),
            'edit' => EditScenarioRule::route('/{record}/edit'),
        ];
    }
}
