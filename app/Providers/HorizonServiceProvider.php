<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            $organizationId = config('tenancy.default_organization_id');

            if (! $user instanceof User || ! is_numeric($organizationId)) {
                return false;
            }

            $organization = Organization::query()->find((int) $organizationId);

            return $organization instanceof Organization
                && $user->hasPermission(OrganizationPermission::ViewHorizon, $organization);
        });
    }
}
