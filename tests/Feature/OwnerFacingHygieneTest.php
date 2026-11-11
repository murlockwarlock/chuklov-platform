<?php

namespace Tests\Feature;

use App\Filament\Pages\AnalyticsTaxonomyGroups;
use App\Filament\Pages\MedicalAudioSettings;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class OwnerFacingHygieneTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_configuration_uses_human_examples_and_business_facing_limits(): void
    {
        [$organization, $admin] = $this->fixture();
        app(OrganizationContext::class)->set($organization);

        Livewire::actingAs($admin)
            ->test(AnalyticsTaxonomyGroups::class)
            ->assertSee('Опорно-двигательная система')
            ->assertDontSee('QA synthetic')
            ->assertDontSee('Synthetic group');

        Livewire::actingAs($admin)
            ->test(MedicalAudioSettings::class)
            ->assertSee('Изменения применяются сразу. Максимальный размер ограничен настройками системы.')
            ->assertSee('Не больше 64 МБ.')
            ->assertDontSee('hard cap')
            ->assertDontSee('Серверного');
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return [$organization, $admin];
    }
}
