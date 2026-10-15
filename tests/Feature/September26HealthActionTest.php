<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ClientPortal\Application\GetClientHomeHealthAction;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tracker\Application\AssignTrackerTask;
use App\Modules\Tracker\Application\GrantPaidTrackerAccess;
use App\Modules\Tracker\Application\GrantTrackerAccess;
use App\Modules\Tracker\Application\SaveTrackerPlan;
use App\Modules\Tracker\Domain\Enums\TrackerEntitlementSource;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
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
            $this->get(route('portal.home'))->assertInertia(fn (AssertableInertia $page) => $page->where('healthAction', null));
        });
    }

    public function test_paid_renewal_of_manual_access_updates_its_source_without_replacing_the_entitlement(): void
    {
        [$organization, $admin, $client] = $this->actors();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Synthetic plan', true, true, '10.00', 'USD', 30, null, true, 1, 'Synthetic monthly practice');
        $previousEnd = CarbonImmutable::now('UTC')->addDays(10)->startOfSecond();
        $manual = app(GrantTrackerAccess::class)->handle($admin, $client, $version->plan()->firstOrFail(), CarbonImmutable::now('UTC')->subDay(), $previousEnd, 'Synthetic manual grant');
        self::assertSame(TrackerEntitlementSource::Manual, $manual->source);
        $this->get(route('portal.home'))->assertInertia(fn (AssertableInertia $page) => $page->where('healthAction', null));

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

    public function test_foreign_client_cannot_supply_focus_data(): void
    {
        $this->actors();
        $foreign = Client::factory()->forOrganization(Organization::factory()->create())->create();
        $this->expectException(AuthorizationException::class);
        app(GetClientHomeHealthAction::class)->handle($foreign, ['today' => []]);
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
