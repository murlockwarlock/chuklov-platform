<?php

namespace Tests\Feature;

use App\Filament\Pages\MedicalClinicalCatalogConfiguration;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class MedicalClinicalCatalogConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_configure_tests_values_and_causes_and_session_options_use_them(): void
    {
        [$organization, $admin] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(MedicalClinicalCatalogConfiguration::class)
            ->fillForm([
                'tests' => [[
                    'key' => 'synthetic_balance',
                    'name' => 'Синтетический баланс',
                    'section' => 'Синтетика',
                    'is_active' => true,
                    'sort_order' => 1,
                    'values' => [
                        ['key' => 'normal', 'label' => 'Норма', 'is_abnormal' => false, 'is_active' => true, 'sort_order' => 1],
                        ['key' => 'abnormal', 'label' => 'Отклонение', 'is_abnormal' => true, 'is_active' => true, 'sort_order' => 2],
                    ],
                ]],
                'causes' => [[
                    'name' => 'Синтетическая причина',
                    'group_name' => 'QA',
                    'is_active' => true,
                    'sort_order' => 1,
                ]],
            ])
            ->call('save')
            ->assertNotified('Каталог сохранён');

        $test = MedicalTestDefinition::query()->where('organization_id', $organization->getKey())->sole();
        self::assertSame('Синтетический баланс', $test->name);
        self::assertSame(2, MedicalTestValue::query()->where('medical_test_definition_id', $test->getKey())->count());
        self::assertSame('Синтетическая причина', MedicalCauseDefinition::query()->where('organization_id', $organization->getKey())->sole()->name);

        $reloaded = Livewire::actingAs($admin)->test(MedicalClinicalCatalogConfiguration::class);
        $state = $reloaded->instance()->data;
        $tests = array_values(is_array($state['tests'] ?? null) ? $state['tests'] : []);
        $causes = array_values(is_array($state['causes'] ?? null) ? $state['causes'] : []);
        self::assertSame('Синтетический баланс', $tests[0]['name'] ?? null);
        $values = array_values(is_array($tests[0]['values'] ?? null) ? $tests[0]['values'] : []);
        $abnormal = collect($values)->firstWhere('label', 'Отклонение');
        self::assertTrue((bool) ($abnormal['is_abnormal'] ?? false));
        self::assertSame('Синтетическая причина', $causes[0]['name'] ?? null);
    }

    /** @return array{Organization, User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function resolveFilamentContext(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }
}
