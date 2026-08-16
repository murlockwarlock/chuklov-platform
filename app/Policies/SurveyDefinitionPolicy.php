<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;

final class SurveyDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return app(OrganizationAuthorizer::class)->allows($user, app(OrganizationContext::class)->organization(), OrganizationPermission::ViewSurveys);
    }

    public function view(User $user, SurveyDefinition $definition): bool
    {
        return $this->viewAny($user) && (int) $definition->organization_id === app(OrganizationContext::class)->id();
    }

    public function create(User $user): bool
    {
        return app(OrganizationAuthorizer::class)->allows($user, app(OrganizationContext::class)->organization(), OrganizationPermission::ManageSurveys);
    }

    public function update(User $user, SurveyDefinition $definition): bool
    {
        return $this->create($user) && (int) $definition->organization_id === app(OrganizationContext::class)->id();
    }
}
