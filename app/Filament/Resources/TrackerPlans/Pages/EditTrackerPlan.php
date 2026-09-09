<?php

namespace App\Filament\Resources\TrackerPlans\Pages;

use App\Filament\Resources\TrackerPlans\TrackerPlanResource;
use App\Models\User;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Tracker\Application\SaveTrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditTrackerPlan extends EditRecord
{
    protected static string $resource = TrackerPlanResource::class;

    protected static ?string $title = 'Изменить тариф';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        abort_unless($record instanceof TrackerPlan, 404);
        $version = $record->currentVersion;
        if ($version !== null) {
            $data['price'] = Money::ofMinor($version->price_minor, $version->currencyCode())->toDecimalString();
            $data['currency'] = $version->currencyCode()->value;
            $data['duration_days'] = $version->duration_days;
            $data['description'] = $version->description;
            $data['included_access'] = $version->included_access;
            $data['display_order'] = $version->display_order;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof TrackerPlan, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        app(SaveTrackerPlan::class)->handle($actor, $record, (string) $data['name'], (bool) $data['is_active'], (bool) $data['is_visible'], (string) $data['price'], (string) $data['currency'], (int) $data['duration_days'], isset($data['description']) ? (string) $data['description'] : null, (bool) $data['included_access'], (int) $data['display_order']);

        return $record->refresh();
    }
}
