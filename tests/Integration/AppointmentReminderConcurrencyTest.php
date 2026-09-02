<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\AppointmentReminderScheduler;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class AppointmentReminderConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_two_real_processes_materialize_one_reminder_per_recipient_and_offset(): void
    {
        $this->requirePostgres();
        [$organization, $client, $specialist, $service] = $this->fixture();
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Confirmed->value,
                'visit_format' => VisitFormat::Office->value,
                'starts_at' => CarbonImmutable::now()->addDays(5),
                'ends_at' => CarbonImmutable::now()->addDays(5)->addHour(),
                'blocking_ends_at' => CarbonImmutable::now()->addDays(5)->addHour(),
                'schedule_timezone' => 'UTC',
                'client_timezone' => 'UTC',
            ]);
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'reminder-concurrency', CarbonImmutable::now());

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::scheduleInProcess($booking->getKey(), $event->getKey()),
            static fn (): string => self::scheduleInProcess($booking->getKey(), $event->getKey()),
        ]);

        self::assertCount(2, $results);
        self::assertNotContains('error', $results);
        self::assertSame(4, ScenarioAction::query()->where('organization_id', $organization->getKey())->where('kind', 'appointment_reminder')->count());
        self::assertSame(4, DB::table('scenario_deliveries')->where('organization_id', $organization->getKey())->count());
    }

    /** @return array{Organization, Client, Specialist, Service} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'ru', 'timezone' => 'UTC']);
        ClientChannelIdentity::factory()->forClient($client)->create([
            'channel' => 'telegram',
            'external_id' => 'client-'.$client->getKey(),
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        OrganizationChannelIdentity::factory()->forUser($staff)->verified()->create(['external_id' => 'staff-'.$staff->getKey()]);
        $specialist = Specialist::factory()->forOrganization($organization)->create([
            'staff_user_id' => $staff->getKey(),
            'notifications_enabled' => true,
        ]);
        $service = Service::factory()->forOrganization($organization)->create(['formats' => ['office']]);

        app(OrganizationContext::class)->set($organization);
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([new RecordingNotificationChannel]));

        return [$organization, $client, $specialist, $service];
    }

    private static function scheduleInProcess(int $bookingId, int $eventId): string
    {
        try {
            app(AppointmentReminderScheduler::class)->schedule(
                Booking::query()->findOrFail($bookingId),
                ScenarioEvent::query()->findOrFail($eventId),
            );

            return 'ok';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Appointment reminder concurrency requires PostgreSQL row locks and unique indexes.');
        }
    }
}
