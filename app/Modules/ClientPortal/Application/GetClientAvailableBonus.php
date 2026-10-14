<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Application\ReferralRewardBalanceProjection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final class GetClientAvailableBonus
{
    /** @var array<string, array{value: ?string, currencyUnavailable: bool}> */
    private array $resolved = [];

    public function __construct(
        private readonly ReferralRewardBalanceProjection $balances,
        private readonly CurrencyConfigurationService $currencies,
        private readonly OrganizationContext $organizations,
    ) {}

    public function handle(Client $client): ?string
    {
        return $this->projection($client)['value'];
    }

    public function currencyUnavailable(Client $client): bool
    {
        return $this->projection($client)['currencyUnavailable'];
    }

    /** @return array{value: ?string, currencyUnavailable: bool} */
    private function projection(Client $client): array
    {
        if ((int) $client->organization_id !== $this->organizations->id()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
        $key = $client->organization_id.':'.$client->getKey().':'.$client->getAttribute('preferred_currency');
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }
        $configuration = OrganizationCurrencyConfiguration::query()->where('organization_id', $client->organization_id)->first();
        if ($configuration === null) {
            return $this->resolved[$key] = ['value' => null, 'currencyUnavailable' => false];
        }
        $balance = $this->balances->serviceCredit($client);
        $available = $balance->available();
        if (! $available->isPositive()) {
            return $this->resolved[$key] = ['value' => null, 'currencyUnavailable' => false];
        }
        $preferred = $client->getAttribute('preferred_currency');
        $target = ! $configuration->force_single_currency && is_string($preferred) ? $preferred : $balance->currency;
        try {
            $conversion = $this->currencies->convert($client->organization_id, $available, $target);
            $display = Money::ofMinor($conversion->targetAmountMinor, $conversion->targetCurrency);

            return $this->resolved[$key] = ['value' => $display->toDecimalString().' '.$display->currency()->value, 'currencyUnavailable' => false];
        } catch (InvalidArgumentException|ModelNotFoundException) {
            return $this->resolved[$key] = ['value' => $available->toDecimalString().' '.$balance->currency->value, 'currencyUnavailable' => true];
        }
    }
}
