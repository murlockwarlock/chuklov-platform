<?php

namespace Tests\Integration;

use App\Filament\Livewire\DatabaseNotifications;
use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationSeverity;
use App\Modules\Channels\Infrastructure\Database\DatabaseNotificationChannel;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureOperationalNotificationDefaults;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CrmNotificationCenterPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_persists_one_permission_scoped_notification_for_a_replayed_event(): void
    {
        $this->requirePostgres();
        [$organization, $administrator, $staff, $foreignAdministrator, $client, $specialist, $service] = $this->fixture();
        app(OrganizationContext::class)->set($organization);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);

        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::PendingReview->value,
                'visit_format' => VisitFormat::HomeVisit->value,
                'starts_at' => CarbonImmutable::now()->addDay(),
                'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
                'blocking_ends_at' => CarbonImmutable::now()->addDay()->addHour(),
                'schedule_timezone' => 'UTC',
                'client_timezone' => 'UTC',
            ]);
        $event = app(RecordScenarioEvent::class)->bookingCreated($booking, 'postgres-crm-center', CarbonImmutable::now());

        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        app(MaterializeScenarioEvent::class)->handle($event->getKey());

        $action = ScenarioAction::query()
            ->where('scenario_event_id', $event->getKey())
            ->where('recipient_user_id', $administrator->getKey())
            ->whereJsonContains('channel_priority', 'database')
            ->sole();
        self::assertSame(1, ScenarioAction::query()->where('scenario_event_id', $event->getKey())->count());
        self::assertSame(0, $staff->notifications()->count());
        self::assertSame(0, $foreignAdministrator->notifications()->count());

        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([
            app(DatabaseNotificationChannel::class),
        ]));

        app(ExecuteScenarioAction::class)->handle($action->getKey());
        app(ExecuteScenarioAction::class)->handle($action->getKey());

        self::assertSame(ScenarioActionStatus::Delivered, $action->fresh()->status);
        self::assertSame(1, $administrator->fresh()->notifications()->count());
        self::assertSame(NotificationSeverity::High->value, $administrator->fresh()->notifications()->sole()->data['severity']);
        $this->actingAs($administrator);
        self::assertSame(1, app(DatabaseNotifications::class)->getNotificationsQuery()->count());
        self::assertSame(1, DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $administrator->getKey())
            ->count());
    }

    /** @return array{Organization, User, User, User, Client, Specialist, Service} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $foreignOrganization = Organization::factory()->create(['timezone' => 'Europe/Berlin']);
        $administrator = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $foreignAdministrator = User::factory()->forOrganization($foreignOrganization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'ru', 'timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create([
            'staff_user_id' => $staff->getKey(),
            'timezone' => 'UTC',
        ]);
        $service = Service::factory()->forOrganization($organization)->create();

        return [$organization, $administrator, $staff, $foreignAdministrator, $client, $specialist, $service];
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('CRM notification persistence requires the isolated PostgreSQL test environment.');
        }
    }
}
