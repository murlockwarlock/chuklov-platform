<?php

namespace App\Filament\Resources\NotificationTemplates;

use App\Filament\Resources\NotificationTemplates\Pages\CreateNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Resources\NotificationTemplates\Pages\ViewNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Schemas\NotificationTemplateForm;
use App\Filament\Resources\NotificationTemplates\Tables\NotificationTemplatesTable;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Сообщения';

    protected static ?string $modelLabel = 'сообщение';

    protected static ?string $pluralModelLabel = 'сообщения';

    public static function form(Schema $schema): Schema
    {
        return NotificationTemplateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label('Сообщение'),
                TextEntry::make('locale')
                    ->label('Язык')
                    ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Русский' : 'Английский'),
                TextEntry::make('purpose')
                    ->label('Назначение')
                    ->formatStateUsing(fn (ScenarioRulePurpose|string $state): string => self::purposeLabel($state)),
                TextEntry::make('is_active')->label('Включён')->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет'),
                TextEntry::make('version_summary')
                    ->label('Состояние текста')
                    ->state(fn (NotificationTemplate $record): string => $record->versions
                        ->sortByDesc('version')
                        ->isEmpty() ? 'Текст не добавлен' : 'Текст сохранён'),
                TextEntry::make('latest_subject')
                    ->label('Тема')
                    ->state(fn (NotificationTemplate $record): ?string => $record->versions->sortByDesc('version')->first()?->subject),
                TextEntry::make('latest_body')
                    ->label('Текст сообщения')
                    ->state(fn (NotificationTemplate $record): ?string => $record->versions->sortByDesc('version')->first()?->body)
                    ->columnSpanFull(),
                TextEntry::make('latest_variables')
                    ->label('Доступные данные')
                    ->state(function (NotificationTemplate $record): string {
                        $latest = $record->versions->sortByDesc('version')->first();

                        return $latest === null
                            ? ''
                            : collect($latest->variables)->map(fn (string $variable): string => ScenarioTemplateVariableCatalog::labels()[$variable] ?? 'Данные')->implode(', ');
                    })
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return NotificationTemplatesTable::configure($table);
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

    public static function canEdit(mixed $record): bool
    {
        return self::canCreate();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->with('versions');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'create' => CreateNotificationTemplate::route('/create'),
            'view' => ViewNotificationTemplate::route('/{record}'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }

    private static function purposeLabel(ScenarioRulePurpose|string $purpose): string
    {
        $purpose = $purpose instanceof ScenarioRulePurpose ? $purpose : ScenarioRulePurpose::tryFrom($purpose);

        return match ($purpose) {
            ScenarioRulePurpose::Service => 'Сервисное сообщение',
            ScenarioRulePurpose::Transactional => 'Системное сообщение',
            default => 'Не указано',
        };
    }
}
