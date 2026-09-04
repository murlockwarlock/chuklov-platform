<?php

namespace App\Filament\Resources\WorkingLocations\Pages;

use App\Filament\Resources\WorkingLocations\WorkingLocationResource;
use App\Models\User;
use App\Modules\Scheduling\Application\UpdateWorkingLocation as UpdateWorkingLocationAction;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditWorkingLocation extends EditRecord
{
    protected static string $resource = WorkingLocationResource::class;

    protected static ?string $title = 'Изменить локацию';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof WorkingLocation, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateWorkingLocationAction::class)->handle(
            actor: $actor,
            location: $record,
            name: (string) $data['name'],
            address: (string) $data['address'],
            timezone: (string) $data['timezone'],
            latitude: $this->nullableFloat($data['latitude'] ?? null),
            longitude: $this->nullableFloat($data['longitude'] ?? null),
            mapUrl: isset($data['map_url']) ? (string) $data['map_url'] : null,
            isActive: (bool) ($data['is_active'] ?? true),
            isDefaultOffice: (bool) ($data['is_default_office'] ?? false),
        );
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
