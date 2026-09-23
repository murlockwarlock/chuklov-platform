<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SavePaymentProviderOfferMappings
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly CurrencyConfigurationService $currencies,
    ) {}

    /** @param array<array-key, mixed> $mappings */
    public function handle(
        User $actor,
        string $sellableType,
        int $sellableId,
        bool $enabled,
        array $mappings,
    ): void {
        $organization = $this->authorization->authorizeManage($actor);
        $this->assertSellableBelongsToOrganization($organization, $sellableType, $sellableId);
        $normalized = $enabled ? $this->normalizeMappings($organization, $mappings) : [];

        DB::transaction(function () use ($organization, $sellableType, $sellableId, $normalized): void {
            Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = PaymentProviderOfferMapping::query()
                ->where('organization_id', $organization->getKey())
                ->where('gateway', 'lava')
                ->where('sellable_type', $sellableType)
                ->where('sellable_id', $sellableId)
                ->lockForUpdate()
                ->orderByDesc('is_active')
                ->orderByDesc('id')
                ->get()
                ->unique('currency')
                ->keyBy('currency');
            $desiredCurrencies = [];

            foreach ($normalized as $mapping) {
                $desiredCurrencies[] = $mapping['currency'];
                $record = $existing->get($mapping['currency']) ?? new PaymentProviderOfferMapping;
                $record->forceFill([
                    'organization_id' => $organization->getKey(),
                    'gateway' => 'lava',
                    'sellable_type' => $sellableType,
                    'sellable_id' => $sellableId,
                    'currency' => $mapping['currency'],
                    'external_offer_id' => $mapping['offer_id'],
                    'is_active' => true,
                ])->save();
            }

            $query = PaymentProviderOfferMapping::query()
                ->where('organization_id', $organization->getKey())
                ->where('gateway', 'lava')
                ->where('sellable_type', $sellableType)
                ->where('sellable_id', $sellableId);

            if ($desiredCurrencies === []) {
                $query->update(['is_active' => false, 'updated_at' => now()]);
            } else {
                $query->whereNotIn('currency', $desiredCurrencies)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }
        }, attempts: 3);
    }

    private function assertSellableBelongsToOrganization(
        Organization $organization,
        string $sellableType,
        int $sellableId,
    ): void {
        $exists = match ($sellableType) {
            Service::class => Service::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($sellableId)
                ->exists(),
            TrackerPlanVersion::class => TrackerPlanVersion::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($sellableId)
                ->exists(),
            default => false,
        };

        if (! $exists) {
            throw new AuthorizationException('The sellable item is outside the current organization.');
        }
    }

    /** @param array<array-key, mixed> $mappings @return list<array{currency: string, offer_id: string}> */
    private function normalizeMappings(Organization $organization, array $mappings): array
    {
        if (! array_is_list($mappings) || $mappings === []) {
            throw ValidationException::withMessages([
                'lava_offers' => 'Добавьте хотя бы одно предложение Lava.',
            ]);
        }

        $normalized = [];
        $seenCurrencies = [];

        foreach ($mappings as $index => $mapping) {
            if (! is_array($mapping)) {
                throw ValidationException::withMessages([
                    "lava_offers.{$index}" => 'Настройка предложения указана неверно.',
                ]);
            }

            $currency = $mapping['currency'] ?? null;
            $offerId = $mapping['offer_id'] ?? null;

            if (! is_string($currency) || trim($currency) === '') {
                throw ValidationException::withMessages([
                    "lava_offers.{$index}.currency" => 'Выберите валюту.',
                ]);
            }

            try {
                $currency = $this->currencies->assertAllowed($organization, trim($currency))->value;
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages([
                    "lava_offers.{$index}.currency" => 'Валюта не разрешена финансовыми настройками.',
                ]);
            }

            if (! is_string($offerId) || trim($offerId) === '' || ! Str::isUuid(trim($offerId))) {
                throw ValidationException::withMessages([
                    "lava_offers.{$index}.offer_id" => 'Укажите корректный Offer ID Lava.',
                ]);
            }

            if (isset($seenCurrencies[$currency])) {
                throw ValidationException::withMessages([
                    "lava_offers.{$index}.currency" => 'Для одной валюты можно сохранить только один Offer ID.',
                ]);
            }

            $seenCurrencies[$currency] = true;
            $normalized[] = [
                'currency' => $currency,
                'offer_id' => trim($offerId),
            ];
        }

        return array_values($normalized);
    }
}
