<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Infrastructure\Filament\AuditedAppAuthentication;
use Filament\Facades\Filament;

trait AuthenticatesMfaConfiguredAdmin
{
    protected function authenticateConfiguredAdmin(User $admin, Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $provider = app(AuditedAppAuthentication::class);
        if (! $provider->isEnabled($admin)) {
            $provider->saveSecret($admin, $provider->generateSecret());
        }

        $this->get('/admin')->assertOk();
    }
}
