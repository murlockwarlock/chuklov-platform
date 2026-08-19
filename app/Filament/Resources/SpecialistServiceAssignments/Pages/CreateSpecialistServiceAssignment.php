<?php

namespace App\Filament\Resources\SpecialistServiceAssignments\Pages;

use App\Filament\Resources\SpecialistServiceAssignments\SpecialistServiceAssignmentResource;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSpecialistServiceAssignment extends CreateRecord
{
    protected static string $resource = SpecialistServiceAssignmentResource::class;

    protected static ?string $title = 'Назначить специалиста на услугу';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Специалист назначен на услугу';
    }

    protected function getRedirectUrl(): string
    {
        return SpecialistServiceAssignmentResource::getUrl('index');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $organizationId = app(OrganizationContext::class)->id();
        $specialist = Specialist::query()
            ->where('organization_id', $organizationId)
            ->findOrFail((int) $data['specialist_id']);
        $service = Service::query()
            ->where('organization_id', $organizationId)
            ->findOrFail((int) $data['service_id']);

        try {
            return app(AssignSpecialistToService::class)->handle($actor, $specialist, $service);
        } catch (ValidationException $exception) {
            $duplicateMessages = $exception->errors()['assignment'] ?? [];

            if (! in_array('This specialist is already assigned to the service.', $duplicateMessages, true)) {
                throw $exception;
            }

            Notification::make()
                ->danger()
                ->title('Этот специалист уже оказывает выбранную услугу')
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);

            throw $exception;
        }
    }
}
