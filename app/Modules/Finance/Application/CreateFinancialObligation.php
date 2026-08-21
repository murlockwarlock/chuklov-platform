<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateFinancialObligation
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly CurrencyConfigurationService $configuration,
        private readonly CurrencyCatalog $catalog,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Booking $booking, ?string $causationId = null): ?FinancialObligation
    {
        $organization = $this->context->organization();
        $booking->loadMissing(['client', 'service']);

        if ((int) $booking->organization_id !== (int) $organization->getKey()) {
            throw ValidationException::withMessages(['booking' => 'Запись не относится к текущей организации.']);
        }

        if ($booking->status !== BookingStatus::Completed) {
            throw ValidationException::withMessages(['booking' => 'Финансовое обязательство создаётся только после завершённого визита.']);
        }

        $service = $booking->service;
        $priceMinor = $service->price_minor;

        if ($priceMinor === null || $priceMinor <= 0) {
            return null;
        }

        $priceCurrency = $this->catalog->code((string) $service->price_currency);
        $this->configuration->assertAllowed($organization, $priceCurrency);
        $price = Money::ofMinor($priceMinor, $priceCurrency);
        $currencyConfiguration = $this->configuration->configuration($organization);
        $baseSnapshot = $this->configuration->convert($organization, $price, $currencyConfiguration->base_currency);
        $displaySnapshot = $this->configuration->convert($organization, $price, $currencyConfiguration->display_currency);
        $creationKey = 'booking.completed:'.$organization->getKey().':'.$booking->getKey();

        return DB::transaction(function () use ($actor, $booking, $organization, $service, $price, $priceCurrency, $baseSnapshot, $displaySnapshot, $creationKey, $causationId): FinancialObligation {
            $existing = FinancialObligation::query()
                ->where('organization_id', $organization->getKey())
                ->where('booking_id', $booking->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $attributes = [
                'organization_id' => $organization->getKey(),
                'client_id' => $booking->client_id,
                'booking_id' => $booking->getKey(),
                'service_id' => $service->getKey(),
                'amount_minor' => $price->minorUnits(),
                'currency' => $priceCurrency->value,
                'base_amount_minor' => (int) $baseSnapshot->targetAmountMinor,
                'base_currency' => $baseSnapshot->targetCurrency->value,
                'display_amount_minor' => (int) $displaySnapshot->targetAmountMinor,
                'display_currency' => $displaySnapshot->targetCurrency->value,
                'payment_amount_minor' => $price->minorUnits(),
                'payment_currency' => $priceCurrency->value,
                'settlement_amount_minor' => $price->minorUnits(),
                'settlement_currency' => $priceCurrency->value,
                'price_snapshot' => json_encode([
                    'service_id' => (int) $service->getKey(),
                    'service_name' => (string) $service->name,
                    'amount_minor' => $price->minorUnitsString(),
                    'currency' => $priceCurrency->value,
                    'payment_policy' => $service->payment_policy,
                    'captured_at' => CarbonImmutable::now()->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'conversion_snapshots' => json_encode([
                    'base' => $baseSnapshot->toArray(),
                    'display' => $displaySnapshot->toArray(),
                ], JSON_THROW_ON_ERROR),
                'creation_key' => $creationKey,
                'created_by_user_id' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $inserted = DB::table('financial_obligations')->insertOrIgnore($attributes);
            $obligation = FinancialObligation::query()
                ->where('organization_id', $organization->getKey())
                ->where('creation_key', $creationKey)
                ->firstOrFail();

            if ($inserted === 0) {
                return $obligation;
            }

            $this->scenarioEvents->financialObligationCreated(
                obligation: $obligation,
                causationId: $causationId,
                occurredAt: CarbonImmutable::now(),
            );
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'finance.obligation.created',
                targetType: FinancialObligation::class,
                targetId: (string) $obligation->getKey(),
                metadata: [
                    'source' => 'booking.completed',
                    'currency' => $priceCurrency->value,
                ],
            );

            return $obligation->refresh();
        });
    }
}
