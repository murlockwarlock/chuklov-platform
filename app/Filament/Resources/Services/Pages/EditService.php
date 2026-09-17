<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\SavePaymentProviderOfferMappings;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Services\Application\ServiceSnapshotHasher;
use App\Modules\Services\Application\UpdateService;
use App\Modules\Services\Domain\Models\Service;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected static ?string $title = 'Редактировать услугу';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Service, 404);

        $data['price'] = null;

        if (($data['price_minor'] ?? null) !== null && is_string($data['price_currency'] ?? null)) {
            try {
                $data['price'] = Money::ofMinor($data['price_minor'], $data['price_currency'])->toDecimalString();
            } catch (InvalidArgumentException) {
            }
        }

        unset($data['price_minor'], $data['image_path']);
        $data['service_image'] = null;
        $data['remove_image'] = false;
        $data['expected_snapshot'] = app(ServiceSnapshotHasher::class)->forService($record);
        $mapping = $this->mappingFor($record);
        $data['lava_enabled'] = $mapping['enabled'];
        $data['lava_offer_id'] = $mapping['offer_id'];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Service, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $mapping = $this->mappingData($data);
        unset($data['lava_enabled'], $data['lava_offer_id']);

        $acknowledgeImpact = (bool) ($data['acknowledge_impact'] ?? false);
        $impactDigest = isset($data['impact_digest']) ? (string) $data['impact_digest'] : null;
        unset($data['acknowledge_impact']);
        unset($data['impact_digest']);

        try {
            $updated = app(UpdateService::class)->handle(
                actor: $actor,
                service: $record,
                name: $data,
                acknowledgeImpact: $acknowledgeImpact,
                acknowledgedImpactDigest: $impactDigest,
            );

            if (app(FinanceAuthorization::class)->allowsManage($actor)) {
                app(SavePaymentProviderOfferMappings::class)->handle(
                    actor: $actor,
                    sellableType: Service::class,
                    sellableId: (int) $record->getKey(),
                    enabled: $mapping['enabled'],
                    mappings: $mapping['mappings'],
                );
            }

            return $updated;
        } catch (ValidationException $exception) {
            $this->form->fill(ScheduleImpactPreview::mergeValidationPreview([...$data, 'acknowledge_impact' => $acknowledgeImpact, 'impact_digest' => $impactDigest], $exception));

            throw $exception;
        }
    }

    /** @return array{enabled: bool, offer_id: string|null} */
    private function mappingFor(Service $service): array
    {
        $mapping = PaymentProviderOfferMapping::query()
            ->activeFor(
                (int) $service->organization_id,
                'lava',
                Service::class,
                (int) $service->getKey(),
                (string) $service->price_currency,
            )
            ->first();

        return [
            'enabled' => $mapping !== null,
            'offer_id' => $mapping?->external_offer_id,
        ];
    }

    /** @param array<string, mixed> $data @return array{enabled: bool, mappings: list<array{currency: mixed, offer_id: mixed}>} */
    private function mappingData(array $data): array
    {
        $enabled = (bool) ($data['lava_enabled'] ?? false);

        return [
            'enabled' => $enabled,
            'mappings' => $enabled ? [[
                'currency' => $data['price_currency'] ?? null,
                'offer_id' => $data['lava_offer_id'] ?? null,
            ]] : [],
        ];
    }
}
