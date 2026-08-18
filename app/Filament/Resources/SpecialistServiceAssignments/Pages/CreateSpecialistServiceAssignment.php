<?php

namespace App\Filament\Resources\SpecialistServiceAssignments\Pages;

use App\Filament\Resources\SpecialistServiceAssignments\SpecialistServiceAssignmentResource;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSpecialistServiceAssignment extends CreateRecord
{
    protected static string $resource = SpecialistServiceAssignmentResource::class;

    protected static ?string $title = 'Назначить специалиста на услугу';

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

        return app(AssignSpecialistToService::class)->handle($actor, $specialist, $service);
    }
}
