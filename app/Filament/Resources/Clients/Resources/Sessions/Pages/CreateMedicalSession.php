<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateMedicalSession extends CreateRecord
{
    protected static string $resource = MedicalSessionResource::class;

    protected static ?string $title = 'Новый сеанс';

    protected static ?string $breadcrumb = 'Новый сеанс';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = $this->actor();
        $parent = $this->parentClient();

        try {
            $command = CreateSessionCommand::fromArray($data);
        } catch (ValidationException $exception) {
            throw self::formValidation($exception);
        }

        $result = app(CreateSession::class)->handle($actor, $parent, $command);

        return MedicalSession::query()
            ->where('organization_id', $result->organizationId)
            ->where('id', $result->id)
            ->firstOrFail();
    }

    protected function getRedirectUrl(): string
    {
        $parent = $this->getParentRecord();

        return ClientResource::getUrl('sessions', ['record' => $parent], shouldGuessMissingParameters: true);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Сеанс создан';
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

    private static function formValidation(ValidationException $exception): ValidationException
    {
        return ValidationException::withMessages($exception->errors());
    }
}
