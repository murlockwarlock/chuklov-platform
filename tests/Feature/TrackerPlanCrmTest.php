<?php

namespace Tests\Feature;

use App\Filament\Pages\TrackerSettings;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\LocationDays\LocationDayResource;
use App\Filament\Resources\TrackerPlans\Pages\CreateTrackerPlan;
use App\Filament\Resources\TrackerPlans\TrackerPlanResource;
use App\Filament\Resources\WorkingLocations\WorkingLocationResource;
use App\Models\User;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class TrackerPlanCrmTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracker_navigation_is_in_business_order(): void
    {
        self::assertSame(1, TrackerPlanResource::getNavigationSort());
        self::assertSame(2, TrackerSettings::getNavigationSort());
        self::assertSame(3, WorkingLocationResource::getNavigationSort());
        self::assertSame(4, LocationDayResource::getNavigationSort());
    }

    public function test_tracker_plan_form_groups_fields_and_keeps_toggles_aligned(): void
    {
        $admin = $this->trackerAdmin();
        $component = Livewire::actingAs($admin)->test(CreateTrackerPlan::class)->assertSuccessful();
        $sections = $component->instance()->getSchema('form')->getComponents();

        self::assertCount(2, $sections);
        self::assertContainsOnlyInstancesOf(Section::class, $sections);
        self::assertSame(['Основное', 'Публикация и доступ'], array_map(
            static fn (Section $section): string => (string) $section->getHeading(),
            $sections,
        ));
        self::assertSame(
            ['name', 'price', 'currency', 'duration_days', 'description', 'monthly_practice'],
            array_map(static fn ($component): string => $component->getName(), $sections[0]->getChildComponents()),
        );
        self::assertSame(
            ['is_active', 'is_visible', 'included_access', 'display_order'],
            array_map(static fn ($component): string => $component->getName(), $sections[1]->getChildComponents()),
        );

        foreach ($sections[1]->getChildComponents() as $component) {
            if ($component instanceof Toggle) {
                self::assertFalse($component->isInline());
            }
        }
    }

    public function test_client_workspace_can_assign_a_generic_tracker_task(): void
    {
        $admin = $this->trackerAdmin();
        $organization = Organization::query()->findOrFail((int) config('tenancy.default_organization_id'));
        $client = Client::factory()->forOrganization($organization)->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertActionVisible('assignTrackerTask')
            ->callAction('assignTrackerTask', [
                'title' => 'Вечерняя практика',
                'task_type' => TrackerTaskType::Practice->value,
                'frequency' => 'daily',
                'starts_on' => today()->toDateString(),
                'display_order' => 0,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('tracker_tasks', [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'title' => 'Вечерняя практика',
            'task_type' => TrackerTaskType::Practice->value,
            'frequency' => 'daily',
        ]);
    }

    public function test_tracker_plan_currency_options_follow_finance_configuration(): void
    {
        $admin = $this->trackerAdmin();
        $organization = Organization::query()->findOrFail((int) config('tenancy.default_organization_id'));

        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'RUB',
            'allowed_currencies' => ['RUB', 'USD'],
            'force_single_currency' => false,
            'rounding_mode' => 'half_up',
            'rates' => [
                ['source_currency' => 'USD', 'target_currency' => 'RUB', 'rate' => '90'],
            ],
        ]);

        $component = Livewire::actingAs($admin)->test(CreateTrackerPlan::class)->assertSuccessful();
        $currency = collect($component->instance()->getSchema('form')->getFlatComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'currency');

        self::assertInstanceOf(Select::class, $currency);
        self::assertSame([
            'RUB' => 'Российский рубль',
            'USD' => 'Доллар США',
        ], $currency->getOptions());
    }

    private function trackerAdmin(): User
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return $admin;
    }
}
