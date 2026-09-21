<?php

namespace App\Filament\Resources\TrackerPlans\Pages;

use App\Filament\Resources\TrackerPlans\TrackerPlanResource;
use App\Filament\Support\LocalizedEditRecord;
use App\Models\User;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\SavePaymentProviderOfferMappings;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Tracker\Application\SaveTrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Illuminate\Database\Eloquent\Model;

final class EditTrackerPlan extends LocalizedEditRecord
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
            $data['monthly_practice'] = $version->monthly_practice;
            $data['included_access'] = $version->included_access;
            $data['display_order'] = $version->display_order;
            $mapping = PaymentProviderOfferMapping::query()
                ->activeFor(
                    (int) $record->organization_id,
                    'lava',
                    TrackerPlanVersion::class,
                    (int) $version->getKey(),
                    $version->currencyCode()->value,
                )
                ->first();
            $data['lava_enabled'] = $mapping !== null;
            $data['lava_offer_id'] = $mapping?->external_offer_id;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof TrackerPlan, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $mapping = $this->mappingData($data);
        unset($data['lava_enabled'], $data['lava_offer_id']);
        $version = app(SaveTrackerPlan::class)->handle($actor, $record, (string) $data['name'], (bool) $data['is_active'], (bool) $data['is_visible'], (string) $data['price'], (string) $data['currency'], (int) $data['duration_days'], isset($data['description']) ? (string) $data['description'] : null, (bool) $data['included_access'], (int) $data['display_order'], isset($data['monthly_practice']) ? (string) $data['monthly_practice'] : null);

        if (app(FinanceAuthorization::class)->allowsManage($actor)) {
            app(SavePaymentProviderOfferMappings::class)->handle(
                actor: $actor,
                sellableType: TrackerPlanVersion::class,
                sellableId: (int) $version->getKey(),
                enabled: $mapping['enabled'],
                mappings: $mapping['mappings'],
            );
        }

        return $record->refresh();
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
