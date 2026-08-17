<?php

namespace Tests\Feature\AI;

use App\Filament\Pages\AiMonitoringOverview;
use App\Models\User;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

final class AiKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $administrator;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Kill Switch Clinic',
            'slug' => 'kill-switch-clinic',
        ]);
        $this->administrator = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();
        $this->staff = User::factory()->forOrganization($this->organization, OrganizationRole::Staff)->create();
        app(OrganizationContext::class)->set($this->organization);
    }

    public function test_first_authorized_toggle_creates_a_disabled_control_and_next_toggle_reenables_it(): void
    {
        Auth::login($this->administrator);
        $page = app(AiMonitoringOverview::class);

        $this->assertDatabaseMissing('ai_organization_safety_controls', [
            'organization_id' => $this->organization->id,
        ]);

        $page->toggleKillSwitch();
        $this->assertFalse((bool) AiOrganizationSafetyControl::query()->where('organization_id', $this->organization->id)->value('is_ai_globally_enabled'));

        $page->toggleKillSwitch();
        $this->assertTrue((bool) AiOrganizationSafetyControl::query()->where('organization_id', $this->organization->id)->value('is_ai_globally_enabled'));
    }

    public function test_unauthorized_user_cannot_mutate_the_kill_switch(): void
    {
        AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'is_ai_globally_enabled' => true,
        ]);
        Auth::login($this->staff);

        app(AiMonitoringOverview::class)->toggleKillSwitch();

        $this->assertTrue((bool) AiOrganizationSafetyControl::query()->where('organization_id', $this->organization->id)->value('is_ai_globally_enabled'));
    }
}
