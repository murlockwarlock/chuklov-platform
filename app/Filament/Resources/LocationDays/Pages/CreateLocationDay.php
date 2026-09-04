<?php

namespace App\Filament\Resources\LocationDays\Pages;

use App\Filament\Resources\LocationDays\LocationDayResource;
use App\Models\User;
use App\Modules\Scheduling\Application\SaveLocationDay;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLocationDay extends CreateRecord
{
    protected static string $resource = LocationDayResource::class;

    protected static ?string $title = 'Добавить день выезда';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(SaveLocationDay::class)->handle(
            actor: $actor,
            locationDay: null,
            areaName: (string) $data['area_name'],
            weekday: $this->nullableInt($data['weekday'] ?? null),
            specificDate: isset($data['specific_date']) ? (string) $data['specific_date'] : null,
            startTime: (string) $data['start_time'],
            endTime: (string) $data['end_time'],
            timezone: (string) $data['timezone'],
            isActive: (bool) ($data['is_active'] ?? true),
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
