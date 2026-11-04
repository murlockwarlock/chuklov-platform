<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\ConditionEvaluatorRegistry;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioConditionSet;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class ScenarioCompletedVisitsConditionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_visit_count_condition_is_rechecked_from_canonical_bookings(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $first = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $second = $this->booking($organization, $client, $specialist, $service, BookingStatus::Completed);
        $event = ScenarioEvent::factory()->forOrganization($organization)->create([
            'event_name' => 'booking.completed',
            'aggregate_id' => (string) $second->getKey(),
            'payload' => ['booking_id' => $second->getKey(), 'client_id' => $client->getKey()],
        ]);
        $context = new ScenarioEvaluationContext($event, $second, $client);
        $registry = app(ConditionEvaluatorRegistry::class);

        self::assertTrue($registry->matches(ScenarioConditionSet::from([
            ['type' => 'client.completed_visits', 'operator' => 'equals', 'value' => 2],
        ]), $context));
        self::assertFalse($registry->matches(ScenarioConditionSet::from([
            ['type' => 'client.completed_visits', 'operator' => 'equals', 'value' => 1],
        ]), $context));
        self::assertTrue($registry->matches(ScenarioConditionSet::from([
            ['type' => 'client.completed_visits', 'operator' => 'exists', 'value' => null],
        ]), $context));

        $first->forceFill(['status' => BookingStatus::Cancelled])->save();
        self::assertFalse($registry->matches(ScenarioConditionSet::from([
            ['type' => 'client.completed_visits', 'operator' => 'equals', 'value' => 2],
        ]), $context));
    }

    public function test_completed_visit_count_condition_rejects_unsafe_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScenarioConditionSet::from([
            ['type' => 'client.completed_visits', 'operator' => 'equals', 'value' => 0],
        ]);
        app(ConditionEvaluatorRegistry::class)->validate(ScenarioConditionSet::from([
            ['type' => 'client.completed_visits', 'operator' => 'equals', 'value' => 0],
        ]));
    }

    private function booking(Organization $organization, Client $client, Specialist $specialist, Service $service, BookingStatus $status): Booking
    {
        return Booking::factory()->forOrganization($organization)->forClient($client)->forSpecialist($specialist)->forService($service)->create([
            'status' => $status->value,
        ]);
    }
}
