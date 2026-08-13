<?php

namespace Tests\Unit;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scenarios\Application\BookingStatusConditionEvaluator;
use App\Modules\Scenarios\Application\ClientLanguageConditionEvaluator;
use App\Modules\Scenarios\Application\ConditionEvaluatorRegistry;
use App\Modules\Scenarios\Application\ScenarioTemplateRenderer;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\ValueObjects\NotificationTemplateConfiguration;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioConditionSet;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioIdempotencyKey;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipientStrategy;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MilestoneFiveDomainTest extends TestCase
{
    public function test_typed_conditions_are_evaluated_against_current_domain_context(): void
    {
        $booking = new Booking;
        $booking->forceFill(['status' => BookingStatus::Completed->value]);
        $client = new Client;
        $client->forceFill(['language' => 'ru']);
        $event = new ScenarioEvent;
        $event->forceFill(['payload' => ['booking_id' => 1]]);
        $context = new ScenarioEvaluationContext($event, $booking, $client);
        $registry = new ConditionEvaluatorRegistry([
            new BookingStatusConditionEvaluator,
            new ClientLanguageConditionEvaluator,
        ]);

        self::assertTrue($registry->matches(ScenarioConditionSet::from([
            ['type' => 'booking.status', 'operator' => 'equals', 'value' => 'completed'],
            ['type' => 'client.language', 'operator' => 'equals', 'value' => 'ru'],
        ]), $context));
        self::assertFalse($registry->matches(ScenarioConditionSet::from([
            ['type' => 'booking.status', 'operator' => 'equals', 'value' => 'cancelled'],
        ]), $context));
    }

    public function test_condition_registry_rejects_unknown_condition_types(): void
    {
        $registry = new ConditionEvaluatorRegistry([
            new BookingStatusConditionEvaluator,
            new ClientLanguageConditionEvaluator,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $registry->validate(ScenarioConditionSet::from([
            ['type' => 'booking.arbitrary', 'operator' => 'equals', 'value' => 'completed'],
        ]));
    }

    public function test_template_renderer_only_exposes_declared_allowlisted_variables(): void
    {
        $template = new NotificationTemplateVersion;
        $template->forceFill([
            'body' => 'Hello {{ client.full_name }}.',
            'subject' => null,
            'variables' => ['client.full_name'],
        ]);

        $rendered = (new ScenarioTemplateRenderer)->render(
            $template,
            ['client' => ['full_name' => 'Ada Lovelace']],
            'en',
        );

        self::assertSame('Hello Ada Lovelace.', $rendered->body);

        $template->forceFill(['body' => 'Secret {{ user.password }}.']);

        $this->expectException(InvalidArgumentException::class);
        (new ScenarioTemplateRenderer)->render($template, [], 'en');
    }

    public function test_template_configuration_rejects_undeclared_variables(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NotificationTemplateConfiguration::from([
            'template_key' => 'test',
            'name' => 'Test',
            'locale' => 'en',
            'purpose' => 'service',
            'body' => 'Hello {{ client.full_name }}.',
            'variables' => [],
        ]);
    }

    public function test_idempotency_keys_are_deterministic_and_scoped(): void
    {
        $first = ScenarioIdempotencyKey::materialization(1, 2, 3, 'client:4');

        self::assertSame($first, ScenarioIdempotencyKey::materialization(1, 2, 3, 'client:4'));
        self::assertNotSame($first, ScenarioIdempotencyKey::materialization(2, 2, 3, 'client:4'));
        self::assertNotSame(
            ScenarioIdempotencyKey::delivery(1, 10, 'telegram'),
            ScenarioIdempotencyKey::delivery(1, 10, 'email'),
        );
    }

    public function test_role_recipient_strategy_deduplicates_and_rejects_empty_values(): void
    {
        $strategy = ScenarioRecipientStrategy::from([
            'type' => 'roles',
            'roles' => ['staff', 'staff', 'administrator'],
        ]);

        self::assertSame(['staff', 'administrator'], $strategy->toArray()['roles']);

        $this->expectException(InvalidArgumentException::class);
        ScenarioRecipientStrategy::from(['type' => 'roles', 'roles' => []]);
    }
}
