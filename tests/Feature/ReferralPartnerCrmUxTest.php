<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\ReferralPartnerProfiles\Pages\ListReferralPartnerProfiles;
use App\Filament\Resources\ReferralPartnerProfiles\Pages\ViewReferralPartnerProfile;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Application\ActivateReferralPartner;
use App\Modules\Referrals\Domain\Models\ReferralPartnerProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ReferralPartnerCrmUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_partner_list_is_first_class_and_shows_partner_metrics(): void
    {
        $organization = $this->organization();
        $admin = User::factory()->forOrganization($organization)->create();
        $partner = Client::factory()->forOrganization($organization)->create(['full_name' => 'Алина Партнёр']);
        app(ActivateReferralPartner::class)->handle($partner, 'crm', $admin);
        $this->filament($admin, $organization);

        Livewire::actingAs($admin)
            ->test(ListReferralPartnerProfiles::class)
            ->assertSuccessful()
            ->assertTableColumnExists('client.full_name')
            ->assertTableColumnExists('visits_count')
            ->assertTableColumnExists('registrations_count')
            ->assertTableColumnExists('paid_clients_count')
            ->assertTableColumnExists('available_summary')
            ->assertTableColumnExists('pending_summary')
            ->assertTableColumnExists('paid_summary')
            ->assertSee('wire:poll.5s', false)
            ->assertSee('Алина Партнёр');
    }

    public function test_client_page_exposes_partner_activation_as_a_primary_action(): void
    {
        $organization = $this->organization();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Будущий партнёр']);
        $this->filament($admin, $organization);

        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertSuccessful()
            ->assertActionExists('activatePartner')
            ->assertSee('Сделать партнёром')
            ->assertDontSee('Назначить реферера');
    }

    public function test_partner_workspace_renders_campaigns_and_operational_sections(): void
    {
        $organization = $this->organization();
        $admin = User::factory()->forOrganization($organization)->create();
        $partner = Client::factory()->forOrganization($organization)->create(['full_name' => 'Рабочий партнёр']);
        $profile = app(ActivateReferralPartner::class)->handle($partner, 'crm', $admin);
        $this->filament($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(ViewReferralPartnerProfile::class, ['record' => $profile->getKey()])
            ->assertSuccessful()
            ->assertSee('Партнёрский кабинет')
            ->assertSee('Результаты и баланс')
            ->assertSee('Личные рекомендации')
            ->assertSee('Название')
            ->assertSee('Переходы')
            ->assertSee('Регистраций пока нет')
            ->assertSee('История начислений')
            ->assertSee('История выплат')
            ->assertActionExists('createCampaignLink')
            ->assertActionExists('deactivatePartner')
            ->assertSee('wire:poll.5s.visible="refreshWorkspace"', false);

        self::assertSame('—', $component->instance()->workspaceLinkItems()[0]['rewards']);
    }

    public function test_partner_list_is_scoped_to_the_current_organization(): void
    {
        $organization = $this->organization();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $currentPartner = Client::factory()->forOrganization($organization)->create(['full_name' => 'Текущий партнёр']);
        $foreignPartner = Client::factory()->forOrganization($otherOrganization)->create(['full_name' => 'Чужой партнёр']);
        app(ActivateReferralPartner::class)->handle($currentPartner, 'crm', $admin);
        config()->set('tenancy.default_organization_id', $otherOrganization->getKey());
        app(OrganizationContext::class)->set($otherOrganization);
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();
        app(ActivateReferralPartner::class)->handle($foreignPartner, 'crm', $otherAdmin);
        $this->filament($admin, $organization);

        $component = Livewire::actingAs($admin)->test(ListReferralPartnerProfiles::class);

        $component->assertSee('Текущий партнёр')->assertDontSee('Чужой партнёр');
        self::assertSame(1, ReferralPartnerProfile::query()->where('organization_id', $organization->getKey())->count());
    }

    private function organization(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        app(OrganizationContext::class)->set($organization);

        return $organization;
    }

    private function filament(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }
}
