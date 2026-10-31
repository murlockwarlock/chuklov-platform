<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\AnalyticsMetricSettings;
use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsMetricConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class AnalyticsMetricConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_metric_page_exposes_safe_metrics_without_presenting_text_as_an_executable_formula(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);

        $configuration = app(AnalyticsMetricConfiguration::class);
        self::assertFalse($configuration->values()['therapy_effectiveness']['configured']);
        self::assertFalse($configuration->values()['ltv_percent']['configured']);

        $configuration->save($admin, [
            'therapy_effectiveness_formula_reference' => 'Утверждённая формула снижения боли после курса.',
            'ltv_percent_formula_reference' => 'Утверждённая база сравнения LTV и период расчёта.',
        ]);

        Livewire::actingAs($admin)
            ->test(AnalyticsMetricSettings::class)
            ->assertSuccessful()
            ->assertSee('Снижение боли, %')
            ->assertSee('LTV новых клиентов')
            ->assertDontSee('Описание формулы владельца')
            ->assertDontSee('Описание формул сохранено');

        $values = $configuration->values();
        self::assertSame('Утверждённая формула снижения боли после курса.', $values['therapy_effectiveness']['formula_reference']);
        self::assertSame('Утверждённая база сравнения LTV и период расчёта.', $values['ltv_percent']['formula_reference']);
        self::assertTrue($values['therapy_effectiveness']['configured']);
        self::assertTrue($values['ltv_percent']['configured']);
        self::assertDatabaseHas('organization_settings', [
            'organization_id' => $organization->getKey(),
            'setting_key' => OrganizationSettingKey::AnalyticsLtvPercentFormulaReference->value,
            'string_value' => 'Утверждённая база сравнения LTV и период расчёта.',
        ]);
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return [$organization, $admin];
    }
}
