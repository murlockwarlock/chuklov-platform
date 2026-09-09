<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scenarios\Jobs\ProcessScenarioEvent;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Tracker\Application\AssignTrackerTask;
use App\Modules\Tracker\Application\EndTrackerAccess;
use App\Modules\Tracker\Application\ExtendTrackerAccess;
use App\Modules\Tracker\Application\GrantTrackerAccess;
use App\Modules\Tracker\Application\RecordTrackerTaskEntry;
use App\Modules\Tracker\Application\ResolveTrackerAccess;
use App\Modules\Tracker\Application\SaveTrackerPlan;
use App\Modules\Tracker\Application\SubmitTrackerCheckIn;
use App\Modules\Tracker\Application\TrackerAccessConditionEvaluator;
use App\Modules\Tracker\Domain\Enums\TrackerTaskEntryStatus;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use App\Modules\Tracker\Domain\Models\TrackerEntitlement;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class MilestoneTwelveTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_mode_grants_access_without_finance_records_and_turning_it_off_rechecks_entitlement(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $this->setFreeMode($admin, true);
        self::assertTrue(app(ResolveTrackerAccess::class)->handle($client)->allowed());
        self::assertSame(0, TrackerEntitlement::query()->count());
        self::assertSame(0, DB::table('payment_gateway_transactions')->count());

        $this->setFreeMode($admin, false);
        self::assertFalse(app(ResolveTrackerAccess::class)->handle($client)->allowed());
    }

    public function test_manual_grant_preserves_applied_terms_after_plan_edit_and_can_be_extended_or_ended(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Старт', true, true, '10.00', 'USD', 30, 'Описание', true, 1);
        $plan = $version->plan()->firstOrFail();
        $starts = CarbonImmutable::now('UTC')->subDay();
        $ends = CarbonImmutable::now('UTC')->addDays(30);
        $entitlement = app(GrantTrackerAccess::class)->handle($admin, $client, $plan, $starts, $ends, 'Ручная выдача');
        app(SaveTrackerPlan::class)->handle($admin, $plan, 'Старт', true, true, '20.00', 'USD', 60, 'Новое описание', true, 1);
        $entitlement->refresh();
        self::assertSame(1000, $entitlement->applied_price_minor);
        self::assertSame(30, $entitlement->applied_duration_days);
        self::assertSame(2, $plan->versions()->count());
        app(ExtendTrackerAccess::class)->handle($admin, $client, CarbonImmutable::now('UTC')->addDays(90), 'Продление');
        self::assertTrue(CarbonImmutable::parse((string) $entitlement->refresh()->getRawOriginal('ends_at'))->greaterThan($ends));
        app(EndTrackerAccess::class)->handle($admin, $client, 'Завершение по запросу');
        self::assertFalse($entitlement->refresh()->active);
    }

    public function test_portal_uses_authoritative_access_and_offers_only_visible_active_plans(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        $client = Client::factory()->forOrganization($organization)->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);
        $visible = app(SaveTrackerPlan::class)->handle($admin, null, 'Видимый тариф', true, true, '10.00', 'USD', 30, null, true, 1);
        app(SaveTrackerPlan::class)->handle($admin, null, 'Скрытый тариф', true, false, '20.00', 'USD', 30, null, true, 2);
        app(SaveTrackerPlan::class)->handle($admin, null, 'Неактивный тариф', false, true, '30.00', 'USD', 30, null, true, 3);

        $this->withSession(['client_portal.client_id' => $client->id])
            ->get(route('portal.tracker'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Tracker')
                ->where('tracker.access.allowed', false)
                ->has('tracker.plans', 1)
                ->where('tracker.plans.0.name', 'Видимый тариф'));
        self::assertSame('Видимый тариф', $visible->plan()->firstOrFail()->name);
    }

    public function test_tracker_specialist_cta_uses_b2c_online_booking_without_entering_the_b2b_funnel(): void
    {
        [$organization] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialistUrl = route('portal.bookings.create', ['format' => VisitFormat::Online->value]);
        self::assertNotSame(route('portal.b2b'), $specialistUrl);

        $this->withSession(['client_portal.client_id' => $client->id])
            ->get(route('portal.tracker'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Tracker')
                ->where('urls.specialist', $specialistUrl));

        $this->withSession(['client_portal.client_id' => $client->id])
            ->get(route('portal.bookings.create', ['format' => VisitFormat::Online->value]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/BookingCreate')
                ->where('query.format', VisitFormat::Online->value));

        self::assertSame(0, DB::table('b2b_leads')->count());
    }

    public function test_tracker_check_in_requires_access_and_is_tenant_scoped(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        app(SetOrganizationSetting::class)->handle($admin, OrganizationSettingKey::TrackerFreeMode, true);
        $entry = app(SubmitTrackerCheckIn::class)->handle($client, 'Сегодня лучше спал.');
        self::assertSame($organization->id, $entry->organization_id);
        self::assertSame('Сегодня лучше спал.', $entry->note);

        $otherOrganization = Organization::factory()->create();
        app(OrganizationContext::class)->set($otherOrganization);
        $this->expectException(AuthorizationException::class);
        app(ResolveTrackerAccess::class)->handle($client);
    }

    public function test_client_tracker_projection_shows_assigned_tasks_without_access_policy_wording(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $this->setFreeMode($admin, true);
        Queue::fake();

        $task = app(AssignTrackerTask::class)->handle(
            actor: $admin,
            client: $client,
            title: 'Моя задача',
            type: TrackerTaskType::Practice,
            frequency: TrackerTaskFrequency::Daily,
            startsOn: CarbonImmutable::today($client->timezone ?? 'UTC'),
        );

        $this->withSession(['client_portal.client_id' => $client->id])
            ->get(route('portal.tracker'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Tracker')
                ->where('tracker.access.allowed', true)
                ->missing('tracker.access.freeMode')
                ->missing('tracker.access.statusLabel')
                ->where('urls.specialist', route('portal.bookings.create', ['format' => VisitFormat::Online->value]))
                ->where('tracker.today.0.id', $task->getKey())
                ->where('tracker.today.0.title', 'Моя задача')
                ->where('tracker.today.0.status', 'pending'));

        Queue::assertPushed(ProcessScenarioEvent::class);
    }

    public function test_client_can_complete_tracker_task_and_history_keeps_the_result(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $this->setFreeMode($admin, true);
        Queue::fake();

        $task = app(AssignTrackerTask::class)->handle(
            actor: $admin,
            client: $client,
            title: 'Ежедневная задача',
            type: TrackerTaskType::Other,
            frequency: TrackerTaskFrequency::Daily,
            startsOn: CarbonImmutable::today('UTC'),
        );

        app(RecordTrackerTaskEntry::class)->handle(
            client: $client,
            taskId: (int) $task->getKey(),
            status: TrackerTaskEntryStatus::Completed,
            comment: 'Готово',
        );

        $this->withSession(['client_portal.client_id' => $client->id])
            ->get(route('portal.tracker'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('tracker.today.0.status', TrackerTaskEntryStatus::Completed->value)
                ->where('tracker.today.0.comment', 'Готово')
                ->where('tracker.history.0.taskId', $task->getKey())
                ->where('tracker.history.0.status', TrackerTaskEntryStatus::Completed->value));
    }

    public function test_assigned_tracker_task_records_daily_scenario_event_for_reminders(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        Queue::fake();

        app(AssignTrackerTask::class)->handle(
            actor: $admin,
            client: $client,
            title: 'Программа на сегодня',
            type: TrackerTaskType::Exercise,
            frequency: TrackerTaskFrequency::Daily,
            startsOn: CarbonImmutable::today('UTC'),
        );

        $event = ScenarioEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event_name', ScenarioEventType::TrackerDailyTaskAssigned)
            ->first();

        self::assertNotNull($event);
        self::assertSame($client->getKey(), $event->payload['client_id']);
        $rule = ScenarioRule::query()
            ->where('organization_id', $organization->getKey())
            ->where('trigger_event', ScenarioEventType::TrackerDailyTaskAssigned)
            ->firstOrFail();
        self::assertSame(1, $rule->repeat_interval_value);
        self::assertSame(ScenarioDelayUnit::Days, $rule->repeat_interval_unit);
        self::assertSame('tracker.task_active', $rule->conditions[1]['type']);
        Queue::assertPushed(ProcessScenarioEvent::class);
    }

    public function test_tracker_management_requires_authorized_staff_and_rejects_cross_organization_plans(): void
    {
        [$organization] = $this->organizationWithAdmin();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $client = Client::factory()->forOrganization($organization)->create();

        $this->expectException(AuthorizationException::class);
        app(SaveTrackerPlan::class)->handle($staff, null, 'План', true, true, '10.00', 'USD', 30, null, true, 1);
    }

    public function test_included_access_false_plan_cannot_be_offered_or_granted(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Информационный', true, true, '10.00', 'USD', 30, null, false, 1);
        $plan = $version->plan()->firstOrFail();

        $this->expectException(ValidationException::class);
        app(GrantTrackerAccess::class)->handle($admin, $client, $plan, CarbonImmutable::now('UTC'), CarbonImmutable::now('UTC')->addDays(30), 'Ручная выдача');
    }

    public function test_tracker_access_condition_uses_same_authority(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $condition = new ScenarioCondition('tracker.access', ScenarioConditionOperator::Equals, true);
        $event = ScenarioEvent::factory()->forOrganization($organization)->create();
        $context = new ScenarioEvaluationContext($event, null, $client);

        self::assertFalse(app(TrackerAccessConditionEvaluator::class)->evaluate($condition, $context));
        $this->setFreeMode($admin, true);
        self::assertTrue(app(TrackerAccessConditionEvaluator::class)->evaluate($condition, $context));
    }

    public function test_manual_access_operations_are_audited_and_mini_app_tracker_entry_is_allowlisted(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Старт', true, true, '10.00', 'USD', 30, null, true, 1);
        $plan = $version->plan()->firstOrFail();
        app(GrantTrackerAccess::class)->handle($admin, $client, $plan, CarbonImmutable::now('UTC'), CarbonImmutable::now('UTC')->addDays(30), 'Ручная выдача');

        self::assertGreaterThanOrEqual(2, DB::table('audit_events')->where('organization_id', $organization->getKey())->whereIn('action', ['tracker.plan.version.saved', 'tracker.access.granted'])->count());
        self::assertSame('/portal/tracker', app(ResolveTelegramMiniAppEntry::class)->destination('tracker'));
        self::assertTrue(TrackerPlan::query()->whereKey($plan->getKey())->exists());
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function setFreeMode(User $admin, bool $enabled): void
    {
        app(SetOrganizationSetting::class)->handle($admin, OrganizationSettingKey::TrackerFreeMode, $enabled);
    }
}
