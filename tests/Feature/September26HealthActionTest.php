<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\ClientPortal\Application\GetClientHomeHealthAction;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tracker\Application\AssignTrackerTask;
use App\Modules\Tracker\Application\GrantPaidTrackerAccess;
use App\Modules\Tracker\Application\GrantTrackerAccess;
use App\Modules\Tracker\Application\SaveTrackerPlan;
use App\Modules\Tracker\Domain\Enums\TrackerEntitlementSource;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use App\Modules\Tracker\Domain\Models\TrackerTaskEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

final class September26HealthActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_access_without_a_task_keeps_focus_and_opens_the_existing_tracker(): void
    {
        [$organization, $admin, $client] = $this->actors();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Synthetic plan', true, true, '10.00', 'USD', 30, null, true, 1);
        $entitlement = app(GrantPaidTrackerAccess::class)->handle($organization, $client, $version);

        $this->get(route('portal.home'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('healthAction.kind', 'focus')
            ->where('healthAction.summaryKey', 'home.focusEmpty')
            ->where('healthAction.url', route('portal.tracker'))
            ->missing('healthAction.taskEntryUrl'));
        $this->get(route('portal.tracker'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tracker.access.allowed', true)->has('tracker.today', 0));
        $this->travelTo($entitlement->ends_at->addSecond(), function (): void {
            $this->assertDiagnosticAction();
        });
    }

    public function test_new_client_sees_the_diagnostics_action_and_can_open_the_empty_tests_state(): void
    {
        $this->actors();

        $this->assertDiagnosticAction();
        $this->get(route('portal.surveys.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Surveys')
                ->has('definitions', 0)
                ->has('attempts', 0));
    }

    public function test_unreadable_course_report_falls_back_to_diagnostics_and_leaves_a_safe_log_entry(): void
    {
        [$organization, , $client] = $this->actors();
        $run = AiRun::create([
            'organization_id' => $organization->getKey(),
            'capability' => AiCapability::ClinicalSynthesizer,
            'workflow_key' => ClinicalSynthesizerWorkflow::CourseReport->value,
            'origin' => AiRunOrigin::User,
            'execution_mode' => AiExecutionMode::Async,
            'client_id' => $client->getKey(),
            'status' => AiRunStatus::Succeeded,
            'human_review_status' => HumanReviewStatus::Accepted,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        AiRunPayload::create([
            'organization_id' => $organization->getKey(),
            'ai_run_id' => $run->getKey(),
            'encryption_key_version' => 1,
            'encrypted_output_payload' => 'unreadable-test-cipher',
        ]);
        Log::spy();

        $this->assertDiagnosticAction();

        Log::shouldHaveReceived('warning')->once()->with(
            'portal.home.health_action.report_unavailable',
            Mockery::on(fn (array $context): bool => $context['organization_id'] === $organization->getKey()
                && $context['actor_client_id'] === $client->getKey()
                && $context['entity_id'] === $run->getKey()
                && $context['safe_error_code'] === 'decrypt_or_decode_failed'
                && $context['outcome'] === 'fallback_diagnostics'
                && ! array_key_exists('encrypted_output_payload', $context)
                && ! array_key_exists('exception_message', $context)),
        );
    }

    public function test_accepted_course_report_projects_the_roadmap_with_the_existing_report_destination(): void
    {
        [$organization, , $client] = $this->actors();
        $run = AiRun::create([
            'organization_id' => $organization->getKey(),
            'capability' => AiCapability::ClinicalSynthesizer,
            'workflow_key' => ClinicalSynthesizerWorkflow::CourseReport->value,
            'origin' => AiRunOrigin::User,
            'execution_mode' => AiExecutionMode::Async,
            'client_id' => $client->getKey(),
            'status' => AiRunStatus::Succeeded,
            'human_review_status' => HumanReviewStatus::Accepted,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
            'finished_at' => now(),
        ]);
        $summary = 'Synthetic reviewed course report.';
        $encryptor = app(MedicalEncryptorInterface::class);
        $payload = AiRunPayload::create([
            'organization_id' => $organization->getKey(),
            'ai_run_id' => $run->getKey(),
            'encryption_key_version' => 1,
            'encrypted_output_payload' => $encryptor->encryptField(
                $organization->getKey(),
                json_encode(['course_summary' => $summary], JSON_THROW_ON_ERROR),
                1,
            ),
        ]);

        self::assertStringNotContainsString($summary, (string) $payload->encrypted_output_payload);
        $healthAction = app(GetClientHomeHealthAction::class)->handle($client, ['today' => []]);

        self::assertSame('roadmap', $healthAction['kind']);
        self::assertSame('home.roadmap', $healthAction['titleKey']);
        self::assertSame('home.roadmapDescription', $healthAction['summaryKey']);
        self::assertSame('home.ready', $healthAction['badgeKey']);
        self::assertSame('home.openPlan', $healthAction['actionKey']);
        self::assertSame(route('portal.health').'#course-report', $healthAction['url']);
    }

    public function test_paid_renewal_of_manual_access_updates_its_source_without_replacing_the_entitlement(): void
    {
        [$organization, $admin, $client] = $this->actors();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Synthetic plan', true, true, '10.00', 'USD', 30, null, true, 1, 'Synthetic monthly practice');
        $previousEnd = CarbonImmutable::now('UTC')->addDays(10)->startOfSecond();
        $manual = app(GrantTrackerAccess::class)->handle($admin, $client, $version->plan()->firstOrFail(), CarbonImmutable::now('UTC')->subDay(), $previousEnd, 'Synthetic manual grant');
        self::assertSame(TrackerEntitlementSource::Manual, $manual->source);
        $this->assertDiagnosticAction();

        $paid = app(GrantPaidTrackerAccess::class)->handle($organization, $client, $version);

        self::assertSame($manual->getKey(), $paid->getKey());
        self::assertSame(TrackerEntitlementSource::PaidPurchase, $paid->source);
        self::assertTrue($paid->ends_at->equalTo($previousEnd->addDays(30)));
        self::assertSame(1, $client->trackerEntitlements()->count());
        $this->get(route('portal.home'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('healthAction.kind', 'focus')->where('healthAction.summary', 'Synthetic monthly practice')
            ->missing('healthAction.taskEntryUrl'));
    }

    public function test_task_completion_correction_and_reload_are_projected_on_home(): void
    {
        [$organization, $admin, $client] = $this->actors();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Synthetic plan', true, true, '10.00', 'USD', 30, null, true, 1);
        app(GrantPaidTrackerAccess::class)->handle($organization, $client, $version);
        $task = app(AssignTrackerTask::class)->handle($admin, $client, 'Synthetic daily practice', TrackerTaskType::Practice, TrackerTaskFrequency::Daily, CarbonImmutable::now($client->timezone)->startOfDay());
        $url = route('portal.tracker.task-entry', ['taskId' => $task->getKey()]);
        $this->get(route('portal.home'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('healthAction.summary', 'Synthetic daily practice')->where('healthAction.taskEntryUrl', $url));
        foreach (['completed', 'not_completed', 'completed'] as $status) {
            $this->post($url, ['status' => $status])->assertSessionHasNoErrors()->assertRedirect();
            $this->get(route('portal.home'))->assertInertia(fn (AssertableInertia $page) => $page->where('healthAction.taskStatus', $status));
        }
    }

    public function test_invalid_task_status_has_a_localized_error_and_does_not_create_an_entry(): void
    {
        [$organization, $admin, $client] = $this->actors();
        $client->forceFill(['language' => 'ru'])->save();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Synthetic plan', true, true, '10.00', 'USD', 30, null, true, 1);
        app(GrantPaidTrackerAccess::class)->handle($organization, $client, $version);
        $task = app(AssignTrackerTask::class)->handle($admin, $client, 'Synthetic daily practice', TrackerTaskType::Practice, TrackerTaskFrequency::Daily, CarbonImmutable::now($client->timezone)->startOfDay());
        $url = route('portal.tracker.task-entry', ['taskId' => $task->getKey()]);

        $this->from(route('portal.home'))
            ->post($url, ['status' => 'unknown'])
            ->assertRedirect(route('portal.home'))
            ->assertSessionHasErrors([
                'status' => 'Не удалось определить отметку задачи. Выберите состояние и попробуйте снова.',
            ]);

        self::assertFalse(TrackerTaskEntry::query()->where('tracker_task_id', $task->getKey())->exists());
    }

    public function test_client_cannot_record_a_foreign_organizations_task(): void
    {
        [$organization, $admin, $client] = $this->actors();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Synthetic plan', true, true, '10.00', 'USD', 30, null, true, 1);
        app(GrantPaidTrackerAccess::class)->handle($organization, $client, $version);

        $foreignOrganization = Organization::factory()->create();
        $foreignAdmin = User::factory()->forOrganization($foreignOrganization)->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        app(OrganizationContext::class)->set($foreignOrganization);
        $foreignVersion = app(SaveTrackerPlan::class)->handle($foreignAdmin, null, 'Foreign synthetic plan', true, true, '10.00', 'USD', 30, null, true, 1);
        app(GrantPaidTrackerAccess::class)->handle($foreignOrganization, $foreignClient, $foreignVersion);
        $foreignTask = app(AssignTrackerTask::class)->handle($foreignAdmin, $foreignClient, 'Foreign synthetic practice', TrackerTaskType::Practice, TrackerTaskFrequency::Daily, CarbonImmutable::now($foreignClient->timezone)->startOfDay());
        app(OrganizationContext::class)->set($organization);

        $this->postJson(route('portal.tracker.task-entry', ['taskId' => $foreignTask->getKey()]), ['status' => 'completed'])
            ->assertNotFound();

        self::assertFalse(TrackerTaskEntry::query()->where('tracker_task_id', $foreignTask->getKey())->exists());
    }

    public function test_foreign_client_cannot_supply_focus_data(): void
    {
        $this->actors();
        $foreign = Client::factory()->forOrganization(Organization::factory()->create())->create();
        $this->expectException(AuthorizationException::class);
        app(GetClientHomeHealthAction::class)->handle($foreign, ['today' => []]);
    }

    private function assertDiagnosticAction(): void
    {
        $this->get(route('portal.home'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('healthAction.kind', 'diagnostics')
            ->where('healthAction.titleKey', 'home.diagnostics')
            ->where('healthAction.summaryKey', 'home.diagnosticsDescription')
            ->where('healthAction.actionKey', 'home.startTest')
            ->where('healthAction.badgeKey', 'home.new')
            ->where('healthAction.url', route('portal.surveys.index')));
    }

    /** @return array{Organization, User, Client} */
    private function actors(): array
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['tenancy.default_organization_id' => $organization->getKey()]);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $client->getKey()]);

        return [$organization, $admin, $client];
    }
}
