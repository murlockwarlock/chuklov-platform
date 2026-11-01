<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\AnalyticsMetricSettings;
use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsMetricConfiguration;
use App\Modules\Analytics\Application\ClientSessionAxisCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
        self::assertFalse($configuration->metrics()->firstWhere('stable_key', 'pain_reduction_percent')?->active);
        self::assertFalse($configuration->metrics()->firstWhere('stable_key', 'ltv_change_percent')?->active);
        self::assertFalse($configuration->values()['therapy_effectiveness']['configured']);
        self::assertFalse($configuration->values()['ltv_percent']['configured']);

        $configuration->save($admin, [
            'therapy_effectiveness_formula_reference' => 'Утверждённая формула снижения боли после курса.',
            'ltv_percent_formula_reference' => 'Утверждённая база сравнения LTV и период расчёта.',
        ]);

        Livewire::actingAs($admin)
            ->test(new AnalyticsMetricSettings)
            ->assertSuccessful()
            ->assertSee('Снижение боли, %')
            ->assertSee('LTV новых клиентов')
            ->assertSee('Определение процента LTV')
            ->assertSee('Определение процента эффективности терапии');

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

    public function test_session_axis_setting_defaults_to_problem_order_and_preserves_explicit_owner_choice(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);

        $component = Livewire::actingAs($admin)
            ->test(new AnalyticsMetricSettings)
            ->assertSuccessful()
            ->assertSee('Порядок всех сеансов клиента')
            ->assertSee('Порядок сеансов внутри выбранного периода')
            ->assertSee('Порядок сеансов конкретной категории проблемы')
            ->assertDontSeeHtml('>problem_chronological<');

        $defaultData = $component->instance()->data;
        self::assertIsArray($defaultData);
        self::assertSame(ClientSessionAxisCatalog::ProblemChronological, $defaultData['session_axis_semantics']);

        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::AnalyticsSessionAxisSemantics,
            ClientSessionAxisCatalog::Chronological,
        );

        $explicit = Livewire::actingAs($admin)
            ->test(new AnalyticsMetricSettings)
            ->assertSuccessful();

        $explicitData = $explicit->instance()->data;
        self::assertIsArray($explicitData);
        self::assertSame(ClientSessionAxisCatalog::Chronological, $explicitData['session_axis_semantics']);
    }

    public function test_ltv_percentage_cannot_be_activated_without_an_owner_definition(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        $configuration = app(AnalyticsMetricConfiguration::class);
        $metrics = array_map(static function (array $metric): array {
            if ($metric['stable_key'] === 'ltv_change_percent') {
                $metric['active'] = true;
            }

            return $metric;
        }, $configuration->formValues());

        $this->expectException(ValidationException::class);
        $configuration->saveMetrics($admin, $metrics);
    }

    public function test_therapy_percentage_cannot_be_activated_without_an_owner_definition(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        $configuration = app(AnalyticsMetricConfiguration::class);
        $metrics = array_map(static function (array $metric): array {
            if ($metric['stable_key'] === 'pain_reduction_percent') {
                $metric['active'] = true;
            }

            return $metric;
        }, $configuration->formValues());

        $this->expectException(ValidationException::class);
        $configuration->saveMetrics($admin, $metrics);
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
