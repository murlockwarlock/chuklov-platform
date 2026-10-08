<?php

namespace Tests\Unit;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scenarios\Application\BookingSourceConditionEvaluator;
use App\Modules\Scenarios\Application\BookingStatusConditionEvaluator;
use App\Modules\Scenarios\Application\ClientLanguageConditionEvaluator;
use App\Modules\Scenarios\Application\ConditionEvaluatorRegistry;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioConditionSet;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRuleConfiguration;
use App\Modules\Scheduling\Domain\Enums\BookingSource;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MilestoneFiveRemediationDomainTest extends TestCase
{
    public function test_present_non_array_condition_set_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScenarioRuleConfiguration::from([
            'rule_key' => 'invalid-conditions',
            'name' => 'Invalid conditions',
            'trigger_event' => 'booking.completed',
            'is_enabled' => true,
            'delay_value' => 0,
            'delay_unit' => 'hours',
            'purpose' => 'service',
            'conditions' => 'malformed',
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
            'template_version_id' => 1,
        ]);
    }

    #[DataProvider('invalidTypedConditions')]
    public function test_registry_rejects_invalid_typed_condition_values(array $condition): void
    {
        $registry = $this->registry();

        $this->expectException(InvalidArgumentException::class);
        $registry->validate(ScenarioConditionSet::from([$condition]));
    }

    public function test_execution_fails_closed_for_invalid_persisted_condition_value(): void
    {
        $booking = new Booking;
        $booking->forceFill(['status' => BookingStatus::Completed]);
        $client = new Client;
        $client->forceFill(['language' => 'en']);
        $event = new ScenarioEvent;
        $event->forceFill(['payload' => ['booking_id' => 1]]);
        $context = new ScenarioEvaluationContext($event, $booking, $client);

        self::assertFalse($this->registry()->matches(ScenarioConditionSet::from([
            ['type' => 'booking.status', 'operator' => 'not_equals', 'value' => 'invalid'],
        ]), $context));
    }

    public function test_booking_source_condition_distinguishes_portal_and_crm_bookings(): void
    {
        $booking = new Booking;
        $booking->forceFill(['source' => BookingSource::Crm->value]);
        $event = new ScenarioEvent;
        $event->forceFill(['payload' => ['booking_id' => 1]]);
        $context = new ScenarioEvaluationContext($event, $booking, null);
        $registry = new ConditionEvaluatorRegistry([new BookingSourceConditionEvaluator]);

        self::assertTrue($registry->matches(ScenarioConditionSet::from([
            ['type' => 'booking.source', 'operator' => 'equals', 'value' => 'crm'],
        ]), $context));
        self::assertFalse($registry->matches(ScenarioConditionSet::from([
            ['type' => 'booking.source', 'operator' => 'equals', 'value' => 'portal'],
        ]), $context));
    }

    public static function invalidTypedConditions(): array
    {
        return [
            'invalid booking status scalar' => [
                ['type' => 'booking.status', 'operator' => 'not_equals', 'value' => 'invalid'],
            ],
            'invalid booking status list item' => [
                ['type' => 'booking.status', 'operator' => 'in', 'value' => ['completed', 'invalid']],
            ],
            'invalid client language scalar' => [
                ['type' => 'client.language', 'operator' => 'equals', 'value' => 'de'],
            ],
            'invalid client language list item' => [
                ['type' => 'client.language', 'operator' => 'in', 'value' => ['en', 'de']],
            ],
            'invalid booking source scalar' => [
                ['type' => 'booking.source', 'operator' => 'equals', 'value' => 'unknown'],
            ],
            'invalid booking source list item' => [
                ['type' => 'booking.source', 'operator' => 'in', 'value' => ['portal', 'unknown']],
            ],
        ];
    }

    private function registry(): ConditionEvaluatorRegistry
    {
        return new ConditionEvaluatorRegistry([
            new BookingSourceConditionEvaluator,
            new BookingStatusConditionEvaluator,
            new ClientLanguageConditionEvaluator,
        ]);
    }
}
