<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\LocalizedCreateRecord;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\SavePaymentProviderOfferMappings;
use App\Modules\Services\Application\CreateService as CreateServiceAction;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Database\Eloquent\Model;

class CreateService extends LocalizedCreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected static ?string $title = 'Добавить услугу';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $mapping = $this->mappingData($data);
        unset($data['lava_enabled'], $data['lava_offer_id']);

        $service = app(CreateServiceAction::class)->handle(
            actor: $actor,
            name: $data,
        );

        if (app(FinanceAuthorization::class)->allowsManage($actor)) {
            app(SavePaymentProviderOfferMappings::class)->handle(
                actor: $actor,
                sellableType: Service::class,
                sellableId: (int) $service->getKey(),
                enabled: $mapping['enabled'],
                mappings: $mapping['mappings'],
            );
        }

        return $service;
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
