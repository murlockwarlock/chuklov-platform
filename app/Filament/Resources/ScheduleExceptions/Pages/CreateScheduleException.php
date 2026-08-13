<?php

namespace App\Filament\Resources\ScheduleExceptions\Pages;

use App\Filament\Resources\ScheduleExceptions\ScheduleExceptionResource;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\CreateScheduleException as CreateScheduleExceptionAction;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateScheduleException extends CreateRecord
{
    protected static string $resource = ScheduleExceptionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $specialist = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->findOrFail((int) $data['specialist_id']);

        return app(CreateScheduleExceptionAction::class)->handle(
            $actor,
            $specialist,
            $data,
            (bool) ($data['acknowledge_impact'] ?? false),
        );
    }
}
