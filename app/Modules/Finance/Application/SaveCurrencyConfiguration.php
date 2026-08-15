<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\Models\OrganizationExchangeRate;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SaveCurrencyConfiguration
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly CurrencyCatalog $catalog,
        private readonly CurrencyConfigurationService $configuration,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): OrganizationCurrencyConfiguration
    {
        $organization = $this->authorization->authorizeManage($actor);
        try {
            $base = $this->catalog->code($data['base_currency'] ?? null);
            $display = $this->catalog->code($data['display_currency'] ?? null);
            $forceSingle = filter_var($data['force_single_currency'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $rounding = FinancialRoundingMode::fromMixed($data['rounding_mode'] ?? FinancialRoundingMode::HalfUp->value);
            $allowed = $this->currencies($data['allowed_currencies'] ?? []);
            $submittedRates = $this->rates($data['rates'] ?? [], $allowed);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['currency' => 'Валютные настройки указаны неверно.']);
        }

        try {
            return DB::transaction(function () use ($actor, $organization, $base, $display, $forceSingle, $rounding, $allowed, $submittedRates): OrganizationCurrencyConfiguration {
                $configuration = OrganizationCurrencyConfiguration::query()
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->first();
                $existingRates = $this->existingRates((int) $organization->getKey());
                $rates = $existingRates;

                foreach ($submittedRates as $rate) {
                    $rates[$this->configuration->rateKey($rate['source'], $rate['target'])] = $rate['rate'];
                }
                $this->configuration->assertConfiguration(
                    organization: $organization,
                    base: $base,
                    display: $display,
                    forceSingle: $forceSingle,
                    allowed: $allowed,
                    rates: $rates,
                );
                $version = $configuration === null ? 1 : $configuration->version + 1;

                DB::table('organization_currency_configurations')->updateOrInsert(
                    ['organization_id' => $organization->getKey()],
                    [
                        'base_currency' => $base->value,
                        'display_currency' => $display->value,
                        'force_single_currency' => $forceSingle,
                        'rounding_mode' => $rounding->value,
                        'version' => $version,
                        'updated_at' => now(),
                        'created_at' => $configuration === null ? now() : $configuration->created_at,
                    ],
                );
                DB::table('organization_allowed_currencies')
                    ->where('organization_id', $organization->getKey())
                    ->delete();

                $timestamp = now();
                DB::table('organization_allowed_currencies')->insert(array_map(
                    static fn (CurrencyCode $currency): array => [
                        'organization_id' => $organization->getKey(),
                        'currency' => $currency->value,
                        'created_at' => $timestamp,
                    ],
                    $allowed,
                ));

                foreach ($submittedRates as $rate) {
                    $existing = OrganizationExchangeRate::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('source_currency', $rate['source']->value)
                        ->where('target_currency', $rate['target']->value)
                        ->lockForUpdate()
                        ->first();
                    $rateVersion = $existing === null ? 1 : $existing->version + 1;

                    DB::table('organization_exchange_rates')->updateOrInsert(
                        [
                            'organization_id' => $organization->getKey(),
                            'source_currency' => $rate['source']->value,
                            'target_currency' => $rate['target']->value,
                        ],
                        [
                            'rate' => $rate['rate'],
                            'version' => $rateVersion,
                            'effective_at' => now(),
                            'created_by_user_id' => $actor->getKey(),
                            'updated_at' => now(),
                            'created_at' => $existing === null ? now() : $existing->created_at,
                        ],
                    );
                }

                $configuration = OrganizationCurrencyConfiguration::query()
                    ->where('organization_id', $organization->getKey())
                    ->firstOrFail();

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'organization.finance.currency.updated',
                    targetType: Organization::class,
                    targetId: (string) $organization->getKey(),
                    metadata: [
                        'base_currency' => $base->value,
                        'display_currency' => $display->value,
                        'force_single_currency' => $forceSingle,
                        'rounding_mode' => $rounding->value,
                        'allowed_count' => count($allowed),
                        'rate_count' => count($submittedRates),
                    ],
                );

                return $configuration->refresh();
            });
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['currency' => 'Валютные настройки указаны неверно.']);
        }
    }

    /** @return list<CurrencyCode> */
    private function currencies(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw ValidationException::withMessages(['allowed_currencies' => 'Список валют указан неверно.']);
        }

        $currencies = [];

        foreach ($value as $item) {
            $currency = $this->catalog->code($item);

            if (! in_array($currency, $currencies, true)) {
                $currencies[] = $currency;
            }
        }

        usort($currencies, static fn (CurrencyCode $left, CurrencyCode $right): int => $left->value <=> $right->value);

        return $currencies;
    }

    /**
     * @param  list<CurrencyCode>  $allowed
     * @return list<array{source: CurrencyCode, target: CurrencyCode, rate: string}>
     */
    private function rates(mixed $value, array $allowed): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw ValidationException::withMessages(['rates' => 'Список курсов указан неверно.']);
        }

        $rates = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(['rates' => 'Курс указан неверно.']);
            }

            try {
                $source = $this->catalog->code($item['source_currency'] ?? null);
                $target = $this->catalog->code($item['target_currency'] ?? null);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['rates' => 'Валюта курса указана неверно.']);
            }

            $rate = $item['rate'] ?? null;

            if (! is_string($rate) && ! is_int($rate)) {
                throw ValidationException::withMessages(['rates' => 'Курс указан неверно.']);
            }

            $rate = (string) $rate;

            if ($source === $target || ! in_array($source, $allowed, true) || ! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages(['rates' => 'Курс должен связывать две доступные разные валюты.']);
            }

            if (preg_match('/^(?:0|[1-9][0-9]{0,19})(?:\.[0-9]{1,18})?$/', $rate) !== 1) {
                throw ValidationException::withMessages(['rates' => 'Курс должен быть положительным числом с точностью до 18 знаков.']);
            }

            try {
                if (BigDecimal::of($rate)->isNegativeOrZero()) {
                    throw new \RuntimeException;
                }
            } catch (\Throwable) {
                throw ValidationException::withMessages(['rates' => 'Курс должен быть положительным числом.']);
            }

            $key = $this->configuration->rateKey($source, $target);

            if (array_key_exists($key, $rates)) {
                throw ValidationException::withMessages(['rates' => 'Один и тот же направленный курс нельзя указать дважды.']);
            }

            $rates[$key] = [
                'source' => $source,
                'target' => $target,
                'rate' => $rate,
            ];
        }

        return array_values($rates);
    }

    /** @return array<string, string> */
    private function existingRates(int $organizationId): array
    {
        $rates = [];

        foreach (OrganizationExchangeRate::query()
            ->where('organization_id', $organizationId)
            ->get(['source_currency', 'target_currency', 'rate']) as $rate) {
            $source = $this->catalog->code($rate->source_currency);
            $target = $this->catalog->code($rate->target_currency);
            $rates[$this->configuration->rateKey($source, $target)] = (string) $rate->getRawOriginal('rate');
        }

        return $rates;
    }
}
