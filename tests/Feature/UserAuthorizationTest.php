<?php

namespace Tests\Feature;

use App\Models\User;
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
}
