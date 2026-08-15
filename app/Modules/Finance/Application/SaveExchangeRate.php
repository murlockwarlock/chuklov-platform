<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\Models\OrganizationExchangeRate;
use App\Modules\Security\Application\RecordAuditEvent;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SaveExchangeRate
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly CurrencyConfigurationService $configuration,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, CurrencyCode|string $sourceCurrency, CurrencyCode|string $targetCurrency, string $rate): OrganizationExchangeRate
    {
        $organization = $this->authorization->authorizeManage($actor);
        try {
            $source = $this->configuration->assertAllowed($organization, $sourceCurrency);
            $target = $this->configuration->assertAllowed($organization, $targetCurrency);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['currency' => 'Валюты курса не включены в настройки организации.']);
        }

        if ($source === $target || preg_match('/^(?:0|[1-9][0-9]{0,19})(?:\.[0-9]{1,18})?$/', $rate) !== 1) {
            throw ValidationException::withMessages(['rate' => 'Курс должен быть положительным числом с точностью до 18 знаков.']);
        }

        try {
            if (BigDecimal::of($rate)->isNegativeOrZero()) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['rate' => 'Курс должен быть положительным числом.']);
        }

        return DB::transaction(function () use ($actor, $organization, $source, $target, $rate): OrganizationExchangeRate {
            $currentConfiguration = OrganizationCurrencyConfiguration::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = OrganizationExchangeRate::query()
                ->where('organization_id', $organization->getKey())
                ->where('source_currency', $source->value)
                ->where('target_currency', $target->value)
                ->lockForUpdate()
                ->first();
            $version = $existing === null ? 1 : $existing->version + 1;

            DB::table('organization_exchange_rates')->updateOrInsert(
                [
                    'organization_id' => $organization->getKey(),
                    'source_currency' => $source->value,
                    'target_currency' => $target->value,
                ],
                [
                    'rate' => $rate,
                    'version' => $version,
                    'effective_at' => now(),
                    'created_by_user_id' => $actor->getKey(),
                    'updated_at' => now(),
                    'created_at' => $existing === null ? now() : $existing->created_at,
                ],
            );

            $rates = [];

            foreach (OrganizationExchangeRate::query()
                ->where('organization_id', $organization->getKey())
                ->get(['source_currency', 'target_currency', 'rate']) as $savedRate) {
                $savedSource = $this->configuration->assertAllowed($organization, $savedRate->source_currency);
                $savedTarget = $this->configuration->assertAllowed($organization, $savedRate->target_currency);
                $rates[$this->configuration->rateKey($savedSource, $savedTarget)] = (string) $savedRate->getRawOriginal('rate');
            }
            $this->configuration->assertConfiguration(
                organization: $organization,
                base: $currentConfiguration->base_currency,
                display: $currentConfiguration->display_currency,
                forceSingle: $currentConfiguration->force_single_currency,
                allowed: $this->configuration->allowedCurrencies($organization),
                rates: $rates,
            );

            $saved = OrganizationExchangeRate::query()
                ->where('organization_id', $organization->getKey())
                ->where('source_currency', $source->value)
                ->where('target_currency', $target->value)
                ->firstOrFail();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'organization.finance.rate.updated',
                targetType: OrganizationExchangeRate::class,
                targetId: (string) $saved->getKey(),
                metadata: [
                    'source_currency' => $source->value,
                    'target_currency' => $target->value,
                    'rate_version' => $version,
                ],
            );

            return $saved;
        });
    }
}
