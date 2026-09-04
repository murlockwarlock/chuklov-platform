<?php

namespace App\Filament\Resources\WorkingLocations\Pages;

use App\Filament\Resources\WorkingLocations\WorkingLocationResource;
use App\Models\User;
use App\Modules\Scheduling\Application\CreateWorkingLocation as CreateWorkingLocationAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWorkingLocation extends CreateRecord
{
    protected static string $resource = WorkingLocationResource::class;

    protected static ?string $title = 'Добавить локацию';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateWorkingLocationAction::class)->handle(
            actor: $actor,
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
