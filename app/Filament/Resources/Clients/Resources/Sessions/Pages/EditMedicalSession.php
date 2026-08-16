<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditMedicalSession extends EditRecord
{
    protected static string $resource = MedicalSessionResource::class;

    protected static ?string $title = 'Редактирование сеанса';

    protected static ?string $breadcrumb = 'Редактирование';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->model($this->getRecord())
            ->operation('edit')
            ->components([
                Section::make('Клинические заметки')
                    ->schema([
                        Textarea::make('pain')->label('Боль')->rows(3)->placeholder('Что беспокоит клиента и где'),
                        Textarea::make('tests')->label('Тесты')->rows(3)->placeholder('Проведённые проверки и их результаты'),
                        Textarea::make('observations')->label('Наблюдения')->rows(3)->placeholder('Субъективные и объективные наблюдения'),
                        Textarea::make('root_cause_hypothesis')->label('Гипотеза первопричины')->rows(3)->placeholder('Предполагаемая причина состояния'),
                        Textarea::make('protocol')->label('Протокол')->rows(3)->placeholder('Назначенные процедуры и план'),
                        Textarea::make('result')->label('Результат')->rows(3)->placeholder('Эффект после процедур/до следующего сеанса'),
                    ]),
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
            'tests' => $decrypted->tests,
            'observations' => $decrypted->observations,
            'root_cause_hypothesis' => $decrypted->rootCauseHypothesis,
            'protocol' => $decrypted->protocol,
            'result' => $decrypted->result,
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
        return 'Сеанс обновлён';
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
        $fields = [
            'pain',
            'tests',
            'observations',
            'root_cause_hypothesis',
            'protocol',
            'result',
        ];

        $normalized = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            $normalized[$field] = is_string($value) && trim($value) === '' ? null : $value;
        }

        return $normalized;
    }
}
