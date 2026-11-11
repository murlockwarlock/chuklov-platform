<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\LocalizedEditRecord;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\SavePaymentProviderOfferMappings;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Services\Application\ServiceSnapshotHasher;
use App\Modules\Services\Application\SetServiceActive;
use App\Modules\Services\Application\UpdateService;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Services\Domain\ValueObjects\ServicePriceMatrix;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditService extends LocalizedEditRecord
{
    protected static string $resource = ServiceResource::class;

    protected static ?string $title = 'Редактировать услугу';

    protected function getFormActions(): array
    {
        return [
            $this->getLifecycleAction(),
            ...parent::getFormActions(),
        ];
    }

    private function getLifecycleAction(): Action
    {
        $record = $this->getRecord();

        if (! $record instanceof Service) {
            return Action::make('lifecycle')->hidden();
        }

        if (! $record->is_active) {
            return Action::make('restore')
                ->label(__('Вернуть из архива'))
                ->color('success')
                ->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()
                ->modalHeading(__('Вернуть услугу из архива?'))
                ->modalDescription(__('Услуга снова станет доступна для новых записей и клиентского каталога.'))
                ->schema([
                    ...ScheduleImpactPreview::components(),
                ])
                ->fillForm(fn (): array => ScheduleImpactPreview::stateFromImpact(
                    app(ScheduleMutationImpactCalculator::class)->forServiceChange($record, ['is_active' => true]),
                ))
                ->action(function (array $data, Schema $schema): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(SetServiceActive::class)->handle(
                            actor: $actor,
                            service: $record,
                            isActive: true,
                            acknowledgeImpact: (bool) ($data['acknowledge_impact'] ?? false),
                            acknowledgedImpactDigest: isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                        );
                    } catch (ValidationException $exception) {
                        ScheduleImpactPreview::applyValidationPreview($schema, $exception);

                        throw $exception;
                    }

                    $this->redirect(ServiceResource::getUrl('index'));
                });
        }

        return Action::make('archive')
            ->label(__('Архивировать'))
            ->color('danger')
            ->icon('heroicon-o-archive-box')
            ->requiresConfirmation()
            ->modalHeading(__('Архивировать услугу?'))
            ->modalDescription(__('Услуга исчезнет из новых записей и клиентского каталога. История записей и оплат останется без изменений.'))
            ->schema([
                ...ScheduleImpactPreview::components(),
            ])
            ->fillForm(fn (): array => ScheduleImpactPreview::stateFromImpact(
                app(ScheduleMutationImpactCalculator::class)->forServiceChange($record, ['is_active' => false]),
            ))
            ->action(function (array $data, Schema $schema): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    app(SetServiceActive::class)->handle(
                        actor: $actor,
                        service: $record,
                        isActive: false,
                        acknowledgeImpact: (bool) ($data['acknowledge_impact'] ?? false),
                        acknowledgedImpactDigest: isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                    );
                } catch (ValidationException $exception) {
                    ScheduleImpactPreview::applyValidationPreview($schema, $exception);

                    throw $exception;
                }

                $this->redirect(ServiceResource::getUrl('index'));
            });
    }

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

            if (ScheduleImpactPreview::isImpactValidation($exception)) {
                Notification::make()
                    ->title(__('Нужно подтвердить изменение'))
                    ->body(__('Изменение затрагивает будущие записи. Проверьте список ниже и поставьте подтверждение перед сохранением.'))
                    ->warning()
                    ->send();
            }

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
