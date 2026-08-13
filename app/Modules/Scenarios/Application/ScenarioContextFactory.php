<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipient;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ScenarioContextFactory
{
    public function evaluationContext(ScenarioEvent $event): ScenarioEvaluationContext
    {
        $booking = Booking::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey((int) $event->payload['booking_id'])
            ->with(['client', 'service'])
            ->first();

        return new ScenarioEvaluationContext($event, $booking, $booking?->client);
    }

    /** @return array<string, mixed> */
    public function renderContext(ScenarioEvaluationContext $context, ScenarioRecipient $recipient): array
    {
        if ($context->booking === null || $context->client === null) {
            throw (new ModelNotFoundException)->setModel(Booking::class);
        }

        return [
            'client' => [
                'full_name' => $context->client->full_name,
                'language' => strtolower((string) ($context->client->language ?? 'en')),
            ],
            'booking' => [
                'id' => (int) $context->booking->getKey(),
                'status' => $context->booking->status->value,
                'visit_format' => $context->booking->visit_format->value,
                'service_name' => $context->booking->service->name,
                'starts_at' => $context->booking->startsAtUtc()->toIso8601String(),
                'ends_at' => $context->booking->endsAtUtc()->toIso8601String(),
                'completed_at' => CarbonImmutable::parse((string) $context->event->occurred_at)->toIso8601String(),
            ],
            'recipient_locale' => $recipient->locale,
        ];
    }
}
