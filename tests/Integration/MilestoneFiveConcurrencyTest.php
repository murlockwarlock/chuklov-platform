<?php

namespace Tests\Integration;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Application\ScheduleNextScenarioAction;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDeliveryAttempt;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MilestoneFiveConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_two_real_processes_materialize_one_event_into_one_action(): void
    {
        $this->requirePostgres();
        [$organization, $client, $specialist, $service, $event] = $this->fixture();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::materializeInProcess($event->id),
            static fn (): string => self::materializeInProcess($event->id),
        ]);

        self::assertCount(2, $results);
        self::assertNotContains('error', $results);
        self::assertSame(1, ScenarioAction::query()->where('organization_id', $organization->id)->count());
        self::assertSame(1, DB::table('scenario_deliveries')->where('organization_id', $organization->id)->count());
        self::assertSame(1, ScenarioEvent::query()->whereKey($event->id)->where('status', 'processed')->count());
    }

    public function test_two_real_processes_cannot_claim_one_due_action_twice(): void
    {
        $this->requirePostgres();
        [$organization, , , , $event] = $this->fixture();
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->sole();
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::executeInProcess($action->id),
            static fn (): string => self::executeInProcess($action->id),
        ]);

        self::assertCount(2, $results);
        self::assertNotContains('error', $results);
        self::assertContains($action->fresh()->status, [
            ScenarioActionStatus::Suppressed,
            ScenarioActionStatus::Failed,
        ]);
        self::assertSame(1, ScenarioDeliveryAttempt::query()->where('scenario_delivery_id', $action->deliveries()->sole()->id)->count());
    }

    public function test_two_real_processes_materialize_one_repeat_action(): void
    {
        $this->requirePostgres();
        [$organization, , , , $event] = $this->fixture();
        $rule = ScenarioRule::query()->where('organization_id', $organization->id)->sole();
        $rule->forceFill([
            'max_occurrences' => 2,
            'repeat_interval_value' => 1,
            'repeat_interval_unit' => 'hours',
        ])->save();
        app(MaterializeScenarioEvent::class)->handle($event->id);
        $action = ScenarioAction::query()->sole();
        $action->forceFill([
            'status' => ScenarioActionStatus::Delivered,
            'delivered_at' => now(),
            'max_occurrences' => 2,
            'repeat_interval_value' => 1,
            'repeat_interval_unit' => 'hours',
        ])->save();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::scheduleRepeatInProcess($action->id),
            static fn (): string => self::scheduleRepeatInProcess($action->id),
        ]);

        self::assertCount(2, $results);
        self::assertNotContains('error', $results);
        self::assertSame(1, ScenarioAction::query()->where('organization_id', $organization->id)->where('sequence_number', 2)->count());
        self::assertSame(2, DB::table('scenario_deliveries')->where('organization_id', $organization->id)->count());
    }

    /** @return array{Organization, Client, Specialist, Service, ScenarioEvent} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $template = NotificationTemplate::factory()->forOrganization($organization)->create();
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create();
        ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'conditions' => [],
        ]);
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Completed->value,
                'starts_at' => now()->subHours(3),
                'ends_at' => now()->subHours(2),
                'blocking_ends_at' => now()->subHours(2),
            ]);
        $event = app(RecordScenarioEvent::class)->bookingCompleted($booking, 'integration-concurrency', CarbonImmutable::now());

        return [$organization, $client, $specialist, $service, $event];
    }

    private static function materializeInProcess(int $eventId): string
    {
        try {
            app(MaterializeScenarioEvent::class)->handle($eventId);

            return 'ok';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function executeInProcess(int $actionId): string
    {
        try {
            app(ExecuteScenarioAction::class)->handle($actionId);

            return 'ok';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function scheduleRepeatInProcess(int $actionId): string
    {
        try {
            $action = ScenarioAction::query()->findOrFail($actionId);
            app(ScheduleNextScenarioAction::class)->handle($action);

            return 'ok';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The M5A concurrency tests require PostgreSQL row locks.');
        }
    }
}
