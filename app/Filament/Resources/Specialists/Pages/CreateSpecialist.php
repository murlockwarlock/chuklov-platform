<?php

namespace App\Filament\Resources\Specialists\Pages;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Models\User;
use App\Modules\Specialists\Application\CreateSpecialist as CreateSpecialistAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSpecialist extends CreateRecord
{
    protected static string $resource = SpecialistResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateSpecialistAction::class)->handle(
            actor: $actor,
            displayName: $data['display_name'],
            isActive: (bool) $data['is_active'],
            timezone: $data['timezone'] ?? null,
            staffUserId: isset($data['staff_user_id']) ? (int) $data['staff_user_id'] : null,
        );
    }
}
