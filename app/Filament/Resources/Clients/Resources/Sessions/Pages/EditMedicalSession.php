<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Filament\Support\LocalizedEditRecord;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\MedicalFrequencyPeriodCatalog;
use App\Modules\MedicalProfiles\Application\MedicalProblemTagCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\GenerateSessionResult;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\MedicalClinicalCatalog;
use App\Modules\Sessions\Application\MedicalSessionSnapshotHasher;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Domain\Models\MedicalSession;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditMedicalSession extends LocalizedEditRecord
{
    protected static string $resource = MedicalSessionResource::class;

    protected static ?string $title = 'Редактирование сеанса';

    protected static ?string $breadcrumb = 'Редактирование';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fillResult')
                ->label(__('Заполнить результат'))
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation(fn (): bool => filled($this->data['result'] ?? null))
                ->modalHeading(__('Заменить текст результата?'))
                ->modalDescription(__('Текущий текст будет заменён сводкой по данным сеанса.'))
                ->action(function (): void {
                    $actor = $this->actor();
                    $record = $this->getRecord();
                    abort_unless($record instanceof MedicalSession, 404);
                    $result = app(GenerateSessionResult::class)->handle($actor, $record, $this->parentClient());
                    $this->data['result'] = $result;
                    $this->form->fill($this->data);
                    Notification::make()->title(__('Результат заполнен'))->success()->send();
                }),
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
                                Slider::make('before_score')->label(__('До сеанса'))->range(minValue: 0, maxValue: 10)->step(1)->nullable(),
                                Slider::make('after_score')->label(__('После сеанса'))->range(minValue: 0, maxValue: 10)->step(1)->nullable(),
                                Placeholder::make('previous_after_score')
                                    ->label(__('После предыдущего сеанса'))
                                    ->content(fn (Get $get): string => self::scoreDisplay(self::previousAfterScore($get))),
                                Select::make('tag_ids')
                                    ->label(__('Категория проблемы'))
                                    ->options(fn (Get $get): array => app(MedicalProblemTagCatalog::class)->optionsForSelection(app(OrganizationContext::class)->id(), (array) $get('tag_ids')))
                                    ->multiple()
                                    ->searchable()
                                    ->preload(),
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
                        Textarea::make('protocol')->label(__('Протокол'))->rows(3)->placeholder(__('Назначенные процедуры и план')),
                        Textarea::make('subjective_feelings')->label(__('Субъективные ощущения клиента после сеанса'))->rows(3)->placeholder(__('Что клиент почувствовал после сеанса')),
                        Textarea::make('visual_posture_diagnostics')->label(__('Визуальная диагностика осанки'))->rows(3)->placeholder(__('Изменения линии плеч, таза и позвоночника')),
                        Textarea::make('result')->label(__('Результат после сеанса'))->rows(3)->placeholder(__('Эффект после процедур/до следующего сеанса')),
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

    private static function scoreDisplay(mixed $value): string
    {
        return is_numeric($value) && (int) $value >= 0 && (int) $value <= 10
            ? __(':value / 10', ['value' => (int) $value])
            : __('Нет данных');
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
