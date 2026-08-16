<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;

final class SurveyAttemptPolicy
{
    public function viewAny(User $user): bool
    {
        return app(OrganizationAuthorizer::class)->allows($user, app(OrganizationContext::class)->organization(), OrganizationPermission::ViewSurveys);
    }

    public function view(User $user, SurveyAttempt $attempt): bool
    {
        return $this->viewAny($user) && (int) $attempt->organization_id === app(OrganizationContext::class)->id();
    }
}
