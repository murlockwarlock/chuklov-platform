<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientProfile;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;

final class GetClientB2bSpecialistAnswer
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(Client $client): ?B2bSpecialistAnswer
    {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $value = BroadcastClientProfile::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->value('b2b_specialist_answer');

        if ($value instanceof B2bSpecialistAnswer) {
            return $value;
        }

        return is_string($value) ? B2bSpecialistAnswer::tryFrom($value) : null;
    }
}
