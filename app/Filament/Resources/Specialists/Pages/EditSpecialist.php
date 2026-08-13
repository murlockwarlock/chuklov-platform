<?php

namespace App\Filament\Resources\Specialists\Pages;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Models\User;
use App\Modules\Specialists\Application\UpdateSpecialist;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSpecialist extends EditRecord
{
    protected static string $resource = SpecialistResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Specialist, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $acknowledgeImpact = (bool) ($data['acknowledge_impact'] ?? false);
        $impactDigest = isset($data['impact_digest']) ? (string) $data['impact_digest'] : null;

        return app(UpdateSpecialist::class)->handle(
            actor: $actor,
            specialist: $record,
            displayName: $data['display_name'],
            isActive: (bool) $data['is_active'],
            timezone: $data['timezone'] ?? null,
            staffUserId: isset($data['staff_user_id']) ? (int) $data['staff_user_id'] : null,
            acknowledgeImpact: $acknowledgeImpact,
            acknowledgedImpactDigest: $impactDigest,
        );
    }
}
