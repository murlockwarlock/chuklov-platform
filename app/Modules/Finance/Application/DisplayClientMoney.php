<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class DisplayClientMoney
{
    public function __construct(private CurrencyConfigurationService $currencies, private OrganizationContext $context) {}

    /** @return array{amountMinor: int, currency: string, conversionAvailable: bool} */
    public function handle(Client $client, Money $money): array
    {
        return $this->handleMany($client, [$money])[0];
    }

    /**
     * @param  list<Money>  $amounts
     * @return list<array{amountMinor: int, currency: string, conversionAvailable: bool}>
     */
    public function handleMany(Client $client, array $amounts): array
    {
        if ((int) $client->organization_id !== $this->context->id()) {
            throw new AuthorizationException;
        }
        $configuration = OrganizationCurrencyConfiguration::query()->where('organization_id', $client->organization_id)->first();
        $preferred = $client->getAttribute('preferred_currency');
        $quotes = [];
        $result = [];
        foreach ($amounts as $money) {
            $target = $configuration !== null && ! $configuration->force_single_currency && is_string($preferred)
                ? $preferred : $money->currency();
            $key = $money->currency()->value;
            try {
                if (! array_key_exists($key, $quotes)) {
                    try {
                        $quotes[$key] = $this->currencies->convert($client->organization_id, $money, $target);
                    } catch (InvalidArgumentException|ModelNotFoundException|UnexpectedValueException) {
                        $quotes[$key] = null;
                    }
                }
                $quote = $quotes[$key];
                if ($quote === null) {
                    throw new InvalidArgumentException('A display conversion is unavailable.');
                }
                $display = $money->convert($quote->targetCurrency, $quote->rate, $quote->roundingMode);
                $result[] = ['amountMinor' => $display->minorUnits(), 'currency' => $display->currency()->value, 'conversionAvailable' => true];
            } catch (InvalidArgumentException|UnexpectedValueException) {
                $result[] = ['amountMinor' => $money->minorUnits(), 'currency' => $money->currency()->value, 'conversionAvailable' => false];
            }
        }

        return $result;
    }
}
