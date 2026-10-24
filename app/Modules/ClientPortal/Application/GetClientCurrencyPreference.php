<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class GetClientCurrencyPreference
{
    public function __construct(private CurrencyConfigurationService $currencies, private OrganizationContext $organizations) {}

    /** @return array{selected: ?string, base: ?string, options: list<string>} */
    public function handle(Client $client): array
    {
        if ((int) $client->organization_id !== $this->organizations->id()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
        $configuration = OrganizationCurrencyConfiguration::query()->where('organization_id', $client->organization_id)->first();
        $selected = $client->getAttribute('preferred_currency');

        return [
            'selected' => is_string($selected) ? $selected : null,
            'base' => $configuration?->base_currency->value,
            'options' => $configuration === null ? [] : array_map(static fn (CurrencyCode $currency): string => $currency->value,
                $configuration->force_single_currency ? [$configuration->base_currency] : $this->currencies->allowedCurrencies($client->organization_id)),
        ];
    }
}
