<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\InteractsWithFormValidationFeedback;
use App\Filament\Support\LocalizedCreateRecord;
use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\SavePaymentProviderOfferMappings;
use App\Modules\Services\Application\CreateService as CreateServiceAction;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateService extends LocalizedCreateRecord
{
    use InteractsWithFormValidationFeedback;

    protected static string $resource = ServiceResource::class;

    protected static ?string $title = 'Добавить услугу';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $formState = $data;
        $mapping = $this->mappingData($data);
        unset($data['lava_enabled'], $data['lava_offer_id']);

        try {
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
        } catch (ValidationException $exception) {
            throw $this->formValidationFailure($exception, $formState);
        } catch (Throwable $exception) {
            throw $this->unexpectedFormFailure($exception, $formState);
        }
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
