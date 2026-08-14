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
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
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

    protected static ?string $navigationLabel = 'Правила сообщений';

    protected static ?string $modelLabel = 'правило';

    protected static ?string $pluralModelLabel = 'правила сообщений';

    public static function form(Schema $schema): Schema
    {
        return ScenarioRuleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label('Название'),
                TextEntry::make('trigger_event')
                    ->label('Когда')
                    ->formatStateUsing(fn (mixed $state): string => self::eventLabel($state)),
                TextEntry::make('is_enabled')->label('Активно')->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет'),
                TextEntry::make('delay_summary')
                    ->label('Через')
                    ->state(fn (ScenarioRule $record): string => $record->delay_value.' '.self::delayUnitLabel($record->delay_unit)),
                TextEntry::make('repeat_summary')
                    ->label('Повторения')
                    ->state(fn (ScenarioRule $record): string => $record->max_occurrences > 1
                        ? $record->max_occurrences.' раза, каждые '.$record->repeat_interval_value.' '.self::delayUnitLabel($record->repeat_interval_unit)
                        : 'Одно сообщение'),
                TextEntry::make('purpose')
                    ->label('Назначение')
                    ->formatStateUsing(fn (ScenarioRulePurpose|string $state): string => self::purposeLabel($state)),
                TextEntry::make('template_summary')
                    ->label('Сообщение')
                    ->state(function (ScenarioRule $record): string {
                        $template = $record->templateVersion?->template;

                        if ($template === null) {
                            return 'Не выбрано';
                        }

                        return ($template->name ?: 'Не выбрано').' — '.self::localeLabel($template->locale)
                            .' · версия '.$record->templateVersion->version;
                    }),
                TextEntry::make('conditions_summary')
                    ->label('Условие')
                    ->state(fn (ScenarioRule $record): string => self::conditionsSummary($record->conditions))
                    ->columnSpanFull(),
                TextEntry::make('recipient_summary')
                    ->label('Кому')
                    ->state(fn (ScenarioRule $record): string => self::recipientSummary($record->recipient_strategy))
                    ->columnSpanFull(),
                TextEntry::make('channel_priority')
                    ->label('Способ связи')
                    ->formatStateUsing(fn (mixed $state): string => self::channelSummary($state))
                    ->columnSpanFull(),
                TextEntry::make('actions_count')->label('Отправок'),
                TextEntry::make('created_at')->label('Создано')->dateTime('d.m.Y H:i'),
                TextEntry::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i'),
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

    private static function delayUnitLabel(ScenarioDelayUnit|string|null $unit): string
    {
        $unit = $unit instanceof ScenarioDelayUnit ? $unit : ScenarioDelayUnit::tryFrom((string) $unit);

        if ($unit === null) {
            return '';
        }

        if ($unit->value === 'minutes') {
            return 'мин.';
        }

        if ($unit->value === 'hours') {
            return 'ч.';
        }

        return 'дн.';
    }

    private static function eventLabel(mixed $event): string
    {
        $value = $event instanceof BackedEnum ? $event->value : (string) $event;

        return match ($value) {
            ScenarioEventType::BookingCompleted->value => 'После завершения визита',
            ScenarioEventType::OnboardingStarted->value => 'После начала оформления',
            default => 'Событие',
        };
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

    private static function localeLabel(?string $locale): string
    {
        return match ($locale) {
            'ru' => 'Русский',
            'en' => 'Английский',
            default => 'Другой язык',
        };
    }

    private static function conditionsSummary(mixed $conditions): string
    {
        if (! is_array($conditions) || $conditions === []) {
            return 'Без дополнительного условия';
        }

        return collect($conditions)->map(function (mixed $condition): string {
            if (! is_array($condition)) {
                return 'Условие';
            }

            $type = match ($condition['type'] ?? null) {
                'booking.status' => 'статус записи',
                'booking.has_qualifying_next_booking' => 'подходящая следующая запись',
                'client.language' => 'язык клиента',
                'client.marketing_consent' => 'согласие на маркетинговые сообщения',
                'onboarding.completed' => 'завершение оформления',
                'onboarding.stage' => 'этап оформления',
                default => 'условие',
            };
            $operator = match ($condition['operator'] ?? null) {
                'equals' => 'равно',
                'not_equals' => 'не равно',
                'in' => 'одно из',
                'exists' => 'заполнено',
                default => 'проверяется',
            };

            return ucfirst($type).' '.$operator.(array_key_exists('value', $condition) ? ' '.self::conditionValue($condition['value']) : '');
        })->implode('; ');
    }

    private static function conditionValue(mixed $value): string
    {
        $values = is_array($value) ? $value : [$value];

        return collect($values)->map(static fn (mixed $item): string => match ((string) $item) {
            'requested' => 'ожидает подтверждения',
            'pending_review' => 'на рассмотрении',
            'confirmed' => 'подтверждена',
            'completed' => 'завершена',
            'cancelled' => 'отменена',
            'ru' => 'русский',
            'en' => 'английский',
            'true' => 'да',
            'false' => 'нет',
            'contacts' => 'контакты',
            'profile' => 'профиль',
            'service' => 'услуга',
            'goals' => 'цели',
            default => (string) $item,
        })->implode(', ');
    }

    private static function recipientSummary(mixed $strategy): string
    {
        $type = is_array($strategy) ? ($strategy['type'] ?? null) : null;

        return match ($type) {
            'client' => 'Клиент записи',
            'members' => 'Выбранные сотрудники',
            'roles' => 'Сотрудники по роли',
            default => 'Не указано',
        };
    }

    private static function channelSummary(mixed $channels): string
    {
        return collect(is_array($channels) ? $channels : [])->map(static fn (mixed $channel): string => match ((string) $channel) {
            'telegram' => 'Telegram',
            default => 'Другой способ связи',
        })->implode(' → ');
    }
}
