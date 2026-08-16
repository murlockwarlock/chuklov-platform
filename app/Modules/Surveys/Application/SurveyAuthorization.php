<?php

namespace App\Modules\Surveys\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use Illuminate\Auth\Access\AuthorizationException;

final class SurveyAuthorization
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function manage(User $actor): Organization
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSurveys);

        return $organization;
    }

    public function view(User $actor): Organization
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewSurveys);

        return $organization;
    }

    public function assertDefinition(SurveyDefinition $definition): void
    {
        if ((int) $definition->organization_id !== $this->context->id()) {
            throw new AuthorizationException('The survey is outside the current organization.');
        }
    }

    public function assertClient(Client $client): void
    {
        if ((int) $client->organization_id !== $this->context->id()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
    }

    public function assertAttemptOwner(Client $client, SurveyAttempt $attempt): void
    {
        if ((int) $attempt->organization_id !== $this->context->id() || (int) $attempt->client_id !== (int) $client->getKey()) {
            throw new AuthorizationException('The survey attempt is not available.');
        }
    }
}
