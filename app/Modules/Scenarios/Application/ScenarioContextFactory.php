<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipient;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final class ScenarioContextFactory
{
    public function evaluationContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt = null): ScenarioEvaluationContext
    {
        return match ($event->event_name) {
            ScenarioEventType::BookingCompleted => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::OnboardingStarted => $this->onboardingContext($event, $evaluationEndsAt),
        };
    }

    /** @return array<string, mixed> */
    public function renderContext(ScenarioEvaluationContext $context, ScenarioRecipient $recipient): array
    {
        if ($context->client === null) {
            throw (new ModelNotFoundException)->setModel(Client::class);
        }

        $renderContext = [
            'client' => [
                'full_name' => $context->client->full_name,
                'language' => strtolower((string) ($context->client->language ?? 'en')),
            ],
            'recipient_locale' => $recipient->locale,
        ];

        if ($context->booking !== null) {
            $renderContext['booking'] = [
                'id' => (int) $context->booking->getKey(),
                'status' => $context->booking->status->value,
                'visit_format' => $context->booking->visit_format->value,
                'service_name' => $context->booking->service->name,
                'starts_at' => $context->booking->startsAtUtc()->toIso8601String(),
                'ends_at' => $context->booking->endsAtUtc()->toIso8601String(),
                'completed_at' => CarbonImmutable::parse((string) $context->event->occurred_at)->toIso8601String(),
            ];
        }

        if ($context->onboarding !== null) {
            $renderContext['onboarding'] = [
                'stage' => $context->onboarding->current_stage->value,
                'completed' => $context->onboarding->completed_at !== null,
            ];
        }

        if (! isset($renderContext['booking']) && ! isset($renderContext['onboarding'])) {
            throw (new ModelNotFoundException)->setModel(Booking::class);
        }

        return $renderContext;
    }

    private function bookingContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $booking = Booking::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->payloadId($event, 'booking_id'))
            ->with(['client', 'service'])
            ->first();

        return new ScenarioEvaluationContext($event, $booking, $booking?->client, evaluationEndsAt: $evaluationEndsAt);
    }

    private function onboardingContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $onboarding = ClientOnboarding::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->payloadId($event, 'onboarding_id'))
            ->with('client')
            ->first();

        return new ScenarioEvaluationContext(
            event: $event,
            booking: null,
            client: $onboarding?->client,
            onboarding: $onboarding,
            evaluationEndsAt: $evaluationEndsAt,
        );
    }

    private function payloadId(ScenarioEvent $event, string $key): int
    {
        $value = $event->payload[$key] ?? null;

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidArgumentException('The scenario event payload identifier is invalid.');
    }
}
