<?php

namespace App\Filament\Resources\TrackerPlans\Pages;

use App\Filament\Resources\TrackerPlans\TrackerPlanResource;
use App\Models\User;
use App\Modules\Tracker\Application\SaveTrackerPlan;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateTrackerPlan extends CreateRecord
{
    protected static string $resource = TrackerPlanResource::class;

    protected static ?string $title = 'Добавить тариф';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $version = app(SaveTrackerPlan::class)->handle($actor, null, (string) $data['name'], (bool) $data['is_active'], (bool) $data['is_visible'], (string) $data['price'], (string) $data['currency'], (int) $data['duration_days'], isset($data['description']) ? (string) $data['description'] : null, (bool) $data['included_access'], (int) $data['display_order'], isset($data['monthly_practice']) ? (string) $data['monthly_practice'] : null);

        return $version->plan()->firstOrFail();
    }
}
