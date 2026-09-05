<?php

namespace Tests\Feature;

use App\Filament\Resources\ReferralRelationships\Pages\ListReferralRelationships;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Enums\ReferralEstablishmentMethod;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ReferralRelationshipCrmRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_list_renders_persisted_enum_state(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $referrer = Client::factory()->forOrganization($organization)->create(['full_name' => 'Партнёр']);
        $referred = Client::factory()->forOrganization($organization)->create(['full_name' => 'Клиент']);
        $relationship = new ReferralRelationship;
        $relationship->forceFill([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $referrer->getKey(),
            'referred_client_id' => $referred->getKey(),
            'establishment_method' => ReferralEstablishmentMethod::AutomaticReferralLink,
            'registered_at' => now(),
        ]);
        $relationship->save();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        Livewire::actingAs($admin)
            ->test(ListReferralRelationships::class)
            ->assertSuccessful()
            ->assertSee('Автоматическая ссылка');
    }
}
