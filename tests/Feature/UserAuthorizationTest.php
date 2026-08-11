<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_organization_administrators_can_access_the_panel(): void
    {
        $panel = Filament::getPanel('admin');
        $admin = User::factory()->create();
        $regularUser = User::factory()->create(['is_admin' => false]);

        self::assertTrue($admin->canAccessPanel($panel));
        self::assertFalse($regularUser->canAccessPanel($panel));
    }

    public function test_privileged_fields_are_not_mass_assignable(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = new User;
        $user->forceFill([
            'organization_id' => $organization->id,
            'is_admin' => false,
        ]);

        $user->fill([
            'name' => 'Allowed Name',
            'organization_id' => $otherOrganization->id,
            'is_admin' => true,
        ]);

        self::assertSame('Allowed Name', $user->name);
        self::assertSame($organization->id, $user->organization_id);
        self::assertFalse($user->is_admin);
    }
}
