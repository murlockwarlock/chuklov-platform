<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_privileged_membership_roles_can_access_the_panel(): void
    {
        $panel = Filament::getPanel('admin');
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $owner = User::factory()->forOrganization($organization, OrganizationRole::Owner)->create();
        $regularUser = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $nonMember = User::factory()->create();

        self::assertTrue($admin->canAccessPanel($panel));
        self::assertTrue($owner->canAccessPanel($panel));
        self::assertFalse($regularUser->canAccessPanel($panel));
        self::assertFalse($nonMember->canAccessPanel($panel));
    }

    public function test_organization_and_role_fields_are_not_mass_assignable_on_user(): void
    {
        $user = new User;
        $user->fill([
            'name' => 'Allowed Name',
            'organization_id' => 999,
            'is_admin' => true,
        ]);

        self::assertSame('Allowed Name', $user->name);
        self::assertArrayNotHasKey('organization_id', $user->getAttributes());
        self::assertArrayNotHasKey('is_admin', $user->getAttributes());
        self::assertFalse($user->isFillable('organization_id'));
        self::assertFalse($user->isFillable('is_admin'));
    }

    public function test_membership_relationship_is_unique_per_organization_and_user(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->forOrganization($organization)->create();

        self::assertInstanceOf(OrganizationMembership::class, $user->membershipFor($organization));
        self::assertSame(1, OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->count());
    }
}
