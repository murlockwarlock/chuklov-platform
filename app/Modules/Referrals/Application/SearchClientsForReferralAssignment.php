<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;

final readonly class SearchClientsForReferralAssignment
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private ClientSearch $clients,
    ) {}

    /** @return array<int|string, string> */
    public function handle(User $actor, string $search, int $excludedClientId): array
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        return $this->clients
            ->query($actor, $search)
            ->where('id', '<>', $excludedClientId)
            ->orderBy('full_name')
            ->limit(ClientSearch::MAX_RESULTS)
            ->get(['id', 'full_name', 'email', 'phone'])
            ->mapWithKeys(static fn (Client $client): array => [
                $client->getKey() => self::formatLabel($client),
            ])
            ->all();
    }

    public function optionLabel(User $actor, mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        $client = Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey((int) $value)
            ->first();

        return $client instanceof Client ? self::formatLabel($client) : null;
    }

    private static function formatLabel(Client $client): string
    {
        $name = trim((string) $client->full_name);
        $contacts = array_filter([
            trim((string) $client->email),
            trim((string) $client->phone),
        ]);

        return $name !== ''
            ? $name.(($contacts === []) ? '' : ' · '.implode(' · ', $contacts))
            : ($contacts === [] ? 'Клиент без имени' : implode(' · ', $contacts));
    }
}
