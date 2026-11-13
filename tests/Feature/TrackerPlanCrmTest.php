<?php

namespace Tests\Feature;

use App\Filament\Pages\TrackerSettings;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\LocationDays\LocationDayResource;
use App\Filament\Resources\TrackerPlans\Pages\CreateTrackerPlan;
use App\Filament\Resources\TrackerPlans\TrackerPlanResource;
use App\Filament\Resources\WorkingLocations\WorkingLocationResource;
use App\Models\User;
use App\Modules\Commerce\Domain\Models\PaymentProviderOfferMapping;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Tracker\Application\MoveTrackerPlan;
use App\Modules\Tracker\Application\SaveTrackerPlan;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

        self::assertCount(3, $sections);
        self::assertContainsOnlyInstancesOf(Section::class, $sections);
        self::assertSame(['Основное', 'Публикация и доступ', 'Онлайн-оплата'], array_map(
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
        self::assertSame(
            ['lava_enabled', 'lava_currency'],
            array_map(static fn ($component): string => $component->getName(), $sections[2]->getChildComponents()),
        );
        self::assertNotNull($component->instance()->getSchema('form')->getComponent('lava_offer_id', withHidden: true));

        foreach ($sections[1]->getChildComponents() as $component) {
            if ($component instanceof Toggle) {
                self::assertFalse($component->isInline());
            }
        }
    }

    public function test_tracker_plan_order_is_changed_by_human_move_actions_without_mutating_versions(): void
    {
        $admin = $this->trackerAdmin();
        $first = app(SaveTrackerPlan::class)->handle($admin, null, 'Первый тариф', true, true, '10.00', 'USD', 30, null, true, null);
        $second = app(SaveTrackerPlan::class)->handle($admin, null, 'Второй тариф', true, true, '20.00', 'USD', 30, null, true, null);
        $firstPlan = $first->plan()->firstOrFail();
        $secondPlan = $second->plan()->firstOrFail();

        app(MoveTrackerPlan::class)->handle($admin, $secondPlan, 'up');

        self::assertSame(1, (int) $this->currentVersionOrFail($firstPlan)->display_order);
        self::assertSame(0, (int) $this->currentVersionOrFail($secondPlan)->display_order);
        self::assertGreaterThan(1, $firstPlan->versions()->count());
        self::assertGreaterThan(1, $secondPlan->versions()->count());
    }

    public function test_tracker_plan_order_supports_both_directions_and_rejects_first_and_last_boundaries(): void
    {
        $admin = $this->trackerAdmin();
        $firstPlan = app(SaveTrackerPlan::class)->handle($admin, null, 'Первый тариф', true, true, '10.00', 'USD', 30, null, true, 0)->plan()->firstOrFail();
        $secondPlan = app(SaveTrackerPlan::class)->handle($admin, null, 'Второй тариф', true, true, '20.00', 'USD', 30, null, true, 1)->plan()->firstOrFail();
        $lastPlan = app(SaveTrackerPlan::class)->handle($admin, null, 'Последний тариф', true, true, '30.00', 'USD', 30, null, true, 2)->plan()->firstOrFail();

        $this->assertMoveRejected($admin, $firstPlan, 'up', 'Тариф уже находится в начале списка.');
        $this->assertMoveRejected($admin, $lastPlan, 'down', 'Тариф уже находится в конце списка.');

        app(MoveTrackerPlan::class)->handle($admin, $secondPlan, 'down');
        self::assertSame(0, (int) $this->currentVersionOrFail($firstPlan)->display_order);
        self::assertSame(2, (int) $this->currentVersionOrFail($secondPlan)->display_order);
        self::assertSame(1, (int) $this->currentVersionOrFail($lastPlan)->display_order);

        app(MoveTrackerPlan::class)->handle($admin, $secondPlan, 'up');
        self::assertSame(0, (int) $this->currentVersionOrFail($firstPlan)->display_order);
        self::assertSame(1, (int) $this->currentVersionOrFail($secondPlan)->display_order);
        self::assertSame(2, (int) $this->currentVersionOrFail($lastPlan)->display_order);
    }

    public function test_tracker_plan_reorder_preserves_version_history_mappings_and_audit_events(): void
    {
        $admin = $this->trackerAdmin();
        $firstPlan = app(SaveTrackerPlan::class)->handle($admin, null, 'Первый тариф', true, true, '10.00', 'USD', 30, 'Первое описание', true, 0)->plan()->firstOrFail();
        $secondVersion = app(SaveTrackerPlan::class)->handle($admin, null, 'Второй тариф', true, true, '20.00', 'USD', 30, 'Второе описание', true, 1);
        $secondPlan = $secondVersion->plan()->firstOrFail();

        $mapping = new PaymentProviderOfferMapping;
        $mapping->forceFill([
            'organization_id' => $this->organization()->getKey(),
            'gateway' => 'lava',
            'sellable_type' => $secondVersion::class,
            'sellable_id' => $secondVersion->getKey(),
            'currency' => 'USD',
            'external_offer_id' => 'tracker-reorder-offer',
            'provider_metadata' => ['source' => 'tracker-reorder-test'],
            'is_active' => true,
        ])->save();

        $oldVersionId = (int) $secondVersion->getKey();
        $oldVersionCount = $secondPlan->versions()->count();

        app(MoveTrackerPlan::class)->handle($admin, $secondPlan, 'up');

        $secondPlan->refresh();
        $newVersion = $this->currentVersionOrFail($secondPlan);
        self::assertNotSame($oldVersionId, (int) $newVersion->getKey());
        self::assertSame(0, (int) $newVersion->display_order);
        self::assertSame($oldVersionCount + 1, $secondPlan->versions()->count());
        self::assertSame(1, (int) $this->currentVersionOrFail($firstPlan)->display_order);
        self::assertDatabaseHas('tracker_plan_versions', [
            'id' => $oldVersionId,
            'display_order' => 1,
            'description' => 'Второе описание',
        ]);
        self::assertDatabaseHas('payment_provider_offer_mappings', [
            'organization_id' => $this->organization()->getKey(),
            'sellable_type' => $secondVersion::class,
            'sellable_id' => $oldVersionId,
            'external_offer_id' => 'tracker-reorder-offer',
            'is_active' => true,
        ]);
        self::assertDatabaseHas('payment_provider_offer_mappings', [
            'organization_id' => $this->organization()->getKey(),
            'sellable_type' => $secondVersion::class,
            'sellable_id' => $newVersion->getKey(),
            'external_offer_id' => 'tracker-reorder-offer',
            'is_active' => true,
        ]);
        self::assertSame(2, DB::table('audit_events')
            ->where('organization_id', $this->organization()->getKey())
            ->where('action', 'tracker.plan.reordered')
            ->count());
    }

    public function test_tracker_plan_reorder_ignores_plan_without_current_version_and_keeps_tenant_boundary(): void
    {
        $admin = $this->trackerAdmin();
        $organization = $this->organization();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Локальный тариф', true, true, '10.00', 'USD', 30, null, true, 0);
        $plan = $version->plan()->firstOrFail();
        app(SaveTrackerPlan::class)->handle($admin, null, 'Соседний тариф', true, true, '20.00', 'USD', 30, null, true, 1);
        $draftPlan = TrackerPlan::factory()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Черновик без версии',
        ]);

        app(MoveTrackerPlan::class)->handle($admin, $plan, 'down');

        self::assertNull($draftPlan->refresh()->current_version_id);
        self::assertNotNull($this->currentVersionOrFail($plan));

        $foreignOrganization = Organization::factory()->create();
        $foreignAdmin = User::factory()->forOrganization($foreignOrganization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($foreignOrganization);
        $foreignVersion = app(SaveTrackerPlan::class)->handle($foreignAdmin, null, 'Чужой тариф', true, true, '30.00', 'USD', 30, null, true, 0);
        $foreignPlan = $foreignVersion->plan()->firstOrFail();
        $foreignVersionId = (int) $foreignPlan->current_version_id;

        app(OrganizationContext::class)->set($organization);
        $this->assertMoveRejected($admin, $foreignPlan, 'up', 'Выбранный тариф недоступен в этой организации.');

        self::assertSame($foreignVersionId, (int) $foreignPlan->refresh()->current_version_id);
        self::assertSame(1, $foreignPlan->versions()->count());
    }

    public function test_invalid_tracker_plan_price_keeps_form_state_and_shows_human_error(): void
    {
        $admin = $this->trackerAdmin();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(CreateTrackerPlan::class)
            ->fillForm([
                'name' => 'Практика восстановления',
                'price' => '-1',
                'currency' => 'USD',
                'duration_days' => 30,
                'display_order' => 0,
            ])
            ->call('create')
            ->assertHasErrors(['data.price' => 'Укажите цену в формате 1000 или 1000.50.'])
            ->assertNotified('Не удалось сохранить. Исправьте отмеченные поля.')
            ->assertSet('data.name', 'Практика восстановления')
            ->assertSet('data.price', '-1');
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

    public function test_client_profile_goal_can_enter_tracker_taxonomy_from_prefilled_crm_action(): void
    {
        $admin = $this->trackerAdmin();
        $organization = Organization::query()->findOrFail((int) config('tenancy.default_organization_id'));
        $client = Client::factory()->forOrganization($organization)->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(UpdateMedicalProfile::class)->handle($admin, $client, UpdateMedicalProfileCommand::fromArray([
            'complaints' => 'Синтетическая жалоба',
            'goals' => 'Синтетическая цель из медпрофиля',
            'clinical_answers' => json_encode([
                'symptoms' => ['neck'],
                'pain_vas' => null,
                'no_chronic_conditions' => true,
                'had_operations' => false,
                'operation_year' => null,
                'had_injuries' => false,
                'injury_year' => null,
            ], JSON_THROW_ON_ERROR),
        ]));

        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertActionVisible('assignProfileGoal')
            ->callAction('assignProfileGoal', [
                'task_type' => TrackerTaskType::Practice->value,
                'frequency' => 'daily',
                'starts_on' => today()->toDateString(),
                'display_order' => 0,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('tracker_tasks', [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'title' => 'Синтетическая цель из медпрофиля',
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

    private function organization(): Organization
    {
        return app(OrganizationContext::class)->organization();
    }

    private function currentVersionOrFail(TrackerPlan $plan): TrackerPlanVersion
    {
        $version = $plan->refresh()->currentVersion;
        if (! $version instanceof TrackerPlanVersion) {
            self::fail('The tracker plan must have a current version.');
        }

        return $version;
    }

    private function assertMoveRejected(User $admin, TrackerPlan $plan, string $direction, string $message): void
    {
        try {
            app(MoveTrackerPlan::class)->handle($admin, $plan, $direction);
            self::fail('The tracker plan move should have been rejected.');
        } catch (ValidationException $exception) {
            self::assertSame($message, $exception->errors()['plan'][0]);
        }
    }
}
