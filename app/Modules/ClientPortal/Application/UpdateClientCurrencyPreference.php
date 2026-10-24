<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Application\SetClientCurrencyPreference;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class UpdateClientCurrencyPreference
{
    public function __construct(
        private ClientPortalContext $clientContext,
        private OrganizationContext $organizations,
        private CurrencyConfigurationService $currencies,
        private SetClientCurrencyPreference $preferences,
    ) {}

    public function handle(?string $currency): void
    {
        $client = $this->clientContext->client();
        $organization = $this->organizations->organization();
        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
        $selected = null;
        if ($currency !== null) {
            try {
                $configuration = $this->currencies->configuration($organization);
                $selected = $this->currencies->assertAllowed($organization, $currency);
                $this->currencies->convert($organization, Money::zero($configuration->base_currency), $selected);
            } catch (InvalidArgumentException|ModelNotFoundException) {
                throw ValidationException::withMessages(['preferred_currency' => __('Валюта или её курс недоступны. Выберите другую валюту.')]);
            }
        }
        $this->preferences->handle($client, $selected?->value);
    }
}
