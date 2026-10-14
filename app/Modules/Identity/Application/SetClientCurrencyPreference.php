<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SetClientCurrencyPreference
{
    public function __construct(private OrganizationContext $organizations, private RecordAuditEvent $audit) {}

    public function handle(Client $actor, ?string $currency): void
    {
        $organization = $this->organizations->organization();
        if ((int) $actor->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
        if ($currency !== null && preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw ValidationException::withMessages(['preferred_currency' => __('Выберите валюту.')]);
        }
        DB::transaction(function () use ($actor, $organization, $currency): void {
            $record = Client::query()->where('organization_id', $organization->getKey())->whereKey($actor->getKey())
                ->lockForUpdate()->firstOrFail();
            if ($record->getAttribute('preferred_currency') === $currency) {
                return;
            }
            $record->forceFill(['preferred_currency' => $currency])->save();
            $this->audit->handle($organization, null, 'client.display_currency.updated', Client::class, (string) $record->getKey(), [
                'source' => 'portal', 'currency' => $currency,
            ]);
        });
    }
}
