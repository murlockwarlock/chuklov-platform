<?php

namespace App\Filament\Pages;

use App\Filament\Support\LocalizedPage;
use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsClientGroupCatalog;
use App\Modules\Analytics\Application\BookingStatusAnalytics;
use App\Modules\Analytics\Application\PurchaseGroupCatalog;
use App\Modules\Analytics\Application\SaveAnalyticsClientGroup;
use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Analytics\Domain\Enums\PainScoreBasis;
use App\Modules\Attribution\Application\AttributionSourceCatalog;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Feedback\Application\FeedbackThemeCatalog;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Tracker\Application\TrackerGoalTagCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;
use UnitEnum;

/** @property-read Schema $form */
final class ClientCohortGroups extends LocalizedPage
{
    protected static ?string $title = 'Группы клиентов';

    protected static ?string $navigationLabel = 'Группы клиентов';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.client-cohort-groups';

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    /** @var array<int, int> */
    private array $initialGroupVersions = [];

    public static function canAccess(): bool
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return false;
        }

        try {
            return app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ManageSettings,
            );
        } catch (LogicException) {
            return false;
        }
    }

    public function mount(): void
    {
        $rows = app(AnalyticsClientGroupCatalog::class)->rows(includeArchived: true);
        $this->initialGroupVersions = collect($rows)
            ->mapWithKeys(static fn (array $row): array => [(int) $row['id'] => (int) $row['version']])
            ->all();
        $this->form->fill([
            'groups' => array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'version' => $row['version'],
                'name' => $row['name'],
                'description' => $row['description'],
                'group_type' => $row['group_type'],
                'is_active' => $row['is_active'],
                'sort_order' => $row['sort_order'],
                'client_ids' => $row['client_ids'],
                'rule_segments' => $row['rule_definition']['segments'] ?? [],
                'rule_genders' => $row['rule_definition']['genders'] ?? [],
                'rule_source_keys' => $row['rule_definition']['source_keys'] ?? [],
                'rule_service_ids' => $row['rule_definition']['service_ids'] ?? [],
                'rule_service_categories' => $row['rule_definition']['service_categories'] ?? [],
                'rule_problem_tag_ids' => $row['rule_definition']['problem_tag_ids'] ?? [],
                'rule_feedback_theme_ids' => $row['rule_definition']['feedback_theme_ids'] ?? [],
                'rule_goal_tag_ids' => $row['rule_definition']['goal_tag_ids'] ?? [],
                'rule_specialist_ids' => $row['rule_definition']['specialist_ids'] ?? [],
                'rule_countries' => $row['rule_definition']['countries'] ?? [],
                'rule_age_min' => $row['rule_definition']['age_min'] ?? null,
                'rule_age_max' => $row['rule_definition']['age_max'] ?? null,
                'rule_archived' => $row['rule_definition']['archived'] ?? null,
                'rule_blacklist' => $row['rule_definition']['blacklist'] ?? null,
                'rule_problem_tag_mode' => $row['rule_definition']['problem_tag_mode'] ?? 'any',
                'rule_exclude_problem_tag_ids' => $row['rule_definition']['exclude_problem_tag_ids'] ?? [],
                'rule_children' => $row['rule_definition']['children'] ?? null,
                'rule_booking_statuses' => $row['rule_definition']['booking_statuses'] ?? [],
                'rule_booking_event_types' => $row['rule_definition']['booking_event_types'] ?? [],
                'rule_completed_visits_min' => $row['rule_definition']['completed_visits_min'] ?? null,
                'rule_completed_visits_max' => $row['rule_definition']['completed_visits_max'] ?? null,
                'rule_last_visit_from' => $row['rule_definition']['last_visit_from'] ?? null,
                'rule_last_visit_to' => $row['rule_definition']['last_visit_to'] ?? null,
                'rule_purchase_types' => $row['rule_definition']['purchase_types'] ?? [],
                'rule_purchase_statuses' => $row['rule_definition']['purchase_statuses'] ?? [],
                'rule_has_pain_score' => $row['rule_definition']['has_pain_score'] ?? null,
                'rule_pain_score_basis' => $row['rule_definition']['pain_score_basis'] ?? PainScoreBasis::Any->value,
                'rule_pain_score_min' => $row['rule_definition']['pain_score_min'] ?? null,
                'rule_pain_score_max' => $row['rule_definition']['pain_score_max'] ?? null,
                'rule_satisfaction_min' => $row['rule_definition']['satisfaction_min'] ?? null,
                'rule_satisfaction_max' => $row['rule_definition']['satisfaction_max'] ?? null,
            ], app(AnalyticsClientGroupCatalog::class)->rows()),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Сохранённые группы клиентов'))
                ->description(__('Ручные группы содержат выбранных клиентов. Динамические группы пересчитываются по typed-правилам при каждом анализе. История определений сохраняется версиями.'))
                ->schema([
                    Repeater::make('groups')
                        ->label(__('Группы'))
                        ->schema([
                            Hidden::make('id')->dehydrated(true),
                            TextInput::make('name')->label(__('Название'))->required()->maxLength(160),
                            Textarea::make('description')->label(__('Описание'))->maxLength(2000)->rows(2)->nullable(),
                            Select::make('group_type')->label(__('Тип'))->options(['manual' => 'Ручная', 'dynamic' => 'Динамическая'])->required()->live(),
                            Toggle::make('is_active')
                                ->label(__('Активна (снимите, чтобы архивировать)'))
                                ->helperText(__('Архивирование сохраняет историю и прежние версии группы.'))
                                ->default(true),
                            Hidden::make('version')->dehydrated(true),
                            TextInput::make('sort_order')->label(__('Порядок'))->integer()->minValue(0)->default(0),
                            Select::make('client_ids')
                                ->label(__('Клиенты группы'))
                                ->multiple()
                                ->searchable()
                                ->optionsLimit(ClientSearch::MAX_RESULTS)
                                ->getSearchResultsUsing(fn (string $search): array => $this->clientSearchResults($search))
                                ->getOptionLabelsUsing(fn (array $values): array => $this->clientOptionLabels(array_values(array_map(
                                    static fn (mixed $value): int|string => is_int($value) || is_string($value) ? $value : (string) $value,
                                    $values,
                                ))))
                                ->visible(fn (Get $get): bool => $get('group_type') === 'manual'),
                            Select::make('rule_segments')
                                ->label(__('Правила: категории'))
                                ->multiple()
                                ->options(ClientSegment::labels())
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_genders')
                                ->label(__('Правила: пол'))
                                ->multiple()
                                ->options(['MALE' => 'Мужчины', 'FEMALE' => 'Женщины'])
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_source_keys')
                                ->label(__('Правила: источник'))
                                ->multiple()
                                ->options(fn (): array => app(AttributionSourceCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id()))
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_service_ids')
                                ->label(__('Правила: услуга'))
                                ->multiple()
                                ->options(fn (): array => Service::query()->where('organization_id', app(OrganizationContext::class)->id())->orderBy('name')->pluck('name', 'id')->all())
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_service_categories')
                                ->label(__('Правила: группы услуг'))
                                ->multiple()
                                ->options(fn (): array => Service::query()
                                    ->where('organization_id', app(OrganizationContext::class)->id())
                                    ->whereNotNull('category')
                                    ->where('category', '!=', '')
                                    ->distinct()
                                    ->orderBy('category')
                                    ->pluck('category', 'category')
                                    ->all())
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_problem_tag_ids')
                                ->label(__('Правила: проблема'))
                                ->multiple()
                                ->options(fn (): array => app(MedicalProblemTagCatalog::class)->options(app(OrganizationContext::class)->id()))
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_feedback_theme_ids')
                                ->label(__('Правила: темы обратной связи'))
                                ->multiple()
                                ->options(fn (): array => app(FeedbackThemeCatalog::class)->options(app(OrganizationContext::class)->id()))
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_goal_tag_ids')
                                ->label(__('Правила: категории целей'))
                                ->multiple()
                                ->options(fn (): array => app(TrackerGoalTagCatalog::class)->options(app(OrganizationContext::class)->id()))
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_specialist_ids')
                                ->label(__('Правила: специалисты'))
                                ->multiple()
                                ->options(fn (): array => Specialist::query()->where('organization_id', app(OrganizationContext::class)->id())->orderBy('display_name')->pluck('display_name', 'id')->all())
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_countries')
                                ->label(__('Правила: страны'))
                                ->multiple()
                                ->options(fn (): array => Client::query()->where('organization_id', app(OrganizationContext::class)->id())->whereNotNull('country')->where('country', '!=', '')->distinct()->orderBy('country')->pluck('country', 'country')->all())
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            TextInput::make('rule_age_min')
                                ->label(__('Правила: возраст от'))
                                ->integer()
                                ->minValue(0)
                                ->maxValue(150)
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            TextInput::make('rule_age_max')
                                ->label(__('Правила: возраст до'))
                                ->integer()
                                ->minValue(0)
                                ->maxValue(150)
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_archived')
                                ->label(__('Правила: архив'))
                                ->options(['0' => 'Активные', '1' => 'Архивные'])
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_blacklist')
                                ->label(__('Правила: чёрный список'))
                                ->options(['0' => 'Не в списке', '1' => 'В списке'])
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_problem_tag_mode')
                                ->label(__('Правила: проблемы — режим'))
                                ->options(['any' => 'Хотя бы одна', 'all' => 'Все выбранные'])
                                ->default('any')
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_exclude_problem_tag_ids')
                                ->label(__('Правила: исключить проблемы'))
                                ->multiple()
                                ->options(fn (): array => app(MedicalProblemTagCatalog::class)->options(app(OrganizationContext::class)->id()))
                                ->searchable()
                                ->preload()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_children')
                                ->label(__('Правила: дети / взрослые'))
                                ->options(['1' => 'Дети', '0' => 'Взрослые'])
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_booking_statuses')
                                ->label(__('Правила: статусы записей'))
                                ->multiple()
                                ->options([
                                    BookingStatus::Requested->value => 'Запрошен',
                                    BookingStatus::PendingReview->value => 'На проверке',
                                    BookingStatus::Confirmed->value => 'Подтверждён',
                                    BookingStatus::Rejected->value => 'Отклонён',
                                    BookingStatus::Cancelled->value => 'Отменён',
                                    BookingStatus::Completed->value => 'Выполнен',
                                    BookingStatus::NoShow->value => 'Неявка',
                                ])
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_booking_event_types')
                                ->label(__('Правила: статусы истории записей'))
                                ->multiple()
                                ->options(BookingStatusAnalytics::options())
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            TextInput::make('rule_completed_visits_min')
                                ->label(__('Правила: завершённых визитов от'))
                                ->integer()
                                ->minValue(0)
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            TextInput::make('rule_completed_visits_max')
                                ->label(__('Правила: завершённых визитов до'))
                                ->integer()
                                ->minValue(0)
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            TextInput::make('rule_last_visit_from')
                                ->label(__('Правила: последний визит от'))
                                ->placeholder('ГГГГ-ММ-ДД')
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            TextInput::make('rule_last_visit_to')
                                ->label(__('Правила: последний визит до'))
                                ->placeholder('ГГГГ-ММ-ДД')
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_purchase_types')
                                ->label(__('Правила: типы покупок'))
                                ->multiple()
                                ->options(fn (): array => app(PurchaseGroupCatalog::class)->options(app(OrganizationContext::class)->id()))
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_purchase_statuses')
                                ->label(__('Правила: статусы покупок'))
                                ->multiple()
                                ->options([
                                    PurchaseStatus::Paid->value => 'Оплачена',
                                    PurchaseStatus::PendingPayment->value => 'Ожидает оплаты',
                                    PurchaseStatus::Refunded->value => 'Возвращена',
                                ])
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_has_pain_score')
                                ->label(__('Правила: оценка боли'))
                                ->options(['1' => 'Есть оценка', '0' => 'Без оценки'])
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_pain_score_basis')
                                ->label(__('Правила: источник оценки боли'))
                                ->options(PainScoreBasis::labels())
                                ->default(PainScoreBasis::Any->value)
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_pain_score_min')
                                ->label(__('Правила: боль от'))
                                ->options(self::scoreOptions())
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_pain_score_max')
                                ->label(__('Правила: боль до'))
                                ->options(self::scoreOptions())
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_satisfaction_min')
                                ->label(__('Правила: удовлетворённость от'))
                                ->options(self::scoreOptions())
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                            Select::make('rule_satisfaction_max')
                                ->label(__('Правила: удовлетворённость до'))
                                ->options(self::scoreOptions())
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('group_type') === 'dynamic'),
                        ])
                        ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                        ->defaultItems(0)
                        ->addActionLabel(__('Добавить группу'))
                        ->reorderable()
                        ->deleteAction(fn (Action $action): Action => $action
                            ->label(__('Архивировать'))
                            ->requiresConfirmation()
                            ->modalHeading(__('Архивировать группу?'))
                            ->modalDescription(__('Группа исчезнет из активной формы, но её версии и история сохранятся.'))
                            ->modalSubmitActionLabel(__('Архивировать')))
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('client-cohort-groups-form')
            ->livewireSubmitHandler('save')
            ->footer([Actions::make([Action::make('save')->label(__('Сохранить группы'))->submit('save')])]);
    }

    public function save(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        try {
            $state = $this->form->getState();
            $entries = [];
            $submittedIds = [];
            foreach ((array) ($state['groups'] ?? []) as $group) {
                if (! is_array($group)) {
                    continue;
                }
                $id = filter_var($group['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id !== false) {
                    $submittedIds[] = (int) $id;
                }
                $entries[] = [
                    'data' => $this->groupData($group),
                    'client_ids' => array_values(array_map('intval', (array) ($group['client_ids'] ?? []))),
                ];
            }
            foreach (app(AnalyticsClientGroupCatalog::class)->rows(includeArchived: true) as $row) {
                if ($row['is_active'] && ! in_array((int) $row['id'], $submittedIds, true)) {
                    $entries[] = [
                        'data' => [
                            'id' => $row['id'],
                            'version' => $this->initialGroupVersions[(int) $row['id']] ?? $row['version'],
                            'name' => $row['name'],
                            'description' => $row['description'],
                            'group_type' => $row['group_type'],
                            'is_active' => false,
                            'sort_order' => $row['sort_order'],
                            'rule_definition' => $row['rule_definition'],
                        ],
                        'client_ids' => array_values(array_map('intval', (array) $row['client_ids'])),
                    ];
                }
            }
            app(SaveAnalyticsClientGroup::class)->handleMany($actor, $entries);
            $this->mount();
            Notification::make()->success()->title(__('Группы клиентов сохранены'))->send();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            Notification::make()->danger()->title(is_string($message) ? $message : __('Проверьте группы клиентов.'))->send();
        }
    }

    /** @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function groupData(array $group): array
    {
        return [
            'id' => $group['id'] ?? null,
            'version' => $group['version'] ?? null,
            'name' => $group['name'] ?? '',
            'description' => $group['description'] ?? null,
            'group_type' => (string) ($group['group_type'] ?? 'manual'),
            'is_active' => (bool) ($group['is_active'] ?? true),
            'sort_order' => (int) ($group['sort_order'] ?? 0),
            'rule_definition' => [
                'segments' => $group['rule_segments'] ?? [],
                'genders' => $group['rule_genders'] ?? [],
                'source_keys' => $group['rule_source_keys'] ?? [],
                'service_ids' => $group['rule_service_ids'] ?? [],
                'service_categories' => $group['rule_service_categories'] ?? [],
                'problem_tag_ids' => $group['rule_problem_tag_ids'] ?? [],
                'feedback_theme_ids' => $group['rule_feedback_theme_ids'] ?? [],
                'goal_tag_ids' => $group['rule_goal_tag_ids'] ?? [],
                'specialist_ids' => $group['rule_specialist_ids'] ?? [],
                'countries' => $group['rule_countries'] ?? [],
                'age_min' => $group['rule_age_min'] ?? null,
                'age_max' => $group['rule_age_max'] ?? null,
                'archived' => $group['rule_archived'] ?? null,
                'blacklist' => $group['rule_blacklist'] ?? null,
                'problem_tag_mode' => $group['rule_problem_tag_mode'] ?? 'any',
                'exclude_problem_tag_ids' => $group['rule_exclude_problem_tag_ids'] ?? [],
                'children' => $group['rule_children'] ?? null,
                'booking_statuses' => $group['rule_booking_statuses'] ?? [],
                'booking_event_types' => $group['rule_booking_event_types'] ?? [],
                'completed_visits_min' => $group['rule_completed_visits_min'] ?? null,
                'completed_visits_max' => $group['rule_completed_visits_max'] ?? null,
                'last_visit_from' => $group['rule_last_visit_from'] ?? null,
                'last_visit_to' => $group['rule_last_visit_to'] ?? null,
                'purchase_types' => $group['rule_purchase_types'] ?? [],
                'purchase_statuses' => $group['rule_purchase_statuses'] ?? [],
                'has_pain_score' => $group['rule_has_pain_score'] ?? null,
                'pain_score_basis' => $group['rule_pain_score_basis'] ?? PainScoreBasis::Any->value,
                'pain_score_min' => $group['rule_pain_score_min'] ?? null,
                'pain_score_max' => $group['rule_pain_score_max'] ?? null,
                'satisfaction_min' => $group['rule_satisfaction_min'] ?? null,
                'satisfaction_max' => $group['rule_satisfaction_max'] ?? null,
            ],
        ];
    }

    /** @return array<int|string, string> */
    private function clientSearchResults(string $search): array
    {
        $query = Client::query()->where('organization_id', app(OrganizationContext::class)->id());
        $searcher = app(ClientSearch::class);

        return $searcher->formatOptionLabels(
            $searcher->apply($query, $search)->orderBy('full_name')->limit(ClientSearch::MAX_RESULTS)->get(['id', 'full_name', 'email', 'phone']),
        );
    }

    /** @param list<int|string> $values
     * @return array<int|string, string>
     */
    private function clientOptionLabels(array $values): array
    {
        $clients = Client::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereIn('id', array_map('intval', $values))
            ->get(['id', 'full_name', 'email', 'phone'])
            ->keyBy('id');
        $searcher = app(ClientSearch::class);
        $labels = [];
        foreach ($values as $value) {
            $client = $clients->get((int) $value);
            if ($client instanceof Client) {
                $labels[(string) $value] = $searcher->formatOptionLabel($client);
            }
        }

        return $labels;
    }

    /** @return array<int, string> */
    private static function scoreOptions(): array
    {
        $options = [];
        for ($score = 0; $score <= 10; $score++) {
            $options[$score] = (string) $score;
        }

        return $options;
    }
}
