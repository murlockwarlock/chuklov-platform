<?php

namespace App\Filament\Resources\BroadcastCampaigns;

use App\Filament\Resources\BroadcastCampaigns\Pages\CreateBroadcastCampaign;
use App\Filament\Resources\BroadcastCampaigns\Pages\EditBroadcastCampaign;
use App\Filament\Resources\BroadcastCampaigns\Pages\ListBroadcastCampaigns;
use App\Filament\Resources\BroadcastCampaigns\Pages\ViewBroadcastCampaign;
use App\Filament\Resources\BroadcastCampaigns\RelationManagers\RecipientsRelationManager;
use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class BroadcastCampaignResource extends Resource
{
    protected static ?string $model = BroadcastCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Рассылки';

    protected static string|\UnitEnum|null $navigationGroup = 'Коммуникации';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'рассылка';

    protected static ?string $pluralModelLabel = 'рассылки';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Рассылка')->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(160),
                Select::make('channel_priority')->label('Способ связи')->multiple()->options(['telegram' => 'Telegram'])->default(['telegram'])->required(),
                Select::make('template_version_ru_id')->label('Сообщение на русском')->options(fn (): array => self::templateOptions('ru'))->searchable(),
                Select::make('template_version_en_id')->label('Сообщение на английском')->options(fn (): array => self::templateOptions('en'))->searchable(),
            ])->columns(2)->columnSpanFull(),
            Section::make('Получатели')->description('Все условия применяются одновременно. Медицинские и клинические данные недоступны для маркетингового отбора.')->schema([
                Repeater::make('segment_definition')->label('Условия')->schema([
                    Select::make('key')->label('Что проверить')->options(self::filterOptions())->required()->live(),
                    Select::make('operator')->label('Проверка')->options(fn (Get $get): array => self::operatorOptions((string) $get('key')))->required()->live(),
                    Select::make('value_bool')->label('Значение')->options(['1' => 'Да', '0' => 'Нет'])->visible(fn (Get $get): bool => in_array($get('key'), ['survey_completed', 'no_future_booking', 'referral_relationship'], true))->required(fn (Get $get): bool => in_array($get('key'), ['survey_completed', 'no_future_booking', 'referral_relationship'], true)),
                    TagsInput::make('value_list')->label('Значения')->visible(fn (Get $get): bool => $get('operator') === 'in')->required(fn (Get $get): bool => $get('operator') === 'in')->nestedRecursiveRules(['string', 'max:120']),
                    TextInput::make('value_text')->label('Значение')->visible(fn (Get $get): bool => $get('operator') !== 'in' && ! in_array($get('key'), ['survey_completed', 'no_future_booking', 'referral_relationship'], true))->required(fn (Get $get): bool => $get('operator') !== 'in' && ! in_array($get('key'), ['survey_completed', 'no_future_booking', 'referral_relationship'], true))->maxLength(120),
                ])->columns(3)->defaultItems(0)->reorderable(false)->addActionLabel('Добавить условие')->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Запуск')->schema([
                Select::make('send_mode')->label('Когда отправить')->options(['immediate' => 'Сразу после подтверждения', 'scheduled' => 'В выбранное время'])->default('immediate')->required()->live(),
                DateTimePicker::make('scheduled_at')
                    ->label(fn (): string => 'Дата и время ('.app(OrganizationContext::class)->defaultTimezone().')')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                    ->seconds(false)
                    ->helperText(fn (): string => 'Время указывается по часовому поясу организации: '.app(OrganizationContext::class)->defaultTimezone().'.')
                    ->visible(fn (Get $get): bool => $get('send_mode') === 'scheduled')
                    ->required(fn (Get $get): bool => $get('send_mode') === 'scheduled'),
            ])->columns(2)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Рассылка'),
            TextEntry::make('state')->label('Состояние')->formatStateUsing(fn ($state): string => self::stateLabel($state instanceof BroadcastCampaignState ? $state : BroadcastCampaignState::from((string) $state)))->badge(),
            TextEntry::make('segment_summary')->label('Получатели')->columnSpanFull(),
            TextEntry::make('scheduled_at')->label(fn (): string => 'Запланировано ('.app(OrganizationContext::class)->defaultTimezone().')')->dateTime('d.m.Y H:i')->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())->placeholder('Сразу'),
            TextEntry::make('audience_count')->label('Найдено'),
            TextEntry::make('delivered_count')->label('Доставлено'),
            TextEntry::make('failed_count')->label('Ошибки'),
            TextEntry::make('suppressed_count')->label('Исключено'),
            TextEntry::make('audienceSnapshot.materialized_at')->label('Список получателей зафиксирован')->dateTime('d.m.Y H:i')->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())->placeholder('Ещё не зафиксирован'),
            TextEntry::make('failure_summary')->label('Последние ошибки')->state(fn (BroadcastCampaign $record): string => $record->recipients()->whereNotNull('last_error_code')->latest('updated_at')->limit(10)->pluck('last_error_code')->unique()->implode(', ') ?: 'Нет')->columnSpanFull(),
            TextEntry::make('creator.name')->label('Создал')->placeholder('Сотрудник удалён'),
            TextEntry::make('created_at')->label('Создано')->dateTime('d.m.Y H:i'),
            TextEntry::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Рассылка')->searchable()->sortable(),
            TextColumn::make('state')->label('Состояние')->badge()->formatStateUsing(fn ($state): string => self::stateLabel($state instanceof BroadcastCampaignState ? $state : BroadcastCampaignState::from((string) $state))),
            TextColumn::make('scheduled_at')->label(fn (): string => 'Запуск ('.app(OrganizationContext::class)->defaultTimezone().')')->dateTime('d.m.Y H:i')->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())->placeholder('Сразу')->sortable(),
            TextColumn::make('audience_count')->label('Получатели')->numeric()->sortable(),
            TextColumn::make('delivery_summary')->label('Результат')->state(fn (BroadcastCampaign $record): string => "Доставлено {$record->delivered_count} · ошибок {$record->failed_count} · исключено {$record->suppressed_count}"),
            TextColumn::make('creator.name')->label('Создал')->placeholder('—'),
            TextColumn::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i')->sortable(),
        ])->defaultSort('updated_at', 'desc')->recordActions([ViewAction::make(), EditAction::make()->visible(fn (BroadcastCampaign $record): bool => $record->state === BroadcastCampaignState::Draft)]);
    }

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows($actor, app(OrganizationContext::class)->organization(), OrganizationPermission::ViewScenarios);
    }

    public static function canCreate(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows($actor, app(OrganizationContext::class)->organization(), OrganizationPermission::ManageScenarios);
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate() && $record instanceof BroadcastCampaign && $record->state === BroadcastCampaignState::Draft;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('organization_id', app(OrganizationContext::class)->id())->with(['creator', 'audienceSnapshot']);
    }

    public static function getPages(): array
    {
        return ['index' => ListBroadcastCampaigns::route('/'), 'create' => CreateBroadcastCampaign::route('/create'), 'view' => ViewBroadcastCampaign::route('/{record}'), 'edit' => EditBroadcastCampaign::route('/{record}/edit')];
    }

    public static function getRelations(): array
    {
        return [RecipientsRelationManager::class];
    }

    /** @return array<string, string> */
    private static function templateOptions(string $locale): array
    {
        $organizationId = app(OrganizationContext::class)->id();

        return NotificationTemplateVersion::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'published')
            ->whereHas('template', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('locale', $locale)
                ->where('purpose', 'marketing')
                ->where('is_active', true))
            ->with('template')
            ->latest('id')
            ->get()
            ->filter(static function (NotificationTemplateVersion $version): bool {
                if (array_diff($version->variables, ScenarioTemplateVariableCatalog::allowedForPurpose(ScenarioRulePurpose::Marketing)) !== []) {
                    return false;
                }

                try {
                    return array_diff(
                        ScenarioTemplateVariableCatalog::used($version->body, (string) $version->subject),
                        $version->variables,
                    ) === [];
                } catch (\InvalidArgumentException) {
                    return false;
                }
            })
            ->mapWithKeys(fn (NotificationTemplateVersion $version): array => [$version->getKey() => ($version->template?->name ?: 'Сообщение').' · версия '.$version->version])
            ->all();
    }

    /** @return array<string, string> */
    private static function filterOptions(): array
    {
        return ['tag' => 'Метка клиента', 'b2b_role' => 'B2B-роль', 'b2b_specialist_answer' => 'B2B-сегмент специалиста', 'survey_completed' => 'Завершён тест', 'visit_count' => 'Количество завершённых визитов', 'booking_status' => 'Статус записи', 'last_visit' => 'Дата последнего визита', 'no_future_booking' => 'Нет будущей записи', 'referral_relationship' => 'Пришёл по рекомендации', 'attribution_source' => 'Источник привлечения', 'language' => 'Язык', 'verified_channel' => 'Подтверждённый канал'];
    }

    /** @return array<string, string> */
    private static function operatorOptions(string $key): array
    {
        return match ($key) {
            'tag', 'b2b_role', 'b2b_specialist_answer', 'booking_status', 'attribution_source', 'language' => ['equals' => 'Равно', 'in' => 'Одно из'], 'visit_count' => ['gte' => 'Не меньше'], 'last_visit' => ['before' => 'Раньше', 'after' => 'Позже'], default => ['equals' => 'Равно']
        };
    }

    private static function stateLabel(BroadcastCampaignState $state): string
    {
        return match ($state) {
            BroadcastCampaignState::Draft => 'Черновик', BroadcastCampaignState::Scheduled => 'Запланирована', BroadcastCampaignState::Dispatching => 'Отправляется', BroadcastCampaignState::Completed => 'Завершена', BroadcastCampaignState::Cancelled => 'Отменена'
        };
    }
}
