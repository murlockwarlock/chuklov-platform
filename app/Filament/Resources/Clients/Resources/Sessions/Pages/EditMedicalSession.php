<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Pages;

use App\Filament\Pages\MedicalClinicalCatalogConfiguration;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Filament\Support\LocalizedEditRecord;
use App\Models\User;
use App\Modules\Analytics\Application\AcceptAnalyticsClassification;
use App\Modules\Analytics\Application\SuggestAnalyticsTags;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\MedicalPainData;
use App\Modules\MedicalProfiles\Application\MedicalFrequencyPeriodCatalog;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\GenerateSessionResult;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\LinkMedicalSessionBooking;
use App\Modules\Sessions\Application\MedicalClinicalCatalog;
use App\Modules\Sessions\Application\MedicalSessionSnapshotHasher;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionPain;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class EditMedicalSession extends LocalizedEditRecord
{
    protected static string $resource = MedicalSessionResource::class;

    protected static ?string $title = 'Редактирование сеанса';

    protected static ?string $breadcrumb = 'Редактирование';

    public int $resultFormVersion = 0;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('linkBooking')
                ->label(fn (): string => $this->session()->booking_id === null ? __('Связать с записью') : __('Изменить связь с записью'))
                ->icon('heroicon-o-link')
                ->schema([
                    Select::make('booking_id')
                        ->label(__('Запись на приём'))
                        ->placeholder(__('Без записи на приём'))
                        ->options(fn (): array => $this->bookingOptions())
                        ->getSearchResultsUsing(fn (string $search): array => $this->bookingOptions($search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => $this->bookingLabel($value))
                        ->searchable()
                        ->native(false)
                        ->required(),
                ])
                ->fillForm(fn (): array => ['booking_id' => $this->session()->booking_id])
                ->modalSubmitActionLabel(__('Сохранить связь'))
                ->action(function (array $data): void {
                    app(LinkMedicalSessionBooking::class)->handle(
                        $this->actor(),
                        $this->session(),
                        $this->parentClient(),
                        (int) $data['booking_id'],
                    );
                    Notification::make()->success()->title(__('Связь с записью сохранена'))->send();
                    $this->record = $this->session()->fresh();
                    $this->form->model($this->record);
                    $this->fillForm();
                })
                ->visible(fn (): bool => MedicalSessionResource::canEdit($this->getRecord())),
            Action::make('unlinkBooking')
                ->label(__('Отвязать запись'))
                ->icon('heroicon-o-link-slash')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(LinkMedicalSessionBooking::class)->handle(
                        $this->actor(),
                        $this->session(),
                        $this->parentClient(),
                        null,
                    );
                    Notification::make()->success()->title(__('Запись отвязана от сеанса'))->send();
                    $this->record = $this->session()->fresh();
                    $this->form->model($this->record);
                    $this->fillForm();
                })
                ->visible(fn (): bool => MedicalSessionResource::canEdit($this->getRecord()) && $this->session()->booking_id !== null),
            Action::make('fillResult')
                ->label(__('Заполнить результат'))
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation(fn (): bool => filled($this->data['result'] ?? null))
                ->modalHeading(fn (): ?string => filled($this->data['result'] ?? null) ? (string) __('Заменить текст результата?') : null)
                ->modalDescription(fn (): ?string => filled($this->data['result'] ?? null) ? (string) __('Текущий текст будет заменён сводкой по данным сеанса.') : null)
                ->action(function (): void {
                    $actor = $this->actor();
                    $record = $this->getRecord();
                    abort_unless($record instanceof MedicalSession, 404);
                    $result = app(GenerateSessionResult::class)->handle($actor, $record, $this->parentClient());
                    $this->data['result'] = $result;
                    $this->resultFormVersion++;
                    $this->form->fillPartially(['result' => $result], ['result']);
                    Notification::make()->title(__('Результат заполнен'))->success()->send();
                }),
            $this->suggestSessionPainTagsAction(),
            $this->acceptSessionPainTagsAction(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->model($this->getRecord())
            ->operation('edit')
            ->components([
                Hidden::make('expected_snapshot')->dehydrated()->nullable()->string(),
                Section::make(__('Клинические заметки'))
                    ->schema([
                        Hidden::make('pain_vas')->dehydrated()->nullable(),
                        Hidden::make('pain_not_measured')->dehydrated()->default(true),
                        Placeholder::make('pain_vas_legacy')
                            ->label(__('Историческое значение ВАШ'))
                            ->content(fn (Get $get): string => self::legacyPainVasDisplay($get('pain_vas')))
                            ->columnSpanFull(),
                        Repeater::make('pains')
                            ->label(__('Боли'))
                            ->schema([
                                Hidden::make('lineage_key')->dehydrated()->nullable(),
                                Textarea::make('name')->label(__('Название или описание боли'))->rows(2)->required()->maxLength(1000),
                                Select::make('frequency_mode')->label(__('Частота'))->options([
                                    'permanent' => __('Постоянно'),
                                    'count' => __('Количество раз за период'),
                                ])->default('permanent')->live(),
                                TextInput::make('frequency_count')->label(__('Количество'))->integer()->minValue(1)->visible(fn (Get $get): bool => $get('frequency_mode') === 'count')->required(fn (Get $get): bool => $get('frequency_mode') === 'count'),
                                Select::make('frequency_period_unit')->label(__('Период'))->options(fn (Get $get): array => app(MedicalFrequencyPeriodCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id(), $get('frequency_period_unit')))->visible(fn (Get $get): bool => $get('frequency_mode') === 'count')->required(fn (Get $get): bool => $get('frequency_mode') === 'count')->searchable(),
                                CheckboxList::make('time_of_day')->label(__('Время суток'))->options([
                                    'all' => __('Весь день'),
                                    'morning' => __('Утро'),
                                    'day' => __('День'),
                                    'evening' => __('Вечер'),
                                    'night' => __('Ночь'),
                                ])->columns(2)->default(['all']),
                                Slider::make('before_score')
                                    ->label(__('До сеанса'))
                                    ->range(minValue: 0, maxValue: 10)
                                    ->step(1)
                                    ->nullable()
                                    ->live()
                                    ->helperText(__('0 — нет боли · 10 — максимальная боль')),
                                Placeholder::make('before_score_display')
                                    ->label(__('Текущее значение до сеанса'))
                                    ->content(fn (Get $get): string => self::scoreDisplay($get('before_score'))),
                                Actions::make([
                                    Action::make('clearBeforeScore')
                                        ->label(__('Без оценки'))
                                        ->icon('heroicon-o-x-mark')
                                        ->color('gray')
                                        ->action(static function (Set $set): void {
                                            $set('before_score', null);
                                        })
                                        ->visible(fn (Get $get): bool => $get('before_score') !== null),
                                ])->columnSpanFull(),
                                Slider::make('after_score')
                                    ->label(__('После сеанса'))
                                    ->range(minValue: 0, maxValue: 10)
                                    ->step(1)
                                    ->nullable()
                                    ->live()
                                    ->helperText(__('0 — нет боли · 10 — максимальная боль')),
                                Placeholder::make('after_score_display')
                                    ->label(__('Текущее значение после сеанса'))
                                    ->content(fn (Get $get): string => self::scoreDisplay($get('after_score'))),
                                Actions::make([
                                    Action::make('clearAfterScore')
                                        ->label(__('Без оценки'))
                                        ->icon('heroicon-o-x-mark')
                                        ->color('gray')
                                        ->action(static function (Set $set): void {
                                            $set('after_score', null);
                                        })
                                        ->visible(fn (Get $get): bool => $get('after_score') !== null),
                                ])->columnSpanFull(),
                                Placeholder::make('previous_after_score')
                                    ->label(__('После предыдущего сеанса'))
                                    ->content(fn (Get $get): string => self::scoreDisplay(self::previousAfterScore($get))),
                                Select::make('tag_ids')
                                    ->label(__('Категория проблемы'))
                                    ->options(fn (Get $get): array => app(MedicalProblemTagCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id(), (array) $get('tag_ids')))
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->helperText(fn (Get $get): string => self::problemTagHelperText($get('tag_ids')))
                                    ->hintAction(self::problemTagConfigurationAction()),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['name'] ?? __('Новая боль')))
                            ->addActionLabel(__('Добавить боль'))
                            ->columnSpanFull(),
                        Textarea::make('pain')->label(__('Особые жалобы'))->rows(3)->placeholder(__('Что ещё важно учесть специалисту')),
                        Repeater::make('test_results')
                            ->label(__('Кинезиологические и мануальные тесты'))
                            ->schema([
                                Select::make('test_id')->label(__('Тест'))->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->testOptionsForSelection(app(OrganizationContext::class)->id(), (int) $get('test_id')))->required()->searchable()->live(),
                                Select::make('before_value_id')->label(__('До сеанса'))->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->valueOptionsForSelection(app(OrganizationContext::class)->id(), (int) $get('test_id'), [$get('before_value_id')]))->nullable()->searchable(),
                                Select::make('after_value_id')->label(__('После сеанса'))->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->valueOptionsForSelection(app(OrganizationContext::class)->id(), (int) $get('test_id'), [$get('after_value_id')]))->nullable()->searchable(),
                                Select::make('cause_ids')->label(__('Причины нарушений'))->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->causeOptionsForSelection(app(OrganizationContext::class)->id(), (array) $get('cause_ids')))->multiple()->searchable(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable()
                            ->collapsible()
                            ->addActionLabel(__('Добавить тест'))
                            ->columnSpanFull(),
                        Hidden::make('causes_manual_override')->default(false)->dehydrated(),
                        Placeholder::make('derived_causes')
                            ->label(__('Причины по результатам тестов'))
                            ->content(fn (Get $get): string => self::causeNames(self::derivedCauseIds($get)))
                            ->visible(fn (Get $get): bool => $get('causes_manual_override') !== true)
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('editCausesManually')
                                ->label(__('Изменить список вручную'))
                                ->icon('heroicon-o-pencil-square')
                                ->action(static function (Set $set): void {
                                    $set('causes_manual_override', true);
                                })
                                ->visible(fn (Get $get): bool => $get('causes_manual_override') !== true),
                            Action::make('useTestCauses')
                                ->label(__('Вернуться к причинам из тестов'))
                                ->icon('heroicon-o-arrow-uturn-left')
                                ->color('gray')
                                ->action(static function (Get $get, Set $set): void {
                                    $set('cause_ids', self::derivedCauseIds($get));
                                    $set('causes_manual_override', false);
                                })
                                ->visible(fn (Get $get): bool => $get('causes_manual_override') === true),
                        ])->columnSpanFull(),
                        Select::make('cause_ids')
                            ->label(__('Причины сеанса'))
                            ->options(fn (Get $get): array => app(MedicalClinicalCatalog::class)->causeOptionsForSelection(app(OrganizationContext::class)->id(), (array) $get('cause_ids')))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(static fn (Set $set): mixed => $set('causes_manual_override', true))
                            ->visible(fn (Get $get): bool => $get('causes_manual_override') === true)
                            ->columnSpanFull(),
                        Hidden::make('tests')->dehydrated()->nullable(),
                        Hidden::make('observations')->dehydrated()->nullable(),
                        Repeater::make('root_causes')
                            ->label(__('Первопричина нарушений'))
                            ->schema([
                                Select::make('cause_id')->label(__('Причина из сеанса'))->options(fn (Get $get): array => $this->rootCauseOptions($get))->searchable()->nullable(),
                                Textarea::make('free_text')->label(__('Дополнительное описание'))->rows(2)->maxLength(10000),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->maxItems(2)
                            ->reorderable()
                            ->addActionLabel(__('Добавить первопричину'))
                            ->columnSpanFull(),
                        Textarea::make('root_cause_hypothesis')->label(__('Гипотеза первопричины'))->rows(3)->placeholder(__('Предполагаемая причина состояния')),
                        Textarea::make('protocol')->label(__('Алгоритм / протокол сеанса'))->rows(3)->placeholder(__('Пошаговый план сеанса')),
                        Textarea::make('subjective_feelings')->label(__('Субъективные ощущения клиента после сеанса'))->rows(3)->placeholder(__('Что клиент почувствовал после сеанса')),
                        Textarea::make('visual_posture_diagnostics')->label(__('Визуальная диагностика осанки'))->rows(3)->placeholder(__('Изменения линии плеч, таза и позвоночника')),
                        Textarea::make('result')
                            ->label(__('Результат после сеанса'))
                            ->rows(3)
                            ->live(onBlur: true)
                            ->placeholder(__('Эффект после процедур/до следующего сеанса'))
                            ->key(fn (): string => 'session-result-'.$this->resultFormVersion),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $actor = $this->actor();
        $parent = $this->parentClient();

        if (! $record instanceof MedicalSession) {
            return parent::mutateFormDataBeforeFill($data);
        }

        $decrypted = app(GetSession::class)->handle($actor, $record, $parent);

        if (! $decrypted instanceof MedicalSessionData) {
            abort(403);
        }

        return [
            'pain' => $decrypted->pain,
            'pain_vas' => $decrypted->painVas,
            'pain_not_measured' => $decrypted->painVas === null,
            'tests' => $decrypted->tests,
            'observations' => $decrypted->observations,
            'subjective_feelings' => $decrypted->subjectiveFeelings,
            'visual_posture_diagnostics' => $decrypted->visualPostureDiagnostics,
            'root_cause_hypothesis' => $decrypted->rootCauseHypothesis,
            'protocol' => $decrypted->protocol,
            'result' => $decrypted->result,
            'pains' => array_map(static fn ($pain): array => $pain->toArray(), $decrypted->pains),
            'test_results' => array_map(static fn ($test): array => [
                'test_id' => $test->testId,
                'before_value_id' => $test->beforeValueId,
                'after_value_id' => $test->afterValueId,
                'cause_ids' => array_map(static fn (array $cause): int => $cause['id'], $test->causes),
            ], $decrypted->testResults),
            'root_causes' => array_map(static fn ($cause): array => [
                'cause_id' => $cause->causeId,
                'free_text' => $cause->freeText,
            ], $decrypted->rootCauses),
            'cause_ids' => array_map(static fn (array $cause): int => $cause['id'], $decrypted->causes),
            'causes_manual_override' => $decrypted->causesManualOverride,
            'expected_snapshot' => app(MedicalSessionSnapshotHasher::class)->forSession($record),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = $this->actor();
        $parent = $this->parentClient();

        if (! $record instanceof MedicalSession) {
            return parent::handleRecordUpdate($record, $data);
        }

        try {
            $command = UpdateSessionCommand::fromArray(self::normalizeClinical($data));
        } catch (ValidationException $exception) {
            self::throwValidation($exception->errors());
        }

        $updated = app(UpdateSession::class)->handle($actor, $record, $command, $parent);

        return MedicalSession::query()
            ->where('organization_id', $updated->organizationId)
            ->where('id', $updated->id)
            ->firstOrFail();
    }

    protected function getRedirectUrl(): ?string
    {
        $parent = $this->getParentRecord();

        return ClientResource::getUrl('sessions', ['record' => $parent], shouldGuessMissingParameters: true);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('Сеанс обновлён');
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }

    /** @return array<int, string> */
    private function rootCauseOptions(Get $get): array
    {
        $testResults = $get('../../test_results');
        $causeIds = collect(is_array($testResults) ? $testResults : [])
            ->flatMap(static fn (mixed $test): array => is_array($test) && is_array($test['cause_ids'] ?? null)
                ? array_map(static fn (mixed $id): int => (int) $id, $test['cause_ids'])
                : [])
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->all();
        $selectedCauseIds = $get('../../cause_ids');
        if (is_array($selectedCauseIds)) {
            $causeIds = array_values(array_unique([...$causeIds, ...array_map(static fn (mixed $id): int => (int) $id, $selectedCauseIds)]));
        }
        $currentCauseId = (int) $get('cause_id');
        if ($currentCauseId > 0) {
            $causeIds[] = $currentCauseId;
        }

        $causeOptions = app(MedicalClinicalCatalog::class)->causeOptionsForSelection(app(OrganizationContext::class)->id(), $causeIds);

        return array_intersect_key($causeOptions, array_flip($causeIds));
    }

    private function parentClient(): Client
    {
        $parent = $this->getParentRecord();

        if (! $parent instanceof Client) {
            abort(404);
        }

        return $parent;
    }

    /** @return array<int, string> */
    private function bookingOptions(string $search = ''): array
    {
        $query = $this->bookingQuery();
        $normalized = trim($search);
        if (ctype_digit($normalized)) {
            $query->whereKey((int) $normalized);
        } elseif ($normalized !== '') {
            try {
                $query->whereDate('starts_at', Carbon::parse($normalized));
            } catch (\Throwable) {
                $query->whereKey(0);
            }
        }

        return $query
            ->orderByDesc('starts_at')
            ->limit(50)
            ->with('service:id,name')
            ->get(['id', 'starts_at', 'service_id'])
            ->mapWithKeys(static fn (Booking $booking): array => [
                $booking->getKey() => self::bookingPresentation($booking),
            ])
            ->all();
    }

    private function bookingLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $booking = $this->bookingQuery()
            ->with('service:id,name')
            ->whereKey((int) $value)
            ->first(['id', 'starts_at', 'service_id']);

        return $booking instanceof Booking ? self::bookingPresentation($booking) : null;
    }

    /** @return Builder<Booking> */
    private function bookingQuery(): Builder
    {
        $session = $this->session();
        $client = $this->parentClient();
        $organizationId = app(OrganizationContext::class)->id();

        return Booking::query()
            ->where('organization_id', $organizationId)
            ->where('specialist_id', $session->specialist_id)
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('patientProfile', fn (Builder $patients): Builder => $patients
                    ->where('organization_id', $organizationId)
                    ->where('clinical_client_id', $client->getKey()))
                ->orWhere(fn (Builder $legacy): Builder => $legacy
                    ->whereNull('patient_profile_id')
                    ->where('client_id', $client->getKey())));
    }

    private static function bookingPresentation(Booking $booking): string
    {
        $service = trim($booking->service->name);
        $date = Carbon::parse((string) $booking->getAttribute('starts_at'), 'UTC')
            ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
            ->format('d.m.Y H:i');

        return $service === '' ? $date : $date.' — '.$service;
    }

    private function suggestSessionPainTagsAction(): Action
    {
        return Action::make('suggestSessionPainTags')
            ->label(__('Предложить категории боли с ИИ'))
            ->icon('heroicon-o-sparkles')
            ->schema([
                Select::make('pain_id')
                    ->label(__('Боль сеанса'))
                    ->options(fn (): array => $this->sessionPainOptions())
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->visible(fn (): bool => MedicalSessionResource::canEdit($this->getRecord()))
            ->authorize(fn (): bool => MedicalSessionResource::canEdit($this->getRecord()))
            ->action(function (array $data): void {
                $pain = $this->sessionPain((int) ($data['pain_id'] ?? 0));
                $sourceText = $this->sessionPainName($pain);
                if ($sourceText === null) {
                    Notification::make()->warning()->title(__('Не удалось прочитать боль'))->send();

                    return;
                }

                $options = app(MedicalProblemTagCatalog::class)->options(app(OrganizationContext::class)->id());
                $result = app(SuggestAnalyticsTags::class)->handle(
                    actor: $this->actor(),
                    taxonomy: 'complaints',
                    sourceType: 'medical_session_pain',
                    sourceId: (int) $pain->getKey(),
                    clientId: (int) $pain->client_id,
                    sourceText: $sourceText,
                    availableTags: array_map(
                        static fn (int|string $id, string $label): array => ['id' => (int) $id, 'label' => $label],
                        array_keys($options),
                        array_values($options),
                    ),
                    force: true,
                );
                $labels = collect($result->suggestions)->pluck('label')->implode(', ');
                if ($labels !== '') {
                    Notification::make()->success()->title(__('Предложения категорий готовы'))->body($labels)->send();

                    return;
                }

                Notification::make()->warning()->title(__('Предложения категорий недоступны'))->body(__('Настройте prompt классификатора или добавьте категории проблем.'))->send();
            });
    }

    private function acceptSessionPainTagsAction(): Action
    {
        return Action::make('acceptSessionPainTags')
            ->label(__('Принять категории боли'))
            ->icon('heroicon-o-check-circle')
            ->schema([
                Select::make('pain_id')
                    ->label(__('Боль сеанса'))
                    ->options(fn (): array => $this->sessionPainOptionsWithSuggestions())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $run = $this->latestSessionPainClassificationRun((int) $state);
                        $set('tag_ids', $this->classificationSuggestionIds($run));
                    })
                    ->required(),
                Select::make('tag_ids')
                    ->label(__('Категории проблемы'))
                    ->options(fn (Get $get): array => app(MedicalProblemTagCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id(), (array) $get('tag_ids')))
                    ->multiple()
                    ->searchable()
                    ->preload(),
            ])
            ->visible(fn (): bool => MedicalSessionResource::canEdit($this->getRecord()) && $this->sessionPainWithSuggestionsExists())
            ->authorize(fn (): bool => MedicalSessionResource::canEdit($this->getRecord()))
            ->action(function (array $data): void {
                $painId = (int) ($data['pain_id'] ?? 0);
                $run = $this->latestSessionPainClassificationRun($painId);
                abort_unless($run instanceof AnalyticsClassificationRun, 422);
                app(AcceptAnalyticsClassification::class)->handle(
                    actor: $this->actor(),
                    runId: (int) $run->getKey(),
                    themeIds: is_array($data['tag_ids'] ?? null) ? $data['tag_ids'] : [],
                );
                Notification::make()->success()->title(__('Категории боли сохранены'))->send();
            });
    }

    /** @return array<int, string> */
    private function sessionPainOptions(): array
    {
        $data = app(GetSession::class)->handle($this->actor(), $this->session(), $this->parentClient());

        return collect($data === null ? [] : $data->pains)
            ->mapWithKeys(static fn (MedicalPainData $pain): array => [(int) $pain->id => $pain->name])
            ->all();
    }

    /** @return array<int, string> */
    private function sessionPainOptionsWithSuggestions(): array
    {
        $painIds = AnalyticsClassificationRun::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('taxonomy', 'complaints')
            ->where('source_type', 'medical_session_pain')
            ->where('status', ClassificationRunStatus::NeedsReview)
            ->pluck('source_id');

        return collect($this->sessionPainOptions())
            ->filter(static fn (string $name, int|string $id): bool => $painIds->contains((int) $id))
            ->all();
    }

    private function sessionPainWithSuggestionsExists(): bool
    {
        return $this->sessionPainOptionsWithSuggestions() !== [];
    }

    private function sessionPain(int $painId): MedicalSessionPain
    {
        return MedicalSessionPain::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('client_id', $this->parentClient()->getKey())
            ->where('medical_session_id', $this->session()->getKey())
            ->whereKey($painId)
            ->firstOrFail();
    }

    private function sessionPainName(MedicalSessionPain $pain): ?string
    {
        $painData = collect($this->sessionPainOptions())
            ->get((int) $pain->getKey());

        return is_string($painData) && trim($painData) !== '' ? $painData : null;
    }

    private function latestSessionPainClassificationRun(int $painId): ?AnalyticsClassificationRun
    {
        $this->sessionPain($painId);

        return AnalyticsClassificationRun::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('taxonomy', 'complaints')
            ->where('source_type', 'medical_session_pain')
            ->where('source_id', $painId)
            ->where('status', ClassificationRunStatus::NeedsReview)
            ->latest('id')
            ->first();
    }

    /** @return list<int> */
    private function classificationSuggestionIds(?AnalyticsClassificationRun $run): array
    {
        if (! $run instanceof AnalyticsClassificationRun || ! is_array($run->suggestions)) {
            return [];
        }

        return array_map(
            static fn (mixed $id): int => (int) $id,
            array_column($run->suggestions, 'id'),
        );
    }

    private function session(): MedicalSession
    {
        $session = $this->getRecord();
        abort_unless($session instanceof MedicalSession, 404);

        return $session;
    }

    private static function scoreDisplay(mixed $value): string
    {
        return is_numeric($value) && (int) $value >= 0 && (int) $value <= 10
            ? __(':value / 10', ['value' => (int) $value])
            : __('Без оценки');
    }

    private static function previousAfterScore(Get $get): mixed
    {
        $state = $get('');

        return is_array($state) ? ($state['previous_after_score'] ?? null) : null;
    }

    private static function legacyPainVasDisplay(mixed $value): string
    {
        return is_numeric($value) && (int) $value >= 0 && (int) $value <= 10
            ? __(':value / 10', ['value' => (int) $value])
            : __('Не указана');
    }

    /** @return list<int> */
    private static function derivedCauseIds(Get $get): array
    {
        $testResults = $get('test_results');
        $ids = [];
        foreach (is_array($testResults) ? $testResults : [] as $test) {
            if (! is_array($test) || ! is_array($test['cause_ids'] ?? null)) {
                continue;
            }
            foreach ($test['cause_ids'] as $id) {
                $id = (int) $id;
                if ($id > 0 && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /** @param list<int> $causeIds */
    private static function causeNames(array $causeIds): string
    {
        if ($causeIds === []) {
            return __('Причины по результатам тестов пока не выбраны.');
        }

        $options = app(MedicalClinicalCatalog::class)->causeOptionsForSelection(
            app(OrganizationContext::class)->id(),
            $causeIds,
        );

        return implode(', ', array_values(array_intersect_key($options, array_flip($causeIds))));
    }

    /** @return array<int, string> */
    private static function problemTagOptions(mixed $selectedIds): array
    {
        return app(MedicalProblemTagCatalog::class)->optionsForSelection(
            app(OrganizationContext::class)->id(),
            is_array($selectedIds) ? $selectedIds : [],
        );
    }

    private static function problemTagHelperText(mixed $selectedIds): string
    {
        return self::problemTagOptions($selectedIds) === []
            ? __('Категории проблем ещё не настроены.')
            : __('Выберите одну или несколько категорий для статистики.');
    }

    private static function problemTagConfigurationAction(): Action
    {
        return Action::make('configureProblemCategories')
            ->label(__('Настроить категории'))
            ->url(MedicalClinicalCatalogConfiguration::getUrl())
            ->visible(fn (): bool => self::problemTagOptions([]) === [] && MedicalClinicalCatalogConfiguration::canAccess());
    }

    /** @param array<string, list<string>> $messages */
    private static function throwValidation(array $messages): never
    {
        throw ValidationException::withMessages($messages);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeClinical(array $data): array
    {
        if (($data['pain_not_measured'] ?? false) === true) {
            $data['pain_vas'] = null;
        }
        $fields = [
            'pain',
            'pain_vas',
            'tests',
            'observations',
            'subjective_feelings',
            'visual_posture_diagnostics',
            'root_cause_hypothesis',
            'protocol',
            'result',
            'pains',
            'test_results',
            'root_causes',
            'cause_ids',
            'causes_manual_override',
        ];

        $normalized = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            $normalized[$field] = is_string($value) && trim($value) === '' ? null : $value;
        }

        if (array_key_exists('expected_snapshot', $data)) {
            $normalized['expected_snapshot'] = $data['expected_snapshot'];
        }

        return $normalized;
    }
}
