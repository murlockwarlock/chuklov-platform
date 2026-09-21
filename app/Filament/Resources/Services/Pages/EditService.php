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
use App\Modules\Services\Domain\ValueObjects\ServicePriceMatrix;
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
        $data['price_matrix'] = ServicePriceMatrix::fromMinor($record->getAttribute('price_matrix'))->majorUnits();

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
        $data['lava_offers'] = $mapping['offers'];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Service, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $data['payment_policy'] = $record->getRawOriginal('payment_policy');
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

    /** @return array{enabled: bool, offer_id: string|null, offers: list<array{currency: string, offer_id: string}>} */
    private function mappingFor(Service $service): array
    {
        $mappings = PaymentProviderOfferMapping::query()
            ->where('organization_id', $service->organization_id)
            ->where('gateway', 'lava')
            ->where('sellable_type', Service::class)
            ->where('sellable_id', $service->getKey())
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
        $primary = $mappings->first(fn (PaymentProviderOfferMapping $mapping): bool => $mapping->currency->value === (string) $service->price_currency)
            ?? $mappings->first();

        return [
            'enabled' => $primary !== null,
            'offer_id' => $primary?->external_offer_id,
            'offers' => $mappings
                ->reject(fn (PaymentProviderOfferMapping $mapping): bool => $primary !== null && $mapping->getKey() === $primary->getKey())
                ->map(fn (PaymentProviderOfferMapping $mapping): array => [
                    'currency' => $mapping->currency->value,
                    'offer_id' => (string) $mapping->external_offer_id,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $data @return array{enabled: bool, mappings: list<array{currency: mixed, offer_id: mixed}>} */
    private function mappingData(array $data): array
    {
        $enabled = (bool) ($data['lava_enabled'] ?? false);
        $mappings = [];

        if ($enabled) {
            $mappings[] = [
                'currency' => $data['price_currency'] ?? null,
                'offer_id' => $data['lava_offer_id'] ?? null,
            ];

            foreach ((array) ($data['lava_offers'] ?? []) as $mapping) {
                if (is_array($mapping)) {
                    $mappings[] = $mapping;
                }
            }
        }

        return [
            'enabled' => $enabled,
            'mappings' => $mappings,
        ];
    }
}
