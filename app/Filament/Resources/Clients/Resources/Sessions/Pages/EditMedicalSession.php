<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Filament\Support\LocalizedEditRecord;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\GenerateSessionResult;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\MedicalSessionSnapshotHasher;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Textarea;
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
                        Checkbox::make('pain_not_measured')
                            ->label(__('Боль не измерена'))
                            ->default(true)
                            ->live(),
                        Slider::make('pain_vas')
                            ->label(__('Шкала боли'))
                            ->range(minValue: 0, maxValue: 10)
                            ->step(1)
                            ->nullable()
                            ->default(null)
                            ->disabled(fn (Get $get): bool => $get('pain_not_measured') === true)
                            ->live()
                            ->helperText(__('0 — нет боли · 10 — максимальная боль')),
                        Placeholder::make('pain_vas_current')
                            ->label(__('Текущее значение'))
                            ->content(fn (Get $get): string => $get('pain_not_measured') === true ? __('Не указана') : self::painVasDisplay($get('pain_vas')))
                            ->columnSpanFull(),
                        Textarea::make('pain')->label(__('Описание боли'))->rows(3)->placeholder(__('Что беспокоит клиента и где')),
                        Textarea::make('tests')->label(__('Тесты'))->rows(3)->placeholder(__('Проведённые проверки и их результаты')),
                        Textarea::make('observations')->label(__('Наблюдения'))->rows(3)->placeholder(__('Субъективные и объективные наблюдения')),
                        Textarea::make('subjective_feelings')->label(__('Субъективные ощущения клиента после сеанса'))->rows(3)->placeholder(__('Что клиент почувствовал после сеанса')),
                        Textarea::make('visual_posture_diagnostics')->label(__('Визуальная диагностика осанки'))->rows(3)->placeholder(__('Изменения линии плеч, таза и позвоночника')),
                        Textarea::make('root_cause_hypothesis')->label(__('Гипотеза первопричины'))->rows(3)->placeholder(__('Предполагаемая причина состояния')),
                        Textarea::make('protocol')->label(__('Протокол'))->rows(3)->placeholder(__('Назначенные процедуры и план')),
                        Textarea::make('result')->label(__('Результат'))->rows(3)->placeholder(__('Эффект после процедур/до следующего сеанса')),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function painVasDisplay(mixed $value): string
    {
        return is_numeric($value) && (int) $value >= 0 && (int) $value <= 10
            ? __(':value / 10', ['value' => (int) $value])
            : __('Не указана');
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

    private function parentClient(): Client
    {
        $parent = $this->getParentRecord();

        if (! $parent instanceof Client) {
            abort(404);
        }

        return $parent;
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
