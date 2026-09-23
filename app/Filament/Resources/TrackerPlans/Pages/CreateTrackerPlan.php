<?php

namespace App\Filament\Resources\TrackerPlans\Pages;

use App\Filament\Resources\TrackerPlans\TrackerPlanResource;
use App\Filament\Support\LocalizedCreateRecord;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\SavePaymentProviderOfferMappings;
use App\Modules\Tracker\Application\SaveTrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Illuminate\Database\Eloquent\Model;

final class CreateTrackerPlan extends LocalizedCreateRecord
{
    protected static string $resource = TrackerPlanResource::class;

    protected static ?string $title = 'Добавить тариф';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $mapping = $this->mappingData($data);
        unset($data['lava_enabled'], $data['lava_offer_id']);
        $version = app(SaveTrackerPlan::class)->handle($actor, null, (string) $data['name'], (bool) $data['is_active'], (bool) $data['is_visible'], (string) $data['price'], (string) $data['currency'], (int) $data['duration_days'], isset($data['description']) ? (string) $data['description'] : null, (bool) $data['included_access'], (int) $data['display_order'], isset($data['monthly_practice']) ? (string) $data['monthly_practice'] : null);

        if (app(FinanceAuthorization::class)->allowsManage($actor)) {
            app(SavePaymentProviderOfferMappings::class)->handle(
                actor: $actor,
                sellableType: TrackerPlanVersion::class,
                sellableId: (int) $version->getKey(),
                enabled: $mapping['enabled'],
                mappings: $mapping['mappings'],
            );
        }

        return $version->plan()->firstOrFail();
    }

    /** @param array<string, mixed> $data @return array{enabled: bool, mappings: list<array{currency: mixed, offer_id: mixed}>} */
    private function mappingData(array $data): array
    {
        $enabled = (bool) ($data['lava_enabled'] ?? false);

        return [
            'enabled' => $enabled,
            'mappings' => $enabled ? [[
                'currency' => $data['currency'] ?? null,
                'offer_id' => $data['lava_offer_id'] ?? null,
            ]] : [],
        ];
    }
}
