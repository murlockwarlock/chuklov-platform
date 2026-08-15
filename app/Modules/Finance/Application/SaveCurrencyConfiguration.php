<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SaveCurrencyConfiguration
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly CurrencyCatalog $catalog,
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
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['currency' => 'Валютные настройки указаны неверно.']);
        }

        if ($allowed === [] || ! in_array($base, $allowed, true) || ! in_array($display, $allowed, true)) {
            throw ValidationException::withMessages(['allowed_currencies' => 'Выберите базовую и отображаемую валюты среди доступных.']);
        }

        if ($forceSingle && ($base !== $display || $allowed !== [$base])) {
            throw ValidationException::withMessages(['force_single_currency' => 'При одномерном режиме должна быть выбрана только базовая валюта.']);
        }

        return DB::transaction(function () use ($actor, $organization, $base, $display, $forceSingle, $rounding, $allowed): OrganizationCurrencyConfiguration {
            $configuration = OrganizationCurrencyConfiguration::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->first();
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
                ],
            );

            return $configuration->refresh();
        });
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
}
